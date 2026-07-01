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

if (!function_exists('mf_oem_find_catalog_products'))
{
	/**
	 * @return array<int, array{id:int,name:string,brand:string,code:string,url:string}>
	 */
	function mf_oem_find_catalog_products(string $partNumber, string $brandHint = ''): array
	{
		$articleNorm = mf_oem_normalize_part_number($partNumber);
		if ($articleNorm === '' || !class_exists(\CIBlockElement::class))
		{
			return [];
		}

		$iblockId = 4;
		$brandHint = trim($brandHint);
		$brandNorm = '';
		if ($brandHint !== '' && function_exists('mf_ce_normalize_brand'))
		{
			$brandNorm = mf_ce_normalize_brand($brandHint);
		}

		$productIds = [];
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

		$productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static function (int $id): bool {
			return $id > 0;
		})));
		if ($productIds === [])
		{
			return [];
		}

		$out = [];
		$rs = \CIBlockElement::GetList(
			['ID' => 'ASC'],
			['IBLOCK_ID' => $iblockId, 'ID' => $productIds, 'ACTIVE' => 'Y'],
			false,
			false,
			['ID', 'NAME', 'CODE', 'PROPERTY_MF_BRAND']
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
		}

		return $out;
	}
}

if (!function_exists('mf_oem_part_offers_payload'))
{
	/**
	 * @return array{ok:bool,part_number:string,products:array<int,array>,empty_message:string,error?:string}
	 */
	function mf_oem_part_offers_payload(string $partNumber, string $brandHint = ''): array
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

		$renderLib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_search_render.php';
		if (is_file($renderLib))
		{
			require_once $renderLib;
		}
		$cardLib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_product_search_card.php';
		if (is_file($cardLib))
		{
			require_once $cardLib;
		}

		$products = mf_oem_find_catalog_products($partNumber, $brandHint);
		if ($products === [])
		{
			return [
				'ok' => true,
				'part_number' => $partNumber,
				'products' => [],
				'empty_message' => 'По артикулу «' . $partNumber . '» в каталоге Motor Force предложений не найдено.',
			];
		}

		if (function_exists('mf_product_search_card_warm_cache'))
		{
			mf_product_search_card_warm_cache(array_map(static function (array $row): int {
				return (int)($row['id'] ?? 0);
			}, $products));
		}

		$payloadProducts = [];
		foreach ($products as $product)
		{
			$pid = (int)($product['id'] ?? 0);
			if ($pid <= 0)
			{
				continue;
			}
			$html = function_exists('mf_search_render_card_avail_html')
				? mf_search_render_card_avail_html($pid, (string)$product['name'], (string)$product['url'])
				: '';
			$payloadProducts[] = [
				'id' => $pid,
				'name' => (string)$product['name'],
				'brand' => (string)$product['brand'],
				'url' => (string)$product['url'],
				'html' => $html,
			];
		}

		return [
			'ok' => true,
			'part_number' => $partNumber,
			'products' => $payloadProducts,
			'empty_message' => '',
		];
	}
}
