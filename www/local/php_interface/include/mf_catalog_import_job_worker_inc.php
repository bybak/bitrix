<?php

declare(strict_types=1);

use Bitrix\Main\Application;

/**
 * Снять блокировку сессии на время долгого импорта.
 */
function mf_ci_release_session_lock_for_long_request(): void
{
	if (class_exists(Application::class))
	{
		$s = Application::getInstance()->getSession();
		if ($s->isStarted())
		{
			$s->save();

			return;
		}
	}
	if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE)
	{
		session_write_close();
	}
}

function mf_ci_bootstrap_long_import(): void
{
	if (function_exists('set_time_limit'))
	{
		@set_time_limit(0);
	}
	@ini_set('max_execution_time', '0');
	@ini_set('memory_limit', '2048M');
	if (function_exists('ignore_user_abort'))
	{
		@ignore_user_abort(true);
	}
}

/**
 * @param array<string, mixed> $stats
 */
function mf_ci_catalog_import_job_stats_json(array $stats): string
{
	$flags = JSON_UNESCAPED_UNICODE;
	if (defined('JSON_INVALID_UTF8_SUBSTITUTE'))
	{
		$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
	}
	$js = json_encode($stats, $flags);
	if ($js !== false && $js !== '')
	{
		return $js;
	}

	$safe = [
		'updated' => (int)($stats['updated'] ?? 0),
		'skipped' => (int)($stats['skipped'] ?? 0),
		'skipped_redirect' => (int)($stats['skipped_redirect'] ?? 0),
		'skipped_no_id' => (int)($stats['skipped_no_id'] ?? 0),
		'skipped_brand' => (int)($stats['skipped_brand'] ?? 0),
		'errors_count' => is_array($stats['errors'] ?? null) ? count($stats['errors']) : 0,
		'_stats_json_fallback' => 'non_utf8_or_non_serializable_details_omitted',
	];

	$js2 = json_encode($safe, $flags);

	return ($js2 !== false && $js2 !== '') ? $js2 : '{}';
}

