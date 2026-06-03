<?php

declare(strict_types=1);

use Bitrix\Main\Application;

/**
 * Парсинг и обработка bulk CSV импорта аналогов (admin + фоновая job).
 */

if (!function_exists('mf_parse_originals_cell'))
{
	/**
	 * @return array<int, array{brand:string,article:string,raw:string}>
	 */
	function mf_parse_originals_cell(string $s): array
	{
		$s = trim((string)$s);
		if ($s === '')
		{
			return [];
		}
		$parts = preg_split('~\\s*,\\s*~u', $s) ?: [];
		$out = [];
		foreach ($parts as $p)
		{
			$p = trim((string)$p);
			if ($p === '')
			{
				continue;
			}
			if (str_contains($p, ':'))
			{
				[$b, $a] = explode(':', $p, 2);
			}
			elseif (str_contains($p, '|'))
			{
				[$b, $a] = explode('|', $p, 2);
			}
			else
			{
				$out[] = ['brand' => '', 'article' => '', 'raw' => $p];
				continue;
			}
			$out[] = ['brand' => trim((string)$b), 'article' => trim((string)$a), 'raw' => $p];
		}

		return $out;
	}
}

if (!function_exists('mf_parse_bulk_analogs_csv'))
{
	/**
	 * @return array{rows: list<array<string, mixed>>, idx: array<string, int>}
	 */
	function mf_parse_bulk_analogs_csv(string $text): array
	{
		$text = mf_analogs_to_utf8((string)$text);
		$lines = preg_split('~\\R+~u', $text) ?: [];

		$header = null;
		$idx = [];
		$rows = [];

		foreach ($lines as $line)
		{
			$line = trim((string)$line);
			if ($line === '' || str_starts_with($line, '#'))
			{
				continue;
			}

			$cols = str_getcsv($line, ';', '"');
			if (!is_array($cols) || empty($cols))
			{
				continue;
			}

			if ($header === null)
			{
				$header = $cols;
				foreach ($header as $i => $name)
				{
					$name = trim((string)$name);
					if ($name !== '')
					{
						$idx[$name] = (int)$i;
					}
				}
				continue;
			}

			$orig = [];
			if (isset($idx['Оригиналы']))
			{
				$origCell = trim((string)($cols[$idx['Оригиналы']] ?? ''));
				$orig = mf_parse_originals_cell($origCell);
			}
			elseif (isset($idx['ОригиналБренд']) && isset($idx['ОригиналАртикул']))
			{
				$ob = trim((string)($cols[$idx['ОригиналБренд']] ?? ''));
				$oaCell = trim((string)($cols[$idx['ОригиналАртикул']] ?? ''));
				if ($ob !== '' && $oaCell !== '')
				{
					$arts = preg_split('~\\s*,\\s*~u', $oaCell) ?: [];
					foreach ($arts as $a)
					{
						$a = trim((string)$a);
						if ($a === '')
						{
							continue;
						}
						$orig[] = ['brand' => $ob, 'article' => $a, 'raw' => $ob . ':' . $a];
					}
				}
			}

			$brand = trim((string)($cols[$idx['Бренд'] ?? -1] ?? ''));
			$article = trim((string)($cols[$idx['Артикул'] ?? -1] ?? ''));
			if ($brand === '' || $article === '' || empty($orig))
			{
				continue;
			}

			$rows[] = [
				'originals' => $orig,
				'analog_brand' => $brand,
				'analog_article' => $article,
				'raw' => $line,
			];
		}

		return ['rows' => $rows, 'idx' => $idx];
	}
}

if (!function_exists('mf_analogs_bulk_lookup_cache_key'))
{
	function mf_analogs_bulk_lookup_cache_key(string $brand, string $article): string
	{
		return mf_analogs_norm_brand($brand) . "\x1E" . mf_analogs_norm_article($article);
	}
}

