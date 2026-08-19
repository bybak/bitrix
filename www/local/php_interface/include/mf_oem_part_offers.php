<?php
/**
 * OEM catalog: lookup Bitrix catalog offers for a part number.
 */

if (!function_exists('mf_oem_normalize_part_number'))
{
	function mf_oem_normalize_part_number(string $value): string
	{
		if (function_exists('mf_ce_normalize_article'))
		{
			return mf_ce_normalize_article($value);
		}
		$value = mb_strtoupper(trim($value));
		$value = preg_replace('~[^A-Z0-9]+~', '', $value) ?? '';

		return $value;
	}
}

if (!function_exists('mf_oem_normalize_brand'))
{
	function mf_oem_normalize_brand(string $value): string
	{
		if (function_exists('mf_ce_normalize_brand'))
		{
			return mf_ce_normalize_brand($value);
		}
		$value = mb_strtoupper(trim($value));
		$value = str_replace('Ё', 'Е', $value);
		$value = preg_replace('~[^A-ZА-Я0-9]+~u', '', $value) ?? '';

		return $value;
	}
}

if (!function_exists('mf_oem_is_yamaha_brand'))
{
	function mf_oem_is_yamaha_brand(string $value): bool
	{
		$norm = mf_oem_normalize_brand($value);

		return $norm !== '' && str_starts_with($norm, 'YAMAHA');
	}
}

if (!function_exists('mf_oem_is_yamaha_lookup'))
{
	function mf_oem_is_yamaha_lookup(string $brandHint, string $rootHint = ''): bool
	{
		if (mf_oem_is_yamaha_brand($brandHint))
		{
			return true;
		}
		$root = strtoupper(trim($rootHint));

		return $root !== '' && str_starts_with($root, 'YMH');
	}
}

if (!function_exists('mf_oem_find_yamaha_catalog_product_ids'))
{
	/**
	 * Yamaha: точное совпадение MF_ARTICLE_NORM и более длинные артикулы,
	 * в которые короткий номер входит целиком (9581206014 ⊂ 958120601400).
	 *
	 * @return list<int>
	 */
	function mf_oem_find_yamaha_catalog_product_ids(int $iblockId, string $articleNorm): array
	{
		$ids = [];
		$collect = static function (array $filter, int $limit) use ($articleNorm, &$ids): void {
			$rs = \CIBlockElement::GetList(
				['ID' => 'ASC'],
				$filter,
				false,
				['nTopCount' => $limit],
				['ID', 'PROPERTY_MF_ARTICLE_NORM', 'PROPERTY_MF_BRAND', 'PROPERTY_MF_BRAND_NORM']
			);
			while ($row = $rs->Fetch())
			{
				$pid = (int)($row['ID'] ?? 0);
				if ($pid <= 0 || isset($ids[$pid]))
				{
					continue;
				}
				$brand = trim((string)($row['PROPERTY_MF_BRAND_VALUE'] ?? ''));
				$brandNorm = trim((string)($row['PROPERTY_MF_BRAND_NORM_VALUE'] ?? ''));
				if (!mf_oem_is_yamaha_brand($brand) && !mf_oem_is_yamaha_brand($brandNorm))
				{
					continue;
				}
				$foundNorm = mf_oem_normalize_part_number((string)($row['PROPERTY_MF_ARTICLE_NORM_VALUE'] ?? ''));
				if ($foundNorm === '')
				{
					continue;
				}
				$isExact = ($foundNorm === $articleNorm);
				$isContainedLonger = (strlen($foundNorm) > strlen($articleNorm)
					&& strpos($foundNorm, $articleNorm) !== false);
				if (!$isExact && !$isContainedLonger)
				{
					continue;
				}
				$ids[$pid] = true;
			}
		};

		$baseFilter = [
			'IBLOCK_ID' => $iblockId,
			'ACTIVE' => 'Y',
			'!PROPERTY_MF_IS_REDIRECT' => 'Y',
		];
		$collect($baseFilter + ['=PROPERTY_MF_ARTICLE_NORM' => $articleNorm], 24);
		// Короткий префикс даст слишком много ложных вхождений.
		if (strlen($articleNorm) >= 6)
		{
			$collect($baseFilter + ['%PROPERTY_MF_ARTICLE_NORM' => $articleNorm], 64);
		}

		return array_map('intval', array_keys($ids));
	}
}

