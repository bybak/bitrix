<?php
/**
 * Быстрый поиск по каталогу (IBLOCK) без CSearch.
 * Артикулы — прямой SQL (b_iblock_element_prop_s{N}), текст — только NAME.
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

if (!function_exists('mf_catalog_search_meta'))
{
	/**
	 * @return array{version: int, props: array<string, int>}
	 */
	function mf_catalog_search_meta(int $iblockId = 0): array
	{
		$iblockId = $iblockId > 0 ? $iblockId : mf_catalog_search_iblock_id();
		static $mem = [];
		if (isset($mem[$iblockId]))
		{
			return $mem[$iblockId];
		}

		if (class_exists(\Bitrix\Main\Data\Cache::class))
		{
			$cache = \Bitrix\Main\Data\Cache::createInstance();
			if ($cache->initCache(86400, 'meta_' . $iblockId, '/mf/catalog_search_meta'))
			{
				$vars = $cache->getVars();
				if (is_array($vars) && isset($vars['version'], $vars['props']))
				{
					return $mem[$iblockId] = $vars;
				}
			}
		}

		$version = 1;
		if (class_exists('CIBlock'))
		{
			$ibRow = \CIBlock::GetByID($iblockId)->Fetch();
			if (is_array($ibRow))
			{
				$version = (int)($ibRow['VERSION'] ?? 1);
			}
		}

		$props = [];
		if (class_exists('CIBlockProperty'))
		{
			foreach (['CML2_ARTICLE', 'MF_ARTICLE_NORM', 'MF_BRAND', 'MF_BRAND_NORM'] as $code)
			{
				$p = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code])->Fetch();
				if (is_array($p))
				{
					$id = (int)($p['ID'] ?? 0);
					if ($id > 0)
					{
						$props[$code] = $id;
					}
				}
			}
		}

		$meta = ['version' => $version, 'props' => $props];
		if (isset($cache) && $cache->startDataCache())
		{
			$cache->endDataCache($meta);
		}

		return $mem[$iblockId] = $meta;
	}
}

if (!function_exists('mf_catalog_search_sql_in_strings'))
{
	/**
	 * @param string[] $values
	 */
	function mf_catalog_search_sql_in_strings(array $values): string
	{
		if (!class_exists(\Bitrix\Main\Application::class))
		{
			return "''";
		}
		$helper = \Bitrix\Main\Application::getConnection()->getSqlHelper();
		$parts = [];
		foreach ($values as $value)
		{
			$value = trim((string)$value);
			if ($value === '')
			{
				continue;
			}
			$parts[] = "'" . $helper->forSql($value) . "'";
		}
		if (empty($parts))
		{
			return "''";
		}

		return implode(',', $parts);
	}
}

