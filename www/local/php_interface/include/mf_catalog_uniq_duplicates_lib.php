<?php

declare(strict_types=1);

/**
 * Дубликаты каталога по MF_UNIQ_KEY (артикул + бренд).
 */

use Bitrix\Main\Application;

if (!function_exists('mf_cud_catalog_iblock_id'))
{
	function mf_cud_catalog_iblock_id(): int
	{
		if (function_exists('mf_cdc_catalog_iblock_id'))
		{
			return mf_cdc_catalog_iblock_id();
		}

		return 4;
	}
}

if (!function_exists('mf_cud_catalog_iblock_ids'))
{
	/**
	 * Инфоблоки каталога: товары + торговые предложения (SKU), если есть.
	 *
	 * @return int[]
	 */
	function mf_cud_catalog_iblock_ids(): array
	{
		$pid = mf_cud_catalog_iblock_id();
		if (function_exists('mf_cdc_allowed_catalog_iblocks'))
		{
			return mf_cdc_allowed_catalog_iblocks($pid);
		}

		return [$pid];
	}
}

if (!function_exists('mf_cud_group_key'))
{
	function mf_cud_group_key(string $uniqKey): string
	{
		return md5($uniqKey);
	}
}

if (!function_exists('mf_cud_parse_element_ids'))
{
	/**
	 * @return list<int>
	 */
	function mf_cud_parse_element_ids(string $csv): array
	{
		$out = [];
		foreach (explode(',', $csv) as $p)
		{
			$i = (int)trim($p);
			if ($i > 0)
			{
				$out[$i] = true;
			}
		}

		return array_keys($out);
	}
}

if (!function_exists('mf_cud_iblock_property_meta'))
{
	/**
	 * @return array{version: int, uniq_prop_id: int, redirect_prop_id: int}
	 */
	function mf_cud_iblock_property_meta(int $iblockId): array
	{
		$meta = ['version' => 1, 'uniq_prop_id' => 0, 'redirect_prop_id' => 0];
		$ibRow = \CIBlock::GetByID($iblockId)->Fetch();
		if ($ibRow)
		{
			$meta['version'] = (int)($ibRow['VERSION'] ?? 1);
		}
		$uniq = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => 'MF_UNIQ_KEY'])->Fetch();
		if ($uniq)
		{
			$meta['uniq_prop_id'] = (int)$uniq['ID'];
		}
		$redir = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => 'MF_IS_REDIRECT'])->Fetch();
		if ($redir)
		{
			$meta['redirect_prop_id'] = (int)$redir['ID'];
		}

		return $meta;
	}
}

if (!function_exists('mf_cud_count_duplicate_groups'))
{
	function mf_cud_count_duplicate_groups(int $iblockId, array $opts = []): int
	{
		$groups = mf_cud_fetch_duplicate_groups($iblockId, $opts, 0, 0, true);

		return (int)($groups['total'] ?? 0);
	}
}