if (!function_exists('mf_oem_find_catalog_products'))
{
	/**
	 * @return array<int, array{id:int,name:string,brand:string,code:string,url:string}>
	 */
	function mf_oem_find_catalog_products(string $partNumber, string $brandHint = '', string $rootHint = ''): array
	{
		$articleNorm = mf_oem_normalize_part_number($partNumber);
		if ($articleNorm === '' || !class_exists(\CIBlockElement::class))
		{
			return [];
		}

		$iblockId = 4;
		$brandHint = trim($brandHint);
		$rootHint = trim($rootHint);
		$brandNorm = '';
		if ($brandHint !== '')
		{
			$brandNorm = mf_oem_normalize_brand($brandHint);
		}

		$yamahaLookup = mf_oem_is_yamaha_lookup($brandHint, $rootHint);
		$productIds = [];
		if ($yamahaLookup)
		{
			$productIds = mf_oem_find_yamaha_catalog_product_ids($iblockId, $articleNorm);
		}
		else
		{
			if (function_exists('mf_ep_find_product'))
			{
				$matched = mf_ep_find_product($iblockId, $articleNorm, $brandHint, $brandNorm);
				if ($matched)
				{
					$productIds[] = (int)$matched;
				}
			}

			if ($productIds === [])
			{
				$filter = [
					'IBLOCK_ID' => $iblockId,
					'=PROPERTY_MF_ARTICLE_NORM' => $articleNorm,
					'ACTIVE' => 'Y',
					'!PROPERTY_MF_IS_REDIRECT' => 'Y',
				];
				$rs = \CIBlockElement::GetList(
					['ID' => 'ASC'],
					$filter,
					false,
					['nTopCount' => 8],
					['ID']
				);
				while ($row = $rs->Fetch())
				{
					$pid = (int)($row['ID'] ?? 0);
					if ($pid > 0)
					{
						$productIds[] = $pid;
					}
				}
			}
		}

		$productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static function (int $id): bool {
			return $id > 0;
		})));
		if ($productIds === [])
		{
			return [];
		}

		$out = [];
		$articleById = [];
		$select = ['ID', 'NAME', 'CODE', 'PROPERTY_MF_BRAND'];
		if ($yamahaLookup)
		{
			$select[] = 'PROPERTY_MF_ARTICLE_NORM';
		}
		$rs = \CIBlockElement::GetList(
			['ID' => 'ASC'],
			['IBLOCK_ID' => $iblockId, 'ID' => $productIds, 'ACTIVE' => 'Y'],
			false,
			false,
			$select
		);
		while ($row = $rs->Fetch())
		{
			$pid = (int)($row['ID'] ?? 0);
			if ($pid <= 0)
			{
				continue;
			}
			$name = trim((string)($row['NAME'] ?? ''));
			$code = trim((string)($row['CODE'] ?? ''));
			$brand = trim((string)($row['PROPERTY_MF_BRAND_VALUE'] ?? ''));
			$url = ($code !== '' ? '/products/' . rawurlencode($code) . '/' : '/products/?ELEMENT_ID=' . $pid);
			$out[] = [
				'id' => $pid,
				'name' => ($name !== '' ? $name : ('Товар #' . $pid)),
				'brand' => $brand,
				'code' => $code,
				'url' => $url,
			];
			if ($yamahaLookup)
			{
				$articleById[$pid] = mf_oem_normalize_part_number((string)($row['PROPERTY_MF_ARTICLE_NORM_VALUE'] ?? ''));
			}
		}

		if ($yamahaLookup && count($out) > 1)
		{
			usort($out, static function (array $a, array $b) use ($articleNorm, $articleById): int {
				$aNorm = (string)($articleById[(int)$a['id']] ?? '');
				$bNorm = (string)($articleById[(int)$b['id']] ?? '');
				$aExact = ($aNorm === $articleNorm) ? 0 : 1;
				$bExact = ($bNorm === $articleNorm) ? 0 : 1;
				if ($aExact !== $bExact)
				{
					return $aExact <=> $bExact;
				}
				$lenCmp = strlen($aNorm) <=> strlen($bNorm);
				if ($lenCmp !== 0)
				{
					return $lenCmp;
				}

				return ((int)$a['id']) <=> ((int)$b['id']);
			});
		}

		return $out;
	}
}

