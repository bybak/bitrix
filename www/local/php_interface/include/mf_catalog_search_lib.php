<?php
/**
 * Быстрый поиск по каталогу (IBLOCK) без CSearch.
 * Артикулы — прямой SQL (b_iblock_element_prop_s{N}), текст — NAME / свойства бренда и артикула.
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

if (!function_exists('mf_catalog_search_merge_unique_ids'))
{
	/**
	 * @param int[] $base
	 * @param int[] $add
	 * @return int[]
	 */
	function mf_catalog_search_merge_unique_ids(array $base, array $add): array
	{
		$seen = [];
		foreach ($base as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$seen[$id] = true;
			}
		}

		foreach ($add as $id)
		{
			$id = (int)$id;
			if ($id > 0 && !isset($seen[$id]))
			{
				$seen[$id] = true;
				$base[] = $id;
			}
		}

		return $base;
	}
}

if (!function_exists('mf_catalog_search_ensure_brand_dict'))
{
	function mf_catalog_search_ensure_brand_dict(): void
	{
		if (function_exists('mf_brand_find'))
		{
			return;
		}

		$dictFile = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/mf_brand_dict.php';
		if ($dictFile !== '' && is_file($dictFile))
		{
			require_once $dictFile;
		}
	}
}

if (!function_exists('mf_catalog_search_brand_sort_context'))
{
	/**
	 * Контекст приоритета брендов: канон из сопоставления vs исходный алиас.
	 *
	 * @return array{preferred: array<string, string>, aliases: array<string, true>}
	 */
	function mf_catalog_search_brand_sort_context(string $query): array
	{
		mf_catalog_search_ensure_brand_dict();

		$preferred = [];
		$aliases = [];
		if (!function_exists('mf_brand_find') || !function_exists('mf_brand_norm'))
		{
			return ['preferred' => $preferred, 'aliases' => $aliases];
		}

		$candidates = mf_catalog_search_text_words($query);
		$query = trim($query);
		if ($query !== '')
		{
			$candidates[] = $query;
		}

		$seen = [];
		foreach ($candidates as $candidate)
		{
			$candidate = trim((string)$candidate);
			if ($candidate === '' || isset($seen[mb_strtolower($candidate)]))
			{
				continue;
			}
			$seen[mb_strtolower($candidate)] = true;

			$canon = mf_brand_find($candidate, false);
			if ($canon === '')
			{
				continue;
			}

			$canonNorm = mf_brand_norm($canon);
			$aliasNorm = mf_brand_norm($candidate);
			if ($canonNorm !== '')
			{
				$preferred[$canonNorm] = $canon;
			}
			if ($aliasNorm !== '' && $aliasNorm !== $canonNorm)
			{
				$aliases[$aliasNorm] = true;
			}
		}

		return ['preferred' => $preferred, 'aliases' => $aliases];
	}
}

if (!function_exists('mf_catalog_search_brand_rank_for_product'))
{
	/**
	 * @param array{preferred: array<string, string>, aliases: array<string, true>} $ctx
	 */
	function mf_catalog_search_brand_rank_for_product(string $brandNorm, array $ctx): int
	{
		$brandNorm = trim($brandNorm);
		if ($brandNorm === '')
		{
			return 2;
		}
		if (isset($ctx['preferred'][$brandNorm]))
		{
			return 0;
		}
		if (isset($ctx['aliases'][$brandNorm]))
		{
			return 1;
		}

		return 2;
	}
}

