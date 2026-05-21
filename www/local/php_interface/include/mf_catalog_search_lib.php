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

if (!function_exists('mf_catalog_search_base_filter'))
{
	function mf_catalog_search_base_filter(): array
	{
		return [
			'IBLOCK_ID' => mf_catalog_search_iblock_id(),
			'ACTIVE' => 'Y',
			'CHECK_PERMISSIONS' => 'Y',
			'MIN_PERMISSION' => 'R',
		];
	}
}

if (!function_exists('mf_catalog_search_fetch_ids_by_filter'))
{
	/**
	 * @return int[]
	 */
	function mf_catalog_search_fetch_ids_by_filter(array $filter, int $limit = 200): array
	{
		if (!class_exists('CIBlockElement') || $limit <= 0)
		{
			return [];
		}

		$ids = [];
		$rs = \CIBlockElement::GetList(
			['ID' => 'ASC'],
			$filter,
			false,
			['nTopCount' => $limit],
			['ID']
		);
		while ($row = $rs->Fetch())
		{
			$id = (int)($row['ID'] ?? 0);
			if ($id > 0)
			{
				$ids[] = $id;
			}
		}

		return $ids;
	}
}

if (!function_exists('mf_catalog_search_fetch_rows_by_ids'))
{
	/**
	 * @param int[] $ids
	 * @return array<int, array>
	 */
	function mf_catalog_search_fetch_rows_by_ids(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		if (empty($ids) || !class_exists('CIBlockElement'))
		{
			return [];
		}

		$rows = [];
		$rs = \CIBlockElement::GetList(
			['ID' => 'ASC'],
			array_merge(mf_catalog_search_base_filter(), ['ID' => $ids]),
			false,
			false,
			mf_catalog_search_select()
		);
		while ($row = $rs->Fetch())
		{
			$id = (int)($row['ID'] ?? 0);
			if ($id > 0)
			{
				$rows[$id] = $row;
			}
		}

		$ordered = [];
		foreach ($ids as $id)
		{
			if (isset($rows[$id]))
			{
				$ordered[$id] = $rows[$id];
			}
		}

		return $ordered;
	}
}

if (!function_exists('mf_catalog_search_is_article_only_query'))
{
	function mf_catalog_search_is_article_only_query(string $query): bool
	{
		$query = trim($query);
		if ($query === '' || preg_match('~\s~u', $query))
		{
			return false;
		}

		return (bool)preg_match('~^[\dA-Za-z][\dA-Za-z\-_./]*$~', $query);
	}
}

if (!function_exists('mf_catalog_search_ids_by_articles'))
{
	/**
	 * @param string[] $candidates
	 * @return int[]
	 */
	function mf_catalog_search_ids_by_articles(array $candidates): array
	{
		$candidates = array_values(array_unique(array_filter(array_map('strval', $candidates))));
		if (empty($candidates))
		{
			return [];
		}

		$seen = [];
		$ids = [];
		$pushIds = static function (array $found) use (&$ids, &$seen): void {
			foreach ($found as $id)
			{
				$id = (int)$id;
				if ($id > 0 && !isset($seen[$id]))
				{
					$seen[$id] = true;
					$ids[] = $id;
				}
			}
		};

		foreach ($candidates as $candidate)
		{
			$candidate = trim($candidate);
			if ($candidate === '')
			{
				continue;
			}

			$or = [
				['=PROPERTY_CML2_ARTICLE' => $candidate],
			];
			if (function_exists('mf_analogs_norm_article'))
			{
				$norm = mf_analogs_norm_article($candidate);
				if ($norm !== '' && $norm !== $candidate)
				{
					$or[] = ['=PROPERTY_MF_ARTICLE_NORM' => $norm];
				}
			}

			$pushIds(mf_catalog_search_fetch_ids_by_filter(array_merge(
				mf_catalog_search_base_filter(),
				[count($or) === 1 ? $or[0] : array_merge(['LOGIC' => 'OR'], $or)]
			), 40));

			if (count($ids) >= 120)
			{
				break;
			}
		}

		return $ids;
	}
}

