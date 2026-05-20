<?php

declare(strict_types=1);

use Bitrix\Catalog\StoreTable;

/**
 * Пометить строку mf_external_price_import_log как упавшую (ранний выход до try/внутри try).
 */
function mf_epu_import_log_mark_failed(int $importLogId, float $t0, string $err): void
{
	if ($importLogId <= 0 || !function_exists('mf_external_price_import_log_update'))
	{
		return;
	}
	$fin = date('Y-m-d H:i:s');
	$dur = (int)round((microtime(true) - $t0) * 1000.0);
	mf_external_price_import_log_update($importLogId, [
		'UF_FINISHED_AT' => $fin,
		'UF_DURATION_MS' => $dur,
		'UF_STATUS' => 'failed',
		'UF_ERROR_MESSAGE' => mb_substr($err, 0, 1000),
	]);
}

/**
 * Выполняет импорт внешнего прайса из CSV (путь к уже сохранённому файлу).
 *
 * @param array{
 *   store: array,
 *   xmlId: string,
 *   priceGroupId: int,
 *   currency: string,
 *   zeroMissing: bool,
 *   weightUse: bool,
 *   weightTariff: float,
 *   importFileName: string,
 *   importFileSize: int,
 *   importUserId: int,
 *   importUserLogin: string,
 *   importStartedAt: string,
 *   importT0: float,
 *   feedCode: string,
 *   importLogId?: int,
 *   importJobId?: int,
 *   recalcBase?: bool
 * } $ctx
 * @param callable|null $onProgress function(int $rowsDone, int $rowsTotal): void
 * @return array{ok: bool, stats?: array, error?: string}
 */
