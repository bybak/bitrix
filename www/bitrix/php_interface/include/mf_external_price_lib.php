<?php

/**
 * Внешние прайсы: курсы ЦБ (кэш), матчинг товара, пересчёт BASE, обнуление, наценка по весу.
 */

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

if (!function_exists('mf_ep_invalidate_catalog_price_group_cache'))
{
	/**
	 * После создания типа цены (CCatalogGroup::Add) кэш GroupTable::getList (TTL сутки) и статический
	 * кэш Catalog\Model\Price могут не содержать новый CATALOG_GROUP_ID — CPrice::Add тогда возвращает false.
	 */
	function mf_ep_invalidate_catalog_price_group_cache(): void
	{
		if (class_exists(\Bitrix\Catalog\Model\Price::class) && method_exists(\Bitrix\Catalog\Model\Price::class, 'clearSettings'))
		{
			\Bitrix\Catalog\Model\Price::clearSettings();
		}
		if (class_exists(\Bitrix\Catalog\GroupTable::class))
		{
			\Bitrix\Catalog\GroupTable::cleanCache();
		}
	}
}

if (!function_exists('mf_ep_norm_article'))
{
	function mf_ep_norm_article(string $s): string
	{
		$s = mb_strtoupper(trim($s));
		$s = preg_replace('~[^A-Z0-9]+~', '', $s) ?? '';

		return $s;
	}
}

if (!function_exists('mf_ep_norm_brand'))
{
	function mf_ep_norm_brand(string $s): string
	{
		$s = mb_strtoupper(trim($s));
		$s = str_replace('Ё', 'Е', $s);
		$s = preg_replace('~[^A-ZА-Я0-9]+~u', '', $s) ?? '';

		return $s;
	}
}

if (!function_exists('mf_ep_make_uniq_key'))
{
	function mf_ep_make_uniq_key(string $articleNorm, string $brandNorm): string
	{
		$articleNorm = trim($articleNorm);
		$brandNorm = trim($brandNorm);
		if ($brandNorm === '')
		{
			$brandNorm = 'UNKNOWNBRAND';
		}

		return $articleNorm . '_' . $brandNorm;
	}
}

if (!function_exists('mf_ep_find_product'))
{
	/**
	 * Поиск товара каталога (как mf_update_supplier_stock / findCanonicalProductIdByArticleBrand).
	 */
	function mf_ep_find_product(int $iblockId, string $articleNorm, string $brandRaw, string $brandNorm): ?int
	{
		$articleNorm = trim($articleNorm);
		$brandRaw = trim($brandRaw);
		$brandNorm = trim($brandNorm);
		if ($articleNorm === '' || !class_exists(\CIBlockElement::class))
		{
			return null;
		}

		$filter = [
			'IBLOCK_ID' => $iblockId,
			'=PROPERTY_MF_ARTICLE_NORM' => $articleNorm,
			'!PROPERTY_MF_IS_REDIRECT' => 'Y',
		];

		$brandProvided = ($brandNorm !== '' || $brandRaw !== '');

		if ($brandProvided && $brandNorm !== '')
		{
			$uniqKey = mf_ep_make_uniq_key($articleNorm, $brandNorm);
			$r = \CIBlockElement::GetList(
				[],
				$filter + ['=PROPERTY_MF_UNIQ_KEY' => $uniqKey],
				false,
				['nTopCount' => 1],
				['ID']
			)->Fetch();
			if ($r && (int)$r['ID'] > 0)
			{
				return (int)$r['ID'];
			}
		}

		if ($brandNorm !== '')
		{
			$r = \CIBlockElement::GetList(
				[],
				$filter + ['%PROPERTY_MF_BRAND_NORM' => $brandNorm],
				false,
				['nTopCount' => 1],
				['ID']
			)->Fetch();
			if ($r && (int)$r['ID'] > 0)
			{
				return (int)$r['ID'];
			}
		}

		if ($brandRaw !== '')
		{
			$r = \CIBlockElement::GetList(
				[],
				$filter + ['%PROPERTY_MF_BRAND' => $brandRaw],
				false,
				['nTopCount' => 1],
				['ID']
			)->Fetch();
			if ($r && (int)$r['ID'] > 0)
			{
				return (int)$r['ID'];
			}
		}

		if ($brandProvided)
		{
			return null;
		}

		$r = \CIBlockElement::GetList(
			[],
			$filter,
			false,
			['nTopCount' => 1],
			['ID']
		)->Fetch();

		return ($r && (int)$r['ID'] > 0) ? (int)$r['ID'] : null;
	}
}

if (!function_exists('mf_ep_generate_unique_element_code'))
{
	/**
	 * Уникальный CODE элемента каталога (транслит + суффикс при коллизии).
	 */
	function mf_ep_generate_unique_element_code(int $iblockId, string $base): string
	{
		$base = trim($base);
		if ($base === '')
		{
			$base = 'item';
		}

		if (class_exists(\CUtil::class))
		{
			$code = (string)\CUtil::translit($base, 'ru', [
				'change_case' => 'L',
				'replace_space' => '-',
				'replace_other' => '-',
				'delete_repeat_replace' => true,
				'use_google' => false,
			]);
		}
		else
		{
			$code = strtolower(preg_replace('~[^a-z0-9]+~i', '-', $base) ?? $base);
		}

		$code = trim((string)(preg_replace('~[^a-z0-9\-]+~', '', $code) ?? ''), '-');
		if ($code === '')
		{
			$code = 'item';
		}

		$try = $code;
		$i = 1;
		while (true)
		{
			$exists = \CIBlockElement::GetList(
				[],
				['=IBLOCK_ID' => $iblockId, '=CODE' => $try],
				false,
				['nTopCount' => 1],
				['ID']
			)->Fetch();
			if (!$exists)
			{
				return $try;
			}
			$i++;
			$try = $code . '-' . $i;
			if ($i > 50)
			{
				return $code . '-' . time();
			}
		}
	}
}