if (!function_exists('mf_analogs_bulk_collect_pairs_from_rows'))
{
	/**
	 * Уникальные пары бренд+артикул из всех строк CSV (аналог + оригиналы).
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<array{brand:string, article:string, brand_norm:string, article_norm:string, key:string}>
	 */
	function mf_analogs_bulk_collect_pairs_from_rows(array $rows): array
	{
		$seen = [];
		$out = [];
		$add = static function (string $brand, string $article) use (&$seen, &$out): void {
			$brandNorm = mf_analogs_norm_brand($brand);
			$articleNorm = mf_analogs_norm_article($article);
			if ($brandNorm === '' || $articleNorm === '')
			{
				return;
			}
			$key = $brandNorm . "\x1E" . $articleNorm;
			if (isset($seen[$key]))
			{
				return;
			}
			$seen[$key] = true;
			$out[] = [
				'brand' => $brand,
				'article' => $article,
				'brand_norm' => $brandNorm,
				'article_norm' => $articleNorm,
				'key' => $key,
			];
		};

		foreach ($rows as $row)
		{
			$add((string)($row['analog_brand'] ?? ''), (string)($row['analog_article'] ?? ''));
			foreach ((array)($row['originals'] ?? []) as $o)
			{
				$add((string)($o['brand'] ?? ''), (string)($o['article'] ?? ''));
			}
		}

		return $out;
	}
}

if (!function_exists('mf_analogs_bulk_load_cud_meta'))
{
	/**
	 * @return array<string, int>|null
	 */
	function mf_analogs_bulk_load_cud_meta(int $iblockId): ?array
	{
		if (!function_exists('mf_cud_iblock_property_meta'))
		{
			$lib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_catalog_uniq_duplicates_lib.php';
			if (!is_file($lib))
			{
				$lib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/bitrix/php_interface/include/mf_catalog_uniq_duplicates_lib.php';
			}
			if (!is_file($lib))
			{
				return null;
			}
			require_once $lib;
		}
		if (!function_exists('mf_cud_iblock_property_meta'))
		{
			return null;
		}

		return mf_cud_iblock_property_meta($iblockId);
	}
}

if (!function_exists('mf_analogs_bulk_prewarm_lookup_sql'))
{
	/**
	 * Один проход SQL: MF_BRAND_NORM + MF_ARTICLE_NORM → ID (strict, как первый шаг GetList).
	 * Промахи остаются без ключа — дальше mf_analogs_bulk_find_cached() с fallback в PHP.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @param array<string, int|null> $cache
	 * @return array{pairs: int, resolved: int, chunks: int, skipped: bool}
	 */
	function mf_analogs_bulk_prewarm_lookup_sql(int $iblockId, array $rows, array &$cache, int $jobId = 0): array
	{
		$empty = ['pairs' => 0, 'resolved' => 0, 'chunks' => 0, 'skipped' => true];
		$iblockId = (int)$iblockId;
		if ($iblockId <= 0 || $rows === [])
		{
			return $empty;
		}

		$meta = mf_analogs_bulk_load_cud_meta($iblockId);
		$brandPid = (int)($meta['brand_norm_prop_id'] ?? 0);
		$articlePid = (int)($meta['article_norm_prop_id'] ?? 0);
		if ($brandPid <= 0 || $articlePid <= 0)
		{
			return $empty;
		}

		$pairs = mf_analogs_bulk_collect_pairs_from_rows($rows);
		$pairCount = count($pairs);
		if ($pairCount === 0)
		{
			return ['pairs' => 0, 'resolved' => 0, 'chunks' => 0, 'skipped' => true];
		}

		if ($jobId > 0 && function_exists('mf_analogs_import_job_progress_apply'))
		{
			mf_analogs_import_job_progress_apply(
				$jobId,
				1,
				'SQL: поиск ID по каталогу (' . $pairCount . ' уник. пар бренд+артикул)…'
			);
		}

		$neededKeys = [];
		foreach ($pairs as $p)
		{
			$neededKeys[(string)$p['key']] = true;
		}

		$conn = Application::getConnection();
		$h = $conn->getSqlHelper();
		$resolved = 0;
		$chunks = 0;
		$chunkSize = 350;

		foreach (array_chunk($pairs, $chunkSize) as $chunk)
		{
			$chunks++;
			$tuples = [];
			foreach ($chunk as $p)
			{
				$bn = (string)$p['brand_norm'];
				$an = (string)$p['article_norm'];
				$tuples[] = "('" . $h->forSql($bn) . "','" . $h->forSql($an) . "')";
			}
			if ($tuples === [])
			{
				continue;
			}

			$sql = "
				SELECT e.ID, bn.VALUE AS BN, an.VALUE AS AN
				FROM b_iblock_element e
				INNER JOIN b_iblock_element_property an
					ON an.IBLOCK_ELEMENT_ID = e.ID AND an.IBLOCK_PROPERTY_ID = {$articlePid}
				INNER JOIN b_iblock_element_property bn
					ON bn.IBLOCK_ELEMENT_ID = e.ID AND bn.IBLOCK_PROPERTY_ID = {$brandPid}
				WHERE e.IBLOCK_ID = {$iblockId}
					AND e.ACTIVE = 'Y'
					AND (bn.VALUE, an.VALUE) IN (" . implode(',', $tuples) . ")
				ORDER BY e.ID ASC
			";

			try
			{
				$rs = $conn->query($sql);
			}
			catch (\Throwable $e)
			{
				if (function_exists('mf_analogs_import_job_diag_write'))
				{
					mf_analogs_import_job_diag_write('prewarm SQL chunk failed: ' . $e->getMessage());
				}
				continue;
			}

			while ($r = $rs->fetch())
			{
				$bn = trim((string)($r['BN'] ?? ''));
				$an = trim((string)($r['AN'] ?? ''));
				$id = (int)($r['ID'] ?? 0);
				if ($id <= 0 || $bn === '' || $an === '')
				{
					continue;
				}
				$key = $bn . "\x1E" . $an;
				if (!isset($neededKeys[$key]) || array_key_exists($key, $cache))
				{
					continue;
				}
				$cache[$key] = $id;
				$resolved++;
			}
		}

		if ($jobId > 0 && function_exists('mf_analogs_import_job_progress_apply'))
		{
			mf_analogs_import_job_progress_apply(
				$jobId,
				2,
				'SQL: найдено ' . $resolved . ' из ' . $pairCount . ' пар, обработка строк…'
			);
		}

		return [
			'pairs' => $pairCount,
			'resolved' => $resolved,
			'chunks' => $chunks,
			'skipped' => false,
		];
	}
}