if (!function_exists('mf_cud_fetch_duplicate_groups'))
{
	/**
	 * @param array{active_only?: bool, include_redirect?: bool, include_empty_keys?: bool, min_count?: int} $opts
	 * @return array{total: int, rows: list<array{uniq_key: string, cnt: int, element_ids: list<int>}>}
	 */
	function mf_cud_fetch_duplicate_groups(int $iblockId, array $opts = [], int $limit = 50, int $offset = 0, bool $countOnly = false): array
	{
		$iblockId = (int)$iblockId;
		$activeOnly = !isset($opts['active_only']) || (bool)$opts['active_only'];
		$includeRedirect = !empty($opts['include_redirect']);
		$includeEmpty = !empty($opts['include_empty_keys']);
		$minCount = max(2, (int)($opts['min_count'] ?? 2));

		$empty = ['total' => 0, 'rows' => []];
		if ($iblockId <= 0)
		{
			return $empty;
		}

		$meta = mf_cud_iblock_property_meta($iblockId);
		if ($meta['uniq_prop_id'] <= 0)
		{
			return $empty;
		}

		$version = (int)$meta['version'];
		$uniqPropId = (int)$meta['uniq_prop_id'];

		$eConds = ['e.IBLOCK_ID = ' . $iblockId];
		if ($activeOnly)
		{
			$eConds[] = "e.ACTIVE = 'Y'";
		}
		$eWhere = implode(' AND ', $eConds);

		$redirJoin = '';
		$redirSql = '';
		if (!$includeRedirect && $meta['redirect_prop_id'] > 0)
		{
			$rid = (int)$meta['redirect_prop_id'];
			$redirJoin = "
LEFT JOIN b_iblock_element_property redir
  ON redir.IBLOCK_ELEMENT_ID = e.ID AND redir.IBLOCK_PROPERTY_ID = {$rid}";
			$redirSql = 'AND (redir.VALUE IS NULL OR redir.VALUE <> \'Y\')';
		}

		$emptySqlV1 = '';
		$emptySqlV2 = '';
		if (!$includeEmpty)
		{
			$emptySqlV1 = 'AND (p.VALUE IS NOT NULL AND p.VALUE <> \'\')';
			$emptySqlV2 = 'AND (s.PROPERTY_' . $uniqPropId . ' IS NOT NULL AND s.PROPERTY_' . $uniqPropId . ' <> \'\')';
		}

		$innerSql = '';
		if ($version === 2)
		{
			$col = 's.PROPERTY_' . $uniqPropId;
			$innerSql = "
SELECT {$col} AS uniq_key,
       COUNT(*) AS cnt_elements,
       GROUP_CONCAT(s.IBLOCK_ELEMENT_ID ORDER BY s.IBLOCK_ELEMENT_ID SEPARATOR ',') AS element_ids
FROM b_iblock_element_prop_s{$iblockId} s
INNER JOIN b_iblock_element e ON e.ID = s.IBLOCK_ELEMENT_ID
{$redirJoin}
WHERE {$eWhere}
{$redirSql}
{$emptySqlV2}
GROUP BY {$col}
HAVING COUNT(*) >= {$minCount}";
		}
		else
		{
			$innerSql = "
SELECT p.VALUE AS uniq_key,
       COUNT(DISTINCT p.IBLOCK_ELEMENT_ID) AS cnt_elements,
       GROUP_CONCAT(DISTINCT p.IBLOCK_ELEMENT_ID ORDER BY p.IBLOCK_ELEMENT_ID SEPARATOR ',') AS element_ids
FROM b_iblock_element_property p
INNER JOIN b_iblock_element e ON e.ID = p.IBLOCK_ELEMENT_ID
{$redirJoin}
WHERE p.IBLOCK_PROPERTY_ID = {$uniqPropId}
  AND {$eWhere}
{$redirSql}
{$emptySqlV1}
GROUP BY p.VALUE
HAVING COUNT(DISTINCT p.IBLOCK_ELEMENT_ID) >= {$minCount}";
		}

		$conn = Application::getConnection();
		try
		{
			$conn->queryExecute('SET SESSION group_concat_max_len = 8388608');
		}
		catch (\Throwable $e)
		{
		}

		$cntRow = $conn->query('SELECT COUNT(*) AS c FROM (' . $innerSql . ') t')->fetch();
		$total = (int)($cntRow['c'] ?? $cntRow['C'] ?? 0);
		if ($countOnly)
		{
			return ['total' => $total, 'rows' => []];
		}

		$limit = max(0, $limit);
		$offset = max(0, $offset);
		$sql = $innerSql . ' ORDER BY cnt_elements DESC, uniq_key';
		if ($limit > 0)
		{
			$sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
		}

		$rows = [];
		$res = $conn->query($sql);
		while ($r = $res->fetch())
		{
			$key = (string)($r['uniq_key'] ?? $r['UNIQ_KEY'] ?? '');
			$cntSql = (int)($r['cnt_elements'] ?? $r['CNT_ELEMENTS'] ?? 0);
			$ids = mf_cud_parse_element_ids((string)($r['element_ids'] ?? $r['ELEMENT_IDS'] ?? ''));
			$cntFound = count($ids);
			$cntMissing = 0;
			if ($ids !== [])
			{
				$classRow = mf_cud_classify_element_ids($ids, $iblockId, $opts);
				$cntFound = count($classRow['found']);
				$cntMissing = count($classRow['missing']);
				$ids = array_values(array_merge($classRow['found'], $classRow['missing']));
			}
			$rows[] = [
				'uniq_key' => $key,
				'cnt' => $cntSql,
				'cnt_found' => $cntFound,
				'cnt_missing' => $cntMissing,
				'element_ids' => $ids,
				'group_key' => mf_cud_group_key($key),
			];
		}

		return ['total' => $total, 'rows' => $rows];
	}
}

