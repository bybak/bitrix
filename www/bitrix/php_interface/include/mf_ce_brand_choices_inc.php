<?php

declare(strict_types=1);

/**
 * Общая логика списка брендов для фильтра выгрузки каталога и др. админок.
 * Не привязана к конкретной странице (без ADMIN_SECTION).
 */

if (!function_exists('mf_ce_brand_property_ids'))
{
	/**
	 * ID свойств MF_BRAND / MF_BRAND_NORM в инфоблоке.
	 *
	 * @return list<int>
	 */
	function mf_ce_brand_property_ids(int $iblockId): array
	{
		$iblockId = (int)$iblockId;
		if ($iblockId <= 0)
		{
			return [];
		}

		$propIds = [];
		foreach (['MF_BRAND', 'MF_BRAND_NORM'] as $propCode)
		{
			$rs = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $propCode]);
			if ($p = $rs->Fetch())
			{
				$id = (int)($p['ID'] ?? 0);
				if ($id > 0)
				{
					$propIds[$id] = true;
				}
			}
		}

		return array_keys($propIds);
	}
}

if (!function_exists('mf_ce_sql_bool_exportable_element'))
{
	/**
	 * Условие «элемент выгружаемый» (как в выгрузке): workflow + не редирект.
	 * Возвращает выражение для SQL (без ведущего AND), в скобках.
	 */
	function mf_ce_sql_bool_exportable_element(string $eAlias, int $iblockId): string
	{
		$iblockId = (int)$iblockId;
		$eAlias = preg_replace('~[^A-Za-z0-9_]+~', '', $eAlias) ?: 'e';
		$parts = [
			"({$eAlias}.WF_STATUS_ID = 1)",
			"({$eAlias}.WF_PARENT_ELEMENT_ID IS NULL)",
		];

		$rs = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => 'MF_IS_REDIRECT']);
		if ($p = $rs->Fetch())
		{
			$redirectPid = (int)($p['ID'] ?? 0);
			if ($redirectPid > 0)
			{
				$parts[] = "NOT EXISTS (
				SELECT 1 FROM b_iblock_element_property prd
				WHERE prd.IBLOCK_ELEMENT_ID = {$eAlias}.ID
				AND prd.IBLOCK_PROPERTY_ID = {$redirectPid}
				AND prd.VALUE = 'Y'
			)";
			}
		}

		return '(' . implode(' AND ', $parts) . ')';
	}
}

if (!function_exists('mf_ce_sql_and_exportable_element'))
{
	/**
	 * Фрагмент AND … для JOIN к `b_iblock_element` с алиасом $eAlias.
	 */
	function mf_ce_sql_and_exportable_element(string $eAlias, int $iblockId): string
	{
		return ' AND ' . mf_ce_sql_bool_exportable_element($eAlias, $iblockId);
	}
}

if (!function_exists('mf_ce_sql_and_active_if'))
{
	/**
	 * Учёт галочки «только активные»: должно совпадать с фильтром GetList при выгрузке.
	 */
	function mf_ce_sql_and_active_if(bool $onlyActive, string $eAlias = 'e'): string
	{
		if (!$onlyActive)
		{
			return '';
		}
		$eAlias = preg_replace('~[^A-Za-z0-9_]+~', '', $eAlias) ?: 'e';

		return " AND ({$eAlias}.ACTIVE = 'Y')";
	}
}

if (!function_exists('mf_ce_brands_only_on_non_exportable_elements'))
{
	/**
	 * Значения MF_BRAND/MF_BRAND_NORM, у которых ни у одного выгружаемого элемента нет строки с этим значением,
	 * но у других (редирект, копии workflow и т.д.) — есть. Для поиска «мусорных» хвостов в свойствах.
	 *
	 * @return list<array{brand: string, cnt_any: int}>
	 */
	function mf_ce_brands_only_on_non_exportable_elements(int $iblockId, int $limit = 500): array
	{
		global $DB;

		$iblockId = (int)$iblockId;
		$limit = max(1, min(5000, $limit));
		if ($iblockId <= 0)
		{
			return [];
		}

		$propIds = mf_ce_brand_property_ids($iblockId);
		if ($propIds === [])
		{
			return [];
		}

		$in = implode(',', $propIds);
		$expBool = mf_ce_sql_bool_exportable_element('e', $iblockId);
		$sql = "
		SELECT TRIM(p.VALUE) AS V,
			COUNT(DISTINCT p.IBLOCK_ELEMENT_ID) AS CNT_ANY,
			COUNT(DISTINCT CASE WHEN {$expBool} THEN p.IBLOCK_ELEMENT_ID END) AS CNT_EXPORTABLE
		FROM b_iblock_element_property p
		INNER JOIN b_iblock_element e ON e.ID = p.IBLOCK_ELEMENT_ID AND e.IBLOCK_ID = {$iblockId}
		WHERE p.IBLOCK_PROPERTY_ID IN ({$in})
			AND p.VALUE IS NOT NULL
			AND TRIM(p.VALUE) <> ''
		GROUP BY TRIM(p.VALUE)
		HAVING CNT_EXPORTABLE = 0
		ORDER BY CNT_ANY DESC, V
		LIMIT {$limit}
	";

		$q = $DB->Query($sql);
		if (!$q)
		{
			return [];
		}

		$out = [];
		while ($r = $q->Fetch())
		{
			$v = trim((string)($r['V'] ?? ''));
			if ($v === '')
			{
				continue;
			}
			$out[] = [
				'brand' => $v,
				'cnt_any' => (int)($r['CNT_ANY'] ?? 0),
			];
		}

		return $out;
	}
}

