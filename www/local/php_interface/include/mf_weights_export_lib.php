<?php

declare(strict_types=1);

/**
 * Выгрузка весов каталога: батчевый SQL (без CIBlockElement::GetList на каждый элемент).
 */

if (!function_exists('mf_wgw_export_batch_size'))
{
	function mf_wgw_export_batch_size(): int
	{
		return 50000;
	}
}

if (!function_exists('mf_wgw_export_conn'))
{
	function mf_wgw_export_conn(): ?\Bitrix\Main\DB\Connection
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

if (!function_exists('mf_wgw_export_property_meta'))
{
	/**
	 * @return array{
	 *   version: int,
	 *   use_prop_s: bool,
	 *   redirect_prop_id: int,
	 *   article_norm_prop_id: int,
	 *   cml2_article_prop_id: int,
	 *   brand_norm_prop_id: int,
	 *   brand_prop_id: int
	 * }
	 */
	function mf_wgw_export_property_meta(int $iblockId): array
	{
		static $cache = [];
		$iblockId = (int)$iblockId;
		if ($iblockId <= 0)
		{
			return [
				'version' => 1,
				'use_prop_s' => false,
				'redirect_prop_id' => 0,
				'article_norm_prop_id' => 0,
				'cml2_article_prop_id' => 0,
				'brand_norm_prop_id' => 0,
				'brand_prop_id' => 0,
			];
		}
		if (isset($cache[$iblockId]))
		{
			return $cache[$iblockId];
		}

		$meta = [
			'version' => 1,
			'use_prop_s' => false,
			'redirect_prop_id' => 0,
			'article_norm_prop_id' => 0,
			'cml2_article_prop_id' => 0,
			'brand_norm_prop_id' => 0,
			'brand_prop_id' => 0,
		];

		if (class_exists(\CIBlock::class))
		{
			$ibRow = \CIBlock::GetByID($iblockId)->Fetch();
			if ($ibRow)
			{
				$meta['version'] = (int)($ibRow['VERSION'] ?? 1);
			}
		}

		$codeMap = [
			'MF_IS_REDIRECT' => 'redirect_prop_id',
			'MF_ARTICLE_NORM' => 'article_norm_prop_id',
			'CML2_ARTICLE' => 'cml2_article_prop_id',
			'MF_BRAND_NORM' => 'brand_norm_prop_id',
			'MF_BRAND' => 'brand_prop_id',
		];
		if (class_exists(\CIBlockProperty::class))
		{
			foreach ($codeMap as $code => $metaKey)
			{
				$p = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code])->Fetch();
				if ($p)
				{
					$meta[$metaKey] = (int)$p['ID'];
				}
			}
		}

		$conn = mf_wgw_export_conn();
		if ($conn && $meta['version'] === 2)
		{
			try
			{
				$t = $conn->query("SHOW TABLES LIKE 'b_iblock_element_prop_s{$iblockId}'")->fetch();
				$meta['use_prop_s'] = (bool)$t;
			}
			catch (\Throwable $e)
			{
				$meta['use_prop_s'] = false;
			}
		}

		$cache[$iblockId] = $meta;

		return $meta;
	}
}

if (!function_exists('mf_wgw_export_sql_brand_expr'))
{
	function mf_wgw_export_sql_brand_expr(array $meta, string $mode): string
	{
		if ($mode === 'v2_s')
		{
			$brand = (int)$meta['brand_prop_id'];
			$brandNorm = (int)$meta['brand_norm_prop_id'];
			if ($brand > 0 && $brandNorm > 0)
			{
				return "COALESCE(NULLIF(TRIM(s.PROPERTY_{$brand}), ''), NULLIF(TRIM(s.PROPERTY_{$brandNorm}), ''))";
			}
			if ($brand > 0)
			{
				return "COALESCE(NULLIF(TRIM(s.PROPERTY_{$brand}), ''), '')";
			}
			if ($brandNorm > 0)
			{
				return "COALESCE(NULLIF(TRIM(s.PROPERTY_{$brandNorm}), ''), '')";
			}

			return "''";
		}

		if ((int)$meta['brand_prop_id'] > 0 && (int)$meta['brand_norm_prop_id'] > 0)
		{
			return "COALESCE(NULLIF(TRIM(MAX(p_brand.VALUE)), ''), NULLIF(TRIM(MAX(p_brand_norm.VALUE)), ''))";
		}
		if ((int)$meta['brand_prop_id'] > 0)
		{
			return "COALESCE(NULLIF(TRIM(MAX(p_brand.VALUE)), ''), '')";
		}
		if ((int)$meta['brand_norm_prop_id'] > 0)
		{
			return "COALESCE(NULLIF(TRIM(MAX(p_brand_norm.VALUE)), ''), '')";
		}

		return "''";
	}
}