if (!function_exists('mf_ep_create_product_from_external_price'))
{
	/**
	 * Создаёт карточку товара при отсутствии совпадения в каталоге (импорт внешнего прайса).
	 *
	 * @return int|null ID нового элемента или null при ошибке
	 */
	function mf_ep_create_product_from_external_price(
		int $iblockId,
		string $articleHuman,
		string $articleNorm,
		string $brandRaw,
		string $brandNorm,
		string $displayName = ''
	): ?int
	{
		if (!class_exists(Loader::class) || !Loader::includeModule('iblock') || !Loader::includeModule('catalog')
			|| !class_exists(\CIBlockElement::class))
		{
			return null;
		}

		$articleNorm = trim($articleNorm);
		if ($articleNorm === '')
		{
			return null;
		}

		$brandNorm = trim($brandNorm);
		if ($brandNorm === '')
		{
			$brandNorm = 'UNKNOWNBRAND';
		}

		$uniqKey = mf_ep_make_uniq_key($articleNorm, $brandNorm);

		$dup = \CIBlockElement::GetList(
			[],
			[
				'IBLOCK_ID' => $iblockId,
				'=PROPERTY_MF_UNIQ_KEY' => $uniqKey,
			],
			false,
			['nTopCount' => 1],
			['ID']
		)->Fetch();
		if ($dup && (int)$dup['ID'] > 0)
		{
			return (int)$dup['ID'];
		}

		$second = function_exists('mf_ep_find_product') ? mf_ep_find_product($iblockId, $articleNorm, $brandRaw, $brandNorm) : null;
		if ($second !== null && $second > 0)
		{
			return (int)$second;
		}

		$name = trim($displayName);
		if ($name === '')
		{
			$name = trim($brandRaw . ' ' . $articleHuman);
		}
		if ($name === '')
		{
			$name = $uniqKey;
		}

		$codeBase = $articleNorm . '-' . $brandNorm;
		if (mb_strlen($codeBase) > 96)
		{
			$codeBase = mb_substr($codeBase, 0, 96);
		}
		$code = mf_ep_generate_unique_element_code($iblockId, $codeBase);

		$el = new \CIBlockElement();
		$newId = (int)$el->Add(
			[
				'IBLOCK_ID' => $iblockId,
				'NAME' => $name,
				'CODE' => $code,
				'XML_ID' => 'mf_ext_price:' . $uniqKey,
				'ACTIVE' => 'Y',
				'PROPERTY_VALUES' => [
					'CML2_ARTICLE' => $articleHuman,
					'MF_ARTICLE_NORM' => $articleNorm,
					'MF_BRAND' => $brandRaw,
					'MF_BRAND_NORM' => $brandNorm,
					'MF_UNIQ_KEY' => $uniqKey,
					'MF_IS_REDIRECT' => 'N',
					'MF_CANONICAL_CODE' => '',
					'MF_SOURCE_IDS' => 'external_price_import',
				],
			],
			false,
			false
		);

		if ($newId <= 0)
		{
			return null;
		}

		\CIBlockElement::SetPropertyValuesEx(
			$newId,
			$iblockId,
			['MF_SHOW_IN_CATALOG' => 'Y']
		);

		if (class_exists(\CCatalogProduct::class))
		{
			\CCatalogProduct::Add([
				'ID' => $newId,
				'QUANTITY' => 0,
				'QUANTITY_TRACE' => 'N',
				'CAN_BUY_ZERO' => 'Y',
				'AVAILABLE' => 'N',
			]);
		}

		try
		{
			if (Loader::includeModule('search'))
			{
				\CIBlockElement::UpdateSearch($newId, true);
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}

		return $newId;
	}
}

if (!function_exists('mf_ep_get_or_create_price_group'))
{
	function mf_ep_get_or_create_price_group(string $storeXmlId, string $titleFallback, bool $create): int
	{
		$name = mb_strtoupper(trim($storeXmlId));
		if ($name === '')
		{
			return 0;
		}

		if (function_exists('mf_catalog_group_id_by_store_xml_candidates'))
		{
			$found = mf_catalog_group_id_by_store_xml_candidates($storeXmlId);
			if ($found > 0)
			{
				return $found;
			}
		}
		else
		{
			$rs = \CCatalogGroup::GetList([], ['=NAME' => $name], false, false, ['ID', 'NAME']);
			if ($r = $rs->Fetch())
			{
				return (int)$r['ID'];
			}
		}

		if (!$create)
		{
			return 0;
		}

		$cg = new \CCatalogGroup();
		$id = $cg->Add([
			'NAME' => $name,
			'BASE' => 'N',
			'SORT' => 2000,
			'USER_GROUP' => [2],
			'USER_GROUP_BUY' => [2],
			'LANG' => [
				'ru' => ['NAME' => $titleFallback],
				'en' => ['NAME' => $titleFallback],
			],
		]);
		if (!$id)
		{
			return 0;
		}

		mf_ep_invalidate_catalog_price_group_cache();

		return (int)$id;
	}
}

if (!function_exists('mf_ep_resolve_catalog_trade_product_id'))
{
	/**
	 * Для товара с торговыми предложениями (TYPE_SKU) цены и остатки в продаже ведутся на SKU (оффер),
	 * а mf_ep_find_product возвращает элемент инфоблока — часто родителя. Пишем RAW на первый оффер.
	 */
	function mf_ep_resolve_catalog_trade_product_id(int $elementId): int
	{
		$elementId = (int)$elementId;
		if ($elementId <= 0)
		{
			return 0;
		}
		if (!class_exists(Loader::class) || !Loader::includeModule('catalog') || !class_exists(\Bitrix\Catalog\ProductTable::class))
		{
			return $elementId;
		}

		$row = \Bitrix\Catalog\ProductTable::getRow([
			'filter' => ['=ID' => $elementId],
			'select' => ['TYPE'],
		]);
		if (!$row)
		{
			return $elementId;
		}

		if ((int)($row['TYPE'] ?? 0) !== \Bitrix\Catalog\ProductTable::TYPE_SKU)
		{
			return $elementId;
		}

		if (!class_exists(\CCatalogSKU::class))
		{
			return $elementId;
		}

		if (class_exists(Loader::class))
		{
			Loader::includeModule('iblock');
		}

		$iblockId = (int)\CIBlockElement::GetIBlockByID($elementId);
		if ($iblockId <= 0)
		{
			return $elementId;
		}

		$list = \CCatalogSKU::getOffersList($elementId, $iblockId, [], [], [], [], ['ID' => 'ASC']);
		if (empty($list[$elementId]) || !is_array($list[$elementId]))
		{
			return $elementId;
		}

		$first = reset($list[$elementId]);
		$oid = (int)($first['ID'] ?? 0);

		return $oid > 0 ? $oid : $elementId;
	}
}

if (!function_exists('mf_ep_normalize_catalog_currency'))
{
	/**
	 * Код валюты для b_catalog_price (как в модуле «Валюты» Bitrix).
	 */
	function mf_ep_normalize_catalog_currency(string $currency): string
	{
		$c = mb_strtoupper(trim($currency));
		if ($c === 'RUR')
		{
			$c = 'RUB';
		}

		return $c !== '' ? $c : 'RUB';
	}
}

if (!function_exists('mf_ep_set_raw_price'))
{
	/**
	 * Сохраняет закупочную цену в типе цены: сумма и валюта как в прайсе (пересчёт в ₽ — при показе, mf_raw_store_price).
	 *
	 * @return bool true, если запись/обновление/удаление прошли без ошибки API
	 */
	function mf_ep_set_raw_price(int $productId, int $priceGroupId, float $price, string $currency = 'RUB'): bool
	{
		$productId = (int)$productId;
		$priceGroupId = (int)$priceGroupId;
		if ($productId <= 0 || $priceGroupId <= 0)
		{
			return false;
		}

		$ccy = mf_ep_normalize_catalog_currency($currency);

		$apply = static function (int $productId, int $priceGroupId, float $price, string $ccy): bool {
			if ($price <= 0)
			{
				if (class_exists(\Bitrix\Catalog\PriceTable::class))
				{
					$rs = \Bitrix\Catalog\PriceTable::getList([
						'filter' => ['=PRODUCT_ID' => $productId, '=CATALOG_GROUP_ID' => $priceGroupId],
						'select' => ['ID'],
					]);
					$ok = true;
					while ($row = $rs->fetch())
					{
						$del = \Bitrix\Catalog\PriceTable::delete((int)$row['ID']);
						if (!$del->isSuccess())
						{
							$ok = false;
						}
					}

					return $ok;
				}

				$rs = \CPrice::GetList(
					[],
					['PRODUCT_ID' => $productId, 'CATALOG_GROUP_ID' => $priceGroupId],
					false,
					false,
					['ID']
				);
				while ($p = $rs->Fetch())
				{
					\CPrice::Delete((int)$p['ID']);
				}

				return true;
			}

			$rs = \CPrice::GetList(
				[],
				['PRODUCT_ID' => $productId, 'CATALOG_GROUP_ID' => $priceGroupId],
				false,
				false,
				['ID']
			);
			if ($p = $rs->Fetch())
			{
				$res = \CPrice::Update((int)$p['ID'], ['PRICE' => $price, 'CURRENCY' => $ccy]);

				return $res !== false;
			}

			$addId = \CPrice::Add([
				'PRODUCT_ID' => $productId,
				'CATALOG_GROUP_ID' => $priceGroupId,
				'PRICE' => $price,
				'CURRENCY' => $ccy,
			]);

			return $addId !== false && (int)$addId > 0;
		};

		$ok = $apply($productId, $priceGroupId, $price, $ccy);
		if (!$ok && $price > 0)
		{
			mf_ep_invalidate_catalog_price_group_cache();
			$ok = $apply($productId, $priceGroupId, $price, $ccy);
		}

		return $ok;
	}
}

if (!function_exists('mf_ep_set_raw_price_for_catalog_cluster'))
{
	/**
	 * Записывает RAW в b_catalog_price (поля PRODUCT_ID, CATALOG_GROUP_ID, PRICE, CURRENCY) для всех
	 * связанных позиций каталога: элемент из mf_ep_find_product (карточка в URL) + офферы SKU + торговый ID.
	 * Иначе цена могла оказаться только на оффере, а остаток на родителе — mf_calc_store_price не находил RAW.
	 */
	/**
	 * @return int число неудачных mf_ep_set_raw_price по кластеру (для статистики импорта)
	 */
	function mf_ep_set_raw_price_for_catalog_cluster(int $foundElementId, int $priceGroupId, float $price, string $currency = 'RUB'): int
	{
		$foundElementId = (int)$foundElementId;
		$priceGroupId = (int)$priceGroupId;
		if ($foundElementId <= 0 || $priceGroupId <= 0)
		{
			return 0;
		}

		$ids = [$foundElementId];
		if (function_exists('mf_catalog_product_cluster_ids'))
		{
			$cluster = mf_catalog_product_cluster_ids($foundElementId);
			if (!empty($cluster))
			{
				$ids = $cluster;
			}
		}
		if (function_exists('mf_ep_resolve_catalog_trade_product_id'))
		{
			$trade = mf_ep_resolve_catalog_trade_product_id($foundElementId);
			if ($trade > 0)
			{
				$ids[] = $trade;
			}
		}

		$ids = array_values(array_unique(array_filter($ids, static fn($v) => (int)$v > 0)));
		$fail = 0;
		foreach ($ids as $productId)
		{
			if (!mf_ep_set_raw_price((int)$productId, $priceGroupId, $price, $currency))
			{
				$fail++;
			}
		}

		return $fail;
	}
}

if (!function_exists('mf_ep_set_weight_for_catalog_cluster'))
{
	/**
	 * Записывает WEIGHT (граммы) в b_catalog_product для родителя SKU, офферов и торгового ID — по той же схеме, что RAW-цены.
	 *
	 * @return int число неудачных CCatalogProduct::Update
	 */
	function mf_ep_set_weight_for_catalog_cluster(int $foundElementId, int $weightGrams): int
	{
		$foundElementId = (int)$foundElementId;
		$weightGrams = (int)$weightGrams;
		if ($foundElementId <= 0 || $weightGrams < 0)
		{
			return 0;
		}
		if (!class_exists(\CCatalogProduct::class))
		{
			return 1;
		}

		$ids = [$foundElementId];
		if (function_exists('mf_catalog_product_cluster_ids'))
		{
			$cluster = mf_catalog_product_cluster_ids($foundElementId);
			if (!empty($cluster))
			{
				$ids = $cluster;
			}
		}
		if (function_exists('mf_ep_resolve_catalog_trade_product_id'))
		{
			$trade = mf_ep_resolve_catalog_trade_product_id($foundElementId);
			if ($trade > 0)
			{
				$ids[] = $trade;
			}
		}

		$ids = array_values(array_unique(array_filter($ids, static fn($v) => (int)$v > 0)));
		$fail = 0;
		foreach ($ids as $productId)
		{
			if (!\CCatalogProduct::Update((int)$productId, ['WEIGHT' => $weightGrams]))
			{
				$fail++;
			}
		}

		return $fail;
	}
}

if (!function_exists('mf_ep_recalc_base_one'))
{
	/**
	 * BASE = минимум наценок по складам с остатком > 0 (как при импорте поставщика).
	 */
	function mf_ep_recalc_base_one(int $productId): void
	{
		$productId = (int)$productId;
		if ($productId <= 0 || !function_exists('mf_supplier_store_to_price_group'))
		{
			return;
		}

		$storeToGroup = mf_supplier_store_to_price_group();
		if (empty($storeToGroup))
		{
			return;
		}

		$min = null;

		$rsS = \CCatalogStoreProduct::GetList([], ['PRODUCT_ID' => $productId], false, false, ['STORE_ID', 'AMOUNT']);
		while ($sp = $rsS->Fetch())
		{
			$storeId = (int)$sp['STORE_ID'];
			$amount = (float)$sp['AMOUNT'];
			if ($amount <= 0)
			{
				continue;
			}
			if (!isset($storeToGroup[$storeId]))
			{
				continue;
			}
			if (!function_exists('mf_calc_store_price'))
			{
				continue;
			}
			$computed = mf_calc_store_price($productId, $storeId);
			if ($computed === null || $computed <= 0)
			{
				continue;
			}
			if ($min === null || $computed < $min)
			{
				$min = $computed;
			}
		}

		if ($min !== null && $min > 0)
		{
			\CPrice::SetBasePrice($productId, $min, 'RUB');
		}
	}
}

if (!function_exists('mf_ep_sync_catalog_qty_from_stores'))
{
	function mf_ep_sync_catalog_qty_from_stores(int $productId): void
	{
		$productId = (int)$productId;
		if ($productId <= 0 || !class_exists(\CCatalogStoreProduct::class) || !class_exists(\CCatalogProduct::class))
		{
			return;
		}

		$sum = 0.0;
		$rs = \CCatalogStoreProduct::GetList([], ['PRODUCT_ID' => $productId], false, false, ['AMOUNT']);
		while ($r = $rs->Fetch())
		{
			$sum += (float)($r['AMOUNT'] ?? 0);
		}

		\CCatalogProduct::Update($productId, [
			'QUANTITY' => $sum,
			'AVAILABLE' => ($sum > 0 ? 'Y' : 'N'),
		]);
	}
}

if (!function_exists('mf_ep_ensure_unit_if_zero_stock'))
{
	/**
	 * Если на складе импорта остаток 0 или записи нет — фиксируем количество 0 (раньше подставлялась 1 «для витрины»).
	 * Положительный остаток не трогаем.
	 */
	function mf_ep_ensure_unit_if_zero_stock(int $productId, int $storeId): void
	{
		$productId = (int)$productId;
		$storeId = (int)$storeId;
		if ($productId <= 0 || $storeId <= 0 || !class_exists(\CCatalogStoreProduct::class))
		{
			return;
		}

		$rowId = 0;
		$amount = 0.0;
		$rs = \CCatalogStoreProduct::GetList(
			[],
			['PRODUCT_ID' => $productId, 'STORE_ID' => $storeId],
			false,
			false,
			['ID', 'AMOUNT']
		);
		if ($row = $rs->Fetch())
		{
			$rowId = (int)$row['ID'];
			$amount = (float)($row['AMOUNT'] ?? 0);
		}

		if ($amount > 0)
		{
			return;
		}

		if ($rowId > 0)
		{
			\CCatalogStoreProduct::Update($rowId, ['AMOUNT' => 0.0]);
		}
		else
		{
			$addId = \CCatalogStoreProduct::Add([
				'PRODUCT_ID' => $productId,
				'STORE_ID' => $storeId,
				'AMOUNT' => 0.0,
			]);
			if (!$addId && class_exists(\Bitrix\Catalog\StoreProductTable::class))
			{
				try
				{
					$r = \Bitrix\Catalog\StoreProductTable::add([
						'PRODUCT_ID' => $productId,
						'STORE_ID' => $storeId,
						'AMOUNT' => 0.0,
					]);
					if (!$r->isSuccess())
					{
						// оставляем как есть — остаток не создан
					}
				}
				catch (\Throwable $e)
				{
					// ignore
				}
			}
		}
	}
}

if (!function_exists('mf_ep_cbr_fetch_rates'))
{
	/**
	 * @return array{USD: float, EUR: float}|null курс: рублей за 1 единицу валюты
	 */
	function mf_ep_cbr_fetch_rates(): ?array
	{
		$date = date('d/m/Y');
		$url = 'https://www.cbr.ru/scripts/XML_daily.asp?date_req=' . rawurlencode($date);
		$ctx = stream_context_create([
			'http' => [
				'timeout' => 15,
				'header' => "User-Agent: BitrixMF/1.0\r\n",
			],
			'ssl' => [
				'verify_peer' => true,
				'verify_peer_name' => true,
			],
		]);
		$xmlStr = @file_get_contents($url, false, $ctx);
		if ((!is_string($xmlStr) || $xmlStr === '') && function_exists('curl_init'))
		{
			$ch = curl_init($url);
			if ($ch !== false)
			{
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($ch, CURLOPT_TIMEOUT, 20);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
				curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: BitrixMF/1.0']);
				$xmlStr = (string)curl_exec($ch);
				curl_close($ch);
			}
		}
		if (!is_string($xmlStr) || $xmlStr === '')
		{
			return null;
		}

		$xml = @simplexml_load_string($xmlStr);
		if ($xml === false)
		{
			return null;
		}

		$out = ['USD' => 0.0, 'EUR' => 0.0];
		foreach ($xml->Valute ?? [] as $v)
		{
			$code = (string)($v->CharCode ?? '');
			if ($code !== 'USD' && $code !== 'EUR')
			{
				continue;
			}
			$nominal = (float)str_replace(',', '.', (string)($v->Nominal ?? '1'));
			if ($nominal <= 0)
			{
				$nominal = 1.0;
			}
			$val = (float)str_replace(',', '.', (string)($v->Value ?? '0'));
			if ($val <= 0)
			{
				continue;
			}
			$out[$code] = round($val / $nominal, 6);
		}

		if ($out['USD'] <= 0 || $out['EUR'] <= 0)
		{
			return null;
		}

		return $out;
	}
}

if (!function_exists('mf_ep_get_cbr_rates_cached'))
{
	/**
	 * Курсы ЦБ с кэшем (по умолчанию обновление не чаще раза в ~23 ч).
	 *
	 * @return array{USD: float, EUR: float}
	 */
	function mf_ep_get_cbr_rates_cached(bool $forceRefresh = false): array
	{
		$maxAge = 82800;
		if (class_exists(Option::class))
		{
			try
			{
				$at = (int)Option::get('main', 'mf_cbr_rates_at', '0');
				$json = (string)Option::get('main', 'mf_cbr_rates_json', '');
				if (!$forceRefresh && $at > 0 && (time() - $at) < $maxAge && $json !== '')
				{
					$d = json_decode($json, true);
					if (is_array($d) && isset($d['USD'], $d['EUR'])
						&& (float)$d['USD'] > 0 && (float)$d['EUR'] > 0)
					{
						return ['USD' => (float)$d['USD'], 'EUR' => (float)$d['EUR']];
					}
				}
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}

		$fresh = mf_ep_cbr_fetch_rates();
		if ($fresh === null)
		{
			try
			{
				$json = (string)Option::get('main', 'mf_cbr_rates_json', '');
				$d = json_decode($json, true);
				if (is_array($d) && isset($d['USD'], $d['EUR']))
				{
					return ['USD' => (float)$d['USD'], 'EUR' => (float)$d['EUR']];
				}
			}
			catch (\Throwable $e)
			{
				// ignore
			}

			throw new RuntimeException('Не удалось получить курсы USD/EUR с сайта ЦБ РФ.');
		}

		if (class_exists(Option::class))
		{
			try
			{
				Option::set('main', 'mf_cbr_rates_json', json_encode($fresh, JSON_UNESCAPED_UNICODE));
				Option::set('main', 'mf_cbr_rates_at', (string)time());
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}

		return $fresh;
	}
}

if (!function_exists('mf_ep_bitrix_convert_to_rub'))
{
	/**
	 * Перевод в базовую валюту сайта через модуль currency (курсы из админки Bitrix).
	 *
	 * @return float|null null если модуля нет или курс не задан / нулевой
	 */
	function mf_ep_bitrix_convert_to_rub(float $amount, string $currencyCode): ?float
	{
		$c = mb_strtoupper(trim($currencyCode));
		if ($c === 'RUB' || $c === 'RUR' || $c === '')
		{
			return round($amount, 2);
		}

		if (!Loader::includeModule('currency') || !class_exists(\CCurrencyRates::class))
		{
			return null;
		}

		$base = 'RUB';
		if (class_exists(\CCurrency::class))
		{
			$b = (string)\CCurrency::GetBaseCurrency();
			if ($b !== '')
			{
				$base = $b;
			}
		}
		elseif (class_exists(\Bitrix\Currency\CurrencyManager::class))
		{
			try
			{
				$b = (string)\Bitrix\Currency\CurrencyManager::getBaseCurrency();
				if ($b !== '')
				{
					$base = $b;
				}
			}
			catch (\Throwable $e)
			{
				// keep RUB
			}
		}

		$r = \CCurrencyRates::ConvertCurrency($amount, $c, $base);
		if (!is_finite($r) || (float)$r <= 0)
		{
			return null;
		}

		return round((float)$r, 2);
	}
}

if (!function_exists('mf_ep_cbr_fallback_disabled'))
{
	/**
	 * Опция main.mf_external_price_no_cbr = Y — не обращаться к API ЦБ, только курсы Bitrix.
	 */
	function mf_ep_cbr_fallback_disabled(): bool
	{
		if (!class_exists(Option::class))
		{
			return false;
		}
		try
		{
			return Option::get('main', 'mf_external_price_no_cbr', 'N') === 'Y';
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}
}

if (!function_exists('mf_ep_convert_to_rub'))
{
	/**
	 * Сначала курс из модуля «Валюты» (Настройки → Валюты → Курсы валют).
	 * Для USD/EUR при отсутствии курса в БД — опционально подстановка по API ЦБ (если не отключено mf_external_price_no_cbr).
	 */
	function mf_ep_convert_to_rub(float $amount, string $currencyCode): float
	{
		$c = mb_strtoupper(trim($currencyCode));
		if ($c === 'RUB' || $c === 'RUR' || $c === '')
		{
			return round($amount, 2);
		}

		if (abs($amount) < 1e-12)
		{
			return 0.0;
		}

		$bitrix = mf_ep_bitrix_convert_to_rub($amount, $c);
		if ($bitrix !== null && $bitrix > 0)
		{
			return $bitrix;
		}

		if (($c === 'USD' || $c === 'EUR') && !mf_ep_cbr_fallback_disabled())
		{
			$rates = mf_ep_get_cbr_rates_cached(false);

			return round($amount * (float)$rates[$c], 2);
		}

		throw new RuntimeException(
			'Не задан курс ' . $c . ' к рублю в модуле валют Bitrix '
			. '(Настройки → Настройки продукта → Настройки модулей → Валюты → курсы валют). '
			. 'Либо включите резервный курс ЦБ: удалите в main опции mf_external_price_no_cbr.'
		);
	}
}

if (!function_exists('mf_ep_product_weight_kg'))
{
	/**
	 * Масса позиции (кг) = вес единицы × количество; учёт sale weight_koef как в оформлении заказа.
	 */
	function mf_ep_product_weight_kg(int $productId, float $qty): float
	{
		$productId = (int)$productId;
		if ($productId <= 0 || $qty <= 0)
		{
			return 0.0;
		}

		if (!class_exists(\CCatalogProduct::class))
		{
			return 0.0;
		}

		$row = \CCatalogProduct::GetByID($productId);
		if (!is_array($row))
		{
			return 0.0;
		}

		$w = (float)($row['WEIGHT'] ?? 0);
		if ($w <= 0)
		{
			return 0.0;
		}

		$koef = 1.0;
		if (class_exists(Option::class))
		{
			try
			{
				$koef = (float)Option::get('sale', 'weight_koef', 1);
			}
			catch (\Throwable $e)
			{
				$koef = 1.0;
			}
		}
		if ($koef <= 0)
		{
			$koef = 1.0;
		}

		return ($w * $qty) / $koef;
	}
}

if (!function_exists('mf_ep_store_weight_uf_raw'))
{
	/**
	 * Значения UF склада как в базе (для админки / отображения).
	 * Для расчёта доплаты см. mf_ep_store_weight_fields — там «вкл.» учитывается только при rub_per_kg > 0.
	 *
	 * @return array{use: bool, amount_per_kg: float, currency: string}
	 */
	function mf_ep_store_weight_uf_raw(int $storeId): array
	{
		$storeId = (int)$storeId;
		if ($storeId <= 0)
		{
			return ['use' => false, 'amount_per_kg' => 0.0, 'currency' => 'RUB'];
		}

		global $USER_FIELD_MANAGER;
		if (!is_object($USER_FIELD_MANAGER) || !class_exists(\Bitrix\Catalog\StoreTable::class))
		{
			return ['use' => false, 'amount_per_kg' => 0.0, 'currency' => 'RUB'];
		}

		try
		{
			$ufs = $USER_FIELD_MANAGER->GetUserFields(\Bitrix\Catalog\StoreTable::getUfId(), $storeId);
			$u = $ufs['UF_MF_EXT_WEIGHT_USE']['VALUE'] ?? '';
			if (is_array($u))
			{
				$u = reset($u);
			}
			$use = ($u === 1 || $u === '1' || $u === true || $u === 'Y' || $u === 'y');

			$rp = $ufs['UF_MF_EXT_WEIGHT_RUB_PER_KG']['VALUE'] ?? 0;
			if (is_array($rp))
			{
				$rp = reset($rp);
			}
			$amountPerKg = (float)str_replace(',', '.', (string)$rp);

			$ccyRaw = $ufs['UF_MF_EXT_WEIGHT_TARIFF_CCY']['VALUE'] ?? '';
			if (is_array($ccyRaw))
			{
				$ccyRaw = reset($ccyRaw);
			}
			$ccyStr = trim((string)$ccyRaw);
			$ccy = $ccyStr !== '' ? mf_ep_normalize_catalog_currency($ccyStr) : 'RUB';

			return [
				'use' => $use,
				'amount_per_kg' => $amountPerKg,
				'currency' => $ccy,
			];
		}
		catch (\Throwable $e)
		{
			return ['use' => false, 'amount_per_kg' => 0.0, 'currency' => 'RUB'];
		}
	}
}

if (!function_exists('mf_ep_store_weight_fields'))
{
	/**
	 * @return array{use: bool, rub_per_kg: float} rub_per_kg — тариф в рублях за кг по текущему курсу
	 */
	function mf_ep_store_weight_fields(int $storeId): array
	{
		$r = mf_ep_store_weight_uf_raw($storeId);
		$rubPerKg = 0.0;
		if ($r['amount_per_kg'] > 0 && function_exists('mf_ep_convert_to_rub'))
		{
			try
			{
				$rubPerKg = mf_ep_convert_to_rub($r['amount_per_kg'], $r['currency']);
			}
			catch (\Throwable $e)
			{
				$rubPerKg = 0.0;
			}
		}

		return [
			'use' => $r['use'] && $rubPerKg > 0,
			'rub_per_kg' => $rubPerKg,
		];
	}
}

if (!function_exists('mf_ep_weight_surcharge_rub'))
{
	/**
	 * Стоимость доставки по весу за позицию (кг × ₽/кг), без наценки.
	 * В розничной цене вес уже учтён внутри mf_calc_store_price: (Закуп + вес×тариф) × (1+наценка/100).
	 */
	function mf_ep_weight_surcharge_rub(int $productId, int $storeId, float $qty): float
	{
		$w = mf_ep_store_weight_fields($storeId);
		if (!$w['use'])
		{
			return 0.0;
		}

		$kg = mf_ep_product_weight_kg($productId, $qty);
		if ($kg <= 0)
		{
			return 0.0;
		}

		$calc = $kg * (float)$w['rub_per_kg'];

		return round($calc, 2);
	}
}

if (!function_exists('mf_ep_display_price_for_store'))
{
	/**
	 * Цена за единицу для витрины и корзины: mf_calc_store_price (закуп×(1+наценка/100) или (закуп+вес×тариф)×(1+наценка/100)), опт −10%.
	 * Параметр qty зарезервирован (API), на цену за штуку не влияет.
	 */
	function mf_ep_display_price_for_store(int $productId, int $storeId, float $qty = 1.0): ?float
	{
		$productId = (int)$productId;
		$storeId = (int)$storeId;
		if ($productId <= 0 || $storeId <= 0)
		{
			return null;
		}
		if (!function_exists('mf_calc_store_price'))
		{
			return null;
		}
		$computed = mf_calc_store_price($productId, $storeId);
		if ($computed === null || $computed <= 0)
		{
			return null;
		}
		if (function_exists('mf_user_is_wholesale') && mf_user_is_wholesale())
		{
			$computed = round((float)$computed * 0.9, 2);
		}

		return $computed > 0 ? $computed : null;
	}
}

if (!function_exists('mf_ep_ensure_store_weight_ufs'))
{
	function mf_ep_ensure_store_weight_ufs(): void
	{
		if (!class_exists(\CUserTypeEntity::class) || !class_exists(\Bitrix\Catalog\StoreTable::class))
		{
			return;
		}

		$entityId = \Bitrix\Catalog\StoreTable::getUfId();
		$ute = new \CUserTypeEntity();

		$ensure = static function (string $fieldName, string $type, array $labels, int $sort, array $settings = []) use ($ute, $entityId): void {
			$ex = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId, 'FIELD_NAME' => $fieldName])->Fetch();
			if ($ex)
			{
				return;
			}
			$ute->Add([
				'ENTITY_ID' => $entityId,
				'FIELD_NAME' => $fieldName,
				'USER_TYPE_ID' => $type,
				'SORT' => $sort,
				'MULTIPLE' => 'N',
				'MANDATORY' => 'N',
				'SHOW_FILTER' => 'I',
				'SHOW_IN_LIST' => 'Y',
				'EDIT_IN_LIST' => 'Y',
				'IS_SEARCHABLE' => 'N',
				'SETTINGS' => $settings,
				'EDIT_FORM_LABEL' => $labels,
				'LIST_COLUMN_LABEL' => $labels,
				'LIST_FILTER_LABEL' => $labels,
			]);
		};

		$ensure('UF_MF_EXTERNAL_STORE', 'boolean', ['ru' => 'Внешний склад', 'en' => 'External warehouse'], 300, []);
		$ensure('UF_MF_EXT_WEIGHT_USE', 'boolean', ['ru' => 'Внешний прайс: учитывать вес (доставка)', 'en' => 'Ext price: weight surcharge'], 310, []);
		$ensure('UF_MF_EXT_WEIGHT_RUB_PER_KG', 'double', ['ru' => 'Тариф за кг веса (число)', 'en' => 'Tariff per kg'], 320, [
			'DEFAULT_VALUE' => 0,
			'PRECISION' => 2,
			'SIZE' => 10,
		]);
		$ensure('UF_MF_EXT_WEIGHT_TARIFF_CCY', 'string', ['ru' => 'Валюта тарифа за кг (пусто = RUB)', 'en' => 'Tariff currency'], 325, [
			'DEFAULT_VALUE' => '',
			'SIZE' => 8,
		]);
	}
}

if (!function_exists('mf_ep_store_is_external_warehouse'))
{
	/**
	 * Склад участвует во внешних прайсах (UF «Внешний склад»).
	 */
	function mf_ep_store_is_external_warehouse(int $storeId): bool
	{
		$storeId = (int)$storeId;
		if ($storeId <= 0)
		{
			return false;
		}

		global $USER_FIELD_MANAGER;
		if (!is_object($USER_FIELD_MANAGER) || !class_exists(\Bitrix\Catalog\StoreTable::class))
		{
			return false;
		}

		try
		{
			$ufs = $USER_FIELD_MANAGER->GetUserFields(\Bitrix\Catalog\StoreTable::getUfId(), $storeId);
			$u = $ufs['UF_MF_EXTERNAL_STORE']['VALUE'] ?? '';
			if (is_array($u))
			{
				$u = reset($u);
			}

			return ($u === 1 || $u === '1' || $u === true || $u === 'Y' || $u === 'y');
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}
}

if (!function_exists('mf_ep_product_weight_grams_cluster'))
{
	/**
	 * Вес единицы (граммы) по первой позиции кластера каталога.
	 */
	function mf_ep_product_weight_grams_cluster(int $productId): int
	{
		$productId = (int)$productId;
		if ($productId <= 0 || !class_exists(\CCatalogProduct::class))
		{
			return 0;
		}
		$ids = function_exists('mf_catalog_product_cluster_ids')
			? mf_catalog_product_cluster_ids($productId)
			: [$productId];
		$ids = array_values(array_filter(
			$ids,
			static fn($v) => (int)$v > 0
		));
		if ($ids === [])
		{
			return 0;
		}
		$row = \CCatalogProduct::GetByID((int)$ids[0]);

		return isset($row['WEIGHT']) ? (int)$row['WEIGHT'] : 0;
	}
}

if (!function_exists('mf_store_delivery_spb_ui'))
{
	/**
	 * Индикатор «доставка до склада СПб» для строки склада: внешний склад + UF «учитывать вес», иначе по правилам витрины.
	 * Если задан $productId: на внешнем складе при весе товара 0 — всегда «не ок» (крестик), независимо от настроек UF склада.
	 *
	 * @return array{ok:bool,title:string}
	 */
	function mf_store_delivery_spb_ui(int $storeId, int $productId = 0): array
	{
		$storeId = (int)$storeId;
		$productId = (int)$productId;
		$titleOk = 'Доставка до склада СПб включена';
		$titleBad = 'Доставка до склада СПб не включена';
		$titleNoWeight = 'Вес товара не задан (0 г) — доставка до СПб с внешнего склада недоступна';
		if ($storeId <= 0)
		{
			return ['ok' => true, 'title' => $titleOk];
		}

		$external = function_exists('mf_ep_store_is_external_warehouse') && mf_ep_store_is_external_warehouse($storeId);
		if ($external && $productId > 0)
		{
			$w = function_exists('mf_ep_product_weight_grams_cluster')
				? mf_ep_product_weight_grams_cluster($productId)
				: 0;
			if ($w <= 0)
			{
				return ['ok' => false, 'title' => $titleNoWeight];
			}
		}

		if (!$external)
		{
			return ['ok' => true, 'title' => $titleOk];
		}

		$raw = function_exists('mf_ep_store_weight_uf_raw') ? mf_ep_store_weight_uf_raw($storeId) : ['use' => false];
		if (!empty($raw['use']))
		{
			return ['ok' => true, 'title' => $titleOk];
		}

		return ['ok' => false, 'title' => $titleBad];
	}
}

if (!function_exists('mf_store_delivery_spb_icon_html'))
{
	/**
	 * Колонка «Доставка»: зелёная галочка или красный крестик в кружке + title для подсказки.
	 * $productId: для внешнего склада и веса 0 — крестик (см. mf_store_delivery_spb_ui).
	 */
	function mf_store_delivery_spb_icon_html(int $storeId, int $productId = 0): string
	{
		$ui = mf_store_delivery_spb_ui($storeId, $productId);
		$ok = !empty($ui['ok']);
		$title = (string)($ui['title'] ?? '');
		$mod = $ok ? 'ok' : 'bad';
		$t = htmlspecialcharsbx($title);
		$glyph = $ok ? '✓' : '×';

		return '<span class="mf-store-delivery-spb mf-store-delivery-spb--'.$mod.'" title="'.$t.'" aria-label="'.$t.'"><span class="mf-store-delivery-spb__glyph" aria-hidden="true">'.$glyph.'</span></span>';
	}
}

if (!function_exists('mf_ep_collect_candidates_for_store'))
{
	/**
	 * Товары, у которых есть строка остатка по складу или ненулевая цена типа склада.
	 *
	 * @return int[]
	 */
	function mf_ep_collect_candidates_for_store(int $storeId, int $priceGroupId): array
	{
		$storeId = (int)$storeId;
		$priceGroupId = (int)$priceGroupId;
		if ($storeId <= 0 || $priceGroupId <= 0)
		{
			return [];
		}

		$ids = [];
		if (class_exists(\CCatalogStoreProduct::class))
		{
			$rs = \CCatalogStoreProduct::GetList([], ['STORE_ID' => $storeId], false, false, ['PRODUCT_ID']);
			while ($r = $rs->Fetch())
			{
				$ids[(int)$r['PRODUCT_ID']] = true;
			}
		}

		if (class_exists(\CPrice::class))
		{
			$rs = \CPrice::GetList(
				[],
				['CATALOG_GROUP_ID' => $priceGroupId, '>PRICE' => 0],
				false,
				false,
				['PRODUCT_ID']
			);
			while ($r = $rs->Fetch())
			{
				$ids[(int)$r['PRODUCT_ID']] = true;
			}
		}

		return array_map('intval', array_keys($ids));
	}
}

if (!function_exists('mf_ep_zero_product_on_store'))
{
	function mf_ep_zero_product_on_store(int $productId, int $storeId, int $priceGroupId): void
	{
		$productId = (int)$productId;
		$storeId = (int)$storeId;
		$priceGroupId = (int)$priceGroupId;
		if ($productId <= 0 || $storeId <= 0 || $priceGroupId <= 0)
		{
			return;
		}

		if (class_exists(\CCatalogStoreProduct::class))
		{
			$rs = \CCatalogStoreProduct::GetList(
				[],
				['PRODUCT_ID' => $productId, 'STORE_ID' => $storeId],
				false,
				false,
				['ID']
			);
			if ($row = $rs->Fetch())
			{
				\CCatalogStoreProduct::Update((int)$row['ID'], ['AMOUNT' => 0]);
			}
		}

		mf_ep_set_raw_price($productId, $priceGroupId, 0.0);
		mf_ep_sync_catalog_qty_from_stores($productId);
		mf_ep_recalc_base_one($productId);
	}
}

if (!function_exists('mf_ep_clear_external_warehouse'))
{
	/**
	 * Удаляет остатки по внешнему складу целиком либо только данные одного прайса (FEED_CODE) по списку товаров из загрузки внешних цен.
	 * Остатки в каталоге — в b_catalog_store_product; отдельная таблица слоёв остатков по прайсу не используется.
	 *
	 * @return array{
	 *   ok: bool,
	 *   error: string,
	 *   deleted_store_rows: int,
	 *   products_price_cleared: int,
	 *   products_recalc: int,
	 *   mode?: string,
	 *   feed_code?: string,
	 *   affected_products?: int
	 * }
	 */
	function mf_ep_clear_external_warehouse(int $storeId, ?string $feedCode = null): array
	{
		$storeId = (int)$storeId;
		$out = [
			'ok' => false,
			'error' => '',
			'deleted_store_rows' => 0,
			'products_price_cleared' => 0,
			'products_recalc' => 0,
		];
		if ($storeId <= 0)
		{
			$out['error'] = 'Некорректный ID склада.';

			return $out;
		}
		if (!function_exists('mf_ep_store_is_external_warehouse') || !mf_ep_store_is_external_warehouse($storeId))
		{
			$out['error'] = 'Операция доступна только для внешних складов.';

			return $out;
		}
		if (!class_exists(\CCatalogStoreProduct::class))
		{
			$out['error'] = 'Модуль catalog недоступен.';

			return $out;
		}

		$map = function_exists('mf_supplier_store_to_price_group') ? mf_supplier_store_to_price_group() : [];
		$priceGroupId = (int)($map[$storeId] ?? 0);

		$feedOnly = '';
		if ($feedCode !== null && $feedCode !== '')
		{
			$feedOnly = function_exists('mf_esf_normalize_feed_code') ? mf_esf_normalize_feed_code($feedCode) : '';
		}

		if ($feedOnly !== '')
		{
			if (!function_exists('mf_esf_delete_feed_price_products_collect') || !function_exists('mf_esf_ensure_price_product_table'))
			{
				$out['error'] = 'Не подключён mf_external_store_feed_stock (привязка прайсов к товарам).';

				return $out;
			}
			mf_esf_ensure_price_product_table();
			$out['mode'] = 'feed';
			$out['feed_code'] = $feedOnly;
			$touch = mf_esf_delete_feed_price_products_collect($storeId, $feedOnly);
			$out['affected_products'] = count($touch);
			sort($touch, SORT_NUMERIC);
			if ($touch === [])
			{
				$out['hint'] = 'Нет товаров, привязанных к этому прайсу: загрузите внешний CSV с этим кодом прайса или выполните полную очистку склада.';
			}

			foreach ($touch as $productId)
			{
				$productId = (int)$productId;
				if ($productId <= 0)
				{
					continue;
				}
				if (function_exists('mf_esf_price_touch_other_feed_exists') && mf_esf_price_touch_other_feed_exists($storeId, $productId))
				{
					if (function_exists('mf_ep_sync_catalog_qty_from_stores'))
					{
						mf_ep_sync_catalog_qty_from_stores($productId);
					}
					if (function_exists('mf_ep_recalc_base_one'))
					{
						mf_ep_recalc_base_one($productId);
					}
					$out['products_recalc']++;
					continue;
				}
				if ($priceGroupId > 0)
				{
					mf_ep_zero_product_on_store($productId, $storeId, $priceGroupId);
					$out['products_price_cleared']++;
				}
				else
				{
					$rsZ = \CCatalogStoreProduct::GetList(
						[],
						['PRODUCT_ID' => $productId, 'STORE_ID' => $storeId],
						false,
						false,
						['ID']
					);
					if ($rowZ = $rsZ->Fetch())
					{
						\CCatalogStoreProduct::Update((int)$rowZ['ID'], ['AMOUNT' => 0]);
					}
					if (function_exists('mf_ep_sync_catalog_qty_from_stores'))
					{
						mf_ep_sync_catalog_qty_from_stores($productId);
					}
					if (function_exists('mf_ep_recalc_base_one'))
					{
						mf_ep_recalc_base_one($productId);
					}
				}
				$out['products_recalc']++;
			}

			if (function_exists('mf_ep_invalidate_catalog_price_group_cache'))
			{
				mf_ep_invalidate_catalog_price_group_cache();
			}
			if ($out['affected_products'] > 0 && function_exists('mf_esf_registry_remove_feed'))
			{
				mf_esf_registry_remove_feed($storeId, $feedOnly);
			}
			$out['ok'] = true;

			return $out;
		}

		if (function_exists('mf_esf_registry_delete_all_for_store'))
		{
			mf_esf_registry_delete_all_for_store($storeId);
		}
		if (function_exists('mf_esf_delete_all_price_product_for_store'))
		{
			mf_esf_delete_all_price_product_for_store($storeId);
		}
		$out['mode'] = 'all';

		$pidSet = [];
		if ($priceGroupId > 0 && function_exists('mf_ep_collect_candidates_for_store'))
		{
			foreach (mf_ep_collect_candidates_for_store($storeId, $priceGroupId) as $p)
			{
				$p = (int)$p;
				if ($p > 0)
				{
					$pidSet[$p] = true;
				}
			}
		}
		else
		{
			$rs0 = \CCatalogStoreProduct::GetList(
				[],
				['STORE_ID' => $storeId],
				false,
				false,
				['PRODUCT_ID']
			);
			while ($r0 = $rs0->Fetch())
			{
				$pp = (int)($r0['PRODUCT_ID'] ?? 0);
				if ($pp > 0)
				{
					$pidSet[$pp] = true;
				}
			}
		}

		$toDelete = [];
		$rsDel = \CCatalogStoreProduct::GetList(
			[],
			['STORE_ID' => $storeId],
			false,
			false,
			['ID']
		);
		while ($rd = $rsDel->Fetch())
		{
			$idd = (int)($rd['ID'] ?? 0);
			if ($idd > 0)
			{
				$toDelete[] = $idd;
			}
		}
		foreach ($toDelete as $rowId)
		{
			if (\CCatalogStoreProduct::Delete($rowId))
			{
				$out['deleted_store_rows']++;
			}
		}

		$productIds = array_keys($pidSet);
		sort($productIds, SORT_NUMERIC);

		foreach ($productIds as $productId)
		{
			$productId = (int)$productId;
			if ($productId <= 0)
			{
				continue;
			}
			if ($priceGroupId > 0)
			{
				if (mf_ep_set_raw_price($productId, $priceGroupId, 0.0))
				{
					$out['products_price_cleared']++;
				}
			}
			if (function_exists('mf_ep_sync_catalog_qty_from_stores'))
			{
				mf_ep_sync_catalog_qty_from_stores($productId);
			}
			if (function_exists('mf_ep_recalc_base_one'))
			{
				mf_ep_recalc_base_one($productId);
			}
			$out['products_recalc']++;
		}

		if (function_exists('mf_ep_invalidate_catalog_price_group_cache'))
		{
			mf_ep_invalidate_catalog_price_group_cache();
		}

		$out['ok'] = true;

		return $out;
	}
}

// --- История импортов внешних прайсов (MySQL) ---------------------------------

if (!function_exists('mf_external_price_import_log_conn'))
{
	function mf_external_price_import_log_conn(): ?\Bitrix\Main\DB\Connection
	{
		if (!class_exists(\Bitrix\Main\Application::class))
		{
			return null;
		}
		try
		{
			return \Bitrix\Main\Application::getConnection();
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}
}

if (!function_exists('mf_external_price_import_log_ensure_table'))
{
	function mf_external_price_import_log_ensure_table(): bool
	{
		$conn = mf_external_price_import_log_conn();
		if (!$conn)
		{
			return false;
		}

		$driver = method_exists($conn, 'getType') ? (string)$conn->getType() : '';
		if ($driver !== '' && stripos($driver, 'mysql') === false)
		{
			return false;
		}

		$sql = "CREATE TABLE IF NOT EXISTS mf_external_price_import_log (
			ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			UF_STARTED_AT DATETIME NOT NULL,
			UF_FINISHED_AT DATETIME NULL,
			UF_DURATION_MS INT UNSIGNED NULL,
			UF_STATUS VARCHAR(24) NOT NULL DEFAULT 'ok',

			UF_USER_ID INT UNSIGNED NULL,
			UF_USER_LOGIN VARCHAR(64) NULL,

			UF_STORE_ID INT NULL,
			UF_STORE_XML_ID VARCHAR(128) NULL,
			UF_STORE_TITLE VARCHAR(255) NULL,
			UF_PRICE_GROUP_ID INT NULL,

			UF_INPUT_FILENAME VARCHAR(512) NULL,
			UF_FILE_SIZE BIGINT NULL,

			UF_CURRENCY VARCHAR(8) NULL,
			UF_ZERO_MISSING CHAR(1) NULL,
			UF_WEIGHT_USE CHAR(1) NULL,
			UF_WEIGHT_RUB_PER_KG DOUBLE NULL,
			UF_WEIGHT_MIN_RUB DOUBLE NULL,

			UF_TOTAL_DATA_ROWS INT UNSIGNED NULL,
			UF_MATCHED INT UNSIGNED NULL,
			UF_NOT_FOUND INT UNSIGNED NULL,
			UF_BAD_ROWS INT UNSIGNED NULL,
			UF_ZEROED INT UNSIGNED NULL,

			UF_HEADER_LINE VARCHAR(1024) NULL,
			UF_EXAMPLES_NOT_FOUND MEDIUMTEXT NULL,
			UF_ERROR_MESSAGE VARCHAR(1024) NULL,

			PRIMARY KEY (ID),
			KEY IX_EP_LOG_STARTED (UF_STARTED_AT),
			KEY IX_EP_LOG_STORE (UF_STORE_ID),
			KEY IX_EP_LOG_STATUS (UF_STATUS)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

		try
		{
			$conn->queryExecute($sql);
			mf_external_price_import_log_migrate_schema($conn);

			return true;
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}
}

if (!function_exists('mf_external_price_import_log_migrate_schema'))
{
	function mf_external_price_import_log_migrate_schema(\Bitrix\Main\DB\Connection $conn): void
	{
		try
		{
			$r = $conn->query(
				"SHOW COLUMNS FROM mf_external_price_import_log LIKE 'UF_FEED_CODE'"
			)->fetch();
			if (!$r)
			{
				$conn->queryExecute(
					'ALTER TABLE mf_external_price_import_log ADD COLUMN UF_FEED_CODE VARCHAR(64) NULL AFTER UF_PRICE_GROUP_ID'
				);
			}
			$r2 = $conn->query(
				"SHOW COLUMNS FROM mf_external_price_import_log LIKE 'UF_JOB_ID'"
			)->fetch();
			if (!$r2)
			{
				$conn->queryExecute(
					'ALTER TABLE mf_external_price_import_log ADD COLUMN UF_JOB_ID BIGINT UNSIGNED NULL AFTER UF_STATUS'
				);
			}
			$r3 = $conn->query("SHOW INDEX FROM mf_external_price_import_log WHERE Key_name = 'IX_EP_LOG_JOB'")->fetch();
			if (!$r3)
			{
				try
				{
					$conn->queryExecute(
						'ALTER TABLE mf_external_price_import_log ADD KEY IX_EP_LOG_JOB (UF_JOB_ID)'
					);
				}
				catch (\Throwable $e2)
				{
				}
			}
		}
		catch (\Throwable $e)
		{
		}
	}
}

if (!function_exists('mf_external_price_import_log_quote'))
{
	function mf_external_price_import_log_quote(\Bitrix\Main\DB\Connection $conn, $value): string
	{
		if ($value === null)
		{
			return 'NULL';
		}

		return "'" . $conn->getSqlHelper()->forSql((string)$value) . "'";
	}
}

if (!function_exists('mf_external_price_import_log_insert'))
{
	/**
	 * @param array<string, mixed> $fields
	 */
	function mf_external_price_import_log_insert(array $fields): int
	{
		if (!mf_external_price_import_log_ensure_table())
		{
			return 0;
		}

		$conn = mf_external_price_import_log_conn();
		if (!$conn)
		{
			return 0;
		}

		$cols = [];
		$vals = [];
		foreach ($fields as $k => $v)
		{
			$k = trim((string)$k);
			if ($k === '')
			{
				continue;
			}
			$cols[] = '`' . str_replace('`', '', $k) . '`';
			if ($v === null)
			{
				$vals[] = 'NULL';
			}
			elseif (is_int($v) || is_float($v))
			{
				$vals[] = is_finite((float)$v) ? (string)$v : 'NULL';
			}
			else
			{
				$vals[] = mf_external_price_import_log_quote($conn, $v);
			}
		}
		if (empty($cols))
		{
			return 0;
		}

		try
		{
			$conn->queryExecute(
				'INSERT INTO mf_external_price_import_log (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')'
			);
			$r = $conn->query('SELECT LAST_INSERT_ID() AS ID')->fetch();

			return (int)($r['ID'] ?? 0);
		}
		catch (\Throwable $e)
		{
			return 0;
		}
	}
}

if (!function_exists('mf_external_price_import_log_update'))
{
	/**
	 * Обновление строки истории (например running → ok/failed).
	 *
	 * @param array<string, mixed> $fields
	 */
	function mf_external_price_import_log_update(int $id, array $fields): bool
	{
		$id = (int)$id;
		if ($id <= 0 || empty($fields) || !mf_external_price_import_log_ensure_table())
		{
			return false;
		}

		$conn = mf_external_price_import_log_conn();
		if (!$conn)
		{
			return false;
		}

		$sets = [];
		foreach ($fields as $k => $v)
		{
			$k = trim((string)$k);
			if ($k === '')
			{
				continue;
			}
			$k = str_replace('`', '', $k);
			if ($v === null)
			{
				$sets[] = '`' . $k . '`=NULL';
			}
			elseif (is_int($v) || is_float($v))
			{
				$sets[] = '`' . $k . '`=' . (is_finite((float)$v) ? (string)$v : 'NULL');
			}
			else
			{
				$sets[] = '`' . $k . '`=' . mf_external_price_import_log_quote($conn, $v);
			}
		}
		if (empty($sets))
		{
			return false;
		}

		try
		{
			$conn->queryExecute(
				'UPDATE mf_external_price_import_log SET ' . implode(', ', $sets) . ' WHERE ID=' . $id
			);

			return true;
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}
}

// --- Фоновые задания импорта внешнего прайса (админка) -------------------------

if (!function_exists('mf_external_price_import_job_conn'))
{
	function mf_external_price_import_job_conn(): ?\Bitrix\Main\DB\Connection
	{
		return mf_external_price_import_log_conn();
	}
}

if (!function_exists('mf_external_price_import_job_ensure_table'))
{
	function mf_external_price_import_job_ensure_table(): bool
	{
		$conn = mf_external_price_import_job_conn();
		if (!$conn)
		{
			return false;
		}

		$driver = method_exists($conn, 'getType') ? (string)$conn->getType() : '';
		if ($driver !== '' && stripos($driver, 'mysql') === false)
		{
			return false;
		}

		$sql = "CREATE TABLE IF NOT EXISTS mf_external_price_import_job (
			ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			UF_TOKEN CHAR(32) NOT NULL,
			UF_USER_ID INT UNSIGNED NOT NULL,
			UF_STATUS VARCHAR(16) NOT NULL DEFAULT 'pending',
			UF_FILE_PATH VARCHAR(1024) NOT NULL,
			UF_ORIG_NAME VARCHAR(512) NULL,
			UF_FILE_SIZE BIGINT NULL,
			UF_STORE_ID INT NOT NULL,
			UF_CURRENCY CHAR(3) NULL,
			UF_ZERO_MISSING CHAR(1) NULL,
			UF_WEIGHT_USE CHAR(1) NULL,
			UF_WEIGHT_RUB_KG DOUBLE NULL,
			UF_ROWS_TOTAL INT UNSIGNED NULL,
			UF_ROWS_DONE INT UNSIGNED NULL,
			UF_ERROR_TEXT TEXT NULL,
			UF_RESULT_JSON MEDIUMTEXT NULL,
			UF_STARTED_AT DATETIME NULL,
			UF_FINISHED_AT DATETIME NULL,
			PRIMARY KEY (ID),
			KEY IX_MFEPIJ_STATUS (UF_STATUS),
			KEY IX_MFEPIJ_USER (UF_USER_ID),
			KEY IX_MFEPIJ_TOKEN (UF_TOKEN)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

		try
		{
			$conn->queryExecute($sql);
			mf_external_price_import_job_migrate_schema($conn);

			return true;
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}
}

if (!function_exists('mf_external_price_import_job_migrate_schema'))
{
	function mf_external_price_import_job_migrate_schema(\Bitrix\Main\DB\Connection $conn): void
	{
		try
		{
			$r = $conn->query(
				"SHOW COLUMNS FROM mf_external_price_import_job LIKE 'UF_FEED_CODE'"
			)->fetch();
			if (!$r)
			{
				$conn->queryExecute(
					'ALTER TABLE mf_external_price_import_job ADD COLUMN UF_FEED_CODE VARCHAR(64) NULL AFTER UF_STORE_ID'
				);
			}
			$r2 = $conn->query(
				"SHOW COLUMNS FROM mf_external_price_import_job LIKE 'UF_IMPORT_LOG_ID'"
			)->fetch();
			if (!$r2)
			{
				$conn->queryExecute(
					'ALTER TABLE mf_external_price_import_job ADD COLUMN UF_IMPORT_LOG_ID BIGINT UNSIGNED NULL AFTER UF_FINISHED_AT'
				);
			}
		}
		catch (\Throwable $e)
		{
		}
	}
}

if (!function_exists('mf_external_price_import_job_insert'))
{
	/**
	 * @param array<string, mixed> $row
	 */
	function mf_external_price_import_job_insert(array $row): int
	{
		if (!mf_external_price_import_job_ensure_table())
		{
			return 0;
		}

		$conn = mf_external_price_import_job_conn();
		if (!$conn)
		{
			return 0;
		}

		$cols = [];
		$vals = [];
		foreach ($row as $k => $v)
		{
			$k = trim((string)$k);
			if ($k === '')
			{
				continue;
			}
			$cols[] = '`' . str_replace('`', '', $k) . '`';
			if ($v === null)
			{
				$vals[] = 'NULL';
			}
			elseif (is_int($v) || is_float($v))
			{
				$vals[] = is_finite((float)$v) ? (string)$v : 'NULL';
			}
			else
			{
				$vals[] = mf_external_price_import_log_quote($conn, $v);
			}
		}
		if (empty($cols))
		{
			return 0;
		}

		try
		{
			$conn->queryExecute(
				'INSERT INTO mf_external_price_import_job (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')'
			);
			$r = $conn->query('SELECT LAST_INSERT_ID() AS ID')->fetch();

			return (int)($r['ID'] ?? 0);
		}
		catch (\Throwable $e)
		{
			return 0;
		}
	}
}

if (!function_exists('mf_external_price_import_job_get'))
{
	function mf_external_price_import_job_get(int $id): ?array
	{
		if ($id <= 0 || !mf_external_price_import_job_ensure_table())
		{
			return null;
		}

		$conn = mf_external_price_import_job_conn();
		if (!$conn)
		{
			return null;
		}

		try
		{
			$r = $conn->query('SELECT * FROM mf_external_price_import_job WHERE ID=' . $id . ' LIMIT 1')->fetch();
			if (!is_array($r))
			{
				return null;
			}
			$norm = [];
			foreach ($r as $k => $v)
			{
				$norm[is_string($k) ? strtoupper($k) : $k] = $v;
			}

			return $norm;
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}
}

if (!function_exists('mf_external_price_import_job_update'))
{
	/**
	 * @param array<string, mixed> $fields
	 */
	function mf_external_price_import_job_update(int $id, array $fields): bool
	{
		$id = (int)$id;
		if ($id <= 0 || empty($fields))
		{
			return false;
		}

		$conn = mf_external_price_import_job_conn();
		if (!$conn)
		{
			return false;
		}

		$sets = [];
		foreach ($fields as $k => $v)
		{
			$k = trim((string)$k);
			if ($k === '')
			{
				continue;
			}
			$k = str_replace('`', '', $k);
			if ($v === null)
			{
				$sets[] = '`' . $k . '`=NULL';
			}
			elseif (is_int($v) || is_float($v))
			{
				$sets[] = '`' . $k . '`=' . (is_finite((float)$v) ? (string)$v : 'NULL');
			}
			else
			{
				$sets[] = '`' . $k . '`=' . mf_external_price_import_log_quote($conn, $v);
			}
		}
		if (empty($sets))
		{
			return false;
		}

		try
		{
			$conn->queryExecute('UPDATE mf_external_price_import_job SET ' . implode(', ', $sets) . ' WHERE ID=' . $id);

			return true;
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}
}

if (!function_exists('mf_external_price_import_job_release_flock'))
{
	/**
	 * Снять файловую блокировку воркера (после импорта или ошибки).
	 */
	function mf_external_price_import_job_release_flock(): void
	{
		if (empty($GLOBALS['mf_external_price_import_job_lock_fp']) || !is_resource($GLOBALS['mf_external_price_import_job_lock_fp']))
		{
			return;
		}
		$fp = $GLOBALS['mf_external_price_import_job_lock_fp'];
		@flock($fp, LOCK_UN);
		@fclose($fp);
		unset($GLOBALS['mf_external_price_import_job_lock_fp']);
	}
}

if (!function_exists('mf_external_price_import_job_try_mark_running'))
{
	/**
	 * Атомарно: pending → running. Файловый flock + MySQL, без надежды на getAffectedRowsCount.
	 */
	function mf_external_price_import_job_try_mark_running(int $id): bool
	{
		$id = (int)$id;
		if ($id <= 0)
		{
			return false;
		}

		$lockPath = @sys_get_temp_dir() . '/mfextprice_job_' . $id . '.lock';
		$fp = @fopen($lockPath, 'c+');
		if ($fp === false)
		{
			return mf_external_price_import_job_try_mark_running_db($id);
		}
		if (!flock($fp, LOCK_EX | LOCK_NB))
		{
			@fclose($fp);

			return false;
		}

		$row0 = mf_external_price_import_job_get($id);
		if (
			!$row0
			|| mb_strtolower(trim((string)($row0['UF_STATUS'] ?? ''))) !== 'pending'
		)
		{
			flock($fp, LOCK_UN);
			fclose($fp);

			return false;
		}

		$conn = mf_external_price_import_job_conn();
		if (!$conn)
		{
			flock($fp, LOCK_UN);
			fclose($fp);

			return false;
		}

		try
		{
			$conn->queryExecute(
				"UPDATE mf_external_price_import_job SET UF_STATUS='running', UF_STARTED_AT=NOW() WHERE ID=" . $id . " AND UF_STATUS='pending'"
			);
		}
		catch (\Throwable $e)
		{
			flock($fp, LOCK_UN);
			fclose($fp);

			return false;
		}

		$row1 = mf_external_price_import_job_get($id);
		if (
			!$row1
			|| mb_strtolower(trim((string)($row1['UF_STATUS'] ?? ''))) !== 'running'
		)
		{
			flock($fp, LOCK_UN);
			fclose($fp);

			return false;
		}

		$GLOBALS['mf_external_price_import_job_lock_fp'] = $fp;

		return true;
	}
}

if (!function_exists('mf_external_price_import_job_try_mark_running_db'))
{
	/**
	 * Резерв, если нет /tmp — только UPDATE + проверка строки.
	 */
	function mf_external_price_import_job_try_mark_running_db(int $id): bool
	{
		$conn = mf_external_price_import_job_conn();
		if (!$conn)
		{
			return false;
		}
		try
		{
			$conn->queryExecute(
				"UPDATE mf_external_price_import_job SET UF_STATUS='running', UF_STARTED_AT=NOW() WHERE ID=" . (int)$id . " AND UF_STATUS='pending'"
			);
		}
		catch (\Throwable $e)
		{
			return false;
		}
		$row = mf_external_price_import_job_get($id);

		return is_array($row) && mb_strtolower(trim((string)($row['UF_STATUS'] ?? ''))) === 'running';
	}
}

require_once __DIR__ . '/mf_external_store_feed_stock.php';
