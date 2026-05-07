<?php

/**
 * Импорт веса из .xlsx (без PhpSpreadsheet): колонки G(7) — вес, H(8) — артикул, J(10) — бренд.
 */

declare(strict_types=1);

if (!function_exists('mf_wxi_col_letters_to_index'))
{
	function mf_wxi_col_letters_to_index(string $letters): int
	{
		$letters = strtoupper(trim($letters));
		$n = 0;
		$len = strlen($letters);
		for ($i = 0; $i < $len; $i++)
		{
			$ord = ord($letters[$i]);
			if ($ord < 65 || $ord > 90)
			{
				return 0;
			}
			$n = $n * 26 + ($ord - 64);
		}

		return $n;
	}
}

if (!function_exists('mf_wxi_si_to_plain_text'))
{
	function mf_wxi_si_to_plain_text(string $siXml): string
	{
		$sx = @simplexml_load_string($siXml);
		if (!$sx)
		{
			return '';
		}
		if (isset($sx->t))
		{
			return (string)$sx->t;
		}
		$parts = [];
		if (isset($sx->r))
		{
			foreach ($sx->r as $r)
			{
				$parts[] = (string)$r->t;
			}
		}

		return implode('', $parts);
	}
}

if (!function_exists('mf_wxi_load_shared_strings'))
{
	/**
	 * @return list<string>
	 */
	function mf_wxi_load_shared_strings(string $xlsxPath): array
	{
		$path = 'zip://' . str_replace('\\', '/', $xlsxPath) . '#xl/sharedStrings.xml';
		if (!is_file($xlsxPath) || !is_readable($xlsxPath))
		{
			return [];
		}

		$reader = new XMLReader();
		if (!@$reader->open($path))
		{
			return [];
		}

		$out = [];
		while ($reader->read())
		{
			if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'si')
			{
				$out[] = mf_wxi_si_to_plain_text($reader->readOuterXML());
			}
		}
		$reader->close();

		return $out;
	}
}

if (!function_exists('mf_wxi_first_worksheet_entry'))
{
	function mf_wxi_first_worksheet_entry(string $xlsxPath): ?string
	{
		if (!class_exists(ZipArchive::class))
		{
			return null;
		}
		$zip = new ZipArchive();
		if ($zip->open($xlsxPath) !== true)
		{
			return null;
		}
		$candidates = [];
		for ($i = 0; $i < $zip->numFiles; $i++)
		{
			$n = (string)$zip->getNameIndex($i);
			if (preg_match('~^xl/worksheets/sheet(\d+)\.xml$~', $n, $m))
			{
				$candidates[(int)$m[1]] = $n;
			}
		}
		$zip->close();
		if ($candidates === [])
		{
			return null;
		}
		ksort($candidates, SORT_NUMERIC);

		return reset($candidates) ?: null;
	}
}

if (!function_exists('mf_wxi_cell_plain_value'))
{
	function mf_wxi_cell_plain_value(SimpleXMLElement $c, array $sst): string
	{
		$t = (string)($c['t'] ?? '');
		$v = isset($c->v) ? (string)$c->v : '';
		if ($t === 's' && $v !== '' && isset($sst[(int)$v]))
		{
			return (string)$sst[(int)$v];
		}
		if ($t === 'inlineStr' && isset($c->is->t))
		{
			return (string)$c->is->t;
		}

		return $v;
	}
}

if (!function_exists('mf_wxi_row_cells_by_col_index'))
{
	/**
	 * @return array<int, string> 1-based column index => trimmed string
	 */
	function mf_wxi_row_cells_by_col_index(string $rowXml, array $sst): array
	{
		$sx = @simplexml_load_string($rowXml);
		if (!$sx || !isset($sx->c))
		{
			return [];
		}

		$map = [];
		foreach ($sx->c as $c)
		{
			$r = (string)($c['r'] ?? '');
			if ($r === '' || !preg_match('~^([A-Z]+)(\d+)$~', $r, $m))
			{
				continue;
			}
			$colIdx = mf_wxi_col_letters_to_index($m[1]);
			if ($colIdx <= 0)
			{
				continue;
			}
			$map[$colIdx] = trim(mf_wxi_cell_plain_value($c, $sst));
		}

		return $map;
	}
}

if (!function_exists('mf_wxi_clean_article_string'))
{
	function mf_wxi_clean_article_string(string $s): string
	{
		$s = trim($s);
		if ($s === '')
		{
			return '';
		}
		if (is_numeric($s))
		{
			$f = (float)$s;
			if (is_finite($f) && abs($f - round($f)) < 1e-9)
			{
				return (string)(int)round($f);
			}
		}

		return $s;
	}
}

