<?php
/**
 * Быстрый поиск по каталогу (IBLOCK) без CSearch.
 */

if (!function_exists('mf_catalog_search_iblock_id'))
{
	function mf_catalog_search_iblock_id(): int
	{
		return 4;
	}
}

if (!function_exists('mf_catalog_search_select'))
{
	function mf_catalog_search_select(): array
	{
		return [
			'ID',
			'NAME',
			'CODE',
			'PROPERTY_CML2_ARTICLE',
			'PROPERTY_MF_BRAND',
			'PROPERTY_MF_BRAND_NORM',
			'PROPERTY_OEM',
			'PROPERTY_MF_EXT_IMAGES',
		];
	}
}

if (!function_exists('mf_catalog_search_row_to_item'))
{
	function mf_catalog_search_row_to_item(array $row): array
	{
		$id = (int)($row['ID'] ?? 0);
		$name = (string)($row['NAME'] ?? '');
		$code = trim((string)($row['CODE'] ?? ''));
		$url = ($code !== '' ? '/products/' . rawurlencode($code) . '/' : '');

		return [
			'MODULE_ID' => 'iblock',
			'ITEM_ID' => $id,
			'PARAM2' => mf_catalog_search_iblock_id(),
			'TITLE' => $name,
			'TITLE_FORMATED' => htmlspecialcharsbx($name),
			'URL' => $url,
			'BODY_FORMATED' => '',
			'_prefetch' => $row,
		];
	}
}

if (!function_exists('mf_catalog_search_fetch_by_filter'))
{
	/**
	 * @return array<int, array> id => iblock row
	 */
	function mf_catalog_search_fetch_by_filter(array $filter, int $limit = 200): array
	{
		if (!class_exists('CIBlockElement') || $limit <= 0)
		{
			return [];
		}

		$out = [];
		$rs = \CIBlockElement::GetList(
			['NAME' => 'ASC', 'ID' => 'ASC'],
			$filter,
			false,
			['nTopCount' => $limit],
			mf_catalog_search_select()
		);
		while ($row = $rs->Fetch())
		{
			$id = (int)($row['ID'] ?? 0);
			if ($id > 0)
			{
				$out[$id] = $row;
			}
		}

		return $out;
	}
}

if (!function_exists('mf_catalog_search_ids_by_articles'))
{
	/**
	 * @param string[] $candidates
	 * @return array<int, array>
	 */
	function mf_catalog_search_ids_by_articles(array $candidates): array
	{
		$candidates = array_values(array_unique(array_filter(array_map('strval', $candidates))));
		if (empty($candidates))
		{
			return [];
		}

		$or = [];
		$seen = [];
		foreach ($candidates as $candidate)
		{
			$candidate = trim($candidate);
			if ($candidate === '' || isset($seen[$candidate]))
			{
				continue;
			}
			$seen[$candidate] = true;
			$or[] = ['=PROPERTY_CML2_ARTICLE' => $candidate];

			if (function_exists('mf_analogs_norm_article'))
			{
				$norm = mf_analogs_norm_article($candidate);
				if ($norm !== '' && $norm !== $candidate && !isset($seen[$norm]))
				{
					$seen[$norm] = true;
					$or[] = ['=PROPERTY_MF_ARTICLE_NORM' => $norm];
				}
			}
		}
		if (empty($or))
		{
			return [];
		}

		return mf_catalog_search_fetch_by_filter([
			'IBLOCK_ID' => mf_catalog_search_iblock_id(),
			'ACTIVE' => 'Y',
			'CHECK_PERMISSIONS' => 'Y',
			'MIN_PERMISSION' => 'R',
			array_merge(['LOGIC' => 'OR'], $or),
		], 120);
	}
}

if (!function_exists('mf_catalog_search_ids_by_text'))
{
	/**
	 * @return array<int, array>
	 */
	function mf_catalog_search_ids_by_text(string $query, int $limit = 300): array
	{
		$query = trim($query);
		if ($query === '' || mb_strlen($query) < 2)
		{
			return [];
		}

		$words = preg_split('~[\s,;+/|()\\[\\]{}]+~u', $query) ?: [];
		$words = array_values(array_filter(array_map(static function ($w) {
			$w = trim((string)$w);
			return mb_strlen($w) >= 2 ? $w : '';
		}, $words)));

		$or = [
			['%NAME' => $query],
			['%PROPERTY_CML2_ARTICLE' => $query],
			['%PROPERTY_MF_BRAND' => $query],
			['%PROPERTY_MF_BRAND_NORM' => $query],
			['%PROPERTY_OEM' => $query],
		];
		foreach ($words as $word)
		{
			$or[] = ['%NAME' => $word];
			$or[] = ['%PROPERTY_CML2_ARTICLE' => $word];
			$or[] = ['%PROPERTY_MF_BRAND' => $word];
		}

		return mf_catalog_search_fetch_by_filter([
			'IBLOCK_ID' => mf_catalog_search_iblock_id(),
			'ACTIVE' => 'Y',
			'CHECK_PERMISSIONS' => 'Y',
			'MIN_PERMISSION' => 'R',
			array_merge(['LOGIC' => 'OR'], $or),
		], $limit);
	}
}

