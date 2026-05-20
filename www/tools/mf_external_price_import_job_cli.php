<?php

declare(strict_types=1);

/**
 * Долгий импорт внешнего прайса из консоли (без лимитов PHP-FPM в HTTP-запросе).
 *
 *   docker exec bitrix_php php /var/www/html/tools/mf_external_price_import_job_cli.php 26 --resume
 *   docker exec bitrix_php php /var/www/html/tools/mf_external_price_import_job_cli.php 27
 *
 * --resume  — job в статусе running (после обрыва FPM/OOM), CSV на диске должен остаться.
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

$incDir = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include';
if (!is_dir($incDir))
{
	$incDir = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include';
}
foreach ([
	'mf_external_price_lib.php',
	'mf_external_price_import_runner.php',
	'mf_external_price_job_worker_inc.php',
	'mf_external_price_upload_admin.php',
] as $f)
{
	$p = $incDir . '/' . $f;
	if (is_file($p))
	{
		require_once $p;
	}
}

$jobId = 0;
$resume = false;
foreach ($argv as $i => $arg)
{
	if ($i === 0)
	{
		continue;
	}
	if ($arg === '--resume')
	{
		$resume = true;
		continue;
	}
	if (ctype_digit((string)$arg))
	{
		$jobId = (int)$arg;
	}
}

if ($jobId <= 0 || !function_exists('mf_epu_external_price_job_run_cli'))
{
	fwrite(STDERR, "Usage: php tools/mf_external_price_import_job_cli.php JOB_ID [--resume]\n");
	exit(1);
}

exit(mf_epu_external_price_job_run_cli($jobId, $resume));
