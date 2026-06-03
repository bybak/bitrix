<?php

declare(strict_types=1);

use Bitrix\Main\Application;

if (!function_exists('mf_analogs_import_job_conn'))
{
	function mf_analogs_import_job_conn(): ?\Bitrix\Main\DB\Connection
	{
		if (!class_exists(Application::class))
		{
			return null;
		}
		try
		{
			return Application::getConnection();
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}
}

if (!function_exists('mf_analogs_import_job_sql_quote'))
{
	function mf_analogs_import_job_sql_quote(\Bitrix\Main\DB\Connection $conn, $value): string
	{
		if ($value === null)
		{
			return 'NULL';
		}

		return "'" . $conn->getSqlHelper()->forSql((string)$value) . "'";
	}
}

if (!function_exists('mf_analogs_import_job_ensure_table'))
{
	function mf_analogs_import_job_ensure_table(): bool
	{
		$conn = mf_analogs_import_job_conn();
		if (!$conn)
		{
			return false;
		}
		$driver = method_exists($conn, 'getType') ? (string)$conn->getType() : '';
		if ($driver !== '' && stripos($driver, 'mysql') === false)
		{
			return false;
		}
		$sql = "CREATE TABLE IF NOT EXISTS mf_analogs_import_job (
			ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			UF_TOKEN CHAR(32) NOT NULL,
			UF_USER_ID INT UNSIGNED NOT NULL,
			UF_STATUS VARCHAR(16) NOT NULL DEFAULT 'pending',
			UF_FILE_PATH VARCHAR(1024) NOT NULL,
			UF_ORIG_NAME VARCHAR(512) NULL,
			UF_FILE_SIZE BIGINT NULL,
			UF_IBLOCK_ID INT UNSIGNED NOT NULL DEFAULT 4,
			UF_ROWS_TOTAL INT UNSIGNED NULL,
			UF_ROWS_DONE INT UNSIGNED NULL,
			UF_PROGRESS_PCT TINYINT UNSIGNED NULL,
			UF_PROGRESS_NOTE VARCHAR(512) NULL,
			UF_PROGRESS_AT DATETIME NULL,
			UF_ERROR_TEXT TEXT NULL,
			UF_RESULT_JSON MEDIUMTEXT NULL,
			UF_STARTED_AT DATETIME NULL,
			UF_FINISHED_AT DATETIME NULL,
			PRIMARY KEY (ID),
			KEY IX_MFAIJ_STATUS (UF_STATUS),
			KEY IX_MFAIJ_USER (UF_USER_ID),
			KEY IX_MFAIJ_TOKEN (UF_TOKEN)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
		try
		{
			$conn->queryExecute($sql);

			return true;
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}
}

if (!function_exists('mf_analogs_import_job_insert'))
{
	/**
	 * @param array<string, mixed> $row
	 */
	function mf_analogs_import_job_insert(array $row): int
	{
		if (!mf_analogs_import_job_ensure_table())
		{
			return 0;
		}
		$conn = mf_analogs_import_job_conn();
		if (!$conn)
		{
			return 0;
		}
		$cols = [];
		$vals = [];
		foreach ($row as $k => $v)
		{
			$k = trim((string)$k);
			if ($k === '')
			{
				continue;
			}
			$cols[] = '`' . str_replace('`', '', $k) . '`';
			if ($v === null)
			{
				$vals[] = 'NULL';
			}
			elseif (is_int($v) || is_float($v))
			{
				$vals[] = is_finite((float)$v) ? (string)$v : 'NULL';
			}
			else
			{
				$vals[] = mf_analogs_import_job_sql_quote($conn, $v);
			}
		}
		if ($cols === [])
		{
			return 0;
		}
		try
		{
			$conn->queryExecute(
				'INSERT INTO mf_analogs_import_job (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')'
			);
			$r = $conn->query('SELECT LAST_INSERT_ID() AS ID')->fetch();

			return (int)($r['ID'] ?? 0);
		}
		catch (\Throwable $e)
		{
			return 0;
		}
	}
}

if (!function_exists('mf_analogs_import_job_get'))
{
	function mf_analogs_import_job_get(int $id): ?array
	{
		if ($id <= 0 || !mf_analogs_import_job_ensure_table())
		{
			return null;
		}
		$conn = mf_analogs_import_job_conn();
		if (!$conn)
		{
			return null;
		}
		try
		{
			$r = $conn->query('SELECT * FROM mf_analogs_import_job WHERE ID=' . $id . ' LIMIT 1')->fetch();
			if (!is_array($r))
			{
				return null;
			}
			$norm = [];
			foreach ($r as $k => $v)
			{
				$norm[is_string($k) ? strtoupper($k) : $k] = $v;
			}

			return $norm;
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}
}

if (!function_exists('mf_analogs_import_job_update'))
{
	/**
	 * @param array<string, mixed> $fields
	 */
	function mf_analogs_import_job_update(int $id, array $fields): bool
	{
		$id = (int)$id;
		if ($id <= 0 || $fields === [])
		{
			return false;
		}
		$conn = mf_analogs_import_job_conn();
		if (!$conn)
		{
			return false;
		}
		$sets = [];
		foreach ($fields as $k => $v)
		{
			$k = trim((string)$k);
			if ($k === '')
			{
				continue;
			}
			$col = '`' . str_replace('`', '', $k) . '`';
			if ($v === null)
			{
				$sets[] = $col . '=NULL';
			}
			elseif (is_int($v) || is_float($v))
			{
				$sets[] = $col . '=' . (is_finite((float)$v) ? (string)$v : 'NULL');
			}
			else
			{
				$sets[] = $col . '=' . mf_analogs_import_job_sql_quote($conn, $v);
			}
		}
		if ($sets === [])
		{
			return false;
		}
		try
		{
			$conn->queryExecute(
				'UPDATE mf_analogs_import_job SET ' . implode(', ', $sets) . ' WHERE ID=' . $id
			);

			return true;
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}
}

if (!function_exists('mf_analogs_import_job_progress_apply'))
{
	function mf_analogs_import_job_progress_apply(int $jobId, int $pct, ?string $note = null): bool
	{
		$jobId = (int)$jobId;
		if ($jobId <= 0)
		{
			return false;
		}
		$conn = mf_analogs_import_job_conn();
		if (!$conn)
		{
			return false;
		}
		$pct = max(0, min(100, $pct));
		try
		{
			$r = $conn->query(
				'SELECT UF_PROGRESS_PCT FROM mf_analogs_import_job WHERE ID=' . $jobId . ' LIMIT 1'
			)->fetch();
			$cur = is_array($r) ? (int)($r['UF_PROGRESS_PCT'] ?? -1) : -1;
			$newPct = $cur >= 0 ? max($cur, $pct) : $pct;
			$sets = '`UF_PROGRESS_PCT`=' . $newPct . ', `UF_PROGRESS_AT`=NOW()';
			if ($note !== null)
			{
				$n = mb_substr(trim((string)$note), 0, 500);
				$sets .= ', `UF_PROGRESS_NOTE`=' . mf_analogs_import_job_sql_quote($conn, $n);
			}
			$conn->queryExecute(
				'UPDATE mf_analogs_import_job SET ' . $sets . ' WHERE ID=' . $jobId
			);

			return true;
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}
}

if (!function_exists('mf_analogs_import_job_try_mark_running'))
{
	function mf_analogs_import_job_try_mark_running(int $id): bool
	{
		$id = (int)$id;
		if ($id <= 0)
		{
			return false;
		}
		$lockPath = @sys_get_temp_dir() . '/mfanalogs_job_' . $id . '.lock';
		$fp = @fopen($lockPath, 'c+');
		if ($fp === false)
		{
			return mf_analogs_import_job_try_mark_running_db($id);
		}
		if (!flock($fp, LOCK_EX | LOCK_NB))
		{
			@fclose($fp);

			return false;
		}
		$row0 = mf_analogs_import_job_get($id);
		if (
			!$row0
			|| mb_strtolower(trim((string)($row0['UF_STATUS'] ?? ''))) !== 'pending'
		)
		{
			flock($fp, LOCK_UN);
			fclose($fp);

			return false;
		}
		$conn = mf_analogs_import_job_conn();
		if (!$conn)
		{
			flock($fp, LOCK_UN);
			fclose($fp);

			return false;
		}
		try
		{
			$conn->queryExecute(
				"UPDATE mf_analogs_import_job SET UF_STATUS='running', UF_STARTED_AT=NOW() WHERE ID=" . $id . " AND UF_STATUS='pending'"
			);
		}
		catch (\Throwable $e)
		{
			flock($fp, LOCK_UN);
			fclose($fp);

			return false;
		}
		$row = mf_analogs_import_job_get($id);
		$ok = is_array($row) && mb_strtolower(trim((string)($row['UF_STATUS'] ?? ''))) === 'running';
		flock($fp, LOCK_UN);
		fclose($fp);

		return $ok;
	}
}

if (!function_exists('mf_analogs_import_job_try_mark_running_db'))
{
	function mf_analogs_import_job_try_mark_running_db(int $id): bool
	{
		$conn = mf_analogs_import_job_conn();
		if (!$conn)
		{
			return false;
		}
		try
		{
			$conn->queryExecute(
				"UPDATE mf_analogs_import_job SET UF_STATUS='running', UF_STARTED_AT=NOW() WHERE ID=" . (int)$id . " AND UF_STATUS='pending'"
			);
		}
		catch (\Throwable $e)
		{
			return false;
		}
		$row = mf_analogs_import_job_get($id);

		return is_array($row) && mb_strtolower(trim((string)($row['UF_STATUS'] ?? ''))) === 'running';
	}
}

if (!function_exists('mf_analogs_import_job_diag_write'))
{
	function mf_analogs_import_job_diag_write(string $line): void
	{
		$line = trim($line);
		if ($line === '')
		{
			return;
		}
		$doc = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
		if ($doc === '')
		{
			return;
		}
		$msg = date('Y-m-d H:i:s') . ' ' . $line . "\n";
		if (class_exists(\Bitrix\Main\Diag\Debug::class))
		{
			\Bitrix\Main\Diag\Debug::writeToFile($msg, '', 'mf_analogs_import.log');

			return;
		}
		@file_put_contents($doc . '/mf_analogs_import.log', $msg, FILE_APPEND | LOCK_EX);
	}
}