if (!function_exists('mf_wgw_export_sql_article_expr'))
{
	function mf_wgw_export_sql_article_expr(array $meta, string $mode): string
	{
		if ($mode === 'v2_s')
		{
			$artNorm = (int)$meta['article_norm_prop_id'];
			$cml2 = (int)$meta['cml2_article_prop_id'];
			// Как в старом PHP-экспорте: CML2_ARTICLE, иначе MF_ARTICLE_NORM.
			if ($cml2 > 0 && $artNorm > 0)
			{
				return "COALESCE(NULLIF(TRIM(s.PROPERTY_{$cml2}), ''), NULLIF(TRIM(s.PROPERTY_{$artNorm}), ''))";
			}
			if ($cml2 > 0)
			{
				return "COALESCE(NULLIF(TRIM(s.PROPERTY_{$cml2}), ''), '')";
			}
			if ($artNorm > 0)
			{
				return "COALESCE(NULLIF(TRIM(s.PROPERTY_{$artNorm}), ''), '')";
			}

			return "''";
		}

		if ((int)$meta['cml2_article_prop_id'] > 0 && (int)$meta['article_norm_prop_id'] > 0)
		{
			return "COALESCE(NULLIF(TRIM(MAX(p_article.VALUE)), ''), NULLIF(TRIM(MAX(p_article_norm.VALUE)), ''))";
		}
		if ((int)$meta['cml2_article_prop_id'] > 0)
		{
			return "COALESCE(NULLIF(TRIM(MAX(p_article.VALUE)), ''), '')";
		}
		if ((int)$meta['article_norm_prop_id'] > 0)
		{
			return "COALESCE(NULLIF(TRIM(MAX(p_article_norm.VALUE)), ''), '')";
		}

		return "''";
	}
}

if (!function_exists('mf_wgw_export_sql_redirect_sql'))
{
	/** @return array{join: string, where: string} */
	function mf_wgw_export_sql_redirect_sql(array $meta, string $mode): array
	{
		$redirectId = (int)$meta['redirect_prop_id'];
		if ($redirectId <= 0)
		{
			return ['join' => '', 'where' => ''];
		}

		if ($mode === 'v2_s')
		{
			return [
				'join' => '',
				'where' => "AND (NULLIF(TRIM(s.PROPERTY_{$redirectId}), '') IS NULL OR TRIM(s.PROPERTY_{$redirectId}) <> 'Y')",
			];
		}

		return [
			'join' => "
LEFT JOIN b_iblock_element_property p_redir
  ON p_redir.IBLOCK_ELEMENT_ID = e.ID AND p_redir.IBLOCK_PROPERTY_ID = {$redirectId}",
			'where' => "AND (p_redir.VALUE IS NULL OR p_redir.VALUE <> 'Y')",
		];
	}
}

if (!function_exists('mf_wgw_export_sql_v1_joins'))
{
	function mf_wgw_export_sql_v1_joins(array $meta): string
	{
		$joins = '';
		$map = [
			'brand_prop_id' => ['alias' => 'p_brand', 'prefix' => 'prop_brand', 'code' => 'MF_BRAND'],
			'brand_norm_prop_id' => ['alias' => 'p_brand_norm', 'prefix' => 'prop_brand_norm', 'code' => 'MF_BRAND_NORM'],
			'cml2_article_prop_id' => ['alias' => 'p_article', 'prefix' => 'prop_article', 'code' => 'CML2_ARTICLE'],
			'article_norm_prop_id' => ['alias' => 'p_article_norm', 'prefix' => 'prop_article_norm', 'code' => 'MF_ARTICLE_NORM'],
		];
		foreach ($map as $metaKey => $cfg)
		{
			$pid = (int)$meta[$metaKey];
			if ($pid <= 0)
			{
				continue;
			}
			$joins .= "
LEFT JOIN b_iblock_element_property {$cfg['alias']}
  ON {$cfg['alias']}.IBLOCK_ELEMENT_ID = e.ID AND {$cfg['alias']}.IBLOCK_PROPERTY_ID = {$pid}";
		}

		return $joins;
	}
}

