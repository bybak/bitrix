<?php

declare(strict_types=1);

/**
 * CLI: пакетное удаление товаров по CSV (первая колонка — ID).
 *
 * Примеры:
 *   php mf_catalog_delete_cli.php --dry-run --file=/var/www/html/upload/tmp/ids.csv
 *   php mf_catalog_delete_cli.php --apply --file=/var/www/html/upload/tmp/ids.csv
 *   php mf_catalog_delete_cli.php --apply --file=... --log-id=5
 *
 * Если уже есть активное удаление (статус running в журнале, не старше MF_CDC_RUNNING_TTL_MINUTES), --apply завершится с кодом 4.
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);

if (PHP_SAPI !== 'cli')
{
	fwrite(STDERR, "Только CLI.\n");
	exit(1);
}

$longopts = ['file:', 'apply', 'dry-run', 'log-id:'];
$opts = getopt('', $longopts);
if ($opts === false)
{
	fwrite(STDERR, "Неверные аргументы.\n");
	exit(1);
}

$file = isset($opts['file']) ? trim((string)$opts['file']) : '';
$apply = array_key_exists('apply', $opts);
$dry = array_key_exists('dry-run', $opts);
$logId = isset($opts['log-id']) ? (int)$opts['log-id'] : 0;

if ($file === '' || !is_file($file) || !is_readable($file))
{
	fwrite(STDERR, "Укажите существующий --file=path/to.csv\n");
	exit(1);
}

if ($apply && $dry)
{
	fwrite(STDERR, "Нельзя одновременно --apply и --dry-run\n");
	exit(1);
}

if (!$apply && !$dry)
{
	fwrite(STDERR, "Укажите --apply или --dry-run\n");
	exit(1);
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

\Bitrix\Main\Loader::includeModule('iblock');
\Bitrix\Main\Loader::includeModule('catalog');

$libCandidates = [
	$_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_catalog_delete_by_csv_lib.php',
	$_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_catalog_delete_by_csv_lib.php',
];
$lib = null;
foreach ($libCandidates as $p)
{
	if (is_file($p))
	{
		$lib = $p;
		break;
	}
}
if ($lib === null)
{
	fwrite(STDERR, "Не найден mf_catalog_delete_by_csv_lib.php\n");
	exit(1);
}
require_once $lib;

$iblockMain = mf_cdc_catalog_iblock_id();
$allowed = mf_cdc_allowed_catalog_iblocks($iblockMain);

$t0 = microtime(true);
$ids = mf_cdc_parse_ids_from_csv($file);
if ($ids === [])
{
	fwrite(STDERR, "В файле нет числовых ID в первой колонке.\n");
	if ($logId > 0)
	{
		mf_cdc_log_fail($logId, microtime(true) - $t0, 'Пустой список ID');
	}
	exit(1);
}

if ($dry)
{
	$st = mf_cdc_run_dry_list($ids, $allowed);
	fwrite(STDOUT, sprintf(
		"Проверка: всего ID %d, готово к удалению %d, проблем %d\n",
		count($ids),
		(int)$st['ok'],
		(int)$st['fail']
	));
	foreach ($st['errors'] as $ln)
	{
		fwrite(STDOUT, $ln . "\n");
	}
	exit((int)$st['fail'] > 0 ? 2 : 0);
}

// --apply
$blockOther = mf_cdc_log_get_blocking_run($logId > 0 ? $logId : null);
if (is_array($blockOther))
{
	$bid = (int)($blockOther['ID'] ?? 0);
	$bst = (string)($blockOther['STARTED_AT'] ?? '');
	fwrite(STDERR, "Уже выполняется удаление — журнал №{$bid}, старт {$bst}. Дождитесь окончания или проверьте лог /upload/tmp/mf_cdc_cli_{$bid}.log\n");
	exit(4);
}

if ($logId <= 0)
{
	$logId = mf_cdc_log_insert_running([
		'source' => 'cli',
		'file_name' => basename($file),
		'file_path' => $file,
		'ids_total' => count($ids),
	]);
	if ($logId <= 0)
	{
		fwrite(STDERR, "Не удалось создать запись журнала (mf_catalog_delete_run).\n");
		// продолжаем удаление без лога
	}
}
else
{
	mf_cdc_log_ensure_table();
}

$st = mf_cdc_run_delete_list($ids, $allowed);
$dt = microtime(true) - $t0;
$sum = mf_cdc_errors_to_summary($st['errors']);
if ($logId > 0)
{
	mf_cdc_log_complete(
		$logId,
		(int)$st['deleted'],
		(int)$st['failed'],
		$dt,
		'completed',
		$sum
	);
}
fwrite(STDOUT, sprintf(
	"Готово за %.2f с: удалено %d, ошибок %d, всего ID в файле %d\n",
	$dt,
	(int)$st['deleted'],
	(int)$st['failed'],
	count($ids)
));
foreach ($st['errors'] as $ln)
{
	fwrite(STDOUT, $ln . "\n");
}
exit((int)$st['failed'] > 0 ? 3 : 0);