if (!function_exists('mf_wxi_parse_weight'))
{
	function mf_wxi_parse_weight(string $raw): ?float
	{
		$raw = trim(str_replace([' ', ','], ['', '.'], $raw));
		if ($raw === '')
		{
			return null;
		}
		$v = (float)$raw;
		if (!is_finite($v) || $v < 0)
		{
			return null;
		}

		return $v;
	}
}

if (!function_exists('mf_wxi_import_file'))
{
	/**
	 * @param callable|null $onProgress Вызывается каждые $progressEveryRows обработанных строк данных (после строки заголовка).
	 *        Аргумент: array{phase: 'running', rows_seen: int, excel_row: int, ok: int, not_found: int, bad: int, weight_fail: int}
	 * @return array{
	 *   ok: int,
	 *   not_found: int,
	 *   bad: int,
	 *   skipped_header: int,
	 *   weight_fail: int,
	 *   rows_seen: int,
	 *   examples_not_found: list<string>
	 * }
	 */
	function mf_wxi_import_file(
		string $xlsxPath,
		int $iblockId,
		bool $weightInKilograms,
		?callable $onProgress = null,
		int $progressEveryRows = 400
	): array
	{
		$ok = 0;
		$notFound = 0;
		$bad = 0;
		$skippedHeader = 0;
		$weightFail = 0;
		$rowsSeen = 0;
		$examplesNotFound = [];

		if (!is_file($xlsxPath) || !is_readable($xlsxPath))
		{
			return [
				'ok' => 0,
				'not_found' => 0,
				'bad' => 0,
				'skipped_header' => 0,
				'weight_fail' => 0,
				'rows_seen' => 0,
				'examples_not_found' => [],
			];
		}

		$sst = mf_wxi_load_shared_strings($xlsxPath);
		$sheet = mf_wxi_first_worksheet_entry($xlsxPath);
		if ($sheet === null)
		{
			return [
				'ok' => 0,
				'not_found' => 0,
				'bad' => 1,
				'skipped_header' => 0,
				'weight_fail' => 0,
				'rows_seen' => 0,
				'examples_not_found' => [],
			];
		}

		$zipPath = 'zip://' . str_replace('\\', '/', $xlsxPath) . '#' . $sheet;
		$reader = new XMLReader();
		if (!@$reader->open($zipPath))
		{
			return [
				'ok' => 0,
				'not_found' => 0,
				'bad' => 1,
				'skipped_header' => 0,
				'weight_fail' => 0,
				'rows_seen' => 0,
				'examples_not_found' => [],
			];
		}

		$lib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_external_price_lib.php';
		if (is_file($lib))
		{
			require_once $lib;
		}

		$implicitRow = 0;
		while ($reader->read())
		{
			if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'row')
			{
				continue;
			}

			$rowXml = $reader->readOuterXML();
			$rowSx = @simplexml_load_string($rowXml);
			$rAttr = ($rowSx && isset($rowSx['r'])) ? (int)$rowSx['r'] : 0;
			$implicitRow++;
			$effectiveRow = $rAttr > 0 ? $rAttr : $implicitRow;
			if ($effectiveRow === 1)
			{
				$skippedHeader++;
				continue;
			}
			$cells = mf_wxi_row_cells_by_col_index($rowXml, $sst);
			$weightRaw = (string)($cells[7] ?? '');
			$articleRaw = (string)($cells[8] ?? '');
			$brandRaw = (string)($cells[10] ?? '');

			$rowsSeen++;

			$wVal = mf_wxi_parse_weight($weightRaw);
			$article = mf_wxi_clean_article_string($articleRaw);
			$brandTrim = trim($brandRaw);

			if ($wVal === null || $article === '' || $brandTrim === '')
			{
				$bad++;
			}
			else
			{
				$grams = $weightInKilograms ? (int)round($wVal * 1000.0) : (int)round($wVal);
				if ($grams < 0)
				{
					$bad++;
				}
				else
				{
					$canon = function_exists('mf_brand_find') ? mf_brand_find($brandTrim, false) : '';
					$bRaw = $canon !== '' ? $canon : $brandTrim;
					$articleNorm = function_exists('mf_ep_norm_article') ? mf_ep_norm_article($article) : mb_strtoupper(preg_replace('~[^A-Z0-9]+~', '', mb_strtoupper($article)) ?? '');
					$brandNorm = function_exists('mf_ep_norm_brand') ? mf_ep_norm_brand($bRaw) : '';

					if (!function_exists('mf_ep_find_product'))
					{
						$bad++;
					}
					else
					{
						$pid = mf_ep_find_product($iblockId, $articleNorm, $bRaw, $brandNorm);
						if ($pid === null || $pid <= 0)
						{
							$notFound++;
							if (count($examplesNotFound) < 20)
							{
								$examplesNotFound[] = $brandTrim . ' / ' . $article;
							}
						}
						elseif (function_exists('mf_ep_set_weight_for_catalog_cluster'))
						{
							$f = mf_ep_set_weight_for_catalog_cluster((int)$pid, $grams);
							$weightFail += $f;
							$ok++;
						}
						else
						{
							$bad++;
						}
					}
				}
			}

			if (
				$onProgress !== null
				&& $progressEveryRows > 0
				&& ($rowsSeen % $progressEveryRows === 0)
			)
			{
				$onProgress([
					'phase' => 'running',
					'rows_seen' => $rowsSeen,
					'excel_row' => $effectiveRow,
					'ok' => $ok,
					'not_found' => $notFound,
					'bad' => $bad,
					'weight_fail' => $weightFail,
				]);
			}
		}
		$reader->close();

		return [
			'ok' => $ok,
			'not_found' => $notFound,
			'bad' => $bad,
			'skipped_header' => $skippedHeader,
			'weight_fail' => $weightFail,
			'rows_seen' => $rowsSeen,
			'examples_not_found' => $examplesNotFound,
		];
	}
}

