<?php
/**
 * Дедупликация mf_stock_import_missing: одна строка на пару (склад, UF_UNIQ_KEY).
 * Оставляет запись с макс. UF_LAST_SEEN (NULL — как самая старая дата), затем макс. ID.
 *
 * Запуск (в контейнере bitrix_php или где есть доступ к БД Bitrix):
 *   php /var/www/html/tools/mf_stock_import_missing_dedupe.php
 *   php /var/www/html/tools/mf_stock_import_missing_dedupe.php --dry-run
 *   php /var/www/html/tools/mf_stock_import_missing_dedupe.php --batch=8000
 *   php /var/www/html/tools/mf_stock_import_missing_dedupe.php --skip-build   (ids уже в mf_sim_dedupe_ids)
 *   php .../mf_stock_import_missing_dedupe.php --progress-every=5   (лог прогресса каждые N батчей; по умолчанию 1)
 *   php .../mf_stock_import_missing_dedupe.php --queue-chunks=8   (N проходов по таблице при сборе очереди; меньше пиковый /tmp у MySQL)
 *
 * Таблица-очередь ID к удалению: mf_sim_dedupe_ids (временная, создаётся скриптом).
 * По завершении дропается. При обрыве можно --skip-build и добить батчи вручную.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli')
{
	fwrite(STDERR, "CLI only.\n");
	exit(1);
}

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_CRONTAB', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Application;

$dryRun = in_array('--dry-run', $argv, true);
$skipBuild = in_array('--skip-build', $argv, true);
$batch = 5000;
$progressEvery = 1;
$queueChunks = 1;
foreach ($argv as $arg)
{
	if (strpos($arg, '--batch=') === 0)
	{
		$batch = max(100, min(50000, (int)substr($arg, 8)));
	}
	if (strpos($arg, '--progress-every=') === 0)
	{
		$progressEvery = max(1, min(1000, (int)substr($arg, 17)));
	}
	if (strpos($arg, '--queue-chunks=') === 0)
	{
		$queueChunks = max(1, min(256, (int)substr($arg, 15)));
	}
}

$conn = Application::getConnection();

function out(string $s): void
{
	echo $s . PHP_EOL;
	if (function_exists('flush')) flush();
}

function mf_dedupe_fmt_duration(float $sec): string
{
	if ($sec < 0 || !is_finite($sec))
	{
		$sec = 0;
	}
	if ($sec < 90)
	{
		return sprintf('%.1f с', $sec);
	}
	if ($sec < 3600)
	{
		return sprintf('%d мин %d с', (int)floor($sec / 60), (int)round(fmod($sec, 60)));
	}

	return sprintf('%d ч %d мин', (int)floor($sec / 3600), (int)floor(fmod($sec, 3600) / 60));
}

$queueTable = 'mf_sim_dedupe_ids';
$toDelete = 0;

if (!$skipBuild)
{
	out('Проверка MySQL 8+ / ROW_NUMBER…');
	try
	{
		$conn->queryExecute('SELECT ROW_NUMBER() OVER () AS n FROM DUAL LIMIT 1');
	}
	catch (Throwable $e)
	{
		out('Ошибка: нужен MySQL 8.0+ с оконными функциями. ' . $e->getMessage());
		exit(1);
	}

	$row = $conn->query('SELECT COUNT(*) AS c FROM mf_stock_import_missing')->fetch();
	$totalBefore = (int)($row['c'] ?? 0);
	out("Строк в mf_stock_import_missing: {$totalBefore}");

	$conn->queryExecute("DROP TABLE IF EXISTS {$queueTable}");
	$conn->queryExecute("
		CREATE TABLE {$queueTable} (
			ID BIGINT UNSIGNED NOT NULL PRIMARY KEY
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
	");

	$tQueue0 = microtime(true);
	if ($queueChunks > 1)
	{
		out('Заполнение очереди ID дублей: '
			. "{$queueChunks} проходов (хеш пары склад+ключ → меньше временных файлов MySQL), без % по строкам… старт "
			. date('H:i:s'));
	}
	else
	{
		out('Заполнение очереди ID дублей (один полный проход по таблице, без промежуточного %)… старт '
			. date('H:i:s'));
	}
	// Одна и та же пара (UF_WAREHOUSE_XML_ID, UF_UNIQ_KEY) всегда попадает в один чанк → ROW_NUMBER() корректен.
	$chunkKeyExpr = <<<'SQL'
CONCAT(
	IF(UF_WAREHOUSE_XML_ID IS NULL, 'N', 'V'),
	CHAR_LENGTH(IFNULL(UF_WAREHOUSE_XML_ID, '')),
	IFNULL(UF_WAREHOUSE_XML_ID, ''),
	IF(UF_UNIQ_KEY IS NULL, 'N', 'V'),
	CHAR_LENGTH(IFNULL(UF_UNIQ_KEY, '')),
	IFNULL(UF_UNIQ_KEY, '')
)
SQL;
	$chunkKeyExpr = str_replace(["\r", "\n"], '', trim($chunkKeyExpr));
	try
	{
		for ($ci = 0; $ci < $queueChunks; $ci++)
		{
			if ($queueChunks > 1)
			{
				out(sprintf('  [очередь] проход %d / %d…', $ci + 1, $queueChunks));
			}
			$chunkFilter = '';
			if ($queueChunks > 1)
			{
				$chunkFilter = ' WHERE MOD(CRC32(' . $chunkKeyExpr . '), ' . (int)$queueChunks . ') = ' . (int)$ci;
			}
			$sqlInsert = "
				INSERT INTO {$queueTable} (ID)
				SELECT x.id_del FROM (
					SELECT ID AS id_del,
						ROW_NUMBER() OVER (
							PARTITION BY UF_WAREHOUSE_XML_ID, UF_UNIQ_KEY
							ORDER BY IFNULL(UF_LAST_SEEN, '1970-01-01') DESC, ID DESC
						) AS rn
					FROM mf_stock_import_missing
					{$chunkFilter}
				) AS x
				WHERE x.rn > 1
			";
			$conn->queryExecute($sqlInsert);
		}
		$tQueueSec = microtime(true) - $tQueue0;
		out(sprintf('Очередь собрана за %s (окончание %s)', mf_dedupe_fmt_duration($tQueueSec), date('H:i:s')));
	}
	catch (Throwable $e)
	{
		out('Ошибка вставки в очередь: ' . $e->getMessage());
		$conn->queryExecute("DROP TABLE IF EXISTS {$queueTable}");
		exit(1);
	}

	$rowQ = $conn->query("SELECT COUNT(*) AS c FROM {$queueTable}")->fetch();
	$toDelete = (int)($rowQ['c'] ?? 0);
	out("ID помеченных к удалению: {$toDelete}");

	if ($dryRun)
	{
		out('[dry-run] Удаление не выполняется. DROP очереди.');
		$conn->queryExecute("DROP TABLE IF EXISTS {$queueTable}");
		exit(0);
	}

	if ($toDelete === 0)
	{
		out('Дублей по паре (UF_WAREHOUSE_XML_ID, UF_UNIQ_KEY) не найдено.');
		$conn->queryExecute("DROP TABLE IF EXISTS {$queueTable}");
	}
}
elseif ($dryRun)
{
	out('[dry-run] с --skip-build не поддерживается. Уберите --skip-build или --dry-run.');
	exit(1);
}
else
{
	$tbl = $conn->query("SHOW TABLES LIKE '{$queueTable}'")->fetch();
	if (!$tbl)
	{
		out("Таблицы очереди `{$queueTable}` нет. Сначала запустите без --skip-build.");
		exit(1);
	}
	$rowQ = $conn->query("SELECT COUNT(*) AS c FROM {$queueTable}")->fetch();
	$toDelete = (int)($rowQ['c'] ?? 0);
	out("Продолжение: в очереди ID: {$toDelete}");
}

if (!$dryRun && $toDelete > 0)
{
	$targetDeletes = $toDelete;
	$batchesApprox = (int)max(1, ceil($targetDeletes / $batch));
	out("Батч-удаление: по {$batch} ID за шаг, в очереди {$targetDeletes} (~{$batchesApprox} батчей). Лог каждые {$progressEvery} батч.");
	$tLoop0 = microtime(true);
	$pass = 0;
	$deletedTotal = 0;
	while (true)
	{
		$ids = [];
		$res = $conn->query("SELECT ID FROM {$queueTable} ORDER BY ID ASC LIMIT {$batch}");
		while ($r = $res->fetch())
		{
			$id = (int)($r['ID'] ?? 0);
			if ($id > 0)
			{
				$ids[] = $id;
			}
		}
		if ($ids === [])
		{
			break;
		}
		$in = implode(',', $ids);
		$conn->queryExecute("DELETE FROM mf_stock_import_missing WHERE ID IN ({$in})");
		$n = (int)$conn->getAffectedRowsCount();
		$conn->queryExecute("DELETE FROM {$queueTable} WHERE ID IN ({$in})");
		$deletedTotal += $n;
		$pass++;
		$elapsed = microtime(true) - $tLoop0;
		$pct = $targetDeletes > 0 ? min(100.0, ($deletedTotal / $targetDeletes) * 100.0) : 100.0;
		$etaStr = '—';
		if ($deletedTotal > 0 && $deletedTotal < $targetDeletes)
		{
			$speed = $deletedTotal / $elapsed;
			$leftRows = $targetDeletes - $deletedTotal;
			$etaSec = $speed > 1e-9 ? ($leftRows / $speed) : 0;
			$etaStr = $etaSec > 0 ? ('~' . mf_dedupe_fmt_duration($etaSec)) : '—';
		}
		if (($pass % $progressEvery) === 0 || $pass === 1)
		{
			out(sprintf(
				'  [удаление] батч %d | удалено %d / %d (%.1f%%) | шаг %d строк | прошло %s | ETA %s',
				$pass,
				$deletedTotal,
				$targetDeletes,
				$pct,
				$n,
				mf_dedupe_fmt_duration($elapsed),
				$etaStr
			));
		}
	}
	$tLoopSec = microtime(true) - $tLoop0;
	out(sprintf('Всего удалено строк из mf_stock_import_missing: %d за %s', $deletedTotal, mf_dedupe_fmt_duration($tLoopSec)));

	$leftover = (int)($conn->query("SELECT COUNT(*) AS c FROM {$queueTable}")->fetch()['c'] ?? 0);
	if ($leftover > 0)
	{
		out("Предупреждение: в очереди осталось {$leftover} ID — DROP очереди.");
	}

	$conn->queryExecute("DROP TABLE IF EXISTS {$queueTable}");
}

$row = $conn->query('SELECT COUNT(*) AS c FROM mf_stock_import_missing')->fetch();
$totalAfter = (int)($row['c'] ?? 0);
out("Готово. Строк сейчас: {$totalAfter}");

out('');
out('Опционально добавьте уникальный индекс (после проверки отсутствия коллизий по префиксу):');
out("  ALTER TABLE mf_stock_import_missing");
out("    ADD UNIQUE KEY UX_MF_MISSING_WAREHOUSE_UNIQ (UF_WAREHOUSE_XML_ID(64), UF_UNIQ_KEY(128));");
