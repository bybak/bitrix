<?php
/**
 * Обновление остатков (и опционально цены) от поставщика в отдельный склад Bitrix.
 *
 * Запуск (внутри контейнера bitrix_php):
 *   php /var/www/html/mf_update_supplier_stock.php --dry-run --supplier=SupplierA --file=/var/www/html/supplier_stock.csv
 *   php /var/www/html/mf_update_supplier_stock.php --apply   --supplier=SupplierA --file=/var/www/html/supplier_stock.csv
 *
 * CSV формат (рекомендуется):
 *   Артикул;Остаток;Цена
 *
 * Важно про цену:
 * - Для каждого поставщика создаётся свой тип цены (CATALOG_GROUP) и обновляется туда.
 * - BASE цена (которая показывается на сайте, т.к. компоненты настроены на PRICE_CODE=BASE)
 *   пересчитывается как минимальная цена среди поставщиков, у которых есть остаток > 0.
 *
 * Опции:
 *   --iblock-id=4
 *   --supplier=NameOrCode
 *   --file=/path/file.csv
 *   --encoding=cp1251|utf-8
 *   --apply / --dry-run
 *   --price=Y|N        (по умолчанию N)
 *   --recalc-base=Y|N  (по умолчанию Y, если --price=Y)
 */

$_SERVER["DOCUMENT_ROOT"] = __DIR__;
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Catalog\StoreTable;

Loader::includeModule("iblock");
Loader::includeModule("catalog");

// Ensure progress output is visible in CLI (no buffering).
while (ob_get_level() > 0)
{
	@ob_end_flush();
}
@ob_implicit_flush(true);

function arg(string $name): ?string
{
	foreach ($_SERVER['argv'] as $a)
	{
		if (strpos($a, $name.'=') === 0)
		{
			return substr($a, strlen($name) + 1);
		}
	}
	return null;
}
function flag(string $name): bool { return in_array($name, $_SERVER['argv'], true); }
function out(string $s): void
{
	echo $s . PHP_EOL;
	if (function_exists('flush')) flush();
}

function mf_pct(float $done, float $total): float
{
	if ($total <= 0) return 0.0;
	$p = ($done / $total) * 100.0;
	if ($p < 0) $p = 0.0;
	if ($p > 100) $p = 100.0;
	return $p;
}

function mf_progress(string $label, float $pct, string $extra = ''): void
{
	static $lastLen = 0;
	$line = sprintf("%s: %6.2f%%", $label, $pct);
	if ($extra !== '') $line .= "  " . $extra;

	$pad = max(0, $lastLen - strlen($line));
	echo "\r" . $line . str_repeat(' ', $pad);
	$lastLen = strlen($line);
	if (function_exists('flush')) flush();
}

function mf_progressDone(): void
{
	echo PHP_EOL;
	if (function_exists('flush')) flush();
}

function normalizeArticle(string $s): string
{
	$s = mb_strtoupper(trim($s));
	$s = preg_replace('~[^A-Z0-9]+~', '', $s) ?? '';
	return $s;
}

function findStoreIdByXmlId(string $xmlId): ?int
{
	$xmlId = trim($xmlId);
	if ($xmlId === '') return null;

	$row = StoreTable::getList([
		'filter' => ['=XML_ID' => $xmlId],
		'select' => ['ID'],
		'limit' => 1,
	])->fetch();
	return $row ? (int)$row['ID'] : null;
}

function getOrCreateStore(string $supplier, bool $create): int
{
	$supplier = trim($supplier);
	$xmlId = 'SUPPLIER_' . preg_replace('~[^A-Z0-9_]+~', '_', mb_strtoupper($supplier));

	$existingId = findStoreIdByXmlId($xmlId);
	if ($existingId) return $existingId;

	if (!$create)
	{
		return 0;
	}

	$res = StoreTable::add([
		'TITLE' => $supplier,
		'ACTIVE' => 'Y',
		'ADDRESS' => '',
		'DESCRIPTION' => 'Автосозданный склад поставщика ' . $supplier,
		'XML_ID' => $xmlId,
	]);
	if (!$res->isSuccess())
	{
		throw new RuntimeException("Не удалось создать склад поставщика '$supplier': " . implode('; ', $res->getErrorMessages()));
	}
	return (int)$res->getId();
}

function getOrCreatePriceGroupIdByStoreXmlId(string $storeXmlId, string $titleFallback): int
{
	$name = mb_strtoupper(trim($storeXmlId));
	if ($name === '') throw new RuntimeException('Пустой storeXmlId');

	$rs = CCatalogGroup::GetList([], ['=NAME' => $name], false, false, ['ID', 'NAME']);
	if ($r = $rs->Fetch())
	{
		return (int)$r['ID'];
	}

	$cg = new CCatalogGroup();
	$id = $cg->Add([
		'NAME' => $name,
		'BASE' => 'N',
		'SORT' => 2000,
		'USER_GROUP' => [2], // all users
		'USER_GROUP_BUY' => [2],
		'LANG' => [
			'ru' => ['NAME' => $titleFallback],
			'en' => ['NAME' => $titleFallback],
		],
	]);
	if (!$id)
	{
		throw new RuntimeException("Не удалось создать тип цены '$name': " . $cg->LAST_ERROR);
	}
	return (int)$id;
}