if (!function_exists('mf_oem_product_offer_row'))
{
	/**
	 * @return array{id:int,name:string,brand:string,url:string,html:string}
	 */
	function mf_oem_product_offer_row(int $id, string $name, string $brand, string $url): array
	{
		$html = function_exists('mf_search_render_card_avail_html')
			? mf_search_render_card_avail_html($id, $name, $url)
			: '';

		return [
			'id' => $id,
			'name' => $name,
			'brand' => $brand,
			'url' => $url,
			'html' => $html,
		];
	}
}

if (!function_exists('mf_oem_same_brand_analogs_for_products'))
{
	/**
	 * Аналоги из HL, отфильтрованные по бренду оригинала (PROPERTY_MF_BRAND).
	 *
	 * @param array<int, array{id:int,name:string,brand:string,code?:string,url:string}> $products
	 * @return array<int, array<int, array{id:int,name:string,brand:string,url:string}>>
	 */
	function mf_oem_same_brand_analogs_for_products(array $products, int $limit = 8): array
	{
		$limit = max(1, min(12, (int)$limit));
		$out = [];
		$productIds = [];
		$brandNormByProduct = [];

		foreach ($products as $product)
		{
			$pid = (int)($product['id'] ?? 0);
			if ($pid <= 0)
			{
				continue;
			}
			$out[$pid] = [];
			$brandNorm = mf_oem_normalize_brand((string)($product['brand'] ?? ''));
			$brandNormByProduct[$pid] = $brandNorm;
			if ($brandNorm !== '')
			{
				$productIds[] = $pid;
			}
		}

		if ($productIds === [] || !class_exists(\CIBlockElement::class))
		{
			return $out;
		}

		$analogsLib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_analogs.php';
		if (is_file($analogsLib))
		{
			require_once $analogsLib;
		}

		// Берём с запасом: после фильтра по бренду останется меньше.
		$fetchLimit = min(24, max($limit * 3, $limit));
		$analogsByProduct = [];
		if (function_exists('mf_analogs_related_ids_for_products'))
		{
			$analogsByProduct = mf_analogs_related_ids_for_products($productIds, $fetchLimit, true);
		}
		elseif (function_exists('mf_analogs_related_ids_for_product') || function_exists('mf_analogs_ids_for_product'))
		{
			foreach ($productIds as $pid)
			{
				$ids = function_exists('mf_analogs_related_ids_for_product')
					? mf_analogs_related_ids_for_product($pid, $fetchLimit)
					: mf_analogs_ids_for_product($pid, $fetchLimit);
				if (!empty($ids))
				{
					$analogsByProduct[$pid] = $ids;
				}
			}
		}

		$allAnalogIds = [];
		foreach ($analogsByProduct as $ids)
		{
			foreach ((array)$ids as $aid)
			{
				$aid = (int)$aid;
				if ($aid > 0)
				{
					$allAnalogIds[$aid] = true;
				}
			}
		}
		$allAnalogIds = array_keys($allAnalogIds);
		if ($allAnalogIds === [])
		{
			return $out;
		}

		$rowsById = [];
		$rs = \CIBlockElement::GetList(
			['ID' => 'ASC'],
			[
				'IBLOCK_ID' => 4,
				'ID' => $allAnalogIds,
				'ACTIVE' => 'Y',
				'!PROPERTY_MF_IS_REDIRECT' => 'Y',
			],
			false,
			false,
			['ID', 'NAME', 'CODE', 'PROPERTY_MF_BRAND']
		);
		while ($row = $rs->Fetch())
		{
			$aid = (int)($row['ID'] ?? 0);
			if ($aid <= 0)
			{
				continue;
			}
			$name = trim((string)($row['NAME'] ?? ''));
			$code = trim((string)($row['CODE'] ?? ''));
			$brand = trim((string)($row['PROPERTY_MF_BRAND_VALUE'] ?? ''));
			$url = ($code !== '' ? '/products/' . rawurlencode($code) . '/' : '/products/?ELEMENT_ID=' . $aid);
			$rowsById[$aid] = [
				'id' => $aid,
				'name' => ($name !== '' ? $name : ('Товар #' . $aid)),
				'brand' => $brand,
				'url' => $url,
			];
		}

		foreach ($productIds as $pid)
		{
			$brandNorm = $brandNormByProduct[$pid] ?? '';
			if ($brandNorm === '')
			{
				continue;
			}
			$seen = [$pid => true];
			$filtered = [];
			foreach ((array)($analogsByProduct[$pid] ?? []) as $aid)
			{
				$aid = (int)$aid;
				if ($aid <= 0 || isset($seen[$aid]))
				{
					continue;
				}
				$seen[$aid] = true;
				$row = $rowsById[$aid] ?? null;
				if ($row === null)
				{
					continue;
				}
				if (mf_oem_normalize_brand((string)$row['brand']) !== $brandNorm)
				{
					continue;
				}
				$filtered[] = $row;
				if (count($filtered) >= $limit)
				{
					break;
				}
			}
			$out[$pid] = $filtered;
		}

		return $out;
	}
}

