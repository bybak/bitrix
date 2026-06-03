<?php

declare(strict_types=1);

use Bitrix\Main\Application;

/**
 * Фоновый запуск bulk-импорта аналогов (fastcgi_finish_request + прогресс в mf_analogs_import_job).
 */

function mf_ai_release_session_lock_for_long_request(): void
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

function mf_ai_bootstrap_long_import(): void
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

function mf_analogs_import_job_run_or_die(int $runJobId, bool $requireSessid): void
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

	$jobR = function_exists('mf_analogs_import_job_get') ? mf_analogs_import_job_get($runJobId) : null;
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
	if (!function_exists('mf_analogs_import_job_try_mark_running') || !mf_analogs_import_job_try_mark_running($runJobId))
	{
		echo json_encode(['ok' => true, 'skipped' => true, 'status' => 'busy'], JSON_UNESCAPED_UNICODE);

		return;
	}

	try
	{
		mf_ai_release_session_lock_for_long_request();

		$bulkLib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_import_analogs_bulk_lib.php';
		$analogsLib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_analogs.php';
		$importLib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_import_analogs_lib.php';
		if (!is_file($bulkLib) || !is_file($analogsLib) || !is_file($importLib))
		{
			mf_analogs_import_job_update($runJobId, [
				'UF_STATUS' => 'failed',
				'UF_FINISHED_AT' => date('Y-m-d H:i:s'),
				'UF_ERROR_TEXT' => 'Не подключены файлы обработчика импорта аналогов.',
			]);
			echo json_encode(['ok' => false, 'error' => 'runner'], JSON_UNESCAPED_UNICODE);

			return;
		}
		require_once $analogsLib;
		require_once $importLib;
		require_once $bulkLib;

		$relF = (string)($jobR['UF_FILE_PATH'] ?? '');
		$docRw = rtrim((string)($_SERVER['DOCUMENT_ROOT']), '/\\');
		$absF = $docRw . '/' . ltrim($relF, '/');
		if (!is_file($absF) || !is_readable($absF))
		{
			mf_analogs_import_job_update($runJobId, [
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

		mf_ai_bootstrap_long_import();

		if (function_exists('mf_analogs_import_job_progress_apply'))
		{
			mf_analogs_import_job_progress_apply($runJobId, 0, 'Чтение и разбор CSV…');
		}

		$source = 'admin_job:' . (int)($jobR['UF_USER_ID'] ?? 0);

		try
		{
			$resW = mf_analogs_bulk_run_import_file($iblockId, $absF, $source, ['job_id' => $runJobId]);
		}
		catch (\Throwable $e)
		{
			$resW = [
				'rows' => 0,
				'linked' => 0,
				'not_found' => [],
				'not_found_more' => 0,
				'rows_processed' => 0,
				'_fatal' => true,
				'errors' => [$e->getMessage()],
			];
			if (function_exists('mf_analogs_import_job_diag_write'))
			{
				mf_analogs_import_job_diag_write(
					'job#' . $runJobId . ' EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
				);
			}
		}

		$fatal = !empty($resW['_fatal']);
		$headerErr = '';
		if (!$fatal && isset($resW['errors']) && is_array($resW['errors']) && $resW['errors'] !== [])
		{
			$headerErr = (string)($resW['errors'][0] ?? '');
		}

		if ($fatal || $headerErr !== '')
		{
			$eW = $fatal
				? (string)(($resW['errors'][0] ?? '') ?: 'Ошибка импорта')
				: $headerErr;
			mf_analogs_import_job_update($runJobId, [
				'UF_STATUS' => 'failed',
				'UF_FINISHED_AT' => date('Y-m-d H:i:s'),
				'UF_ERROR_TEXT' => mb_substr($eW, 0, 2000),
			]);
		}
		else
		{
			$rowsDone = (int)($resW['rows_processed'] ?? 0);
			$rowsTotal = (int)($resW['rows'] ?? $rowsDone);
			$js = mf_analogs_bulk_result_json($resW);
			mf_analogs_import_job_update($runJobId, [
				'UF_STATUS' => 'done',
				'UF_FINISHED_AT' => date('Y-m-d H:i:s'),
				'UF_ERROR_TEXT' => null,
				'UF_RESULT_JSON' => $js,
				'UF_ROWS_TOTAL' => max($rowsTotal, $rowsDone),
				'UF_ROWS_DONE' => $rowsDone,
				'UF_PROGRESS_PCT' => 100,
				'UF_PROGRESS_NOTE' => 'Готово: связей ' . (int)($resW['linked'] ?? 0) . ', строк ' . $rowsDone,
			]);
			@unlink($absF);
		}
	}
	catch (\Throwable $e)
	{
		if (function_exists('mf_analogs_import_job_diag_write'))
		{
			mf_analogs_import_job_diag_write('job#' . $runJobId . ' OUTER: ' . $e->getMessage());
		}
		if (function_exists('mf_analogs_import_job_update'))
		{
			mf_analogs_import_job_update($runJobId, [
				'UF_STATUS' => 'failed',
				'UF_FINISHED_AT' => date('Y-m-d H:i:s'),
				'UF_ERROR_TEXT' => mb_substr($e->getMessage(), 0, 2000),
			]);
		}
	}
}