if (!function_exists('mf_ce_load_brand_choices'))
{
	/**
	 * Список непустых значений MF_BRAND / MF_BRAND_NORM для выпадающего списка фильтра.
	 * По умолчанию только у активных элементов — как у формы с включённой галочкой «только активные».
	 *
	 * @return list<string>
	 */
	function mf_ce_load_brand_choices(int $iblockId, bool $onlyActiveBrands = true): array
	{
		global $DB;

		$iblockId = (int)$iblockId;
		if ($iblockId <= 0)
		{
			return [];
		}

		$propIds = mf_ce_brand_property_ids($iblockId);
		if ($propIds === [])
		{
			return [];
		}

		$in = implode(',', $propIds);
		$exEl = mf_ce_sql_and_exportable_element('e', $iblockId);
		$act = mf_ce_sql_and_active_if($onlyActiveBrands, 'e');
		$sql = "
		SELECT DISTINCT TRIM(p.VALUE) AS V
		FROM b_iblock_element_property p
		INNER JOIN b_iblock_element e ON e.ID = p.IBLOCK_ELEMENT_ID AND e.IBLOCK_ID = {$iblockId}
		WHERE p.IBLOCK_PROPERTY_ID IN ({$in})
			AND p.VALUE IS NOT NULL
			AND TRIM(p.VALUE) <> ''
			{$exEl}{$act}
	";

		$q = $DB->Query($sql);
		if (!$q)
		{
			return [];
		}

		$seen = [];
		while ($r = $q->Fetch())
		{
			$v = trim((string)($r['V'] ?? ''));
			if ($v === '')
			{
				continue;
			}
			$seen[$v] = true;
		}

		$out = array_keys($seen);
		natcasesort($out);

		return array_values($out);
	}
}

if (!function_exists('mf_ce_element_ids_for_brand_value'))
{
	/**
	 * Элементы, у которых в MF_BRAND или MF_BRAND_NORM после TRIM совпадает значение с выбранным в списке.
	 * Так же, как DISTINCT в mf_ce_load_brand_choices — без расхождений из-за пробелов в БД и без OR-фильтра GetList.
	 *
	 * @return list<int>
	 */
	function mf_ce_element_ids_for_brand_value(int $iblockId, string $brand, bool $onlyActiveBrands = true): array
	{
		global $DB;

		$iblockId = (int)$iblockId;
		$brand = trim($brand);
		if ($iblockId <= 0 || $brand === '')
		{
			return [];
		}

		$propIds = mf_ce_brand_property_ids($iblockId);
		if ($propIds === [])
		{
			return [];
		}

		$in = implode(',', $propIds);
		$b = $DB->ForSql($brand);
		$exEl = mf_ce_sql_and_exportable_element('e', $iblockId);
		$act = mf_ce_sql_and_active_if($onlyActiveBrands, 'e');
		$sql = "
		SELECT DISTINCT p.IBLOCK_ELEMENT_ID AS ID
		FROM b_iblock_element_property p
		INNER JOIN b_iblock_element e ON e.ID = p.IBLOCK_ELEMENT_ID AND e.IBLOCK_ID = {$iblockId}
		WHERE p.IBLOCK_PROPERTY_ID IN ({$in})
			AND TRIM(p.VALUE) = TRIM('{$b}')
			{$exEl}{$act}
	";

		$q = $DB->Query($sql);
		if (!$q)
		{
			return [];
		}

		$out = [];
		while ($r = $q->Fetch())
		{
			$id = (int)($r['ID'] ?? 0);
			if ($id > 0)
			{
				$out[$id] = true;
			}
		}

		return array_keys($out);
	}
}