if (!function_exists('mf_cud_classify_element_ids'))
{
	/**
	 * Проверка ID по b_iblock_element (без фильтра GetList).
	 *
	 * @param list<int> $ids
	 * @return array{
	 *   found: list<int>,
	 *   product: list<int>,
	 *   missing: list<int>,
	 *   other_iblock: list<int>,
	 *   inactive: list<int>,
	 *   rows: array<int, array{id: int, iblock_id: int, active: string, name: string, code: string}>
	 * }
	 */
	function mf_cud_classify_element_ids(array $ids, int $productIblockId, array $opts = []): array
	{
		$productIblockId = (int)$productIblockId;
		$allowed = mf_cud_catalog_iblock_ids();
		$allowedMap = array_fill_keys($allowed, true);
		$activeOnly = !isset($opts['active_only']) || (bool)$opts['active_only'];

		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $v) => $v > 0)));
		$out = [
			'found' => [],
			'product' => [],
			'missing' => [],
			'other_iblock' => [],
			'inactive' => [],
			'rows' => [],
		];
		if ($ids === [])
		{
			return $out;
		}

		$conn = Application::getConnection();
		$inSql = implode(',', $ids);
		$res = $conn->query(
			'SELECT `ID`, `IBLOCK_ID`, `ACTIVE`, `NAME`, `CODE` FROM b_iblock_element WHERE `ID` IN (' . $inSql . ')'
		);
		$byId = [];
		while ($r = $res->fetch())
		{
			if (!is_array($r))
			{
				continue;
			}
			$id = (int)($r['ID'] ?? 0);
			if ($id > 0)
			{
				$byId[$id] = $r;
			}
		}

		foreach ($ids as $id)
		{
			$id = (int)$id;
			if (!isset($byId[$id]))
			{
				$out['missing'][] = $id;
				continue;
			}
			$r = $byId[$id];
			$ib = (int)($r['IBLOCK_ID'] ?? 0);
			$active = (string)($r['ACTIVE'] ?? '');
			$out['rows'][$id] = [
				'id' => $id,
				'iblock_id' => $ib,
				'active' => $active,
				'name' => trim((string)($r['NAME'] ?? '')),
				'code' => trim((string)($r['CODE'] ?? '')),
			];
			if (!isset($allowedMap[$ib]))
			{
				$out['other_iblock'][] = $id;
				continue;
			}
			if ($activeOnly && $active !== 'Y')
			{
				$out['inactive'][] = $id;
				continue;
			}
			$out['found'][] = $id;
			if ($ib === $productIblockId)
			{
				$out['product'][] = $id;
			}
		}

		return $out;
	}
}