if (!function_exists('mf_oem_part_offers_payload'))
{
	/**
	 * @return array{ok:bool,part_number:string,products:array<int,array>,empty_message:string,error?:string}
	 */
	function mf_oem_part_offers_payload(string $partNumber, string $brandHint = '', string $rootHint = ''): array
	{
		$partNumber = trim($partNumber);
		if ($partNumber === '')
		{
			return [
				'ok' => false,
				'part_number' => '',
				'products' => [],
				'empty_message' => '',
				'error' => 'Не указан артикул.',
			];
		}

		$docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
		$renderLib = $docRoot . '/local/php_interface/include/mf_search_render.php';
		if (is_file($renderLib))
		{
			require_once $renderLib;
		}
		$cardLib = $docRoot . '/local/php_interface/include/mf_product_search_card.php';
		if (is_file($cardLib))
		{
			require_once $cardLib;
		}

		$products = mf_oem_find_catalog_products($partNumber, $brandHint, $rootHint);
		if ($products === [])
		{
			return [
				'ok' => true,
				'part_number' => $partNumber,
				'products' => [],
				'empty_message' => 'По артикулу «' . $partNumber . '» в каталоге Motor Force предложений не найдено.',
			];
		}

		$analogsByProduct = mf_oem_same_brand_analogs_for_products($products, 8);

		$warmIds = [];
		foreach ($products as $product)
		{
			$pid = (int)($product['id'] ?? 0);
			if ($pid > 0)
			{
				$warmIds[] = $pid;
			}
			foreach ((array)($analogsByProduct[$pid] ?? []) as $analog)
			{
				$aid = (int)($analog['id'] ?? 0);
				if ($aid > 0)
				{
					$warmIds[] = $aid;
				}
			}
		}
		if (function_exists('mf_product_search_card_warm_cache') && $warmIds !== [])
		{
			mf_product_search_card_warm_cache($warmIds);
		}

		$payloadProducts = [];
		foreach ($products as $product)
		{
			$pid = (int)($product['id'] ?? 0);
			if ($pid <= 0)
			{
				continue;
			}
			$row = mf_oem_product_offer_row(
				$pid,
				(string)$product['name'],
				(string)$product['brand'],
				(string)$product['url']
			);
			$analogs = [];
			foreach ((array)($analogsByProduct[$pid] ?? []) as $analog)
			{
				$aid = (int)($analog['id'] ?? 0);
				if ($aid <= 0)
				{
					continue;
				}
				$analogs[] = mf_oem_product_offer_row(
					$aid,
					(string)$analog['name'],
					(string)$analog['brand'],
					(string)$analog['url']
				);
			}
			$row['analogs'] = $analogs;
			$payloadProducts[] = $row;
		}

		return [
			'ok' => true,
			'part_number' => $partNumber,
			'products' => $payloadProducts,
			'empty_message' => '',
		];
	}
}
