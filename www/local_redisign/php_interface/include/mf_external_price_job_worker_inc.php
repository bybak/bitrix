<?php

declare(strict_types=1);

use Bitrix\Main\Application;

/**
 * Отпустить блокировку сессии: иначе долгий воркер держит session lock и вся админка в других вкладках «висит».
 */
function mf_epu_release_session_lock_for_long_request(): void
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

/**
 * Сериализация UF_RESULT_JSON для завершённого job: битая UTF-8 в stats (например в examples_nf)
 * давала json_encode = false → статус done не писался, задание залипало в running при полных счётчиках.
 *
 * @param array<string,mixed> $stats
 */
function mf_epu_external_price_job_stats_json(array $stats): string
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
		'ok' => (int)($stats['ok'] ?? 0),
		'not_found' => (int)($stats['not_found'] ?? 0),
		'created' => (int)($stats['created'] ?? 0),
		'bad' => (int)($stats['bad'] ?? 0),
		'brand_skipped' => (int)($stats['brand_skipped'] ?? 0),
		'zeroed' => (int)($stats['zeroed'] ?? 0),
		'price_write_fail' => (int)($stats['price_write_fail'] ?? 0),
		'store' => (string)($stats['store'] ?? ''),
		'xml' => (string)($stats['xml'] ?? ''),
		'currency' => (string)($stats['currency'] ?? ''),
		'feed_code' => (string)($stats['feed_code'] ?? ''),
		'_stats_json_fallback' => 'non_utf8_or_non_serializable_details_omitted',
	];
	$js2 = json_encode($safe, $flags);

	return ($js2 !== false && $js2 !== '') ? $js2 : '{}';
}

/**
 * Запуск фонового импорта по ID задания (POST с sessid или GET nudge с token, без sessid).
 */