if (!function_exists('mf_wgw_export_fetch_batch'))
{
	/**
	 * @return list<array{brand: string, article: string, name: string, weight_g: int}>
	 */
	function mf_wgw_export_fetch_batch(int $iblockId, int $lastId, int $limit, array $meta): array
	{
		$conn = mf_wgw_export_conn();
		if (!$conn)
		{
			return [];
		}

		$iblockId = (int)$iblockId;
		$lastId = (int)$lastId;
		$limit = max(1, (int)$limit);
		$mode = !empty($meta['use_prop_s']) ? 'v2_s' : 'v1_join';
		$brandExpr = mf_wgw_export_sql_brand_expr($meta, $mode);
		$articleExpr = mf_wgw_export_sql_article_expr($meta, $mode);
		$redirect = mf_wgw_export_sql_redirect_sql($meta, $mode);

		if ($mode === 'v2_s')
		{
			$sql = "
SELECT
  e.ID AS ID,
  {$brandExpr} AS brand,
  {$articleExpr} AS article,
  TRIM(e.NAME) AS name,
  IFNULL(CAST(cp.WEIGHT AS SIGNED), 0) AS weight_g
FROM b_iblock_element e
INNER JOIN b_iblock_element_prop_s{$iblockId} s ON s.IBLOCK_ELEMENT_ID = e.ID
LEFT JOIN b_catalog_product cp ON cp.ID = e.ID
{$redirect['join']}
WHERE e.IBLOCK_ID = {$iblockId}
  AND e.ACTIVE = 'Y'
  AND e.ID > {$lastId}
  {$redirect['where']}
  AND {$brandExpr} <> ''
  AND {$articleExpr} <> ''
ORDER BY e.ID
LIMIT {$limit}";
		}
		else
		{
			$v1Joins = mf_wgw_export_sql_v1_joins($meta);
			$sql = "
SELECT
  e.ID AS ID,
  {$brandExpr} AS brand,
  {$articleExpr} AS article,
  TRIM(MAX(e.NAME)) AS name,
  IFNULL(CAST(MAX(cp.WEIGHT) AS SIGNED), 0) AS weight_g
FROM b_iblock_element e
LEFT JOIN b_catalog_product cp ON cp.ID = e.ID
{$v1Joins}
{$redirect['join']}
WHERE e.IBLOCK_ID = {$iblockId}
  AND e.ACTIVE = 'Y'
  AND e.ID > {$lastId}
  {$redirect['where']}
GROUP BY e.ID
HAVING brand <> '' AND article <> ''
ORDER BY e.ID
LIMIT {$limit}";
		}

		$rows = [];
		try
		{
			$res = $conn->query($sql);
			while ($row = $res->fetch())
			{
				$rows[] = [
					'ID' => (int)($row['ID'] ?? 0),
					'brand' => trim((string)($row['brand'] ?? '')),
					'article' => trim((string)($row['article'] ?? '')),
					'name' => trim((string)($row['name'] ?? '')),
					'weight_g' => (int)($row['weight_g'] ?? 0),
				];
			}
		}
		catch (\Throwable $e)
		{
			return [];
		}

		return $rows;
	}
}

if (!function_exists('mf_wgw_export_rows_batched'))
{
	/**
	 * @return \Generator<int, array{0: string, 1: string, 2: string, 3: int}>
	 */
	function mf_wgw_export_rows_batched(int $iblockId, ?int $batchSize = null): \Generator
	{
		$iblockId = (int)$iblockId;
		if ($iblockId <= 0)
		{
			return;
		}

		$batchSize = $batchSize ?? mf_wgw_export_batch_size();
		$batchSize = max(1000, min(100000, (int)$batchSize));
		$meta = mf_wgw_export_property_meta($iblockId);
		$lastId = 0;

		for (;;)
		{
			$batch = mf_wgw_export_fetch_batch($iblockId, $lastId, $batchSize, $meta);
			if ($batch === [])
			{
				break;
			}

			foreach ($batch as $row)
			{
				$id = (int)($row['ID'] ?? 0);
				if ($id > $lastId)
				{
					$lastId = $id;
				}
				if ($row['brand'] === '' || $row['article'] === '')
				{
					continue;
				}

				yield [
					$row['brand'],
					$row['article'],
					$row['name'],
					(int)$row['weight_g'],
				];
			}

			if (count($batch) < $batchSize)
			{
				break;
			}

			if (function_exists('connection_aborted') && connection_aborted())
			{
				break;
			}
		}
	}
}