if (!function_exists('mf_cud_fetch_elements_detail'))
{
	/**
	 * @param list<int> $ids
	 * @return array<int, array<string, mixed>>
	 */
	function mf_cud_fetch_elements_detail(int $iblockId, array $ids, array $opts = []): array
	{
		$iblockId = (int)$iblockId;
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $v) => $v > 0)));
		$out = [];
		if ($ids === [])
		{
			return $out;
		}

		$class = mf_cud_classify_element_ids($ids, $iblockId, $opts);

		foreach ($class['missing'] as $id)
		{
			$out[$id] = [
				'id' => $id,
				'status' => 'missing',
				'name' => '',
				'code' => '',
				'active' => '',
				'brand' => '',
				'article' => '',
				'uniq_key' => '',
				'is_redirect' => false,
				'iblock_id' => 0,
				'date_create' => '',
				'timestamp_x' => '',
			];
		}

		foreach ($class['other_iblock'] as $id)
		{
			$raw = $class['rows'][$id] ?? [];
			$out[$id] = [
				'id' => $id,
				'status' => 'other_iblock',
				'name' => (string)($raw['name'] ?? ''),
				'code' => (string)($raw['code'] ?? ''),
				'active' => (string)($raw['active'] ?? ''),
				'brand' => '',
				'article' => '',
				'uniq_key' => '',
				'is_redirect' => false,
				'iblock_id' => (int)($raw['iblock_id'] ?? 0),
				'date_create' => '',
				'timestamp_x' => '',
			];
		}

		foreach ($class['inactive'] as $id)
		{
			$raw = $class['rows'][$id] ?? [];
			$out[$id] = [
				'id' => $id,
				'status' => 'inactive',
				'name' => (string)($raw['name'] ?? ''),
				'code' => (string)($raw['code'] ?? ''),
				'active' => (string)($raw['active'] ?? ''),
				'brand' => '',
				'article' => '',
				'uniq_key' => '',
				'is_redirect' => false,
				'iblock_id' => (int)($raw['iblock_id'] ?? 0),
				'date_create' => '',
				'timestamp_x' => '',
			];
		}

		$select = [
			'ID',
			'IBLOCK_ID',
			'NAME',
			'CODE',
			'ACTIVE',
			'DATE_CREATE',
			'TIMESTAMP_X',
			'PROPERTY_MF_UNIQ_KEY',
			'PROPERTY_MF_BRAND',
			'PROPERTY_MF_ARTICLE_NORM',
			'PROPERTY_CML2_ARTICLE',
			'PROPERTY_MF_IS_REDIRECT',
			'PROPERTY_MF_CANONICAL_CODE',
			'XML_ID',
		];

		$byIblock = [];
		foreach ($class['found'] as $id)
		{
			$ib = (int)($class['rows'][$id]['iblock_id'] ?? 0);
			if ($ib > 0)
			{
				$byIblock[$ib][] = $id;
			}
		}

		foreach ($byIblock as $ib => $chunkIds)
		{
			$rs = \CIBlockElement::GetList(
				['ID' => 'ASC'],
				['IBLOCK_ID' => (int)$ib, 'ID' => $chunkIds, 'CHECK_PERMISSIONS' => 'N'],
				false,
				false,
				$select
			);
			while ($f = $rs->Fetch())
			{
				$pid = (int)($f['ID'] ?? 0);
				if ($pid <= 0)
				{
					continue;
				}
				$article = trim((string)($f['PROPERTY_MF_ARTICLE_NORM_VALUE'] ?? ''));
				if ($article === '')
				{
					$article = trim((string)($f['PROPERTY_CML2_ARTICLE_VALUE'] ?? ''));
				}
				$status = ((int)($f['IBLOCK_ID'] ?? 0) === $iblockId) ? 'product' : 'sku';
				$brand = trim((string)($f['PROPERTY_MF_BRAND_VALUE'] ?? ''));
				$articleNorm = mf_cud_normalize_article($article);
				$brandNorm = $brand !== '' ? mf_cud_normalize_brand($brand) : '';
				$uniqKey = trim((string)($f['PROPERTY_MF_UNIQ_KEY_VALUE'] ?? ''));
				$expectedKey = ($articleNorm !== '') ? mf_cud_make_uniq_key($articleNorm, $brandNorm) : '';
				$out[$pid] = [
					'id' => $pid,
					'status' => $status,
					'name' => trim((string)($f['NAME'] ?? '')),
					'code' => trim((string)($f['CODE'] ?? '')),
					'xml_id' => trim((string)($f['XML_ID'] ?? '')),
					'active' => (string)($f['ACTIVE'] ?? ''),
					'brand' => $brand,
					'article' => $article,
					'uniq_key' => $uniqKey,
					'expected_uniq_key' => $expectedKey,
					'uniq_key_corrupt_hint' => mf_cud_uniq_key_looks_corrupt($uniqKey),
					'is_redirect' => mb_strtoupper(trim((string)($f['PROPERTY_MF_IS_REDIRECT_VALUE'] ?? ''))) === 'Y',
					'canonical_code' => trim((string)($f['PROPERTY_MF_CANONICAL_CODE_VALUE'] ?? '')),
					'iblock_id' => (int)($f['IBLOCK_ID'] ?? 0),
					'date_create' => (string)($f['DATE_CREATE'] ?? ''),
					'timestamp_x' => (string)($f['TIMESTAMP_X'] ?? ''),
				];
			}
		}

		// GetList не вернул элемент, хотя в b_iblock_element он есть.
		foreach ($class['found'] as $id)
		{
			if (isset($out[$id]))
			{
				continue;
			}
			$raw = $class['rows'][$id] ?? [];
			$out[$id] = [
				'id' => $id,
				'status' => ((int)($raw['iblock_id'] ?? 0) === $iblockId) ? 'product' : 'sku',
				'name' => (string)($raw['name'] ?? ''),
				'code' => (string)($raw['code'] ?? ''),
				'active' => (string)($raw['active'] ?? ''),
				'brand' => '',
				'article' => '',
				'uniq_key' => '',
				'is_redirect' => false,
				'iblock_id' => (int)($raw['iblock_id'] ?? 0),
				'date_create' => '',
				'timestamp_x' => '',
			];
		}

		return $out;
	}
}