if (!function_exists('mf_catalog_search_sql_ids'))
{
	/**
	 * @return int[]
	 */
	function mf_catalog_search_sql_ids(string $sql): array
	{
		if (!class_exists(\Bitrix\Main\Application::class))
		{
			return [];
		}
		$ids = [];
		try
		{
			$res = \Bitrix\Main\Application::getConnection()->query($sql);
			while ($row = $res->fetch())
			{
				$id = (int)($row['ID'] ?? 0);
				if ($id > 0)
				{
					$ids[] = $id;
				}
			}
		}
		catch (\Throwable $e)
		{
			return [];
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
			[
				'IBLOCK_ID' => mf_catalog_search_iblock_id(),
				'ID' => $ids,
				'ACTIVE' => 'Y',
				'CHECK_PERMISSIONS' => 'N',
			],
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

if (!function_exists('mf_catalog_search_article_values'))
{
	/**
	 * @param string[] $candidates
	 * @return string[]
	 */
	function mf_catalog_search_article_values(array $candidates): array
	{
		$seen = [];
		$values = [];
		$push = static function (string $value) use (&$seen, &$values): void {
			$value = trim($value);
			if ($value === '' || isset($seen[$value]))
			{
				return;
			}
			$seen[$value] = true;
			$values[] = $value;
		};

		foreach ($candidates as $candidate)
		{
			$push((string)$candidate);
			if (function_exists('mf_analogs_norm_article'))
			{
				$norm = mf_analogs_norm_article((string)$candidate);
				if ($norm !== '')
				{
					$push($norm);
				}
			}
		}

		return $values;
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
		$values = mf_catalog_search_article_values($candidates);
		if (empty($values))
		{
			return [];
		}

		$iblockId = mf_catalog_search_iblock_id();
		$meta = mf_catalog_search_meta($iblockId);
		$version = (int)($meta['version'] ?? 1);
		$props = (array)($meta['props'] ?? []);
		$articlePid = (int)($props['CML2_ARTICLE'] ?? 0);
		$normPid = (int)($props['MF_ARTICLE_NORM'] ?? 0);
		if ($articlePid <= 0 && $normPid <= 0)
		{
			return [];
		}

		$inValues = mf_catalog_search_sql_in_strings($values);
		$conds = [];
		if ($version === 2)
		{
			if ($articlePid > 0)
			{
				$conds[] = 's.PROPERTY_' . $articlePid . ' IN (' . $inValues . ')';
			}
			if ($normPid > 0)
			{
				$conds[] = 's.PROPERTY_' . $normPid . ' IN (' . $inValues . ')';
			}
			if (empty($conds))
			{
				return [];
			}

			$sql = '
				SELECT DISTINCT s.IBLOCK_ELEMENT_ID AS ID
				FROM b_iblock_element_prop_s' . $iblockId . ' s
				INNER JOIN b_iblock_element e ON e.ID = s.IBLOCK_ELEMENT_ID
				WHERE e.IBLOCK_ID = ' . $iblockId . "
					AND e.ACTIVE = 'Y'
					AND (" . implode(' OR ', $conds) . ')
				ORDER BY s.IBLOCK_ELEMENT_ID
				LIMIT 120';
		}
		else
		{
			$pids = array_values(array_filter([$articlePid, $normPid]));
			if (empty($pids))
			{
				return [];
			}

			$sql = '
				SELECT DISTINCT p.IBLOCK_ELEMENT_ID AS ID
				FROM b_iblock_element_property p
				INNER JOIN b_iblock_element e ON e.ID = p.IBLOCK_ELEMENT_ID
				WHERE e.IBLOCK_ID = ' . $iblockId . "
					AND e.ACTIVE = 'Y'
					AND p.IBLOCK_PROPERTY_ID IN (" . implode(',', $pids) . ')
					AND p.VALUE IN (' . $inValues . ')
				ORDER BY p.IBLOCK_ELEMENT_ID
				LIMIT 120';
		}

		return mf_catalog_search_sql_ids($sql);
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

if (!function_exists('mf_catalog_search_ids_by_brand_words'))
{
	/**
	 * Точное совпадение бренда (BRP, UNV …) — быстрее, чем LIKE по NAME.
	 *
	 * @param string[] $words
	 * @return int[]
	 */
	function mf_catalog_search_ids_by_brand_words(array $words, int $limit = 80): array
	{
		if (empty($words))
		{
			return [];
		}

		$iblockId = mf_catalog_search_iblock_id();
		$meta = mf_catalog_search_meta($iblockId);
		$version = (int)($meta['version'] ?? 1);
		$props = (array)($meta['props'] ?? []);
		$brandPid = (int)($props['MF_BRAND'] ?? 0);
		$brandNormPid = (int)($props['MF_BRAND_NORM'] ?? 0);
		if ($brandPid <= 0 && $brandNormPid <= 0)
		{
			return [];
		}

		$values = [];
		foreach ($words as $word)
		{
			$word = trim((string)$word);
			if ($word === '')
			{
				continue;
			}
			$values[] = $word;
			if (function_exists('mf_analogs_norm_brand'))
			{
				$norm = mf_analogs_norm_brand($word);
				if ($norm !== '' && $norm !== $word)
				{
					$values[] = $norm;
				}
			}
		}
		$values = array_values(array_unique($values));
		if (empty($values))
		{
			return [];
		}

		$inValues = mf_catalog_search_sql_in_strings($values);
		$conds = [];
		if ($version === 2)
		{
			if ($brandPid > 0)
			{
				$conds[] = 's.PROPERTY_' . $brandPid . ' IN (' . $inValues . ')';
			}
			if ($brandNormPid > 0)
			{
				$conds[] = 's.PROPERTY_' . $brandNormPid . ' IN (' . $inValues . ')';
			}
			if (empty($conds))
			{
				return [];
			}

			$sql = '
				SELECT DISTINCT s.IBLOCK_ELEMENT_ID AS ID
				FROM b_iblock_element_prop_s' . $iblockId . ' s
				INNER JOIN b_iblock_element e ON e.ID = s.IBLOCK_ELEMENT_ID
				WHERE e.IBLOCK_ID = ' . $iblockId . "
					AND e.ACTIVE = 'Y'
					AND (" . implode(' OR ', $conds) . ')
				ORDER BY s.IBLOCK_ELEMENT_ID
				LIMIT ' . (int)$limit;
		}
		else
		{
			$pids = array_values(array_filter([$brandPid, $brandNormPid]));
			if (empty($pids))
			{
				return [];
			}

			$sql = '
				SELECT DISTINCT p.IBLOCK_ELEMENT_ID AS ID
				FROM b_iblock_element_property p
				INNER JOIN b_iblock_element e ON e.ID = p.IBLOCK_ELEMENT_ID
				WHERE e.IBLOCK_ID = ' . $iblockId . "
					AND e.ACTIVE = 'Y'
					AND p.IBLOCK_PROPERTY_ID IN (" . implode(',', $pids) . ')
					AND p.VALUE IN (' . $inValues . ')
				ORDER BY p.IBLOCK_ELEMENT_ID
				LIMIT ' . (int)$limit;
		}

		return mf_catalog_search_sql_ids($sql);
	}
}

if (!function_exists('mf_catalog_search_ids_by_text'))
{
	/**
	 * @return int[]
	 */
	function mf_catalog_search_ids_by_text(string $query, int $limit = 80): array
	{
		$query = trim($query);
		if ($query === '' || mb_strlen($query) < 2 || !class_exists(\Bitrix\Main\Application::class))
		{
			return [];
		}

		$words = mf_catalog_search_text_words($query);
		if (empty($words))
		{
			return [];
		}

		$brandIds = mf_catalog_search_ids_by_brand_words($words, $limit);
		if (!empty($brandIds) && count($words) === 1 && mb_strlen($words[0]) <= 6)
		{
			return $brandIds;
		}

		$helper = \Bitrix\Main\Application::getConnection()->getSqlHelper();
		$iblockId = mf_catalog_search_iblock_id();
		$where = ["e.IBLOCK_ID = {$iblockId}", "e.ACTIVE = 'Y'"];
		foreach ($words as $word)
		{
			$where[] = "e.NAME LIKE '%" . $helper->forSql($word) . "%'";
		}

		$sql = '
			SELECT e.ID
			FROM b_iblock_element e
			WHERE ' . implode(' AND ', $where) . '
			ORDER BY e.NAME
			LIMIT ' . (int)$limit;

		$ids = mf_catalog_search_sql_ids($sql);
		if (empty($ids))
		{
			return $brandIds;
		}

		$seen = [];
		$merged = [];
		foreach (array_merge($brandIds, $ids) as $id)
		{
			$id = (int)$id;
			if ($id > 0 && !isset($seen[$id]))
			{
				$seen[$id] = true;
				$merged[] = $id;
			}
		}

		return $merged;
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
			$cacheId = 'ids_v3_' . $key;
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
			foreach (mf_catalog_search_ids_by_text($query, 80) as $id)
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
	 * @return array{items: array, total: int, page: int, pageSize: int, nav_string: string, engine: string, ms: float}
	 */
	function mf_catalog_search_page(string $query, int $page, int $pageSize, int $iblockId = 4): array
	{
		unset($iblockId);
		$t0 = microtime(true);
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
				'engine' => 'catalog-v3-sql',
				'ms' => 0.0,
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
			'engine' => 'catalog-v3-sql',
			'ms' => round((microtime(true) - $t0) * 1000, 1),
		];
	}
}