function mf_ci_catalog_import_job_run_or_die(int $runJobId, bool $requireSessid): void
{
	global $USER;

	header('Content-Type: application/json; charset=utf-8');
	if ($requireSessid && !check_bitrix_sessid())
	{
		echo json_encode(['ok' => false, 'error' => 'Sessid'], JSON_UNESCAPED_UNICODE);

		return;
	}

	$runToken = $requireSessid
		? preg_replace('~[^a-f0-9]~', '', (string)($_POST['token'] ?? ''))
		: preg_replace('~[^a-f0-9]~', '', (string)($_GET['token'] ?? ''));

	if ($runJobId <= 0 || strlen($runToken) !== 32)
	{
		echo json_encode(['ok' => false, 'error' => 'Параметры'], JSON_UNESCAPED_UNICODE);

		return;
	}

	$jobR = function_exists('mf_catalog_import_job_get') ? mf_catalog_import_job_get($runJobId) : null;
	if (
		!$jobR
		|| (int)($jobR['UF_USER_ID'] ?? 0) !== (int)$USER->GetID()
		|| (string)($jobR['UF_TOKEN'] ?? '') !== $runToken
	)
	{
		echo json_encode(['ok' => false, 'error' => 'Задание'], JSON_UNESCAPED_UNICODE);

		return;
	}

	$jStat = (string)($jobR['UF_STATUS'] ?? '');
	if ($jStat === 'done' || $jStat === 'failed')
	{
		echo json_encode(['ok' => true, 'skipped' => true, 'status' => $jStat], JSON_UNESCAPED_UNICODE);

		return;
	}
	if ($jStat === 'running')
	{
		echo json_encode(['ok' => true, 'skipped' => true, 'status' => 'running'], JSON_UNESCAPED_UNICODE);

		return;
	}
	if (!function_exists('mf_catalog_import_job_try_mark_running') || !mf_catalog_import_job_try_mark_running($runJobId))
	{
		echo json_encode(['ok' => true, 'skipped' => true, 'status' => 'busy'], JSON_UNESCAPED_UNICODE);

		return;
	}

	try
	{
		mf_ci_release_session_lock_for_long_request();

		if (!function_exists('mf_ce_run_csv_import'))
		{
			mf_catalog_import_job_update($runJobId, [
				'UF_STATUS' => 'failed',
				'UF_FINISHED_AT' => date('Y-m-d H:i:s'),
				'UF_ERROR_TEXT' => 'Не подключён обработчик импорта каталога.',
			]);
			echo json_encode(['ok' => false, 'error' => 'runner'], JSON_UNESCAPED_UNICODE);

			return;
		}

		$relF = (string)($jobR['UF_FILE_PATH'] ?? '');
		$docRw = rtrim((string)($_SERVER['DOCUMENT_ROOT']), '/\\');
		$absF = $docRw . '/' . ltrim($relF, '/');
		if (!is_file($absF) || !is_readable($absF))
		{
			mf_catalog_import_job_update($runJobId, [
				'UF_STATUS' => 'failed',
				'UF_FINISHED_AT' => date('Y-m-d H:i:s'),
				'UF_ERROR_TEXT' => 'Файл задания не найден на сервере.',
			]);
			echo json_encode(['ok' => false, 'error' => 'file'], JSON_UNESCAPED_UNICODE);

			return;
		}

		$iblockId = (int)($jobR['UF_IBLOCK_ID'] ?? 4);
		if ($iblockId <= 0)
		{
			$iblockId = 4;
		}

		echo json_encode(['ok' => true, 'started' => true], JSON_UNESCAPED_UNICODE);
		if (function_exists('fastcgi_finish_request'))
		{
			fastcgi_finish_request();
		}
		else
		{
			while (ob_get_level() > 0)
			{
				@ob_end_flush();
			}
			if (function_exists('flush'))
			{
				@flush();
			}
		}

		mf_ci_bootstrap_long_import();

		if (function_exists('mf_catalog_import_job_progress_apply'))
		{
			mf_catalog_import_job_progress_apply($runJobId, 0, 'Подсчёт строк в CSV…');
		}

		$rowsTotal = 0;
		if (function_exists('mf_ce_csv_count_data_rows'))
		{
			$cnt = mf_ce_csv_count_data_rows($absF);
			if (!empty($cnt['ok']))
			{
				$rowsTotal = (int)($cnt['total'] ?? 0);
			}
			elseif (!empty($cnt['error']))
			{
				mf_catalog_import_job_update($runJobId, [
					'UF_STATUS' => 'failed',
					'UF_FINISHED_AT' => date('Y-m-d H:i:s'),
					'UF_ERROR_TEXT' => mb_substr((string)$cnt['error'], 0, 2000),
				]);
				@unlink($absF);

				return;
			}
		}

		if (function_exists('mf_catalog_import_job_update'))
		{
			mf_catalog_import_job_update($runJobId, [
				'UF_ROWS_TOTAL' => max(0, $rowsTotal),
				'UF_ROWS_DONE' => 0,
			]);
		}
		if (function_exists('mf_catalog_import_job_progress_apply'))
		{
			$noteStart = $rowsTotal > 0
				? ('Обновление товаров: 0 из ' . $rowsTotal)
				: 'Обновление товаров…';
			mf_catalog_import_job_progress_apply($runJobId, 1, $noteStart);
		}

		try
		{
			$resW = mf_ce_run_csv_import($iblockId, $absF, [
				'job_id' => $runJobId,
				'rows_total' => $rowsTotal,
			]);
		}
		catch (\Throwable $e)
		{
			$resW = [
				'updated' => 0,
				'skipped' => 0,
				'skipped_redirect' => 0,
				'skipped_no_id' => 0,
				'skipped_brand' => 0,
				'errors' => [$e->getMessage()],
				'_fatal' => true,
			];
			if (function_exists('mf_catalog_import_job_diag_write'))
			{
				mf_catalog_import_job_diag_write(
					'job#' . $runJobId . ' EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
				);
			}
		}

		$fatal = !empty($resW['_fatal']);
		$headerErr = '';
		if (!$fatal && isset($resW['errors']) && is_array($resW['errors']) && $resW['errors'] !== [])
		{
			$first = (string)($resW['errors'][0] ?? '');
			if (
				$first !== ''
				&& (
					mb_strpos($first, 'заголовк') !== false
					|| mb_strpos($first, 'Пустой файл') !== false
					|| mb_strpos($first, 'Не удалось открыть') !== false
				)
				&& (int)($resW['rows_processed'] ?? 0) === 0
				&& (int)($resW['updated'] ?? 0) === 0
			)
			{
				$headerErr = $first;
			}
		}

		if ($fatal || $headerErr !== '')
		{
			$eW = $fatal
				? (string)(($resW['errors'][0] ?? '') ?: 'Ошибка импорта')
				: $headerErr;
			mf_catalog_import_job_update($runJobId, [
				'UF_STATUS' => 'failed',
				'UF_FINISHED_AT' => date('Y-m-d H:i:s'),
				'UF_ERROR_TEXT' => mb_substr($eW, 0, 2000),
			]);
		}
		else
		{
			$rowsDone = (int)($resW['rows_processed'] ?? 0);
			if ($rowsTotal <= 0)
			{
				$rowsTotal = $rowsDone;
			}
			$js = mf_ci_catalog_import_job_stats_json($resW);
			mf_catalog_import_job_update($runJobId, [
				'UF_STATUS' => 'done',
				'UF_FINISHED_AT' => date('Y-m-d H:i:s'),
				'UF_ERROR_TEXT' => null,
				'UF_RESULT_JSON' => $js,
				'UF_ROWS_TOTAL' => max($rowsTotal, $rowsDone),
				'UF_ROWS_DONE' => $rowsDone,
				'UF_PROGRESS_PCT' => 100,
				'UF_PROGRESS_NOTE' => 'Готово: обновлено ' . (int)($resW['updated'] ?? 0) . ' товаров',
			]);
			@unlink($absF);
		}
	}
	catch (\Throwable $e)
	{
		if (function_exists('mf_catalog_import_job_diag_write'))
		{
			mf_catalog_import_job_diag_write('job#' . $runJobId . ' OUTER: ' . $e->getMessage());
		}
		if (function_exists('mf_catalog_import_job_update'))
		{
			mf_catalog_import_job_update($runJobId, [
				'UF_STATUS' => 'failed',
				'UF_FINISHED_AT' => date('Y-m-d H:i:s'),
				'UF_ERROR_TEXT' => mb_substr($e->getMessage(), 0, 2000),
			]);
		}
	}
}