if (!function_exists('mf_catalog_search_sort_result_ids'))
{
	/**
	 * Приоритет: наличие → сопоставленный бренд → тип совпадения → исходный порядок.
	 *
	 * @param int[] $ids
	 * @param array<int, int> $tierMap 0=артикул, 1=артикул в названии, 2=текст
	 * @return int[]
	 */
	function mf_catalog_search_sort_result_ids(array $ids, string $query, array $tierMap): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		if (count($ids) <= 1)
		{
			return $ids;
		}

		$stockMap = function_exists('mf_catalog_batch_products_have_stock')
			? mf_catalog_batch_products_have_stock($ids)
			: [];

		$brandMap = [];
		foreach (mf_catalog_search_fetch_rows_by_ids($ids) as $id => $row)
		{
			$brand = trim((string)($row['PROPERTY_MF_BRAND_VALUE'] ?? ''));
			if ($brand === '')
			{
				$brand = trim((string)($row['PROPERTY_MF_BRAND_NORM_VALUE'] ?? ''));
			}
			if (function_exists('mf_brand_norm'))
			{
				mf_catalog_search_ensure_brand_dict();
				$brandMap[(int)$id] = mf_brand_norm($brand);
			}
			else
			{
				$brandMap[(int)$id] = mb_strtoupper($brand);
			}
		}

		$brandCtx = mf_catalog_search_brand_sort_context($query);
		$indexed = [];
		foreach ($ids as $pos => $id)
		{
			$id = (int)$id;
			$indexed[] = [
				'id' => $id,
				'pos' => $pos,
				'stock' => !empty($stockMap[$id]) ? 0 : 1,
				'brand_rank' => mf_catalog_search_brand_rank_for_product((string)($brandMap[$id] ?? ''), $brandCtx),
				'tier' => (int)($tierMap[$id] ?? 9),
			];
		}

		usort($indexed, static function (array $a, array $b): int {
			if ($a['stock'] !== $b['stock'])
			{
				return $a['stock'] <=> $b['stock'];
			}
			if ($a['brand_rank'] !== $b['brand_rank'])
			{
				return $a['brand_rank'] <=> $b['brand_rank'];
			}
			if ($a['tier'] !== $b['tier'])
			{
				return $a['tier'] <=> $b['tier'];
			}

			return $a['pos'] <=> $b['pos'];
		});

		return array_values(array_map(static fn(array $row): int => (int)$row['id'], $indexed));
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

if (!function_exists('mf_catalog_search_ids_by_article_in_name'))
{
	/**
	 * Товары, в названии которых встречается артикул из запроса.
	 *
	 * @param string[] $candidates
	 * @return int[]
	 */
	function mf_catalog_search_ids_by_article_in_name(array $candidates, int $limit = 120): array
	{
		$values = mf_catalog_search_article_values($candidates);
		if (empty($values) || !class_exists(\Bitrix\Main\Application::class))
		{
			return [];
		}

		$filtered = [];
		foreach ($values as $value)
		{
			$value = trim((string)$value);
			if ($value === '' || mb_strlen($value) < 3)
			{
				continue;
			}
			if (function_exists('mf_analogs_norm_article') && function_exists('mf_search_query_article_is_plausible'))
			{
				$norm = mf_analogs_norm_article($value);
				if ($norm !== '' && !mf_search_query_article_is_plausible($norm))
				{
					continue;
				}
			}
			$filtered[] = $value;
		}
		$filtered = array_values(array_unique($filtered));
		if (empty($filtered))
		{
			return [];
		}

		$helper = \Bitrix\Main\Application::getConnection()->getSqlHelper();
		$iblockId = mf_catalog_search_iblock_id();
		$where = ["e.IBLOCK_ID = {$iblockId}", "e.ACTIVE = 'Y'"];
		$nameConds = [];
		foreach ($filtered as $value)
		{
			$nameConds[] = "e.NAME LIKE '%" . $helper->forSql($value) . "%'";
		}
		$where[] = '(' . implode(' OR ', $nameConds) . ')';

		$sql = '
			SELECT e.ID
			FROM b_iblock_element e
			WHERE ' . implode(' AND ', $where) . '
			ORDER BY e.NAME
			LIMIT ' . (int)$limit;

		return mf_catalog_search_sql_ids($sql);
	}
}

if (!function_exists('mf_catalog_search_ids_by_name_words'))
{
	/**
	 * @param string[] $words
	 * @return int[]
	 */
	function mf_catalog_search_ids_by_name_words(array $words, int $limit = 120): array
	{
		if (empty($words) || !class_exists(\Bitrix\Main\Application::class))
		{
			return [];
		}

		$helper = \Bitrix\Main\Application::getConnection()->getSqlHelper();
		$iblockId = mf_catalog_search_iblock_id();
		$where = ["e.IBLOCK_ID = {$iblockId}", "e.ACTIVE = 'Y'"];
		foreach ($words as $word)
		{
			$where[] = "e.NAME LIKE '%" . $helper->forSql((string)$word) . "%'";
		}

		$sql = '
			SELECT e.ID
			FROM b_iblock_element e
			WHERE ' . implode(' AND ', $where) . '
			ORDER BY e.NAME
			LIMIT ' . (int)$limit;

		return mf_catalog_search_sql_ids($sql);
	}
}

if (!function_exists('mf_catalog_search_ids_by_name_substring'))
{
	function mf_catalog_search_ids_by_name_substring(string $term, int $limit = 120): array
	{
		$term = trim($term);
		if ($term === '' || mb_strlen($term) < 2 || !class_exists(\Bitrix\Main\Application::class))
		{
			return [];
		}

		$helper = \Bitrix\Main\Application::getConnection()->getSqlHelper();
		$iblockId = mf_catalog_search_iblock_id();
		$sql = '
			SELECT e.ID
			FROM b_iblock_element e
			WHERE e.IBLOCK_ID = ' . $iblockId . "
				AND e.ACTIVE = 'Y'
				AND e.NAME LIKE '%" . $helper->forSql($term) . "%'
			ORDER BY e.NAME
			LIMIT " . (int)$limit;

		return mf_catalog_search_sql_ids($sql);
	}
}

if (!function_exists('mf_catalog_search_ids_by_property_substrings'))
{
	/**
	 * Поиск подстроки в свойствах артикула / бренда.
	 *
	 * @param string[] $propertyCodes
	 * @param string[] $terms
	 * @return int[]
	 */
	function mf_catalog_search_ids_by_property_substrings(array $propertyCodes, array $terms, int $limit = 120): array
	{
		$terms = array_values(array_unique(array_filter(array_map('trim', $terms), static fn($t) => $t !== '' && mb_strlen((string)$t) >= 2)));
		if (empty($terms) || empty($propertyCodes) || !class_exists(\Bitrix\Main\Application::class))
		{
			return [];
		}

		$iblockId = mf_catalog_search_iblock_id();
		$meta = mf_catalog_search_meta($iblockId);
		$version = (int)($meta['version'] ?? 1);
		$props = (array)($meta['props'] ?? []);
		$pids = [];
		foreach ($propertyCodes as $code)
		{
			$pid = (int)($props[$code] ?? 0);
			if ($pid > 0)
			{
				$pids[$pid] = true;
			}
		}
		if (empty($pids))
		{
			return [];
		}

		$helper = \Bitrix\Main\Application::getConnection()->getSqlHelper();
		$valueConds = [];
		foreach ($terms as $term)
		{
			$valueConds[] = "p.VALUE LIKE '%" . $helper->forSql((string)$term) . "%'";
		}

		if ($version === 2)
		{
			$propConds = [];
			foreach (array_keys($pids) as $pid)
			{
				foreach ($terms as $term)
				{
					$propConds[] = 's.PROPERTY_' . (int)$pid . " LIKE '%" . $helper->forSql((string)$term) . "%'";
				}
			}
			if (empty($propConds))
			{
				return [];
			}

			$sql = '
				SELECT DISTINCT s.IBLOCK_ELEMENT_ID AS ID
				FROM b_iblock_element_prop_s' . $iblockId . ' s
				INNER JOIN b_iblock_element e ON e.ID = s.IBLOCK_ELEMENT_ID
				WHERE e.IBLOCK_ID = ' . $iblockId . "
					AND e.ACTIVE = 'Y'
					AND (" . implode(' OR ', $propConds) . ')
				ORDER BY s.IBLOCK_ELEMENT_ID
				LIMIT ' . (int)$limit;
		}
		else
		{
			$sql = '
				SELECT DISTINCT p.IBLOCK_ELEMENT_ID AS ID
				FROM b_iblock_element_property p
				INNER JOIN b_iblock_element e ON e.ID = p.IBLOCK_ELEMENT_ID
				WHERE e.IBLOCK_ID = ' . $iblockId . "
					AND e.ACTIVE = 'Y'
					AND p.IBLOCK_PROPERTY_ID IN (" . implode(',', array_keys($pids)) . ')
					AND (' . implode(' OR ', $valueConds) . ')
				ORDER BY p.IBLOCK_ELEMENT_ID
				LIMIT ' . (int)$limit;
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
	 * Текстовый поиск: название, артикулы, бренды (включая сопоставленные алиасы).
	 *
	 * @return int[]
	 */
	function mf_catalog_search_ids_by_text(string $query, int $limit = 120): array
	{
		$query = trim($query);
		if ($query === '' || mb_strlen($query) < 2)
		{
			return [];
		}

		mf_catalog_search_ensure_brand_dict();

		$words = mf_catalog_search_text_words($query);
		$brandWords = $words;
		if (function_exists('mf_brand_find'))
		{
			$brandCandidates = $words;
			$brandCandidates[] = $query;
			$seenBrand = [];
			foreach ($brandCandidates as $candidate)
			{
				$candidate = trim((string)$candidate);
				if ($candidate === '' || isset($seenBrand[mb_strtolower($candidate)]))
				{
					continue;
				}
				$seenBrand[mb_strtolower($candidate)] = true;
				$canon = mf_brand_find($candidate, false);
				if ($canon !== '')
				{
					$brandWords[] = $canon;
					$brandWords[] = $candidate;
				}
			}
		}
		$brandWords = array_values(array_unique(array_filter(array_map('trim', $brandWords))));

		$parts = [];
		if (!empty($brandWords))
		{
			$parts[] = mf_catalog_search_ids_by_brand_words($brandWords, $limit);
		}
		if (!empty($words))
		{
			$parts[] = mf_catalog_search_ids_by_name_words($words, $limit);

			$articleTerm = mb_strlen($query) >= 3 ? $query : '';
			if ($articleTerm === '')
			{
				foreach ($words as $word)
				{
					if (mb_strlen((string)$word) > mb_strlen($articleTerm))
					{
						$articleTerm = (string)$word;
					}
				}
			}
			if (mb_strlen($articleTerm) >= 3)
			{
				$parts[] = mf_catalog_search_ids_by_property_substrings(
					['CML2_ARTICLE', 'MF_ARTICLE_NORM'],
					[$articleTerm],
					$limit
				);
			}
		}

		$merged = [];
		foreach ($parts as $chunk)
		{
			$merged = mf_catalog_search_merge_unique_ids($merged, (array)$chunk);
		}

		if (count($merged) > $limit)
		{
			$merged = array_slice($merged, 0, $limit);
		}

		return $merged;
	}
}

if (!function_exists('mf_catalog_search_collect_stage'))
{
	/**
	 * Один этап поиска: 1 — артикул, 2 — артикул в названии, 3 — текст.
	 *
	 * @param int[] $excludeIds уже показанные ID (без дублей между этапами)
	 * @return array{ids: int[], stage: int}
	 */
	function mf_catalog_search_collect_stage(string $query, int $stage, array $excludeIds = []): array
	{
		$query = trim($query);
		$stage = (int)$stage;
		if ($query === '' || $stage < 1 || $stage > 3)
		{
			return ['ids' => [], 'stage' => $stage];
		}

		$exclude = [];
		foreach ($excludeIds as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$exclude[$id] = true;
			}
		}

		$filter = static function (array $ids) use ($exclude): array {
			$out = [];
			foreach ($ids as $id)
			{
				$id = (int)$id;
				if ($id > 0 && !isset($exclude[$id]))
				{
					$out[] = $id;
				}
			}

			return $out;
		};

		$lib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_import_analogs_lib.php';
		if (is_file($lib))
		{
			require_once $lib;
		}

		$articleCandidates = function_exists('mf_search_query_article_candidates')
			? mf_search_query_article_candidates($query)
			: [];

		$sortLimited = static function (array $ids, string $queryText, array $tierMap): array {
			if (count($ids) <= 1)
			{
				return $ids;
			}
			if (count($ids) > 60)
			{
				$head = array_slice($ids, 0, 60);
				$tail = array_slice($ids, 60);
				$head = mf_catalog_search_sort_result_ids($head, $queryText, $tierMap);

				return array_merge($head, $tail);
			}

			return mf_catalog_search_sort_result_ids($ids, $queryText, $tierMap);
		};

		$loadStageRawIds = static function (int $stageNum, string $queryText) use ($articleCandidates): array {
			static $runtime = [];
			$key = $stageNum . '|' . md5(mb_strtolower($queryText));
			if (isset($runtime[$key]))
			{
				return $runtime[$key];
			}

			$cache = null;
			if (class_exists(\Bitrix\Main\Data\Cache::class))
			{
				$cache = \Bitrix\Main\Data\Cache::createInstance();
				$cacheId = 'ids_v5_s' . $stageNum . '_' . md5(mb_strtolower($queryText));
				if ($cache->initCache(900, $cacheId, '/mf/catalog_search'))
				{
					$vars = $cache->getVars();
					if (is_array($vars) && isset($vars['ids']))
					{
						return $runtime[$key] = array_values(array_map('intval', (array)$vars['ids']));
					}
				}
			}

			$raw = [];
			if ($stageNum === 2)
			{
				if (!empty($articleCandidates))
				{
					$raw = mf_catalog_search_ids_by_article_in_name($articleCandidates);
				}
			}
			elseif ($stageNum === 3)
			{
				$raw = mf_catalog_search_ids_by_text($queryText, 120);
			}

			if ($cache instanceof \Bitrix\Main\Data\Cache && $cache->startDataCache())
			{
				$cache->endDataCache(['ids' => $raw]);
			}

			return $runtime[$key] = $raw;
		};

		if ($stage === 1)
		{
			$ids = !empty($articleCandidates)
				? $filter(mf_catalog_search_ids_by_articles($articleCandidates))
				: [];
			$tierMap = [];
			foreach ($ids as $id)
			{
				$tierMap[(int)$id] = 0;
			}

			return [
				'ids' => $sortLimited($ids, $query, $tierMap),
				'stage' => 1,
			];
		}

		if ($stage === 2)
		{
			if (empty($articleCandidates))
			{
				return ['ids' => [], 'stage' => 2];
			}

			$ids = $filter($loadStageRawIds(2, $query));
			$tierMap = [];
			foreach ($ids as $id)
			{
				$tierMap[(int)$id] = 1;
			}

			return [
				'ids' => $sortLimited($ids, $query, $tierMap),
				'stage' => 2,
			];
		}

		$ids = $filter($loadStageRawIds(3, $query));
		$tierMap = [];
		foreach ($ids as $id)
		{
			$tierMap[(int)$id] = 2;
		}

		return [
			'ids' => $sortLimited($ids, $query, $tierMap),
			'stage' => 3,
		];
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
			$cacheId = 'ids_v5_s1_' . $key;
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

		$stage = mf_catalog_search_collect_stage($query, 1, []);
		$result = [
			'ids' => (array)($stage['ids'] ?? []),
			'total' => count((array)($stage['ids'] ?? [])),
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