if (!function_exists('mf_analogs_bulk_find_cached'))
{
	/**
	 * @param array<string, int|null> $cache
	 */
	function mf_analogs_bulk_find_cached(int $iblockId, string $brand, string $article, array &$cache): ?int
	{
		$key = mf_analogs_bulk_lookup_cache_key($brand, $article);
		if (array_key_exists($key, $cache))
		{
			$v = $cache[$key];

			return $v !== null && (int)$v > 0 ? (int)$v : null;
		}
		$id = mf_analogs_find_product_id_by_brand_article($iblockId, $brand, $article);
		$cache[$key] = ($id && (int)$id > 0) ? (int)$id : null;

		return $cache[$key];
	}
}

if (!function_exists('mf_analogs_bulk_pair_key'))
{
	function mf_analogs_bulk_pair_key(int $a, int $b): string
	{
		$p1 = min($a, $b);
		$p2 = max($a, $b);

		return $p1 . ':' . $p2;
	}
}

if (!function_exists('mf_analogs_bulk_flush_links'))
{
	/**
	 * @param array<string, true> $pending
	 */
	function mf_analogs_bulk_flush_links(array &$pending, string $source): int
	{
		if ($pending === [])
		{
			return 0;
		}
		$meta = mf_analogs_ensure_hl();
		$table = (string)$meta['TABLE'];
		$conn = Application::getConnection();
		$sqlHelper = $conn->getSqlHelper();
		$createdAt = (new \Bitrix\Main\Type\DateTime())->format('Y-m-d H:i:s');
		$createdAtSql = "'" . $sqlHelper->forSql($createdAt) . "'";
		$sourceSql = "'" . $sqlHelper->forSql($source) . "'";

		$chunks = array_chunk(array_keys($pending), 400);
		$pending = [];
		$attempted = 0;
		foreach ($chunks as $chunk)
		{
			$vals = [];
			foreach ($chunk as $pairKey)
			{
				$parts = explode(':', (string)$pairKey, 2);
				if (count($parts) !== 2)
				{
					continue;
				}
				$p1 = (int)$parts[0];
				$p2 = (int)$parts[1];
				if ($p1 <= 0 || $p2 <= 0 || $p1 === $p2)
				{
					continue;
				}
				$vals[] = '(' . $p1 . ',' . $p2 . ',500,' . $sourceSql . ',' . $createdAtSql . ')';
				$attempted++;
			}
			if ($vals === [])
			{
				continue;
			}
			$sql = 'INSERT IGNORE INTO `' . str_replace('`', '', $table) . '` (`UF_P1_ID`,`UF_P2_ID`,`UF_SORT`,`UF_SOURCE`,`UF_CREATED_AT`) VALUES '
				. implode(',', $vals);
			$conn->queryExecute($sql);
		}

		return $attempted;
	}
}

