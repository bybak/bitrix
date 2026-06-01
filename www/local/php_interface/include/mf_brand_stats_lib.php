<?php

declare(strict_types=1);

/**
 * Статистика брендов каталога: число товаров по значению MF_BRAND.
 */

if (!function_exists('mf_bss_catalog_iblock_id'))
{
	function mf_bss_catalog_iblock_id(): int
	{
		if (function_exists('mf_cud_catalog_iblock_id'))
		{
			return mf_cud_catalog_iblock_id();
		}

		return 4;
	}
}

if (!function_exists('mf_bss_load_brand_product_counts'))
{
	/**
	 * @param array{
	 *   active_only?: bool,
	 *   exportable_only?: bool,
	 *   brand_substr?: string
	 * } $opts
	 * @return array{
	 *   rows: list<array{brand: string, cnt: int}>,
	 *   total_products: int,
	 *   total_brands: int,
	 *   iblock_id: int,
	 *   error: string
	 * }
	 */
	function mf_bss_load_brand_product_counts(array $opts = []): array
	{
		$activeOnly = !array_key_exists('active_only', $opts) || (bool)$opts['active_only'];
		$exportableOnly = !array_key_exists('exportable_only', $opts) || (bool)$opts['exportable_only'];
		$brandSubstr = trim((string)($opts['brand_substr'] ?? ''));

		$iblockId = mf_bss_catalog_iblock_id();
		$empty = [
			'rows' => [],
			'total_products' => 0,
			'total_brands' => 0,
			'iblock_id' => $iblockId,
			'error' => '',
		];

		if ($iblockId <= 0)
		{
			$empty['error'] = 'Некорректный IBLOCK_ID каталога.';

			return $empty;
		}

		if (!function_exists('mf_cud_iblock_property_meta'))
		{
			$lib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_catalog_uniq_duplicates_lib.php';
			if (!is_file($lib))
			{
				$lib = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_catalog_uniq_duplicates_lib.php';
			}
			if (is_file($lib))
			{
				require_once $lib;
			}
		}

		if (!function_exists('mf_ce_sql_and_exportable_element') || !function_exists('mf_ce_sql_and_active_if'))
		{
			$inc = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_ce_brand_choices_inc.php';
			if (!is_file($inc))
			{
				$inc = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_ce_brand_choices_inc.php';
			}
			if (is_file($inc))
			{
				require_once $inc;
			}
		}

		if (!function_exists('mf_cud_iblock_property_meta'))
		{
			$empty['error'] = 'Не найден mf_catalog_uniq_duplicates_lib.php';

			return $empty;
		}

		$meta = mf_cud_iblock_property_meta($iblockId);
		$brandPid = (int)($meta['brand_prop_id'] ?? 0);
		if ($brandPid <= 0)
		{
			$empty['error'] = 'В инфоблоке ' . $iblockId . ' не найдено свойство MF_BRAND.';

			return $empty;
		}

		global $DB;
		if (!isset($DB) || !is_object($DB))
		{
			$empty['error'] = 'DB недоступен.';

			return $empty;
		}

		$act = function_exists('mf_ce_sql_and_active_if') && $activeOnly
			? mf_ce_sql_and_active_if(true, 'e')
			: '';
		$exElAnd = function_exists('mf_ce_sql_and_exportable_element') && $exportableOnly
			? mf_ce_sql_and_exportable_element('e', $iblockId)
			: '';

		$brandFilter = '';
		if ($brandSubstr !== '')
		{
			$brandFilter = " AND TRIM(BRAND) LIKE '%" . $DB->ForSql($brandSubstr) . "%' ";
		}

		$version = (int)($meta['version'] ?? 1);
		if ($version === 2)
		{
			$sql = "
				SELECT TRIM(s.PROPERTY_{$brandPid}) AS BRAND,
					COUNT(DISTINCT s.IBLOCK_ELEMENT_ID) AS CNT
				FROM b_iblock_element_prop_s{$iblockId} s
				INNER JOIN b_iblock_element e
					ON e.ID = s.IBLOCK_ELEMENT_ID AND e.IBLOCK_ID = {$iblockId}
				WHERE TRIM(COALESCE(s.PROPERTY_{$brandPid}, '')) <> ''
					{$act}{$exElAnd}
				GROUP BY TRIM(s.PROPERTY_{$brandPid})
			";
		}
		else
		{
			$sql = "
				SELECT TRIM(p.VALUE) AS BRAND,
					COUNT(DISTINCT p.IBLOCK_ELEMENT_ID) AS CNT
				FROM b_iblock_element_property p
				STRAIGHT_JOIN b_iblock_element e
					ON e.ID = p.IBLOCK_ELEMENT_ID AND e.IBLOCK_ID = {$iblockId}
				WHERE p.IBLOCK_PROPERTY_ID = {$brandPid}
					AND p.VALUE IS NOT NULL
					AND TRIM(p.VALUE) <> ''
					{$act}{$exElAnd}
				GROUP BY TRIM(p.VALUE)
			";
		}

		$sql = "
			SELECT BRAND, CNT
			FROM ({$sql}) t
			WHERE 1=1 {$brandFilter}
			ORDER BY BRAND ASC
		";

		$q = $DB->Query($sql);
		if (!$q)
		{
			$empty['error'] = 'Ошибка SQL при подсчёте брендов.';

			return $empty;
		}

		$rows = [];
		$totalProducts = 0;
		while ($r = $q->Fetch())
		{
			$brand = trim((string)($r['BRAND'] ?? ''));
			if ($brand === '')
			{
				continue;
			}
			$cnt = (int)($r['CNT'] ?? 0);
			$rows[] = ['brand' => $brand, 'cnt' => $cnt];
			$totalProducts += $cnt;
		}

		return [
			'rows' => $rows,
			'total_products' => $totalProducts,
			'total_brands' => count($rows),
			'iblock_id' => $iblockId,
			'error' => '',
		];
	}
}