function getSupplierStoreToPriceGroupMap(): array
{
	$map = []; // storeId => priceGroupId
	$rs = CCatalogStore::GetList(['ID' => 'ASC'], ['%XML_ID' => 'SUPPLIER_'], false, false, ['ID', 'XML_ID', 'TITLE']);
	while ($s = $rs->Fetch())
	{
		$storeId = (int)$s['ID'];
		$xmlId = (string)($s['XML_ID'] ?? '');
		if ($storeId <= 0 || $xmlId === '') continue;
		$map[$storeId] = getOrCreatePriceGroupIdByStoreXmlId($xmlId, (string)($s['TITLE'] ?? $xmlId));
	}
	return $map;
}

function findCanonicalProductIdByNorm(int $iblockId, string $norm): ?int
{
	$r = CIBlockElement::GetList(
		[],
		[
			'IBLOCK_ID' => $iblockId,
			'=PROPERTY_MF_ARTICLE_NORM' => $norm,
			'!PROPERTY_MF_IS_REDIRECT' => 'Y',
		],
		false,
		false,
		['ID']
	)->Fetch();
	return $r ? (int)$r['ID'] : null;
}

function upsertPrice(int $productId, int $priceGroupId, float $price): void
{
	if ($price <= 0) return;

	$rs = CPrice::GetList(
		[],
		['PRODUCT_ID' => $productId, 'CATALOG_GROUP_ID' => $priceGroupId],
		false,
		false,
		['ID', 'PRICE', 'CURRENCY']
	);
	if ($p = $rs->Fetch())
	{
		CPrice::Update((int)$p['ID'], ['PRICE' => $price, 'CURRENCY' => 'RUB']);
	}
	else
	{
		CPrice::Add(['PRODUCT_ID' => $productId, 'CATALOG_GROUP_ID' => $priceGroupId, 'PRICE' => $price, 'CURRENCY' => 'RUB']);
	}
}

function upsertStoreAmount(int $productId, int $storeId, float $amount): void
{
	$rs = CCatalogStoreProduct::GetList(
		[],
		['PRODUCT_ID' => $productId, 'STORE_ID' => $storeId],
		false,
		false,
		['ID', 'AMOUNT']
	);
	if ($row = $rs->Fetch())
	{
		CCatalogStoreProduct::Update((int)$row['ID'], ['AMOUNT' => $amount]);
	}
	else
	{
		CCatalogStoreProduct::Add(['PRODUCT_ID' => $productId, 'STORE_ID' => $storeId, 'AMOUNT' => $amount]);
	}
}

function sumAllStoresAmount(int $productId): float
{
	$sum = 0.0;
	$rs = CCatalogStoreProduct::GetList([], ['PRODUCT_ID' => $productId], false, false, ['AMOUNT']);
	while ($r = $rs->Fetch())
	{
		$sum += (float)$r['AMOUNT'];
	}
	return $sum;
}

function setBasePrice(int $productId, float $price): void
{
	if ($price <= 0) return;
	CPrice::SetBasePrice($productId, $price, 'RUB');
}

function recalcBasePriceFromAvailableStores(int $productId, array $storeToGroup): ?float
{
	// prices by group
	$prices = [];
	$rsP = CPrice::GetList([], ['PRODUCT_ID' => $productId], false, false, ['CATALOG_GROUP_ID', 'PRICE']);
	while ($p = $rsP->Fetch())
	{
		$gid = (int)$p['CATALOG_GROUP_ID'];
		$prices[$gid] = (float)$p['PRICE'];
	}

	$min = null;
	$rsS = CCatalogStoreProduct::GetList([], ['PRODUCT_ID' => $productId], false, false, ['STORE_ID', 'AMOUNT']);
	while ($sp = $rsS->Fetch())
	{
		$storeId = (int)$sp['STORE_ID'];
		$amount = (float)$sp['AMOUNT'];
		if ($amount <= 0) continue;
		if (!isset($storeToGroup[$storeId])) continue;
		$gid = (int)$storeToGroup[$storeId];
		$price = (float)($prices[$gid] ?? 0);
		if ($price <= 0) continue;
		if ($min === null || $price < $min) $min = $price;
	}

	return $min;
}