if (!function_exists('mf_analogs_bulk_run_import_rows'))
{
	/**
	 * @param list<array<string, mixed>> $rows
	 * @param array{job_id?: int, rows_total?: int} $opts
	 * @return array{rows: int, linked: int, not_found: list<string>, not_found_more: int, rows_processed: int}
	 */
	function mf_analogs_bulk_run_import_rows(int $iblockId, array $rows, string $source, array $opts = []): array
	{
		$jobId = (int)($opts['job_id'] ?? 0);
		$rowsTotal = (int)($opts['rows_total'] ?? count($rows));
		if ($rowsTotal <= 0)
		{
			$rowsTotal = count($rows);
		}

		$linked = 0;
		$notFound = [];
		$notFoundCap = 400;
		$notFoundMore = 0;
		$lookupCache = [];
		mf_analogs_bulk_prewarm_lookup_sql($iblockId, $rows, $lookupCache, $jobId);
		/** @var array<string, true> $pendingLinks */
		$pendingLinks = [];
		$processed = 0;
		$flushEvery = 250;
		$progressEvery = 50;

		foreach ($rows as $row)
		{
			$processed++;
			$analogId = mf_analogs_bulk_find_cached(
				$iblockId,
				(string)$row['analog_brand'],
				(string)$row['analog_article'],
				$lookupCache
			);
			if (!$analogId || $analogId <= 0)
			{
				if (count($notFound) < $notFoundCap)
				{
					$notFound[] = 'АНАЛОГ: ' . (string)$row['analog_brand'] . ';' . (string)$row['analog_article'];
				}
				else
				{
					$notFoundMore++;
				}
				if ($jobId > 0 && $processed % $progressEvery === 0 && function_exists('mf_analogs_import_job_progress_apply'))
				{
					$pct = $rowsTotal > 0 ? (int)floor(100 * $processed / $rowsTotal) : 0;
					mf_analogs_import_job_progress_apply(
						$jobId,
						min(99, max(1, $pct)),
						'Строк ' . $processed . ' / ' . $rowsTotal . ', связей ~' . $linked
					);
					if (function_exists('mf_analogs_import_job_update'))
					{
						mf_analogs_import_job_update($jobId, ['UF_ROWS_DONE' => $processed]);
					}
				}
				continue;
			}

			$origIds = [];
			foreach ((array)($row['originals'] ?? []) as $o)
			{
				$ob = trim((string)($o['brand'] ?? ''));
				$oa = trim((string)($o['article'] ?? ''));
				if ($ob === '' || $oa === '')
				{
					if (count($notFound) < $notFoundCap)
					{
						$notFound[] = 'ОРИГИНАЛ: ' . (string)($o['raw'] ?? '');
					}
					else
					{
						$notFoundMore++;
					}
					continue;
				}
				$origId = mf_analogs_bulk_find_cached($iblockId, $ob, $oa, $lookupCache);
				if (!$origId || $origId <= 0)
				{
					if (count($notFound) < $notFoundCap)
					{
						$notFound[] = 'ОРИГИНАЛ: ' . $ob . ';' . $oa;
					}
					else
					{
						$notFoundMore++;
					}
					continue;
				}
				$origIds[] = (int)$origId;
			}

			$origIds = array_values(array_unique(array_filter($origIds, static fn($x) => (int)$x > 0)));
			foreach ($origIds as $oid)
			{
				$pendingLinks[mf_analogs_bulk_pair_key((int)$oid, (int)$analogId)] = true;
			}
			$nOrig = count($origIds);
			if ($nOrig >= 2)
			{
				for ($i = 0; $i < $nOrig; $i++)
				{
					for ($j = $i + 1; $j < $nOrig; $j++)
					{
						$pendingLinks[mf_analogs_bulk_pair_key((int)$origIds[$i], (int)$origIds[$j])] = true;
					}
				}
			}

			if (count($pendingLinks) >= $flushEvery)
			{
				$linked += mf_analogs_bulk_flush_links($pendingLinks, $source);
			}

			if ($jobId > 0 && $processed % $progressEvery === 0 && function_exists('mf_analogs_import_job_progress_apply'))
			{
				$pct = $rowsTotal > 0 ? (int)floor(100 * $processed / $rowsTotal) : 0;
				mf_analogs_import_job_progress_apply(
					$jobId,
					min(99, max(1, $pct)),
					'Строк ' . $processed . ' / ' . $rowsTotal . ', связей ~' . $linked
				);
				if (function_exists('mf_analogs_import_job_update'))
				{
					mf_analogs_import_job_update($jobId, ['UF_ROWS_DONE' => $processed]);
				}
			}
		}

		$linked += mf_analogs_bulk_flush_links($pendingLinks, $source);

		if ($jobId > 0 && function_exists('mf_analogs_import_job_update'))
		{
			mf_analogs_import_job_update($jobId, [
				'UF_ROWS_DONE' => $processed,
				'UF_ROWS_TOTAL' => $rowsTotal,
			]);
		}

		return [
			'rows' => count($rows),
			'linked' => $linked,
			'not_found' => $notFound,
			'not_found_more' => $notFoundMore,
			'rows_processed' => $processed,
		];
	}
}