function mf_epu_external_price_job_run_or_die(int $runJobId, bool $requireSessid): void
{
	global $USER;

	$iblockId = 4;
	$locked = false;

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

	$jobR = function_exists('mf_external_price_import_job_get') ? mf_external_price_import_job_get($runJobId) : null;
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
	if (!function_exists('mf_external_price_import_job_try_mark_running') || !mf_external_price_import_job_try_mark_running($runJobId))
	{
		echo json_encode(['ok' => true, 'skipped' => true, 'status' => 'busy'], JSON_UNESCAPED_UNICODE);

		return;
	}
	$locked = true;

	try
	{
		mf_epu_release_session_lock_for_long_request();

		if (!function_exists('mf_epu_run_external_price_import'))
		{
			mf_external_price_import_job_update($runJobId, [
				'UF_STATUS' => 'failed',
				'UF_FINISHED_AT' => date('Y-m-d H:i:s'),
				'UF_ERROR_TEXT' => 'Не подключён runner импорта.',
			]);
			echo json_encode(['ok' => false, 'error' => 'runner'], JSON_UNESCAPED_UNICODE);

			return;
		}

		$relF = (string)($jobR['UF_FILE_PATH'] ?? '');
		$docRw = rtrim((string)($_SERVER['DOCUMENT_ROOT']), '/\\');
		$absF = $docRw . '/' . ltrim($relF, '/');
		if (!is_file($absF) || !is_readable($absF))
		{
			mf_external_price_import_job_update($runJobId, [
				'UF_STATUS' => 'failed',
				'UF_FINISHED_AT' => date('Y-m-d H:i:s'),
				'UF_ERROR_TEXT' => 'Файл задания не найден на сервере.',
			]);
			echo json_encode(['ok' => false, 'error' => 'file'], JSON_UNESCAPED_UNICODE);

			return;
		}

		$stW = \CCatalogStore::GetList([], ['ID' => (int)($jobR['UF_STORE_ID'] ?? 0)], false, false, ['ID', 'TITLE', 'XML_ID'])->Fetch();
		if (!$stW)
		{
			mf_external_price_import_job_update($runJobId, [
				'UF_STATUS' => 'failed',
				'UF_FINISHED_AT' => date('Y-m-d H:i:s'),
				'UF_ERROR_TEXT' => 'Склад не найден.',
			]);
			echo json_encode(['ok' => false, 'error' => 'store'], JSON_UNESCAPED_UNICODE);

			return;
		}
		$xmlW = mb_strtoupper(trim((string)($stW['XML_ID'] ?? '')));
		if ($xmlW === '')
		{
			mf_external_price_import_job_update($runJobId, [
				'UF_STATUS' => 'failed',
				'UF_FINISHED_AT' => date('Y-m-d H:i:s'),
				'UF_ERROR_TEXT' => 'У склада нет XML_ID.',
			]);
			echo json_encode(['ok' => false, 'error' => 'xml'], JSON_UNESCAPED_UNICODE);

			return;
		}
		$pgW = mf_ep_get_or_create_price_group($xmlW, (string)($stW['TITLE'] ?? $xmlW), true);
		if ($pgW <= 0)
		{
			mf_external_price_import_job_update($runJobId, [
				'UF_STATUS' => 'failed',
				'UF_FINISHED_AT' => date('Y-m-d H:i:s'),
				'UF_ERROR_TEXT' => 'Не удалось получить тип цены.',
			]);
			echo json_encode(['ok' => false, 'error' => 'price_group'], JSON_UNESCAPED_UNICODE);

			return;
		}

		if (function_exists('mf_supplier_store_to_price_group_reset'))
		{
			mf_supplier_store_to_price_group_reset();
		}
		if (function_exists('mf_ep_invalidate_catalog_price_group_cache'))
		{
			mf_ep_invalidate_catalog_price_group_cache();
		}

		$importT0W = microtime(true);
		$importStartedW = date('Y-m-d H:i:s');
		$ctxW = [
			'store' => $stW,
			'xmlId' => $xmlW,
			'priceGroupId' => $pgW,
			'currency' => (string)($jobR['UF_CURRENCY'] ?? 'RUB'),
			'zeroMissing' => (string)($jobR['UF_ZERO_MISSING'] ?? '') === 'Y',
			'weightUse' => (string)($jobR['UF_WEIGHT_USE'] ?? '') === 'Y',
			'weightTariff' => (float)($jobR['UF_WEIGHT_RUB_KG'] ?? 0.0),
			'importFileName' => (string)($jobR['UF_ORIG_NAME'] ?? ''),
			'importFileSize' => (int)($jobR['UF_FILE_SIZE'] ?? 0),
			'importUserId' => (int)($jobR['UF_USER_ID'] ?? 0),
			'importUserLogin' => (string)($USER->GetLogin() ?? ''),
			'importStartedAt' => $importStartedW,
			'importT0' => $importT0W,
			'feedCode' => (string)($jobR['UF_FEED_CODE'] ?? ''),
			'importLogId' => (int)($jobR['UF_IMPORT_LOG_ID'] ?? 0),
		];
		$onPr = static function (int $done, int $total) use ($runJobId): void {
			if (!function_exists('mf_external_price_import_job_update'))
			{
				return;
			}
			mf_external_price_import_job_update($runJobId, [
				'UF_ROWS_DONE' => $done,
				'UF_ROWS_TOTAL' => $total,
			]);
		};

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

		mf_epu_bootstrap_long_import();
		$resW = mf_epu_run_external_price_import($absF, $iblockId, $ctxW, $onPr);
		if (!empty($resW['ok']) && isset($resW['stats']) && is_array($resW['stats']))
		{
			$js = mf_epu_external_price_job_stats_json($resW['stats']);
			mf_external_price_import_job_update($runJobId, [
				'UF_STATUS' => 'done',
				'UF_FINISHED_AT' => date('Y-m-d H:i:s'),
				'UF_ERROR_TEXT' => null,
				'UF_RESULT_JSON' => $js,
			]);
			@unlink($absF);
		}
		else
		{
			$eW = (string)($resW['error'] ?? 'Ошибка импорта');
			mf_external_price_import_job_update($runJobId, [
				'UF_STATUS' => 'failed',
				'UF_FINISHED_AT' => date('Y-m-d H:i:s'),
				'UF_ERROR_TEXT' => mb_substr($eW, 0, 2000),
			]);
		}
	}
	finally
	{
		if ($locked)
		{
			mf_external_price_import_job_release_flock();
		}
	}
}