if (!function_exists('mf_catalog_search_collect_ids'))
{
	/**
	 * @return array{rows: array<int, array>, total: int}
	 */
	function mf_catalog_search_collect_ids(string $query): array
	{
		$query = trim($query);
		if ($query === '')
		{
			return ['rows' => [], 'total' => 0];
		}

		static $runtime = [];
		$key = md5(mb_strtolower($query));
		if (isset($runtime[$key]))
		{
			return $runtime[$key];
		}

		if (class_exists(\Bitrix\Main\Data\Cache::class))
		{
			$cache = \Bitrix\Main\Data\Cache::createInstance();
			$cacheId = 'ids_v1_' . $key;
			$cacheDir = '/mf/catalog_search';
			if ($cache->initCache(900, $cacheId, $cacheDir))
			{
				$vars = $cache->getVars();
				if (is_array($vars) && isset($vars['rows'], $vars['total']))
				{
					return $runtime[$key] = $vars;
				}
			}
		}

		$lib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_import_analogs_lib.php';
		if (is_file($lib))
		{
			require_once $lib;
		}

		$articleRows = [];
		if (function_exists('mf_search_query_article_candidates'))
		{
			$articleRows = mf_catalog_search_ids_by_articles(mf_search_query_article_candidates($query));
		}

		$textRows = mf_catalog_search_ids_by_text($query, 300);

		$merged = [];
		foreach ($articleRows as $id => $row)
		{
			$merged[(int)$id] = $row;
		}
		foreach ($textRows as $id => $row)
		{
			if (!isset($merged[(int)$id]))
			{
				$merged[(int)$id] = $row;
			}
		}

		$result = [
			'rows' => $merged,
			'total' => count($merged),
		];

		if (isset($cache) && $cache->startDataCache())
		{
			$cache->endDataCache($result);
		}

		return $runtime[$key] = $result;
	}
}

if (!function_exists('mf_catalog_search_build_nav'))
{
	function mf_catalog_search_build_nav(int $page, int $pageSize, int $total, string $query): string
	{
		if ($total <= $pageSize || $pageSize <= 0)
		{
			return '';
		}
		$pages = (int)ceil($total / $pageSize);
		if ($pages <= 1)
		{
			return '';
		}

		$base = '/search/?q=' . urlencode($query);
		$parts = [];
		$mk = static function (int $p, string $label, bool $active = false) use ($base): string {
			if ($active)
			{
				return '<span class="mf-search-pager__current">' . htmlspecialcharsbx($label) . '</span>';
			}
			$href = $base . ($p > 1 ? '&PAGEN_1=' . $p : '');

			return '<a class="mf-search-pager__link" href="' . htmlspecialcharsbx($href) . '">' . htmlspecialcharsbx($label) . '</a>';
		};

		if ($page > 1)
		{
			$parts[] = $mk($page - 1, '←');
		}
		for ($p = 1; $p <= $pages; $p++)
		{
			if ($pages > 9 && abs($p - $page) > 2 && $p !== 1 && $p !== $pages)
			{
				continue;
			}
			$parts[] = $mk($p, (string)$p, $p === $page);
		}
		if ($page < $pages)
		{
			$parts[] = $mk($page + 1, '→');
		}

		return '<div class="mf-search-pager">' . implode(' ', $parts) . '</div>';
	}
}

if (!function_exists('mf_catalog_search_page'))
{
	/**
	 * @return array{items: array, total: int, page: int, pageSize: int, nav_string: string}
	 */
	function mf_catalog_search_page(string $query, int $page, int $pageSize, int $iblockId = 4): array
	{
		unset($iblockId);
		$query = trim($query);
		$page = max(1, $page);
		$pageSize = max(1, min(50, $pageSize));

		if ($query === '')
		{
			return [
				'items' => [],
				'total' => 0,
				'page' => $page,
				'pageSize' => $pageSize,
				'nav_string' => '',
			];
		}

		$collected = mf_catalog_search_collect_ids($query);
		$allRows = (array)($collected['rows'] ?? []);
		$total = (int)($collected['total'] ?? count($allRows));
		$offset = ($page - 1) * $pageSize;
		$slice = array_slice(array_values($allRows), $offset, $pageSize, true);

		$items = [];
		foreach ($slice as $row)
		{
			if (!is_array($row))
			{
				continue;
			}
			$items[] = mf_catalog_search_row_to_item($row);
		}

		return [
			'items' => $items,
			'total' => $total,
			'page' => $page,
			'pageSize' => $pageSize,
			'nav_string' => mf_catalog_search_build_nav($page, $pageSize, $total, $query),
		];
	}
}