if (!function_exists('mf_analogs_bulk_run_import_file'))
{
	/**
	 * @param array{job_id?: int} $opts
	 * @return array<string, mixed>
	 */
	function mf_analogs_bulk_run_import_file(int $iblockId, string $absPath, string $source, array $opts = []): array
	{
		if (!is_file($absPath) || !is_readable($absPath))
		{
			return [
				'rows' => 0,
				'linked' => 0,
				'not_found' => [],
				'not_found_more' => 0,
				'rows_processed' => 0,
				'_fatal' => true,
				'errors' => ['Файл не найден или недоступен для чтения.'],
			];
		}
		$text = (string)file_get_contents($absPath);
		$parsed = mf_parse_bulk_analogs_csv($text);
		$rows = (array)($parsed['rows'] ?? []);
		if ($rows === [])
		{
			return [
				'rows' => 0,
				'linked' => 0,
				'not_found' => [],
				'not_found_more' => 0,
				'rows_processed' => 0,
				'_fatal' => true,
				'errors' => ['Файл пуст или не распознан. Нужны колонки Бренд, Артикул и Оригиналы (или ОригиналБренд+ОригиналАртикул).'],
			];
		}

		$jobId = (int)($opts['job_id'] ?? 0);
		$opts['rows_total'] = count($rows);

		return mf_analogs_bulk_run_import_rows($iblockId, $rows, $source, $opts);
	}
}

if (!function_exists('mf_analogs_bulk_result_json'))
{
	/**
	 * @param array<string, mixed> $stats
	 */
	function mf_analogs_bulk_result_json(array $stats): string
	{
		$flags = JSON_UNESCAPED_UNICODE;
		if (defined('JSON_INVALID_UTF8_SUBSTITUTE'))
		{
			$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
		}
		$payload = [
			'rows' => (int)($stats['rows'] ?? 0),
			'linked' => (int)($stats['linked'] ?? 0),
			'rows_processed' => (int)($stats['rows_processed'] ?? 0),
			'not_found' => is_array($stats['not_found'] ?? null) ? $stats['not_found'] : [],
			'not_found_more' => (int)($stats['not_found_more'] ?? 0),
		];
		$js = json_encode($payload, $flags);

		return ($js !== false && $js !== '') ? $js : '{}';
	}
}