if (!function_exists('mf_wxi_map_csv_weight_headers'))
{
	/**
	 * @return array{brand?:int, article?:int, name?:int, weight?:int}
	 */
	function mf_wxi_map_csv_weight_headers(array $headerCells): array
	{
		$map = [];
		foreach ($headerCells as $i => $cell)
		{
			$h = mb_strtolower(trim((string)$cell));
			$h = preg_replace('~^\xEF\xBB\xBF~', '', $h) ?? $h;
			$h = trim($h);
			if ($h === '')
			{
				continue;
			}
			if (preg_match('~(бренд|brand|производитель|vendor)~u', $h))
			{
				$map['brand'] = (int)$i;
			}
			elseif (preg_match('~(артикул|article|sku|oem)~u', $h))
			{
				$map['article'] = (int)$i;
			}
			elseif (preg_match('~(наименование|название|наим|name|title|товар)~u', $h))
			{
				$map['name'] = (int)$i;
			}
			elseif (preg_match('~(^вес|вес\(|^weight|масса|грамм|грам\.)~u', $h))
			{
				$map['weight'] = (int)$i;
			}
		}

		return $map;
	}
}

if (!function_exists('mf_wxi_detect_csv_delimiter'))
{
	function mf_wxi_detect_csv_delimiter(string $firstLine): string
	{
		$firstLine = preg_replace('~^\xEF\xBB\xBF~', '', $firstLine) ?? $firstLine;
		$sc = substr_count($firstLine, ';');
		$cc = substr_count($firstLine, ',');
		$tc = substr_count($firstLine, "\t");
		if ($tc >= $sc && $tc >= $cc && $tc > 0)
		{
			return "\t";
		}

		return $sc >= $cc ? ';' : ',';
	}
}