if (!function_exists('mf_cud_suggest_keep_id'))
{
	/**
	 * @param array<int, array<string, mixed>> $details keyed by id
	 */
	function mf_cud_suggest_keep_id(array $details): int
	{
		$candidates = [];
		foreach ($details as $id => $row)
		{
			$id = (int)$id;
			if ($id <= 0)
			{
				continue;
			}
			$status = (string)($row['status'] ?? '');
			if ($status === 'missing' || $status === 'other_iblock' || $status === 'inactive')
			{
				continue;
			}
			if ($status === 'sku')
			{
				continue;
			}
			$candidates[] = [
				'id' => $id,
				'redirect' => !empty($row['is_redirect']),
			];
		}
		if ($candidates === [])
		{
			return 0;
		}
		usort($candidates, static function ($a, $b) {
			if ($a['redirect'] !== $b['redirect'])
			{
				return $a['redirect'] <=> $b['redirect'];
			}

			return $a['id'] <=> $b['id'];
		});

		return (int)$candidates[0]['id'];
	}
}

if (!function_exists('mf_cud_make_uniq_key'))
{
	function mf_cud_make_uniq_key(string $articleNorm, string $brandNorm): string
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

if (!function_exists('mf_cud_normalize_article'))
{
	function mf_cud_normalize_article(string $s): string
	{
		$s = mb_strtoupper(trim($s));
		$s = preg_replace('~[^A-Z0-9]+~', '', $s) ?? '';

		return $s;
	}
}

if (!function_exists('mf_cud_normalize_brand'))
{
	function mf_cud_normalize_brand(string $s): string
	{
		$s = mb_strtoupper(trim($s));
		$s = str_replace('Ё', 'Е', $s);
		$s = preg_replace('~[^A-ZА-Я0-9]+~u', '', $s) ?? '';

		return $s;
	}
}

if (!function_exists('mf_cud_uniq_key_looks_corrupt'))
{
	/** Ключ с «хвостом» из подписей полей CSV / лишней кириллицы (не артикул_бренд). */
	function mf_cud_uniq_key_looks_corrupt(string $uniqKey): string
	{
		$uniqKey = trim($uniqKey);
		if ($uniqKey === '')
		{
			return '';
		}
		$markers = [
			'НОМЕРПОКАТАЛОГУ',
			'ОРИГИНАЛЬНЫЕНОМЕРА',
			'СОСТОЯНИЕБУ',
			'НОМЕРПОДШИПНИКА',
			'ПРОИЗВОДИТЕЛЯ',
		];
		foreach ($markers as $m)
		{
			if (mb_stripos($uniqKey, $m) !== false)
			{
				return 'В ключе подписи полей каталога (битый импорт/экспорт CSV), ожидается формат «артикул_бренд».';
			}
		}
		if (mb_strlen($uniqKey) > 80)
		{
			return 'Слишком длинный MF_UNIQ_KEY — вероятно склеены лишние поля.';
		}

		return '';
	}
}

if (!function_exists('mf_cud_admin_edit_url'))
{
	function mf_cud_admin_edit_url(int $iblockId, int $elementId, string $lang = 'ru'): string
	{
		$elementId = (int)$elementId;
		if ($elementId <= 0)
		{
			return '';
		}
		if ($iblockId <= 0)
		{
			$conn = Application::getConnection();
			$row = $conn->query('SELECT `IBLOCK_ID` FROM b_iblock_element WHERE `ID`=' . $elementId . ' LIMIT 1')->fetch();
			$iblockId = is_array($row) ? (int)($row['IBLOCK_ID'] ?? 0) : 0;
		}
		$iblockId = (int)$iblockId;
		if ($iblockId <= 0)
		{
			return '';
		}
		$type = '';
		$ib = \CIBlock::GetByID($iblockId)->Fetch();
		if (is_array($ib))
		{
			$type = (string)($ib['IBLOCK_TYPE_ID'] ?? '');
		}

		return '/bitrix/admin/iblock_element_edit.php?IBLOCK_ID=' . $iblockId
			. '&type=' . rawurlencode($type)
			. '&ID=' . $elementId
			. '&lang=' . rawurlencode($lang);
	}
}

if (!function_exists('mf_cud_admin_list_url'))
{
	/** Список элементов IB с фильтром по ID (не полагается на поисковый индекс). */
	function mf_cud_admin_list_url(int $iblockId, int $elementId, string $lang = 'ru'): string
	{
		$elementId = (int)$elementId;
		$iblockId = (int)$iblockId;
		if ($elementId <= 0 || $iblockId <= 0)
		{
			return '';
		}
		$type = '';
		$ib = \CIBlock::GetByID($iblockId)->Fetch();
		if (is_array($ib))
		{
			$type = (string)($ib['IBLOCK_TYPE_ID'] ?? '');
		}

		return '/bitrix/admin/iblock_element_admin.php?IBLOCK_ID=' . $iblockId
			. '&type=' . rawurlencode($type)
			. '&lang=' . rawurlencode($lang)
			. '&set_filter=Y&adm_filter_applied=1&find_el_id=' . $elementId;
	}
}

if (!function_exists('mf_cud_purge_orphan_property_for_element_id'))
{
	/**
	 * Удалить хвост в prop_s / b_iblock_element_property для ID без записи в b_iblock_element.
	 *
	 * @return array{ok: bool, message: string}
	 */
	function mf_cud_purge_orphan_property_for_element_id(int $elementId, int $iblockId): array
	{
		$elementId = (int)$elementId;
		$iblockId = (int)$iblockId;
		if ($elementId <= 0 || $iblockId <= 0)
		{
			return ['ok' => false, 'message' => 'Некорректный ID'];
		}

		$conn = Application::getConnection();
		$exists = $conn->query('SELECT `ID` FROM b_iblock_element WHERE `ID`=' . $elementId . ' LIMIT 1')->fetch();
		if (is_array($exists) && (int)($exists['ID'] ?? 0) > 0)
		{
			return ['ok' => false, 'message' => 'Элемент существует — нужно удаление карточки, не очистка хвоста'];
		}

		$meta = mf_cud_iblock_property_meta($iblockId);
		$did = false;

		try
		{
			if ((int)$meta['version'] === 2)
			{
				$conn->queryExecute(
					'DELETE FROM b_iblock_element_prop_s' . $iblockId . ' WHERE IBLOCK_ELEMENT_ID=' . $elementId
				);
				$did = true;
			}
		}
		catch (\Throwable $e)
		{
			return ['ok' => false, 'message' => 'prop_s: ' . $e->getMessage()];
		}

		$propIds = [];
		if ((int)$meta['uniq_prop_id'] > 0)
		{
			$propIds[] = (int)$meta['uniq_prop_id'];
		}
		if ((int)$meta['redirect_prop_id'] > 0)
		{
			$propIds[] = (int)$meta['redirect_prop_id'];
		}
		if ($propIds !== [])
		{
			try
			{
				$conn->queryExecute(
					'DELETE FROM b_iblock_element_property WHERE IBLOCK_ELEMENT_ID=' . $elementId
					. ' AND IBLOCK_PROPERTY_ID IN (' . implode(',', $propIds) . ')'
				);
				$did = true;
			}
			catch (\Throwable $e)
			{
				return ['ok' => false, 'message' => 'property: ' . $e->getMessage()];
			}
		}

		if (!$did)
		{
			return ['ok' => false, 'message' => 'Хвост свойств не найден'];
		}

		return ['ok' => true, 'message' => 'Очищен хвост свойств (карточка уже удалена)'];
	}
}

if (!function_exists('mf_cud_delete_elements'))
{
	/**
	 * @param list<int> $ids
	 * @return array{deleted: int, purged: int, failed: list<array{id: int, message: string}>}
	 */
	function mf_cud_delete_elements(array $ids, int $iblockId): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $v) => $v > 0)));
		$deleted = 0;
		$purged = 0;
		$failed = [];
		if ($ids === [])
		{
			return ['deleted' => 0, 'purged' => 0, 'failed' => []];
		}

		$class = mf_cud_classify_element_ids($ids, $iblockId, [
			'active_only' => false,
			'include_redirect' => true,
		]);
		$toDelete = array_values(array_unique(array_merge($class['product'], $class['inactive'])));
		$toPurge = $class['missing'];

		if (!function_exists('mf_cdc_allowed_catalog_iblocks') || !function_exists('mf_cdc_delete_one_catalog_element'))
		{
			foreach ($ids as $id)
			{
				$failed[] = ['id' => $id, 'message' => 'mf_catalog_delete_by_csv_lib не подключён'];
			}

			return ['deleted' => 0, 'purged' => 0, 'failed' => $failed];
		}

		$allowed = mf_cdc_allowed_catalog_iblocks($iblockId);
		$handled = [];

		foreach ($toDelete as $id)
		{
			$handled[$id] = true;
			$r = mf_cdc_delete_one_catalog_element($id, $allowed);
			if (!empty($r['ok']))
			{
				$deleted++;
				continue;
			}
			if (!empty($r['orphan']))
			{
				$p = mf_cud_purge_orphan_property_for_element_id($id, $iblockId);
				if (!empty($p['ok']))
				{
					$purged++;
				}
				else
				{
					$failed[] = ['id' => $id, 'message' => (string)($p['message'] ?? 'хвост не очищен')];
				}
				continue;
			}
			$failed[] = ['id' => $id, 'message' => (string)($r['message'] ?? 'ошибка')];
		}

		foreach ($toPurge as $id)
		{
			if (isset($handled[$id]))
			{
				continue;
			}
			$handled[$id] = true;
			$p = mf_cud_purge_orphan_property_for_element_id($id, $iblockId);
			if (!empty($p['ok']))
			{
				$purged++;
			}
			else
			{
				$failed[] = ['id' => $id, 'message' => (string)($p['message'] ?? 'хвост не очищен')];
			}
		}

		foreach ($ids as $id)
		{
			if (isset($handled[$id]))
			{
				continue;
			}
			$p = mf_cud_purge_orphan_property_for_element_id($id, $iblockId);
			if (!empty($p['ok']))
			{
				$purged++;
			}
			else
			{
				$failed[] = ['id' => $id, 'message' => (string)($p['message'] ?? 'не обработан')];
			}
		}

		return ['deleted' => $deleted, 'purged' => $purged, 'failed' => $failed];
	}
}
