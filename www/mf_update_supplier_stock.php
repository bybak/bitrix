<?php
/**
 * Обновление остатков (и опционально цены) от поставщика в отдельный склад Bitrix.
 *
 * Запуск (внутри контейнера bitrix_php):
 *   php /var/www/html/mf_update_supplier_stock.php --dry-run --warehouse-code=YAMAHA --file=/var/www/html/supplier_stock.csv
 *   php /var/www/html/mf_update_supplier_stock.php --apply   --warehouse-code=YAMAHA --warehouse-title="Yamaha (поставщик)" --file=/var/www/html/supplier_stock.csv
 *
 * Back-compat:
 *   --supplier=SupplierA (используется как warehouse-code и дефолтное имя)
 *
 * CSV формат (рекомендуется):
 *   Бренд;Артикул;Остаток;Цена
 *
 * Важно про цену:
 * - Для каждого поставщика создаётся свой тип цены (CATALOG_GROUP) и обновляется туда.
 * - BASE цена (которая показывается на сайте, т.к. компоненты настроены на PRICE_CODE=BASE)
 *   пересчитывается как минимальная цена среди поставщиков, у которых есть остаток > 0.
 *
 * Опции:
 *   --iblock-id=4
 *   --warehouse-code=UniqueCode
 *   --warehouse-title=Human name (optional; потом можно отредактировать в админке)
 *   --supplier=NameOrCode (deprecated)
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
use Bitrix\Main\Type\DateTime;
use Bitrix\Highloadblock\HighloadBlockTable;

Loader::includeModule("iblock");
Loader::includeModule("catalog");
Loader::includeModule("highloadblock");

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

function normalizeBrand(string $s): string
{
	$s = mb_strtoupper(trim($s));
	$s = str_replace('Ё', 'Е', $s);
	$s = preg_replace('~[^A-ZА-Я0-9]+~u', '', $s) ?? '';
	return $s;
}

function makeUniqKey(string $articleNorm, string $brandNorm): string
{
	$articleNorm = trim($articleNorm);
	$brandNorm = trim($brandNorm);
	if ($brandNorm === '') $brandNorm = 'UNKNOWNBRAND';
	return $articleNorm . '_' . $brandNorm;
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

function findStoreRowByCodeOrXml(string $code): ?array
{
	$code = trim($code);
	if ($code === '') return null;

	// Try CODE first (that's what admin "Символьный код" sets).
	$row = StoreTable::getList([
		'filter' => ['=CODE' => $code],
		'select' => ['ID', 'CODE', 'XML_ID', 'TITLE', 'ADDRESS'],
		'limit' => 1,
	])->fetch();
	if ($row) return $row;

	// Fallback: some stores may have XML_ID filled but CODE empty.
	$row = StoreTable::getList([
		'filter' => ['=XML_ID' => $code],
		'select' => ['ID', 'CODE', 'XML_ID', 'TITLE', 'ADDRESS'],
		'limit' => 1,
	])->fetch();
	return $row ?: null;
}

function getStoreMarkupPct(int $storeId): float
{
	static $cache = [];

	$storeId = (int)$storeId;
	if ($storeId <= 0) return 0.0;
	if (array_key_exists($storeId, $cache)) return (float)$cache[$storeId];

	$pct = 0.0;
	global $USER_FIELD_MANAGER;
	if (is_object($USER_FIELD_MANAGER))
	{
		$ufs = $USER_FIELD_MANAGER->GetUserFields(StoreTable::getUfId(), $storeId);
		$v = $ufs['UF_MF_MARKUP_PCT']['VALUE'] ?? 0;
		if (is_array($v)) $v = reset($v);
		$v = (string)$v;
		$v = str_replace(',', '.', $v);
		$pct = (float)$v;
		if (!is_finite($pct)) $pct = 0.0;
	}

	$cache[$storeId] = $pct;
	return $pct;
}

function applyMarkup(float $price, float $pct): float
{
	if ($price <= 0) return $price;
	if ($pct == 0.0) return $price;
	return round($price * (1.0 + ($pct / 100.0)), 2);
}

function sanitizeStoreCode(string $s): string
{
	$s = mb_strtoupper(trim($s));
	$s = preg_replace('~[^A-Z0-9_]+~', '_', $s) ?? $s;
	$s = preg_replace('~_+~', '_', (string)$s) ?? $s;
	$s = trim((string)$s, '_');
	return $s !== '' ? $s : 'WAREHOUSE';
}

function getOrCreateStoreByCode(string $warehouseCode, string $warehouseTitle, bool $create): array
{
	$warehouseCode = trim($warehouseCode);
	$code = sanitizeStoreCode($warehouseCode);
	$xmlId = 'SUPPLIER_' . $code;

	$existingId = findStoreIdByXmlId($xmlId);
	if ($existingId) return [$existingId, $xmlId];

	// If store already exists in admin with CODE=$code or XML_ID=$code, reuse it.
	$existing = findStoreRowByCodeOrXml($code);
	if ($existing)
	{
		$storeId = (int)$existing['ID'];
		$currentXmlId = trim((string)($existing['XML_ID'] ?? ''));
		if ($storeId > 0 && $create && $currentXmlId !== $xmlId)
		{
			// Normalize mapping key so imports are stable.
			$upd = StoreTable::update($storeId, ['XML_ID' => $xmlId]);
			if (!$upd->isSuccess())
			{
				throw new RuntimeException("Не удалось обновить XML_ID склада (ID=$storeId) на '$xmlId': " . implode('; ', $upd->getErrorMessages()));
			}
		}
		return [$storeId, $xmlId];
	}

	if (!$create)
	{
		return [0, $xmlId];
	}

	$title = trim($warehouseTitle) !== '' ? trim($warehouseTitle) : $warehouseCode;
	$res = StoreTable::add([
		'TITLE' => $title,
		'ACTIVE' => 'Y',
		'ADDRESS' => 'Склад импорта: ' . ($title !== '' ? $title : $warehouseCode),
		'DESCRIPTION' => 'Автосозданный склад для импорта остатков (' . $warehouseCode . ')',
		'XML_ID' => $xmlId,
		'CODE' => $code,
	]);
	if (!$res->isSuccess())
	{
		throw new RuntimeException("Не удалось создать склад '$warehouseCode': " . implode('; ', $res->getErrorMessages()));
	}
	return [(int)$res->getId(), $xmlId];
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

function findCanonicalProductIdByArticleBrand(int $iblockId, string $articleNorm, string $brandRaw, string $brandNorm): ?int
{
	$articleNorm = trim($articleNorm);
	$brandRaw = trim($brandRaw);
	$brandNorm = trim($brandNorm);
	if ($articleNorm === '') return null;

	$filter = [
		'IBLOCK_ID' => $iblockId,
		'=PROPERTY_MF_ARTICLE_NORM' => $articleNorm,
		'!PROPERTY_MF_IS_REDIRECT' => 'Y',
	];

	// If brand is provided in CSV, do NOT fallback to article-only.
	$brandProvided = ($brandNorm !== '' || $brandRaw !== '');

	// 1) Try brand_norm contains brandNorm (handles existing "грязные" brand_norm that still include YAMAHA...)
	if ($brandNorm !== '')
	{
		$r = CIBlockElement::GetList([], $filter + ['%PROPERTY_MF_BRAND_NORM' => $brandNorm], false, false, ['ID'])->Fetch();
		if ($r) return (int)$r['ID'];
	}

	// 2) Try brand text contains raw brand (handles MF_BRAND like "Yamaha ...")
	if ($brandRaw !== '')
	{
		$r = CIBlockElement::GetList([], $filter + ['%PROPERTY_MF_BRAND' => $brandRaw], false, false, ['ID'])->Fetch();
		if ($r) return (int)$r['ID'];
	}

	if ($brandProvided)
	{
		return null;
	}

	// No brand column → legacy behavior: by article only
	return findCanonicalProductIdByNorm($iblockId, $articleNorm);
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
		$raw = (float)($prices[$gid] ?? 0);
		if ($raw <= 0) continue;

		// IMPORTANT: store price group keeps RAW import price.
		// Retail price used for BASE is computed as raw + store markup.
		$markupPct = getStoreMarkupPct($storeId);
		$computed = applyMarkup($raw, $markupPct);
		if ($computed <= 0) continue;
		if ($min === null || $computed < $min) $min = $computed;
	}

	return $min;
}

function ensureMissingHl(bool $create): ?array
{
	// Highload-block for "ненайденные товары" (для дальнейшей обработки в админке).
	$table = 'mf_stock_import_missing';
	$hl = HighloadBlockTable::getList([
		'filter' => ['=TABLE_NAME' => $table],
		'select' => ['ID', 'NAME', 'TABLE_NAME'],
		'limit' => 1,
	])->fetch();

	if (!$hl && !$create) return null;

	if (!$hl)
	{
		$res = HighloadBlockTable::add([
			'NAME' => 'MfStockImportMissing',
			'TABLE_NAME' => $table,
		]);
		if (!$res->isSuccess())
		{
			throw new RuntimeException("Не удалось создать HL-блок для ненайденных товаров: " . implode('; ', $res->getErrorMessages()));
		}
		$hl = ['ID' => (int)$res->getId(), 'NAME' => 'MfStockImportMissing', 'TABLE_NAME' => $table];
	}

	$hlId = (int)$hl['ID'];
	$entityId = 'HLBLOCK_' . $hlId;
	$ut = new CUserTypeEntity();

	$ensureUf = static function (string $fieldName, string $userTypeId, array $labels, int $sort) use ($ut, $entityId): void {
		$exists = CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId, 'FIELD_NAME' => $fieldName])->Fetch();
		if ($exists) return;
		$id = $ut->Add([
			'ENTITY_ID' => $entityId,
			'FIELD_NAME' => $fieldName,
			'USER_TYPE_ID' => $userTypeId,
			'XML_ID' => $fieldName,
			'SORT' => $sort,
			'MULTIPLE' => 'N',
			'MANDATORY' => 'N',
			'SHOW_FILTER' => 'I',
			'SHOW_IN_LIST' => 'Y',
			'EDIT_IN_LIST' => 'Y',
			'IS_SEARCHABLE' => 'Y',
			'EDIT_FORM_LABEL' => $labels,
			'LIST_COLUMN_LABEL' => $labels,
			'LIST_FILTER_LABEL' => $labels,
		]);
		if (!$id)
		{
			throw new RuntimeException("Не удалось создать UF поле $fieldName для HL-блока (ненайденные товары): " . $ut->LAST_ERROR);
		}
	};

	$ensureUf('UF_WAREHOUSE_XML_ID', 'string', ['ru' => 'XML_ID склада', 'en' => 'Warehouse XML_ID'], 100);
	$ensureUf('UF_WAREHOUSE_TITLE', 'string', ['ru' => 'Название склада', 'en' => 'Warehouse title'], 110);
	$ensureUf('UF_UNIQ_KEY', 'string', ['ru' => 'Ключ (бренд+артикул)', 'en' => 'Key (brand+article)'], 120);
	$ensureUf('UF_BRAND', 'string', ['ru' => 'Бренд', 'en' => 'Brand'], 130);
	$ensureUf('UF_ARTICLE', 'string', ['ru' => 'Артикул', 'en' => 'Article'], 140);
	$ensureUf('UF_QTY', 'double', ['ru' => 'Остаток', 'en' => 'Stock'], 150);
	$ensureUf('UF_PRICE', 'double', ['ru' => 'Цена', 'en' => 'Price'], 160);
	$ensureUf('UF_LAST_SEEN', 'datetime', ['ru' => 'Последнее появление', 'en' => 'Last seen'], 170);
	$ensureUf('UF_FIRST_SEEN', 'datetime', ['ru' => 'Первое появление', 'en' => 'First seen'], 180);

	$entity = HighloadBlockTable::compileEntity($hl);
	return ['DATA_CLASS' => $entity->getDataClass()];
}

function upsertMissing(?array $hl, string $warehouseXmlId, string $warehouseTitle, string $uniqKey, string $brand, string $article, float $qty, float $price): void
{
	if (!$hl) return;
	$dataClass = $hl['DATA_CLASS'];
	$now = new DateTime();

	$filter = ['=UF_WAREHOUSE_XML_ID' => $warehouseXmlId, '=UF_UNIQ_KEY' => $uniqKey];
	$existing = $dataClass::getList(['filter' => $filter, 'select' => ['ID', 'UF_FIRST_SEEN'], 'limit' => 1])->fetch();

	$fields = [
		'UF_WAREHOUSE_XML_ID' => $warehouseXmlId,
		'UF_WAREHOUSE_TITLE' => $warehouseTitle,
		'UF_UNIQ_KEY' => $uniqKey,
		'UF_BRAND' => $brand,
		'UF_ARTICLE' => $article,
		'UF_QTY' => $qty,
		'UF_PRICE' => $price,
		'UF_LAST_SEEN' => $now,
	];

	if ($existing)
	{
		$dataClass::update((int)$existing['ID'], $fields);
	}
	else
	{
		$fields['UF_FIRST_SEEN'] = $now;
		$dataClass::add($fields);
	}
}

function deleteMissing(?array $hl, string $warehouseXmlId, string $uniqKey): void
{
	if (!$hl) return;
	$dataClass = $hl['DATA_CLASS'];
	$existing = $dataClass::getList([
		'filter' => ['=UF_WAREHOUSE_XML_ID' => $warehouseXmlId, '=UF_UNIQ_KEY' => $uniqKey],
		'select' => ['ID'],
		'limit' => 1,
	])->fetch();
	if ($existing)
	{
		$dataClass::delete((int)$existing['ID']);
	}
}

try
{
	$iblockId = (int)(arg('--iblock-id') ?: 4);
	$warehouseCode = arg('--warehouse-code') ?: '';
	$warehouseTitle = arg('--warehouse-title') ?: '';
	$supplier = arg('--supplier') ?: '';
	$file = arg('--file') ?: '';
	$encoding = arg('--encoding') ?: 'cp1251';
	$apply = flag('--apply');
	$dry = flag('--dry-run') || !$apply;
	$usePrice = (arg('--price') ?: 'N') === 'Y';
	$recalcBase = (arg('--recalc-base') ?: 'Y') === 'Y';
	$syncMissing = (arg('--sync-missing') ?: 'Y') === 'Y';

	if ($warehouseCode === '' && $supplier !== '')
	{
		$warehouseCode = $supplier;
		if ($warehouseTitle === '') $warehouseTitle = $supplier;
	}
	if ($warehouseCode === '') throw new RuntimeException("Укажи --warehouse-code=CODE (или --supplier=NAME)");
	if ($file === '') throw new RuntimeException("Укажи --file=/path/file.csv");
	if (!file_exists($file)) throw new RuntimeException("Файл не найден: $file");

	out("=== MF SUPPLIER STOCK UPDATE ===");
	out("IBLOCK_ID: $iblockId");
	out("WAREHOUSE_CODE: $warehouseCode");
	if ($warehouseTitle !== '') out("WAREHOUSE_TITLE: $warehouseTitle");
	out("FILE: $file");
	out("ENCODING: $encoding");
	out("MODE: " . ($apply ? 'APPLY' : 'DRY-RUN'));
	out("PRICE_UPDATE: " . ($usePrice ? 'Y' : 'N'));
	out("RECALC_BASE: " . (($usePrice && $recalcBase) ? 'Y' : 'N'));
	out("SYNC_MISSING: " . ($syncMissing ? 'Y' : 'N'));

	[$storeId, $storeXmlId] = getOrCreateStoreByCode($warehouseCode, $warehouseTitle, $apply);
	if ($storeId <= 0)
	{
		out("STORE_ID: отсутствует (dry-run). При --apply будет создан склад SUPPLIER_... по warehouse-code.");
	}
	else
	{
		out("STORE_ID: $storeId");
		out("STORE_XML_ID: $storeXmlId");
	}

	$storeMarkupPct = ($storeId > 0) ? getStoreMarkupPct($storeId) : 0.0;
	if ($usePrice)
	{
		out("STORE_MARKUP_PCT: " . $storeMarkupPct);
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

	$idxBrand = null;
	$idxArt = null;
	$idxQty = null;
	$idxPrice = null;
	foreach ($headers as $i => $hdr)
	{
		$h2 = mb_strtolower(trim($hdr));
		if (in_array($h2, ['бренд', 'brand', 'производитель', 'manufacturer', 'vendor'], true)) $idxBrand = $i;
		if (in_array($h2, ['артикул', 'article', 'sku'], true)) $idxArt = $i;
		if (in_array($h2, ['остаток', 'количество', 'кол-во', 'qty', 'stock'], true)) $idxQty = $i;
		if (in_array($h2, ['цена', 'price', 'стоимость'], true)) $idxPrice = $i;
	}
	if ($idxArt === null || $idxQty === null)
	{
		throw new RuntimeException("Не найдены колонки Артикул/Остаток");
	}

	$missingHl = ensureMissingHl($apply);
	$total = 0;
	$updated = 0;
	$notFound = 0;
	$errors = 0;
	$zeroedMissing = 0;
	$seenProducts = [];
	$progressEvery = 200;
	$lastProgressAt = microtime(true);

	while (($row = fgetcsv($h, 0, ';')) !== false)
	{
		$total++;
		$row = array_map(static fn($v) => mb_convert_encoding((string)$v, 'UTF-8', $encoding), $row);
		$brandRaw = $idxBrand !== null ? trim((string)($row[$idxBrand] ?? '')) : '';
		// allow comments in demo CSV
		if ($brandRaw !== '' && str_starts_with($brandRaw, '#')) continue;
		$artRaw = trim((string)($row[$idxArt] ?? ''));
		if ($artRaw !== '' && str_starts_with($artRaw, '#')) continue;
		if ($artRaw === '') continue;

		$articleNorm = normalizeArticle($artRaw);
		if ($articleNorm === '') continue;
		$brandNorm = $brandRaw !== '' ? normalizeBrand($brandRaw) : '';
		$uniqKey = makeUniqKey($articleNorm, $brandNorm);

		$qty = (float)str_replace(',', '.', (string)($row[$idxQty] ?? '0'));
		if ($qty < 0) $qty = 0;

		$priceRaw = null;
		if ($usePrice && $idxPrice !== null)
		{
			$priceRaw = (float)str_replace(',', '.', (string)($row[$idxPrice] ?? '0'));
		}
		// IMPORTANT: store price group keeps RAW import price (no markup here).
		$price = ($priceRaw !== null) ? (float)$priceRaw : null;

		$productId = findCanonicalProductIdByArticleBrand($iblockId, $articleNorm, $brandRaw, $brandNorm);
		if (!$productId)
		{
			$notFound++;
			if (!$dry && $storeXmlId !== '')
			{
				upsertMissing(
					$missingHl,
					$storeXmlId,
					($warehouseTitle !== '' ? $warehouseTitle : $warehouseCode),
					$uniqKey,
					$brandRaw,
					$artRaw,
					$qty,
					($priceRaw !== null ? (float)$priceRaw : 0.0)
				);
			}
			continue;
		}

		$seenProducts[$productId] = true;

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
			if ($storeXmlId !== '')
			{
				deleteMissing($missingHl, $storeXmlId, $uniqKey);
			}
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

	// If a product is missing in the supplier file, treat it as out of stock on this warehouse.
	// This prevents stale quantities from keeping items "in stock".
	if ($apply && !$dry && $syncMissing && $storeId > 0)
	{
		$rsStale = CCatalogStoreProduct::GetList(
			[],
			['STORE_ID' => $storeId, '>AMOUNT' => 0],
			false,
			false,
			['ID', 'PRODUCT_ID', 'AMOUNT']
		);
		while ($sp = $rsStale->Fetch())
		{
			$pid = (int)$sp['PRODUCT_ID'];
			if ($pid <= 0) continue;
			if (isset($seenProducts[$pid])) continue;

			try
			{
				CCatalogStoreProduct::Update((int)$sp['ID'], ['AMOUNT' => 0]);
				$sum = sumAllStoresAmount($pid);
				CCatalogProduct::Update($pid, [
					'QUANTITY' => $sum,
					'AVAILABLE' => ($sum > 0) ? 'Y' : 'N',
					'QUANTITY_TRACE' => 'Y',
					'CAN_BUY_ZERO' => 'N',
				]);
				$zeroedMissing++;
			}
			catch (Throwable $e)
			{
				$errors++;
			}
		}
	}

	out("DONE total=$total updated=$updated notFound=$notFound zeroedMissing=$zeroedMissing errors=$errors");
}
catch (Throwable $e)
{
	out("ОШИБКА: " . $e->getMessage());
	fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
	exit(1);
}