function mf_epu_run_external_price_import(string $absCsvPath, int $iblockId, array $ctx, $onProgress = null): array
{
	$importLogId = (int)($ctx['importLogId'] ?? 0);
	$importLogT0 = (float)($ctx['importT0'] ?? microtime(true));

	if (!is_file($absCsvPath) || !is_readable($absCsvPath))
	{
		mf_epu_import_log_mark_failed($importLogId, $importLogT0, 'Файл импорта не найден или недоступен.');

		return ['ok' => false, 'error' => 'Файл импорта не найден или недоступен.'];
	}

	$st = $ctx['store'] ?? [];
	$xmlId = (string)($ctx['xmlId'] ?? '');
	$priceGroupId = (int)($ctx['priceGroupId'] ?? 0);
	$currency = mb_strtoupper(trim((string)($ctx['currency'] ?? 'RUB')));
	$zeroMissing = !empty($ctx['zeroMissing']);
	$weightUse = !empty($ctx['weightUse']);
	$weightTariffInput = (float)($ctx['weightTariff'] ?? 0.0);
	$importFileName = (string)($ctx['importFileName'] ?? '');
	$importFileSize = (int)($ctx['importFileSize'] ?? 0);
	$importUserId = (int)($ctx['importUserId'] ?? 0);
	$importUserLogin = (string)($ctx['importUserLogin'] ?? '');
	$importStartedAt = (string)($ctx['importStartedAt'] ?? '');
	$storeId = (int)($st['ID'] ?? 0);
	$feedCodeNorm = function_exists('mf_esf_normalize_feed_code')
		? mf_esf_normalize_feed_code((string)($ctx['feedCode'] ?? ''))
		: '';

	if ($feedCodeNorm === '')
	{
		mf_epu_import_log_mark_failed($importLogId, $importLogT0, 'В задании не указан код прайса.');

		return ['ok' => false, 'error' => 'В задании не указан код прайса (перезагрузите файл с заполненным полем «Код прайса»).'];
	}

	$jobIdProg = (int)($ctx['importJobId'] ?? 0);
	$recalcBase = !isset($ctx['recalcBase']) ? true : !empty($ctx['recalcBase']);
	$portionCsv = 0.50;
	$portionZero = $zeroMissing ? 0.15 : 0.00;
	$fAfterCsv = $portionCsv;
	$fAfterZeroPhase = $fAfterCsv + $portionZero;
	$fFinalStart = 0.97;

	$importLogWritten = false;
	$header = null;
	$delim = ';';
	$totalDataRows = 0;
	$ok = 0;
	$notFound = 0;
	$bad = 0;
	$zeroed = 0;

	try
	{
		if (!function_exists('mf_epu_read_file_utf8'))
		{
			mf_epu_import_log_mark_failed($importLogId, $importLogT0, 'Не подключён mf_epu_read_file_utf8.');

			return ['ok' => false, 'error' => 'Не подключён mf_epu_read_file_utf8.'];
		}

		mf_epu_bootstrap_long_import();
		$brandDictPath = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/mf_brand_dict.php';
		if (is_file($brandDictPath))
		{
			require_once $brandDictPath;
		}
		$text = mf_epu_read_file_utf8($absCsvPath);
		$lines = preg_split('~\R~u', $text) ?: [];
		if (count($lines) < 2)
		{
			mf_epu_import_log_mark_failed($importLogId, $importLogT0, 'В файле нет данных (нужна строка заголовка и хотя бы одна строка).');

			return ['ok' => false, 'error' => 'В файле нет данных (нужна строка заголовка и хотя бы одна строка).'];
		}

		$delim = mf_epu_detect_delimiter((string)($lines[0] ?? ''));
		$header = mf_epu_parse_csv_line((string)($lines[0] ?? ''), $delim);
		$hmap = mf_epu_map_headers($header);

		if (!isset($hmap['manufacturer'], $hmap['article'], $hmap['price']))
		{
			$hdrErr = 'Не найдены колонки Производитель / Артикул / Цена в первой строке. Обнаружено: '
				. implode($delim, $header);
			mf_epu_import_log_mark_failed($importLogId, $importLogT0, $hdrErr);

			return [
				'ok' => false,
				'error' => $hdrErr,
			];
		}

		$rowsTotal = 0;
		for ($ti = 1, $tn = count($lines); $ti < $tn; $ti++)
		{
			if (trim((string)($lines[$ti] ?? '')) !== '')
			{
				$rowsTotal++;
			}
		}

		if ($onProgress !== null)
		{
			$onProgress(0, max(1, $rowsTotal));
		}
		if ($jobIdProg > 0 && function_exists('mf_external_price_import_job_progress_apply'))
		{
			mf_external_price_import_job_progress_apply($jobIdProg, 0, 'Разбор строк прайса');
		}

		$ok = 0;
		$notFound = 0;
		$created = 0;
		$bad = 0;
		$priceWriteFail = 0;
		$brandSkipped = 0;
		$totalDataRows = 0;
		$matchedIds = [];
		/** Только catalog/SKU id для sync/BASE (без дубля родителя — иначе ~2× работы и OOM). */
		$matchedCatalogIds = [];
		$examplesNotFound = [];

		for ($li = 1, $n = count($lines); $li < $n; $li++)
		{
			$line = trim((string)($lines[$li] ?? ''));
			if ($line === '')
			{
				continue;
			}
			$totalDataRows++;
			if (($totalDataRows % 250) === 0)
			{
				mf_epu_bootstrap_long_import();
			}
			// Чаще, чем «каждые 25» — иначе до 25-й строки в UI цифры не двигаются (опрос ~1,2 с).
			if ($onProgress !== null && (
				$totalDataRows === 1
				|| $totalDataRows === $rowsTotal
				|| ($totalDataRows % 10) === 0
			))
			{
				$onProgress($totalDataRows, max(1, $rowsTotal));
			}
			if ($jobIdProg > 0 && function_exists('mf_external_price_import_job_progress_apply') && (
				$totalDataRows === 1
				|| $totalDataRows === $rowsTotal
				|| ($totalDataRows % 10) === 0
			))
			{
				$csvShare = ($rowsTotal > 0)
					? min(1.0, ($totalDataRows / $rowsTotal))
					: 1.0;
				$fOverall = $portionCsv * $csvShare;
				mf_external_price_import_job_progress_apply(
					$jobIdProg,
					(int)round($fOverall * 100.0),
					'Разбор строк прайса'
				);
			}

			$cells = mf_epu_parse_csv_line($line, $delim);
			$manufacturer = trim((string)($cells[$hmap['manufacturer']] ?? ''));
			$article = trim((string)($cells[$hmap['article']] ?? ''));
			$priceStr = trim((string)($cells[$hmap['price']] ?? ''));
			$priceStr = str_replace(["\xC2\xA0", ' ', ','], ['', '', '.'], $priceStr);

			if ($manufacturer === '' || $article === '')
			{
				$bad++;
				continue;
			}

			if (function_exists('mf_brand_import_is_skipped') && mf_brand_import_is_skipped($manufacturer))
			{
				$brandSkipped++;
				continue;
			}

			// Пустая цена: снять закуп (mf_ep_set_raw_price* при нуле/отсутствии — удаляет запись внешнего RAW).
			if ($priceStr === '')
			{
				$priceVal = 0.0;
			}
			else
			{
				if (!is_numeric($priceStr))
				{
					$bad++;
					continue;
				}
				$priceVal = (float)$priceStr;
				if ($priceVal < 0 || !is_finite($priceVal))
				{
					$bad++;
					continue;
				}
			}

			$canon = function_exists('mf_brand_find') ? mf_brand_find($manufacturer, false) : '';
			$brandRaw = $canon !== '' ? $canon : $manufacturer;
			$articleNorm = mf_ep_norm_article($article);
			$brandNorm = mf_ep_norm_brand($brandRaw);
			$nameFromRow = (isset($hmap['name']) ? trim((string)($cells[$hmap['name']] ?? '')) : '');

			$pid = mf_ep_find_product($iblockId, $articleNorm, $brandRaw, $brandNorm);
			if ($pid === null || $pid <= 0)
			{
				if (function_exists('mf_ep_create_product_from_external_price'))
				{
					$newPid = mf_ep_create_product_from_external_price(
						$iblockId,
						$article,
						$articleNorm,
						$brandRaw,
						$brandNorm,
						$nameFromRow
					);
					if ($newPid !== null && $newPid > 0)
					{
						$pid = (int)$newPid;
						$created++;
					}
				}
			}
			if ($pid === null || $pid <= 0)
			{
				$notFound++;
				if (count($examplesNotFound) < 15)
				{
					$examplesNotFound[] = $manufacturer . ' / ' . $article;
				}
				continue;
			}

			$catalogPid = function_exists('mf_ep_resolve_catalog_trade_product_id')
				? mf_ep_resolve_catalog_trade_product_id((int)$pid)
				: (int)$pid;
			if ($catalogPid <= 0)
			{
				$catalogPid = (int)$pid;
			}

			$clusterWriteFail = 0;
			if (function_exists('mf_ep_set_raw_price_for_catalog_cluster'))
			{
				$clusterWriteFail = mf_ep_set_raw_price_for_catalog_cluster((int)$pid, $priceGroupId, $priceVal, $currency);
				$priceWriteFail += $clusterWriteFail;
			}
			else
			{
				if (!mf_ep_set_raw_price($catalogPid, $priceGroupId, $priceVal, $currency))
				{
					$priceWriteFail++;
					$clusterWriteFail = 1;
				}
			}
			if ($clusterWriteFail === 0 && function_exists('mf_esf_touch_price_product'))
			{
				mf_esf_touch_price_product($storeId, $feedCodeNorm, $catalogPid);
			}
			if ($priceVal > 0 && function_exists('mf_ep_ensure_unit_if_zero_stock'))
			{
				mf_ep_ensure_unit_if_zero_stock($catalogPid, $storeId);
			}
			$matchedIds[(int)$pid] = true;
			$matchedCatalogIds[$catalogPid] = true;
			$ok++;
		}

		if ($jobIdProg > 0 && function_exists('mf_external_price_import_job_progress_apply'))
		{
			mf_external_price_import_job_progress_apply($jobIdProg, (int)round($fAfterCsv * 100.0),
				$zeroMissing ? 'Обнуление отсутствующих на складе' : 'Подготовка к синхронизации каталога');
		}

		$zeroed = 0;
		if ($zeroMissing)
		{
			if (function_exists('mf_epu_bootstrap_long_import'))
			{
				mf_epu_bootstrap_long_import();
			}
			$candidates = mf_ep_collect_candidates_for_store($storeId, $priceGroupId);
			$toZeroIds = [];
			foreach ($candidates as $zp)
			{
				if (isset($matchedIds[(int)$zp]))
				{
					continue;
				}
				$toZeroIds[] = (int)$zp;
			}
			$nZeroTodo = max(1, count($toZeroIds));
			$tzi = 0;
			foreach ($toZeroIds as $cpid)
			{
				mf_ep_zero_product_on_store($cpid, $storeId, $priceGroupId);
				if (function_exists('mf_esf_untouch_price_product'))
				{
					mf_esf_untouch_price_product($storeId, $feedCodeNorm, $cpid);
				}
				$zeroed++;
				$tzi++;
				if (
					$jobIdProg > 0
					&& function_exists('mf_external_price_import_job_progress_apply')
					&& ($tzi % 50 === 0 || $tzi === $nZeroTodo)
				)
				{
					$fZ = $fAfterCsv + $portionZero * ($tzi / $nZeroTodo);
					$nZeroAll = count($toZeroIds);
					mf_external_price_import_job_progress_apply(
						$jobIdProg,
						(int)round($fZ * 100.0),
						'Обнуление отсутствующих на складе — ' . $tzi . ' из ' . $nZeroAll . ' товаров'
					);
				}
				if ($tzi % 200 === 0 && function_exists('mf_epu_bootstrap_long_import'))
				{
					mf_epu_bootstrap_long_import();
				}
			}
			if ($jobIdProg > 0 && function_exists('mf_external_price_import_job_progress_apply') && $toZeroIds === [])
			{
				mf_external_price_import_job_progress_apply($jobIdProg, (int)round($fAfterZeroPhase * 100.0), 'Обнуление отсутствующих на складе');
			}
		}

		if (function_exists('mf_epu_bootstrap_long_import'))
		{
			mf_epu_bootstrap_long_import();
		}

		$syncIds = array_values(array_map('intval', array_keys($matchedCatalogIds)));
		$syncTotal = count($syncIds);
		$syncSpan = max(0.0, $fFinalStart - $fAfterZeroPhase);
		$syncNoteBase = $recalcBase ? 'Остатки и пересчёт BASE в каталоге' : 'Синхронизация суммарного остатка в каталоге';
		$syncNoteCnt = static function (int $done, int $total) use ($syncNoteBase): string {
			if ($total <= 0)
			{
				return $syncNoteBase;
			}

			return $syncNoteBase . ' — ' . $done . ' из ' . $total . ' товаров';
		};
		if ($syncTotal > 0 && $jobIdProg > 0 && function_exists('mf_external_price_import_job_progress_apply'))
		{
			mf_external_price_import_job_progress_apply(
				$jobIdProg,
				(int)round($fAfterZeroPhase * 100.0),
				$syncNoteCnt(0, $syncTotal)
			);
		}
		if ($syncTotal <= 0 && $jobIdProg > 0 && function_exists('mf_external_price_import_job_progress_apply'))
		{
			mf_external_price_import_job_progress_apply($jobIdProg, (int)round($fFinalStart * 100.0), $syncNoteBase);
		}
		for ($si = 0; $si < $syncTotal; $si++)
		{
			if ($si > 0 && ($si % 200) === 0 && function_exists('mf_epu_bootstrap_long_import'))
			{
				mf_epu_bootstrap_long_import();
			}
			if ($si > 0 && ($si % 500) === 0)
			{
				if (function_exists('mf_supplier_store_to_price_group_reset'))
				{
					mf_supplier_store_to_price_group_reset();
				}
				if (function_exists('mf_ep_invalidate_catalog_price_group_cache'))
				{
					mf_ep_invalidate_catalog_price_group_cache();
				}
			}
			$cpid = $syncIds[$si];
			if ($cpid <= 0)
			{
				continue;
			}
			mf_ep_sync_catalog_qty_from_stores($cpid);
			if ($recalcBase && function_exists('mf_ep_recalc_base_one'))
			{
				mf_ep_recalc_base_one($cpid);
			}
			if (
				$jobIdProg > 0 && function_exists('mf_external_price_import_job_progress_apply')
				&& ($si % 10 === 0 || ($si + 1) === $syncTotal)
			)
			{
				$fS = $fAfterZeroPhase + $syncSpan * (($si + 1) / max(1, $syncTotal));
				mf_external_price_import_job_progress_apply(
					$jobIdProg,
					(int)round($fS * 100.0),
					$syncNoteCnt($si + 1, $syncTotal)
				);
			}
		}

		if ($jobIdProg > 0 && function_exists('mf_external_price_import_job_progress_apply'))
		{
			mf_external_price_import_job_progress_apply($jobIdProg, 98, 'Регистрация прайса и настройки склада');
		}

		if (function_exists('mf_esf_register_store_feed'))
		{
			mf_esf_register_store_feed($storeId, $feedCodeNorm);
		}

		global $USER_FIELD_MANAGER;
		if (is_object($USER_FIELD_MANAGER) && class_exists(StoreTable::class))
		{
			$uf = [
				'UF_MF_EXT_WEIGHT_USE' => $weightUse ? 1 : 0,
				'UF_MF_EXT_WEIGHT_RUB_PER_KG' => ($weightUse && $weightTariffInput > 0) ? $weightTariffInput : 0,
				'UF_MF_EXT_WEIGHT_TARIFF_CCY' => ($weightUse && $weightTariffInput > 0) ? $currency : '',
				'UF_MF_EXT_WEIGHT_MIN_RUB' => 0,
			];
			$USER_FIELD_MANAGER->Update(StoreTable::getUfId(), $storeId, $uf);
		}

		$stats = [
			'ok' => $ok,
			'not_found' => $notFound,
			'created' => $created,
			'bad' => $bad,
			'brand_skipped' => $brandSkipped,
			'zeroed' => $zeroed,
			'recalc_base' => $recalcBase,
			'price_write_fail' => $priceWriteFail,
			'store' => (string)($st['TITLE'] ?? ''),
			'xml' => $xmlId,
			'currency' => $currency,
			'feed_code' => $feedCodeNorm,
			'examples_nf' => $examplesNotFound,
		];

		$importFinishedAt = date('Y-m-d H:i:s');
		$importDurMs = (int)round((microtime(true) - $importLogT0) * 1000.0);
		$headerLineStr = is_array($header) ? mb_substr(implode($delim, $header), 0, 1000) : '';
		$exNfStr = implode("\n", $examplesNotFound);
		$logOkFields = [
			'UF_FINISHED_AT' => $importFinishedAt,
			'UF_DURATION_MS' => $importDurMs,
			'UF_STATUS' => 'ok',
			'UF_USER_ID' => $importUserId > 0 ? $importUserId : null,
			'UF_USER_LOGIN' => $importUserLogin !== '' ? $importUserLogin : null,
			'UF_STORE_ID' => $storeId,
			'UF_STORE_XML_ID' => (string)($st['XML_ID'] ?? ''),
			'UF_STORE_TITLE' => (string)($st['TITLE'] ?? ''),
			'UF_PRICE_GROUP_ID' => $priceGroupId,
			'UF_FEED_CODE' => $feedCodeNorm,
			'UF_INPUT_FILENAME' => $importFileName,
			'UF_FILE_SIZE' => $importFileSize > 0 ? $importFileSize : null,
			'UF_CURRENCY' => $currency,
			'UF_ZERO_MISSING' => $zeroMissing ? 'Y' : 'N',
			'UF_WEIGHT_USE' => $weightUse ? 'Y' : 'N',
			'UF_WEIGHT_RUB_PER_KG' => ($weightUse && $weightTariffInput > 0) ? $weightTariffInput : 0.0,
			'UF_WEIGHT_MIN_RUB' => 0.0,
			'UF_TOTAL_DATA_ROWS' => $totalDataRows,
			'UF_MATCHED' => $ok,
			'UF_NOT_FOUND' => $notFound,
			'UF_BAD_ROWS' => $bad,
			'UF_ZEROED' => $zeroed,
			'UF_HEADER_LINE' => $headerLineStr,
			'UF_EXAMPLES_NOT_FOUND' => $exNfStr !== '' ? $exNfStr : null,
			'UF_ERROR_MESSAGE' => null,
		];
		if ($importLogId > 0 && function_exists('mf_external_price_import_log_update'))
		{
			$importLogWritten = mf_external_price_import_log_update($importLogId, $logOkFields);
		}
		if (!$importLogWritten && function_exists('mf_external_price_import_log_insert'))
		{
			$lid = mf_external_price_import_log_insert(array_merge(
				[
					'UF_STARTED_AT' => $importStartedAt,
				],
				$logOkFields
			));
			$importLogWritten = $lid > 0;
		}

		if ($onProgress !== null)
		{
			$onProgress(max(1, $rowsTotal), max(1, $rowsTotal));
		}
		if ($jobIdProg > 0 && function_exists('mf_external_price_import_job_progress_apply'))
		{
			mf_external_price_import_job_progress_apply($jobIdProg, 100, 'Готово');
		}

		return ['ok' => true, 'stats' => $stats];
	}
	catch (\Throwable $e)
	{
		$err = $e->getMessage();
		$importFinishedAt = date('Y-m-d H:i:s');
		$importDurMs = (int)round((microtime(true) - $importLogT0) * 1000.0);
		$headerLineStr = null;
		if (is_array($header) && isset($delim))
		{
			$headerLineStr = mb_substr(implode($delim, $header), 0, 1000);
		}
		if (!$importLogWritten)
		{
			$failFields = [
				'UF_FINISHED_AT' => $importFinishedAt,
				'UF_DURATION_MS' => $importDurMs,
				'UF_STATUS' => 'failed',
				'UF_USER_ID' => $importUserId > 0 ? $importUserId : null,
				'UF_USER_LOGIN' => $importUserLogin !== '' ? $importUserLogin : null,
				'UF_STORE_ID' => $storeId > 0 ? $storeId : null,
				'UF_STORE_XML_ID' => isset($st['XML_ID']) ? (string)$st['XML_ID'] : null,
				'UF_STORE_TITLE' => isset($st['TITLE']) ? (string)$st['TITLE'] : null,
				'UF_PRICE_GROUP_ID' => $priceGroupId > 0 ? $priceGroupId : null,
				'UF_FEED_CODE' => $feedCodeNorm !== '' ? $feedCodeNorm : null,
				'UF_INPUT_FILENAME' => $importFileName !== '' ? $importFileName : null,
				'UF_FILE_SIZE' => $importFileSize > 0 ? $importFileSize : null,
				'UF_CURRENCY' => $currency,
				'UF_ZERO_MISSING' => $zeroMissing ? 'Y' : 'N',
				'UF_WEIGHT_USE' => $weightUse ? 'Y' : 'N',
				'UF_WEIGHT_RUB_PER_KG' => ($weightUse && $weightTariffInput > 0) ? $weightTariffInput : 0.0,
				'UF_WEIGHT_MIN_RUB' => 0.0,
				'UF_TOTAL_DATA_ROWS' => isset($totalDataRows) ? $totalDataRows : null,
				'UF_MATCHED' => isset($ok) ? $ok : null,
				'UF_NOT_FOUND' => isset($notFound) ? $notFound : null,
				'UF_BAD_ROWS' => isset($bad) ? $bad : null,
				'UF_ZEROED' => isset($zeroed) ? $zeroed : null,
				'UF_HEADER_LINE' => $headerLineStr,
				'UF_EXAMPLES_NOT_FOUND' => null,
				'UF_ERROR_MESSAGE' => mb_substr($err, 0, 1000),
			];
			if ($importLogId > 0 && function_exists('mf_external_price_import_log_update'))
			{
				mf_external_price_import_log_update($importLogId, $failFields);
			}
			elseif (function_exists('mf_external_price_import_log_insert'))
			{
				mf_external_price_import_log_insert(array_merge(
					['UF_STARTED_AT' => $importStartedAt],
					$failFields
				));
			}
		}

		if ($jobIdProg > 0 && function_exists('mf_external_price_import_job_diag_write'))
		{
			mf_external_price_import_job_diag_write('job#' . $jobIdProg . ' RUNNER_FAIL: ' . mb_substr($err, 0, 500));
		}

		return ['ok' => false, 'error' => $err];
	}
}
