<?php

declare(strict_types=1);

/**
 * Диагностика зависшего/упавшего фонового импорта внешнего прайса.
 *
 *   php www/tools/mf_external_price_import_job_diag.php [JOB_ID]
 *   php www/tools/mf_external_price_import_job_diag.php --last=5
 *
 * Из docker:
 *   docker exec bitrix_php php /var/www/html/tools/mf_external_price_import_job_diag.php 123
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

$lib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_external_price_lib.php';
if (!is_file($lib))
{
	$lib = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_external_price_lib.php';
}
if (!is_file($lib))
{
	fwrite(STDERR, "mf_external_price_lib.php not found\n");
	exit(1);
}
require_once $lib;

if (!function_exists('mf_external_price_import_job_ensure_table'))
{
	fwrite(STDERR, "job table helpers missing\n");
	exit(1);
}
mf_external_price_import_job_ensure_table();
mf_external_price_import_log_ensure_table();

$jobId = 0;
$lastN = 0;
foreach ($argv as $i => $arg)
{
	if ($i === 0)
	{
		continue;
	}
	if (preg_match('~^--last=(\d+)$~', (string)$arg, $m))
	{
		$lastN = max(1, (int)$m[1]);
		continue;
	}
	if (ctype_digit((string)$arg))
	{
		$jobId = (int)$arg;
	}
}

$conn = mf_external_price_import_job_conn();
if (!$conn)
{
	fwrite(STDERR, "DB connection failed\n");
	exit(1);
}

$printJob = static function (array $row): void {
	$id = (int)($row['ID'] ?? 0);
	echo "=== Job #{$id} ===\n";
	foreach ([
		'UF_STATUS', 'UF_STARTED_AT', 'UF_FINISHED_AT', 'UF_PROGRESS_AT', 'UF_PROGRESS_PCT',
		'UF_PROGRESS_NOTE', 'UF_ROWS_DONE', 'UF_ROWS_TOTAL', 'UF_RECALC_BASE',
		'UF_ERROR_TEXT', 'UF_ORIG_NAME', 'UF_STORE_ID', 'UF_FEED_CODE', 'UF_IMPORT_LOG_ID',
	] as $k)
	{
		if (array_key_exists($k, $row))
		{
			echo $k . ': ' . (string)($row[$k] ?? '') . "\n";
		}
	}
	$progAt = trim((string)($row['UF_PROGRESS_AT'] ?? ''));
	if ($progAt !== '' && (string)($row['UF_STATUS'] ?? '') === 'running')
	{
		$ts = strtotime($progAt);
		if ($ts > 0)
		{
			$ago = time() - $ts;
			echo "progress_stale_sec: {$ago}\n";
			if ($ago > 600)
			{
				echo "LIKELY_ABORTED: прогресс не обновлялся >10 мин — процесс PHP, скорее всего, убит (FPM timeout / OOM).\n";
			}
		}
	}
	$lockPath = sys_get_temp_dir() . '/mfextprice_job_' . $id . '.lock';
	echo 'lock_file: ' . $lockPath . ' exists=' . (is_file($lockPath) ? 'yes' : 'no');
	$lockHeld = false;
	if (is_file($lockPath))
	{
		$lfp = @fopen($lockPath, 'c+');
		if (is_resource($lfp))
		{
			$lockHeld = !@flock($lfp, LOCK_EX | LOCK_NB);
			if (!$lockHeld)
			{
				@flock($lfp, LOCK_UN);
			}
			@fclose($lfp);
		}
	}
	echo ' held_by_worker=' . ($lockHeld ? 'yes (процесс, скорее всего, ещё жив)' : 'no (файл есть, но lock свободен — воркер уже завершился/убит)') . "\n";
	if ((string)($row['UF_STATUS'] ?? '') === 'running' && !$lockHeld)
	{
		echo "VERDICT: job=running, lock свободен → импорт ОТВАЛИЛСЯ без финализации (FPM timeout / OOM / kill). Пометьте failed и смотрите docker logs php.\n";
	}
	elseif ((string)($row['UF_STATUS'] ?? '') === 'running' && $lockHeld)
	{
		echo "VERDICT: воркер, вероятно, ещё работает (или завис на одном товаре). Смотрите ps/top и рост UF_PROGRESS_NOTE.\n";
	}

	$logId = (int)($row['UF_IMPORT_LOG_ID'] ?? 0);
	if ($logId > 0 && function_exists('mf_external_price_import_log_conn'))
	{
		$lc = mf_external_price_import_log_conn();
		if ($lc)
		{
			try
			{
				$lr = $lc->query('SELECT UF_STATUS, UF_ERROR_MESSAGE, UF_STARTED_AT, UF_FINISHED_AT, UF_DURATION_MS FROM mf_external_price_import_log WHERE ID=' . $logId . ' LIMIT 1')->fetch();
				if (is_array($lr))
				{
					echo "--- import_log #{$logId} ---\n";
					foreach ($lr as $lk => $lv)
					{
						echo $lk . ': ' . (string)$lv . "\n";
					}
				}
			}
			catch (\Throwable $e)
			{
				echo 'import_log read error: ' . $e->getMessage() . "\n";
			}
		}
	}
	echo "\n";
};

if ($jobId > 0)
{
	$row = mf_external_price_import_job_get($jobId);
	if (!$row)
	{
		fwrite(STDERR, "Job #{$jobId} not found\n");
		exit(1);
	}
	$printJob($row);
}
else
{
	$n = $lastN > 0 ? $lastN : 3;
	$rs = $conn->query('SELECT * FROM mf_external_price_import_job ORDER BY ID DESC LIMIT ' . $n);
	while ($r = $rs->fetch())
	{
		if (is_array($r))
		{
			$printJob($r);
		}
	}
}

$logPath = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\') . '/mf_external_price_import.log';
echo "=== tail {$logPath} (last 30 lines) ===\n";
if (!is_file($logPath))
{
	echo "(file not found — ошибок в diag-лог не писалось)\n";
	exit(0);
}
$lines = @file($logPath, FILE_IGNORE_NEW_LINES);
if (!is_array($lines))
{
	echo "(cannot read)\n";
	exit(0);
}
$tail = array_slice($lines, -30);
foreach ($tail as $ln)
{
	echo $ln . "\n";
}