try
{
	$iblockId = (int)(arg('--iblock-id') ?: 4);
	$supplier = arg('--supplier') ?: '';
	$file = arg('--file') ?: '';
	$encoding = arg('--encoding') ?: 'cp1251';
	$apply = flag('--apply');
	$dry = flag('--dry-run') || !$apply;
	$usePrice = (arg('--price') ?: 'N') === 'Y';
	$recalcBase = (arg('--recalc-base') ?: 'Y') === 'Y';

	if ($supplier === '') throw new RuntimeException("Укажи --supplier=NAME");
	if ($file === '') throw new RuntimeException("Укажи --file=/path/file.csv");
	if (!file_exists($file)) throw new RuntimeException("Файл не найден: $file");

	out("=== MF SUPPLIER STOCK UPDATE ===");
	out("IBLOCK_ID: $iblockId");
	out("SUPPLIER: $supplier");
	out("FILE: $file");
	out("ENCODING: $encoding");
	out("MODE: " . ($apply ? 'APPLY' : 'DRY-RUN'));
	out("PRICE_UPDATE: " . ($usePrice ? 'Y' : 'N'));
	out("RECALC_BASE: " . (($usePrice && $recalcBase) ? 'Y' : 'N'));

	$storeId = getOrCreateStore($supplier, $apply);
	if ($storeId <= 0)
	{
		out("STORE_ID: отсутствует (dry-run). При --apply будет создан склад SUPPLIER_... для поставщика.");
	}
	else
	{
		out("STORE_ID: $storeId");
	}

	$storeToGroup = $usePrice ? getSupplierStoreToPriceGroupMap() : [];
	$priceGroupId = ($usePrice && $storeId > 0) ? (int)($storeToGroup[$storeId] ?? 0) : 0;
	if ($usePrice && $priceGroupId <= 0)
	{
		if ($dry)
		{
			out("PRICE_GROUP_ID: отсутствует (dry-run). При --apply будет создан тип цены под XML_ID склада поставщика.");
		}
		else
		{
			throw new RuntimeException("Не удалось определить тип цены для склада поставщика (storeId=$storeId)");
		}
	}
	if ($usePrice && $priceGroupId > 0)
	{
		out("PRICE_GROUP_ID(for supplier): $priceGroupId");
	}

	$h = fopen($file, 'r');
	if (!$h) throw new RuntimeException("Не удалось открыть CSV");
	$fileSize = (int)@filesize($file);

	$headers = fgetcsv($h, 0, ';');
	if (!$headers) throw new RuntimeException("Пустой CSV");
	$headers = array_map(static fn($v) => mb_convert_encoding((string)$v, 'UTF-8', $encoding), $headers);

	$idxArt = null;
	$idxQty = null;
	$idxPrice = null;
	foreach ($headers as $i => $hdr)
	{
		$h2 = mb_strtolower(trim($hdr));
		if (in_array($h2, ['артикул', 'article', 'sku'], true)) $idxArt = $i;
		if (in_array($h2, ['остаток', 'количество', 'кол-во', 'qty', 'stock'], true)) $idxQty = $i;
		if (in_array($h2, ['цена', 'price', 'стоимость'], true)) $idxPrice = $i;
	}
	if ($idxArt === null || $idxQty === null)
	{
		throw new RuntimeException("Не найдены колонки Артикул/Остаток");
	}

	$total = 0;
	$updated = 0;
	$notFound = 0;
	$errors = 0;
	$progressEvery = 200;
	$lastProgressAt = microtime(true);

	while (($row = fgetcsv($h, 0, ';')) !== false)
	{
		$total++;
		$row = array_map(static fn($v) => mb_convert_encoding((string)$v, 'UTF-8', $encoding), $row);
		$artRaw = trim((string)($row[$idxArt] ?? ''));
		if ($artRaw === '') continue;

		$norm = normalizeArticle($artRaw);
		if ($norm === '') continue;

		$qty = (float)str_replace(',', '.', (string)($row[$idxQty] ?? '0'));
		if ($qty < 0) $qty = 0;

		$price = null;
		if ($usePrice && $idxPrice !== null)
		{
			$price = (float)str_replace(',', '.', (string)($row[$idxPrice] ?? '0'));
		}

		$productId = findCanonicalProductIdByNorm($iblockId, $norm);
		if (!$productId)
		{
			$notFound++;
			continue;
		}

		if ($dry)
		{
			$updated++;
			continue;
		}

		try
		{
			upsertStoreAmount($productId, $storeId, $qty);
			$sum = sumAllStoresAmount($productId);
			CCatalogProduct::Update($productId, [
				'QUANTITY' => $sum,
				'AVAILABLE' => ($sum > 0) ? 'Y' : 'N',
				'QUANTITY_TRACE' => 'Y',
				'CAN_BUY_ZERO' => 'N',
			]);
			if ($price !== null && $price > 0 && $usePrice)
			{
				upsertPrice($productId, $priceGroupId, $price);
				if ($recalcBase)
				{
					$min = recalcBasePriceFromAvailableStores($productId, $storeToGroup);
					if ($min !== null)
					{
						setBasePrice($productId, $min);
					}
				}
			}
			$updated++;
		}
		catch (Throwable $e)
		{
			$errors++;
		}

		$now = microtime(true);
		if (($total % $progressEvery) === 0 || ($now - $lastProgressAt) >= 0.5)
		{
			$pct = 0.0;
			if ($fileSize > 0)
			{
				$pct = mf_pct((float)ftell($h), (float)$fileSize);
			}
			mf_progress('SUPPLIER', $pct, "rows=$total updated=$updated notFound=$notFound errors=$errors");
			$lastProgressAt = $now;
		}
	}
	fclose($h);
	mf_progressDone();

	out("DONE total=$total updated=$updated notFound=$notFound errors=$errors");
}
catch (Throwable $e)
{
	out("ОШИБКА: " . $e->getMessage());
	fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
	exit(1);
}

