<?php
/**
 * Очистка mf_stock_import_missing: удалить записи старше N дней по UF_LAST_SEEN.
 * Запускать один раз в сутки (после импортов складов), не из mf_update_supplier_stock.php.
 *
 *   php /var/www/html/tools/mf_stock_import_missing_purge.php
 *   php /var/www/html/tools/mf_stock_import_missing_purge.php --days=7
 *   php /var/www/html/tools/mf_stock_import_missing_purge.php --dry-run
 *   php /var/www/html/tools/mf_stock_import_missing_purge.php --ensure-index
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli')
{
	fwrite(STDERR, "CLI only.\n");
	exit(1);
}

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 3);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_CRONTAB', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Application;

$dryRun = in_array('--dry-run', $argv, true);
$ensureIndex = in_array('--ensure-index', $argv, true);
$days = 7;
foreach ($argv as $arg)
{
	if (strpos($arg, '--days=') === 0)
	{
		$days = (int)substr($arg, 7);
	}
}

if ($days < 1)
{
	fwrite(STDERR, "[mf_stock_import_missing_purge] --days must be >= 1\n");
	exit(1);
}
if ($days > 365)
{
	$days = 365;
}

function mf_missing_purge_out(string $s): void
{
	echo $s . PHP_EOL;
	if (function_exists('flush'))
	{
		flush();
	}
}

function mf_missing_purge_table_exists(\Bitrix\Main\DB\Connection $conn): bool
{
	try
	{
		$row = $conn->query("SHOW TABLES LIKE 'mf_stock_import_missing'")->fetch();
		return (bool)$row;
	}
	catch (Throwable $e)
	{
		return false;
	}
}

function mf_missing_purge_ensure_last_seen_index(\Bitrix\Main\DB\Connection $conn): void
{
	try
	{
		$idx = $conn->query(
			"SHOW INDEX FROM mf_stock_import_missing WHERE Key_name='IX_MF_MISSING_LAST_SEEN'"
		)->fetch();
		if ($idx)
		{
			return;
		}
		$conn->queryExecute(
			'ALTER TABLE mf_stock_import_missing ADD KEY IX_MF_MISSING_LAST_SEEN (UF_LAST_SEEN)'
		);
		mf_missing_purge_out('[mf_stock_import_missing_purge] index IX_MF_MISSING_LAST_SEEN created');
	}
	catch (Throwable $e)
	{
		mf_missing_purge_out(
			'[mf_stock_import_missing_purge] WARN: index not created: ' . $e->getMessage()
		);
	}
}

$conn = Application::getConnection();
$driver = method_exists($conn, 'getType') ? (string)$conn->getType() : '';
if ($driver !== '' && stripos($driver, 'mysql') === false)
{
	fwrite(STDERR, "[mf_stock_import_missing_purge] unsupported DB driver: {$driver}\n");
	exit(1);
}

if (!mf_missing_purge_table_exists($conn))
{
	mf_missing_purge_out('[mf_stock_import_missing_purge] table mf_stock_import_missing not found — nothing to do');
	exit(0);
}

if ($ensureIndex)
{
	mf_missing_purge_ensure_last_seen_index($conn);
}

$cutoffSql = "DATE_SUB(NOW(), INTERVAL " . (int)$days . " DAY)";

$rowTotal = $conn->query('SELECT COUNT(*) AS c FROM mf_stock_import_missing')->fetch();
$totalBefore = (int)($rowTotal['c'] ?? $rowTotal['C'] ?? 0);

$rowStale = $conn->query(
	"SELECT COUNT(*) AS c FROM mf_stock_import_missing WHERE UF_LAST_SEEN < {$cutoffSql}"
)->fetch();
$stale = (int)($rowStale['c'] ?? $rowStale['C'] ?? 0);

mf_missing_purge_out(sprintf(
	'[mf_stock_import_missing_purge] retention=%d days, total=%d, to_delete=%d, mode=%s',
	$days,
	$totalBefore,
	$stale,
	$dryRun ? 'dry-run' : 'apply'
));

if ($stale <= 0)
{
	mf_missing_purge_out('[mf_stock_import_missing_purge] done (nothing to delete)');
	exit(0);
}

if ($dryRun)
{
	mf_missing_purge_out('[mf_stock_import_missing_purge] dry-run: DELETE skipped');
	exit(0);
}

$conn->queryExecute(
	"DELETE FROM mf_stock_import_missing WHERE UF_LAST_SEEN < {$cutoffSql}"
);

$rowAfter = $conn->query('SELECT COUNT(*) AS c FROM mf_stock_import_missing')->fetch();
$totalAfter = (int)($rowAfter['c'] ?? $rowAfter['C'] ?? 0);

mf_missing_purge_out(sprintf(
	'[mf_stock_import_missing_purge] deleted=%d, remaining=%d',
	$stale,
	$totalAfter
));

exit(0);