if (!function_exists('mf_catalog_search_text_words'))
{
	/**
	 * @return string[]
	 */
	function mf_catalog_search_text_words(string $query): array
	{
		$words = preg_split('~[\s,;+/|()\\[\\]{}]+~u', trim($query)) ?: [];
		$out = [];
		$seen = [];
		foreach ($words as $word)
		{
			$word = trim((string)$word);
			if ($word === '' || mb_strlen($word) < 2 || isset($seen[mb_strtolower($word)]))
			{
				continue;
			}
			$seen[mb_strtolower($word)] = true;
			$out[] = $word;
		}

		return $out;
	}
}

if (!function_exists('mf_catalog_search_ids_by_text'))
{
	/**
	 * Только по NAME — без LIKE по свойствам (они не индексируются и сканируют весь каталог).
	 *
	 * @return int[]
	 */
	function mf_catalog_search_ids_by_text(string $query, int $limit = 100): array
	{
		$query = trim($query);
		if ($query === '' || mb_strlen($query) < 2)
		{
			return [];
		}

		$words = mf_catalog_search_text_words($query);
		if (empty($words))
		{
			return [];
		}

		if (count($words) === 1)
		{
			return mf_catalog_search_fetch_ids_by_filter(array_merge(
				mf_catalog_search_base_filter(),
				['%NAME' => $words[0]]
			), $limit);
		}

		$nameFilter = ['LOGIC' => 'AND'];
		foreach ($words as $word)
		{
			$nameFilter[] = ['%NAME' => $word];
		}

		return mf_catalog_search_fetch_ids_by_filter(array_merge(
			mf_catalog_search_base_filter(),
			[$nameFilter]
		), $limit);
	}
}

if (!function_exists('mf_catalog_search_collect_ids'))
{
	/**
	 * @return array{ids: int[], total: int}
	 */
	function mf_catalog_search_collect_ids(string $query): array
	{
		$query = trim($query);
		if ($query === '')
		{
			return ['ids' => [], 'total' => 0];
		}

		static $runtime = [];
		$key = md5(mb_strtolower($query));
		if (isset($runtime[$key]))
		{
			return $runtime[$key];
		}

		$cache = null;
		if (class_exists(\Bitrix\Main\Data\Cache::class))
		{
			$cache = \Bitrix\Main\Data\Cache::createInstance();
			$cacheId = 'ids_v2_' . $key;
			$cacheDir = '/mf/catalog_search';
			if ($cache->initCache(900, $cacheId, $cacheDir))
			{
				$vars = $cache->getVars();
				if (is_array($vars) && isset($vars['ids'], $vars['total']))
				{
					return $runtime[$key] = [
						'ids' => array_values(array_map('intval', (array)$vars['ids'])),
						'total' => (int)$vars['total'],
					];
				}
			}
		}

		$lib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_import_analogs_lib.php';
		if (is_file($lib))
		{
			require_once $lib;
		}

		$articleIds = [];
		if (function_exists('mf_search_query_article_candidates'))
		{
			$articleIds = mf_catalog_search_ids_by_articles(mf_search_query_article_candidates($query));
		}

		$ids = $articleIds;
		$seen = [];
		foreach ($ids as $id)
		{
			$seen[(int)$id] = true;
		}

		$skipText = !empty($articleIds) || mf_catalog_search_is_article_only_query($query);
		if (!$skipText)
		{
			foreach (mf_catalog_search_ids_by_text($query, 100) as $id)
			{
				$id = (int)$id;
				if ($id > 0 && !isset($seen[$id]))
				{
					$seen[$id] = true;
					$ids[] = $id;
				}
			}
		}

		$result = [
			'ids' => $ids,
			'total' => count($ids),
		];

		if ($cache instanceof \Bitrix\Main\Data\Cache && $cache->startDataCache())
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
		$allIds = (array)($collected['ids'] ?? []);
		$total = (int)($collected['total'] ?? count($allIds));
		$offset = ($page - 1) * $pageSize;
		$pageIds = array_slice($allIds, $offset, $pageSize);

		$items = [];
		foreach (mf_catalog_search_fetch_rows_by_ids($pageIds) as $row)
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