if (!function_exists('mf_wxi_import_csv_file'))
{
	/**
	 * Импорт веса из CSV: бренд, артикул, [наименование], вес (по заголовкам; иначе 3 или 4 колонки по порядку). Наименование не используется для поиска товара.
	 * Разделитель ; или , первая строка — заголовок.
	 *
	 * @return array{
	 *   ok: int, not_found: int, bad: int, skipped_header: int, weight_fail: int, rows_seen: int,
	 *   examples_not_found: list<string>
	 * }
	 */
	function mf_wxi_import_csv_file(
		string $csvPath,
		int $iblockId,
		bool $weightInKilograms
	): array
	{
		$iblockId = (int)$iblockId;
		if ($iblockId <= 0)
		{
			return [
				'ok' => 0,
				'not_found' => 0,
				'bad' => 1,
				'skipped_header' => 0,
				'weight_fail' => 0,
				'rows_seen' => 0,
				'examples_not_found' => [],
			];
		}

		$ok = 0;
		$notFound = 0;
		$bad = 0;
		$skippedHeader = 1;
		$weightFail = 0;
		$rowsSeen = 0;
		$examplesNotFound = [];

		if (!is_file($csvPath) || !is_readable($csvPath))
		{
			return [
				'ok' => 0,
				'not_found' => 0,
				'bad' => 0,
				'skipped_header' => 0,
				'weight_fail' => 0,
				'rows_seen' => 0,
				'examples_not_found' => [],
			];
		}

		$lib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_external_price_lib.php';
		if (is_file($lib))
		{
			require_once $lib;
		}

		$brandDict = $_SERVER['DOCUMENT_ROOT'] . '/mf_brand_dict.php';
		if (is_file($brandDict))
		{
			require_once $brandDict;
		}

		$h = fopen($csvPath, 'rb');
		if ($h === false)
		{
			return [
				'ok' => 0,
				'not_found' => 0,
				'bad' => 1,
				'skipped_header' => 0,
				'weight_fail' => 0,
				'rows_seen' => 0,
				'examples_not_found' => [],
			];
		}

		$line1 = fgets($h);
		if ($line1 === false || trim($line1) === '')
		{
			fclose($h);

			return [
				'ok' => 0,
				'not_found' => 0,
				'bad' => 1,
				'skipped_header' => 0,
				'weight_fail' => 0,
				'rows_seen' => 0,
				'examples_not_found' => [],
			];
		}

		$delim = mf_wxi_detect_csv_delimiter($line1);
		$line1 = preg_replace('~^\xEF\xBB\xBF~', '', $line1) ?? $line1;
		$header = str_getcsv($line1, $delim);
		$map = mf_wxi_map_csv_weight_headers($header);
		if (!isset($map['brand'], $map['article'], $map['weight']) && count($header) >= 4)
		{
			$map = [
				'brand' => 0,
				'article' => 1,
				'name' => 2,
				'weight' => 3,
			];
		}
		if (!isset($map['brand'], $map['article'], $map['weight']) && count($header) >= 3)
		{
			$map = ['brand' => 0, 'article' => 1, 'weight' => 2];
		}
		if (!isset($map['brand'], $map['article'], $map['weight']))
		{
			fclose($h);

			return [
				'ok' => 0,
				'not_found' => 0,
				'bad' => 1,
				'skipped_header' => 0,
				'weight_fail' => 0,
				'rows_seen' => 0,
				'examples_not_found' => [],
			];
		}

		while (($row = fgetcsv($h, 0, $delim)) !== false)
		{
			$isEmpty = true;
			foreach ($row as $c)
			{
				if (trim((string)$c) !== '')
				{
					$isEmpty = false;
					break;
				}
			}
			if ($isEmpty)
			{
				continue;
			}

			$weightRaw = (string)($row[$map['weight']] ?? '');
			$articleRaw = (string)($row[$map['article']] ?? '');
			$brandRaw = (string)($row[$map['brand']] ?? '');

			$rowsSeen++;

			$wVal = mf_wxi_parse_weight($weightRaw);
			$article = mf_wxi_clean_article_string($articleRaw);
			$brandTrim = trim($brandRaw);

			if ($wVal === null || $article === '' || $brandTrim === '')
			{
				$bad++;
			}
			else
			{
				$grams = $weightInKilograms ? (int)round($wVal * 1000.0) : (int)round($wVal);
				if ($grams < 0)
				{
					$bad++;
				}
				else
				{
					$canon = function_exists('mf_brand_find') ? mf_brand_find($brandTrim, false) : '';
					$bRaw = $canon !== '' ? $canon : $brandTrim;
					$articleNorm = function_exists('mf_ep_norm_article') ? mf_ep_norm_article($article) : mb_strtoupper(preg_replace('~[^A-Z0-9]+~', '', mb_strtoupper($article)) ?? '');
					$brandNorm = function_exists('mf_ep_norm_brand') ? mf_ep_norm_brand($bRaw) : '';

					if (!function_exists('mf_ep_find_product'))
					{
						$bad++;
					}
					else
					{
						$pid = mf_ep_find_product($iblockId, $articleNorm, $bRaw, $brandNorm);
						if ($pid === null || $pid <= 0)
						{
							$notFound++;
							if (count($examplesNotFound) < 20)
							{
								$examplesNotFound[] = $brandTrim . ' / ' . $article;
							}
						}
						elseif (function_exists('mf_ep_set_weight_for_catalog_cluster'))
						{
							$f = mf_ep_set_weight_for_catalog_cluster((int)$pid, $grams);
							$weightFail += $f;
							$ok++;
						}
						else
						{
							$bad++;
						}
					}
				}
			}
		}
		fclose($h);

		return [
			'ok' => $ok,
			'not_found' => $notFound,
			'bad' => $bad,
			'skipped_header' => $skippedHeader,
			'weight_fail' => $weightFail,
			'rows_seen' => $rowsSeen,
			'examples_not_found' => $examplesNotFound,
		];
	}
}
