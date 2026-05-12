<?php

declare(strict_types=1);

/**
 * Общая логика удаления товаров по CSV (первая колонка — ID) + журнал в mf_catalog_delete_run.
 */

use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Application;

if (!function_exists('mf_cdc_catalog_iblock_id'))
{
	function mf_cdc_catalog_iblock_id(): int
	{
		$id = (int)(getenv('MF_SUPPLIER_ORDERS_IBLOCK_ID') ?: 0);
		if ($id <= 0 && class_exists(\Bitrix\Main\Config\Option::class))
		{
			$id = (int)\Bitrix\Main\Config\Option::get('mf.supplier_orders', 'catalog_iblock_id', '0');
		}
		if ($id <= 0)
		{
			$id = 4;
		}

		return $id;
	}
}

if (!function_exists('mf_cdc_allowed_catalog_iblocks'))
{
	/**
	 * @return int[]
	 */
	function mf_cdc_allowed_catalog_iblocks(int $productIblockId): array
	{
		$out = [$productIblockId];
		if ($productIblockId <= 0 || !class_exists(\CCatalogSKU::class))
		{
			return $out;
		}
		$skuInfo = \CCatalogSKU::GetInfoByProductIBlock($productIblockId);
		if (is_array($skuInfo) && !empty($skuInfo['IBLOCK_ID']))
		{
			$out[] = (int)$skuInfo['IBLOCK_ID'];
		}

		return array_values(array_unique(array_filter($out, static fn (int $v) => $v > 0)));
	}
}

if (!function_exists('mf_cdc_log_conn'))
{
	function mf_cdc_log_conn(): ?\Bitrix\Main\DB\Connection
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

if (!function_exists('mf_cdc_log_ensure_table'))
{
	function mf_cdc_log_ensure_table(): bool
	{
		$conn = mf_cdc_log_conn();
		if (!$conn)
		{
			return false;
		}

		$driver = method_exists($conn, 'getType') ? (string)$conn->getType() : '';
		if ($driver !== '' && stripos($driver, 'mysql') === false)
		{
			return false;
		}

		$sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS mf_catalog_delete_run (
  ID int unsigned NOT NULL AUTO_INCREMENT,
  CREATED_AT datetime NOT NULL,
  STARTED_AT datetime DEFAULT NULL,
  FINISHED_AT datetime DEFAULT NULL,
  SOURCE varchar(16) NOT NULL DEFAULT 'cli',
  INIT_USER_ID int unsigned DEFAULT NULL,
  FILE_NAME varchar(512) DEFAULT NULL,
  FILE_PATH varchar(1024) DEFAULT NULL,
  IDS_TOTAL int unsigned NOT NULL DEFAULT 0,
  IDS_DELETED int unsigned NOT NULL DEFAULT 0,
  IDS_FAILED int unsigned NOT NULL DEFAULT 0,
  DURATION_SECONDS decimal(14,3) DEFAULT NULL,
  STATUS varchar(24) NOT NULL DEFAULT 'running',
  ERROR_SUMMARY text,
  PRIMARY KEY (ID),
  KEY IX_MCDR_CREATED (CREATED_AT)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

		try
		{
			$conn->queryExecute($sql);
		}
		catch (\Throwable $e)
		{
			return false;
		}

		return true;
	}
}

if (!function_exists('mf_cdc_log_escape'))
{
	function mf_cdc_log_escape(string $s): string
	{
		static $h = null;
		if ($h === null)
		{
			try
			{
				$h = Application::getConnection()->getSqlHelper();
			}
			catch (\Throwable $e)
			{
				return '';
			}
		}

		return $h->forSql($s);
	}
}

if (!function_exists('mf_cdc_log_insert_running'))
{
	/**
	 * @param array{source?: string, init_user_id?: int, file_name?: string, file_path?: string, ids_total?: int} $a
	 */
	function mf_cdc_log_insert_running(array $a): int
	{
		if (!mf_cdc_log_ensure_table())
		{
			return 0;
		}
		$conn = mf_cdc_log_conn();
		if (!$conn)
		{
			return 0;
		}

		$source = mf_cdc_log_escape(mb_substr((string)($a['source'] ?? 'cli'), 0, 16));
		$uid = (int)($a['init_user_id'] ?? 0);
		$uidSql = $uid > 0 ? (string)$uid : 'NULL';
		$fn = isset($a['file_name']) ? mf_cdc_log_escape(mb_substr((string)$a['file_name'], 0, 512)) : '';
		$fp = isset($a['file_path']) ? mf_cdc_log_escape(mb_substr((string)$a['file_path'], 0, 1024)) : '';
		$total = (int)($a['ids_total'] ?? 0);
		$fnQ = $fn !== '' ? "'" . $fn . "'" : 'NULL';
		$fpQ = $fp !== '' ? "'" . $fp . "'" : 'NULL';

		$conn->queryExecute(
			"INSERT INTO mf_catalog_delete_run (CREATED_AT, STARTED_AT, SOURCE, INIT_USER_ID, FILE_NAME, FILE_PATH, IDS_TOTAL, STATUS) "
			. "VALUES (NOW(), NOW(), '{$source}', {$uidSql}, {$fnQ}, {$fpQ}, " . max(0, $total) . ", 'running')"
		);

		return (int)$conn->getInsertedId();
	}
}

if (!function_exists('mf_cdc_log_complete'))
{
	function mf_cdc_log_complete(
		int $id,
		int $deleted,
		int $failed,
		float $durationSec,
		string $status,
		string $errorSummary
	): void {
		if ($id <= 0 || !mf_cdc_log_ensure_table())
		{
			return;
		}
		$conn = mf_cdc_log_conn();
		if (!$conn)
		{
			return;
		}

		$st = mf_cdc_log_escape(mb_substr($status, 0, 24));
		$es = mf_cdc_log_escape(mb_substr($errorSummary, 0, 65530));
		$d = sprintf('%.3f', max(0.0, $durationSec));
		$conn->queryExecute(
			"UPDATE mf_catalog_delete_run SET FINISHED_AT=NOW(), IDS_DELETED=" . max(0, $deleted) . ", IDS_FAILED=" . max(0, $failed)
			. ", DURATION_SECONDS={$d}, STATUS='{$st}', ERROR_SUMMARY='{$es}' WHERE ID=" . $id
		);
	}
}

if (!function_exists('mf_cdc_log_fail'))
{
	function mf_cdc_log_fail(int $id, float $durationSec, string $errorSummary): void
	{
		if ($id <= 0)
		{
			return;
		}
		mf_cdc_log_complete($id, 0, 0, $durationSec, 'failed', $errorSummary);
	}
}

if (!function_exists('mf_cdc_log_progress'))
{
	/**
	 * Промежуточные IDS_DELETED / IDS_FAILED во время STATUS=running (без FINISHED_AT).
	 * Чтобы по БД отличать «ещё работает» от «процесс умер, строка зависла в running».
	 */
	function mf_cdc_log_progress(int $id, int $deleted, int $failed): void
	{
		if ($id <= 0 || !mf_cdc_log_ensure_table())
		{
			return;
		}
		$conn = mf_cdc_log_conn();
		if (!$conn)
		{
			return;
		}
		$conn->queryExecute(
			'UPDATE mf_catalog_delete_run SET IDS_DELETED=' . max(0, $deleted)
			. ', IDS_FAILED=' . max(0, $failed)
			. " WHERE ID=" . (int)$id . " AND STATUS='running'"
		);
	}
}

if (!function_exists('mf_cdc_log_fetch_recent'))
{
	/**
	 * @return list<array<string, mixed>>
	 */
	function mf_cdc_log_fetch_recent(int $limit = 50): array
	{
		if (!mf_cdc_log_ensure_table())
		{
			return [];
		}
		$conn = mf_cdc_log_conn();
		if (!$conn)
		{
			return [];
		}
		$limit = max(1, min(200, $limit));
		$rows = [];
		$res = $conn->query("SELECT * FROM mf_catalog_delete_run ORDER BY ID DESC LIMIT {$limit}");
		while ($r = $res->fetch())
		{
			if (is_array($r))
			{
				$rows[] = $r;
			}
		}

		return $rows;
	}
}

if (!function_exists('mf_cdc_log_running_ttl_minutes'))
{
	/**
	 * Сколько минут считать статус running «активным» и блокировать новые удаления.
	 * Старше — только предупреждение «возможно зависло» (новый запуск разрешён).
	 */
	function mf_cdc_log_running_ttl_minutes(): int
	{
		$v = (int)(getenv('MF_CDC_RUNNING_TTL_MINUTES') ?: 0);
		if ($v <= 0)
		{
			$v = 180;
		}

		return min(1440, max(5, $v));
	}
}

if (!function_exists('mf_cdc_log_get_blocking_run'))
{
	/**
	 * Другой запуск в статусе running, который ещё не «протух» по TTL.
	 *
	 * @return array<string, mixed>|null
	 */
	function mf_cdc_log_get_blocking_run(?int $exceptLogId = null): ?array
	{
		if (!mf_cdc_log_ensure_table())
		{
			return null;
		}
		$conn = mf_cdc_log_conn();
		if (!$conn)
		{
			return null;
		}
		$ttl = mf_cdc_log_running_ttl_minutes();
		$sql = "SELECT * FROM mf_catalog_delete_run WHERE STATUS='running' AND STARTED_AT IS NOT NULL"
			. " AND STARTED_AT >= DATE_SUB(NOW(), INTERVAL " . $ttl . " MINUTE)";
		if ($exceptLogId !== null && $exceptLogId > 0)
		{
			$sql .= ' AND ID<>' . (int)$exceptLogId;
		}
		$sql .= ' ORDER BY ID ASC LIMIT 1';
		$res = $conn->query($sql);
		$r = $res->fetch();

		return is_array($r) ? $r : null;
	}
}

if (!function_exists('mf_cdc_log_get_stale_running_row'))
{
	/**
	 * running дольше TTL — вероятно упавший процесс (новые запуски не блокируем).
	 *
	 * @return array<string, mixed>|null
	 */
	function mf_cdc_log_get_stale_running_row(): ?array
	{
		if (!mf_cdc_log_ensure_table())
		{
			return null;
		}
		$conn = mf_cdc_log_conn();
		if (!$conn)
		{
			return null;
		}
		$ttl = mf_cdc_log_running_ttl_minutes();
		$sql = 'SELECT * FROM mf_catalog_delete_run WHERE STATUS=\'running\''
			. ' AND (STARTED_AT IS NULL OR STARTED_AT < DATE_SUB(NOW(), INTERVAL ' . $ttl . ' MINUTE))'
			. ' ORDER BY ID DESC LIMIT 1';
		$res = $conn->query($sql);
		$r = $res->fetch();

		return is_array($r) ? $r : null;
	}
}

if (!function_exists('mf_cdc_parse_ids_from_csv'))
{
	/**
	 * @return list<int>
	 */
	function mf_cdc_parse_ids_from_csv(string $path): array
	{
		$content = file_get_contents($path);
		if ($content === false || $content === '')
		{
			return [];
		}
		if (str_starts_with($content, "\xEF\xBB\xBF"))
		{
			$content = substr($content, 3);
		}
		$lines = preg_split('~\r\n|\n|\r~', $content) ?: [];
		$ids = [];
		foreach ($lines as $line)
		{
			$line = trim($line);
			if ($line === '')
			{
				continue;
			}
			$delim = str_contains($line, ';') ? ';' : ',';
			$cells = str_getcsv($line, $delim);
			$first = trim((string)($cells[0] ?? ''));
			if ($first === '' || !ctype_digit($first))
			{
				continue;
			}
			$ids[] = (int)$first;
		}

		return array_values(array_unique($ids));
	}
}

if (!function_exists('mf_cdc_save_ids_to_csv_file'))
{
	/**
	 * Одна колонка ID — для передачи в CLI.
	 *
	 * @param list<int> $ids
	 */
	function mf_cdc_save_ids_to_csv_file(array $ids, string $destPath): bool
	{
		$lines = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$lines[] = (string)$id;
			}
		}
		$body = implode("\n", $lines) . ($lines !== [] ? "\n" : '');

		return file_put_contents($destPath, $body) !== false;
	}
}

if (!function_exists('mf_cdc_filter_eligible_ids'))
{
	/**
	 * @param list<int> $ids
	 * @param int[]     $allowedIblocks
	 * @return list<int>
	 */
	function mf_cdc_filter_eligible_ids(array $ids, array $allowedIblocks): array
	{
		$eligible = [];
		foreach ($ids as $pid)
		{
			$pid = (int)$pid;
			if ($pid <= 0)
			{
				continue;
			}
			$el = \CIBlockElement::GetList(
				[],
				['ID' => $pid, 'CHECK_PERMISSIONS' => 'N'],
				false,
				false,
				['ID', 'IBLOCK_ID']
			)->Fetch();
			if (!is_array($el))
			{
				continue;
			}
			$ibEl = (int)($el['IBLOCK_ID'] ?? 0);
			if (in_array($ibEl, $allowedIblocks, true))
			{
				$eligible[] = $pid;
			}
		}

		return $eligible;
	}
}

if (!function_exists('mf_cdc_delete_one_catalog_element'))
{
	/**
	 * @param int[] $allowedIblocks
	 * @return array{ok: bool, message: string}
	 */
	function mf_cdc_delete_one_catalog_element(int $elementId, array $allowedIblocks): array
	{
		global $APPLICATION;

		$elementId = (int)$elementId;
		if ($elementId <= 0)
		{
			return ['ok' => false, 'message' => 'Некорректный ID'];
		}

		$el = \CIBlockElement::GetList(
			[],
			['ID' => $elementId, 'CHECK_PERMISSIONS' => 'N'],
			false,
			false,
			['ID', 'IBLOCK_ID', 'NAME']
		)->Fetch();
		if (!is_array($el))
		{
			return ['ok' => false, 'message' => 'Элемент не найден'];
		}

		$ibEl = (int)($el['IBLOCK_ID'] ?? 0);
		if (!in_array($ibEl, $allowedIblocks, true))
		{
			return ['ok' => false, 'message' => 'IBLOCK_ID ' . $ibEl . ' не каталог (ожид. ' . implode(', ', $allowedIblocks) . ')'];
		}

		$prod = \CCatalogProduct::GetByID($elementId);
		$type = $prod ? (int)$prod['TYPE'] : 0;

		if ($type === ProductTable::TYPE_SKU && class_exists(\CCatalogSKU::class))
		{
			$list = \CCatalogSKU::getOffersList($elementId, $ibEl, [], [], [], [], ['ID' => 'ASC']);
			if (!empty($list[$elementId]) && is_array($list[$elementId]))
			{
				foreach ($list[$elementId] as $offerRow)
				{
					$oid = (int)($offerRow['ID'] ?? 0);
					if ($oid <= 0)
					{
						continue;
					}
					if (!\CIBlockElement::Delete($oid))
					{
						$ex = $APPLICATION->GetException();

						return ['ok' => false, 'message' => 'Оффер ' . $oid . ': ' . ($ex ? $ex->GetString() : 'ошибка удаления')];
					}
				}
			}
		}

		if (!\CIBlockElement::Delete($elementId))
		{
			$ex = $APPLICATION->GetException();

			return ['ok' => false, 'message' => $ex ? $ex->GetString() : 'CIBlockElement::Delete'];
		}

		return ['ok' => true, 'message' => ''];
	}
}

if (!function_exists('mf_cdc_cli_progress_log_path'))
{
	function mf_cdc_cli_progress_log_path(int $logId): string
	{
		$logId = max(0, $logId);
		$doc = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

		return $doc . '/upload/tmp/mf_cdc_cli_' . $logId . '.log';
	}
}

if (!function_exists('mf_cdc_cli_log_append'))
{
	/**
	 * Пишет строку в лог CLI (обходит буферизацию STDOUT при перенаправлении в файл).
	 */
	function mf_cdc_cli_log_append(int $logId, string $line): void
	{
		if ($logId <= 0)
		{
			return;
		}
		$path = mf_cdc_cli_progress_log_path($logId);
		$dir = dirname($path);
		if (!is_dir($dir))
		{
			@mkdir($dir, 0775, true);
		}
		$ts = date('Y-m-d H:i:s');
		@file_put_contents($path, '[' . $ts . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
	}
}

if (!function_exists('mf_cdc_run_delete_list'))
{
	/**
	 * @param list<int> $ids
	 * @param int[]     $allowedIblocks
	 * @param int       $cliProgressLogId  ID строки журнала: прогресс в upload/tmp/mf_cdc_cli_{id}.log и в БД (IDS_*)
	 * @param int       $cliProgressEvery  раз в столько элементов писать в файл (0 — только БД, см. MF_CDC_DB_PROGRESS_EVERY)
	 * @return array{deleted: int, failed: int, errors: list<string>}
	 */
	function mf_cdc_run_delete_list(
		array $ids,
		array $allowedIblocks,
		int $cliProgressLogId = 0,
		int $cliProgressEvery = 0
	): array {
		$ok = 0;
		$fail = 0;
		$errors = [];
		$total = count($ids);
		$every = max(0, $cliProgressEvery);
		$dbEvery = (int)(getenv('MF_CDC_DB_PROGRESS_EVERY') ?: 100);
		if ($dbEvery < 1)
		{
			$dbEvery = 0;
		}
		$idx = 0;
		foreach ($ids as $pid)
		{
			$r = mf_cdc_delete_one_catalog_element((int)$pid, $allowedIblocks);
			if ($r['ok'])
			{
				$ok++;
			}
			else
			{
				$fail++;
				if (count($errors) < 40)
				{
					$errors[] = 'ID ' . $pid . ': ' . $r['message'];
				}
			}
			$idx++;
			if ($cliProgressLogId > 0 && $dbEvery > 0 && ($idx % $dbEvery === 0 || $idx === $total))
			{
				mf_cdc_log_progress($cliProgressLogId, $ok, $fail);
			}
			if ($cliProgressLogId > 0 && $every > 0 && ($idx % $every === 0 || $idx === $total))
			{
				mf_cdc_cli_log_append(
					$cliProgressLogId,
					sprintf('прогресс %d/%d удалено=%d ошибок=%d', $idx, $total, $ok, $fail)
				);
			}
		}

		return ['deleted' => $ok, 'failed' => $fail, 'errors' => $errors];
	}
}

if (!function_exists('mf_cdc_run_dry_list'))
{
	/**
	 * @param list<int> $ids
	 * @param int[]     $allowedIblocks
	 * @return array{ok: int, fail: int, errors: list<string>}
	 */
	function mf_cdc_run_dry_list(array $ids, array $allowedIblocks): array
	{
		$ok = 0;
		$fail = 0;
		$errors = [];
		foreach ($ids as $pid)
		{
			$el = \CIBlockElement::GetList(
				[],
				['ID' => $pid, 'CHECK_PERMISSIONS' => 'N'],
				false,
				false,
				['ID', 'IBLOCK_ID', 'NAME']
			)->Fetch();
			if (!is_array($el))
			{
				$fail++;
				if (count($errors) < 40)
				{
					$errors[] = 'ID ' . $pid . ': элемент не найден';
				}
				continue;
			}
			$ibEl = (int)($el['IBLOCK_ID'] ?? 0);
			if (!in_array($ibEl, $allowedIblocks, true))
			{
				$fail++;
				if (count($errors) < 40)
				{
					$errors[] = 'ID ' . $pid . ': IBLOCK_ID ' . $ibEl . ' не в списке каталога';
				}
				continue;
			}
			$ok++;
		}

		return ['ok' => $ok, 'fail' => $fail, 'errors' => $errors];
	}
}

if (!function_exists('mf_cdc_format_admin_result_delete'))
{
	/**
	 * @param list<int> $ids
	 * @param array{deleted: int, failed: int, errors: list<string>} $st
	 * @return array{TYPE: string, MESSAGE: string, DETAILS: string}
	 */
	function mf_cdc_format_admin_result_delete(array $ids, array $st): array
	{
		$fail = (int)($st['failed'] ?? 0);
		$ok = (int)($st['deleted'] ?? 0);
		$errors = $st['errors'] ?? [];
		$msg = 'Уникальных ID в списке: ' . count($ids) . '. Удалено: ' . $ok . ', ошибок: ' . $fail . '.';

		return [
			'TYPE' => $fail > 0 ? 'ERROR' : 'OK',
			'MESSAGE' => $msg,
			'DETAILS' => $errors !== []
				? '<pre style="white-space:pre-wrap">' . htmlspecialchars(implode("\n", $errors), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>' : '',
		];
	}
}

if (!function_exists('mf_cdc_format_admin_result_dry'))
{
	/**
	 * @param array{ok: int, fail: int, errors: list<string>} $st
	 * @param list<int>                                        $ids
	 */
	function mf_cdc_format_admin_result_dry(array $ids, array $st): array
	{
		$fail = (int)($st['fail'] ?? 0);
		$ok = (int)($st['ok'] ?? 0);
		$errors = $st['errors'] ?? [];
		$msg = 'Проверка без удаления. Уникальных ID в файле: ' . count($ids) . '. '
			. 'Готово к удалению: ' . $ok . ', проблем: ' . $fail . '.';

		return [
			'TYPE' => $fail > 0 ? 'ERROR' : 'OK',
			'MESSAGE' => $msg,
			'DETAILS' => $errors !== []
				? '<pre style="white-space:pre-wrap">' . htmlspecialchars(implode("\n", $errors), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>' : '',
		];
	}
}

if (!function_exists('mf_cdc_errors_to_summary'))
{
	/**
	 * @param list<string> $errors
	 */
	function mf_cdc_errors_to_summary(array $errors): string
	{
		if ($errors === [])
		{
			return '';
		}
		$s = implode("\n", array_slice($errors, 0, 8));

		return function_exists('mb_substr') ? (string)mb_substr($s, 0, 4000) : substr($s, 0, 4000);
	}
}

if (!function_exists('mf_cdc_spawn_cli_delete'))
{
	/**
	 * Запуск php CLI в фоне (Linux/macOS). Возвращает команду для лога или false.
	 *
	 * @return array{ok: bool, cmd: string, php: string}
	 */
	function mf_cdc_spawn_cli_delete(string $cliScriptPath, string $csvPath, int $logId): array
	{
		$out = ['ok' => false, 'cmd' => '', 'php' => ''];

		$php = (string)(getenv('MF_PHP_CLI') ?: '');
		if ($php === '' && defined('PHP_BINDIR') && is_string(PHP_BINDIR) && PHP_BINDIR !== '')
		{
			$php = PHP_BINDIR . '/php';
		}
		if ($php === '')
		{
			$php = 'php';
		}
		$out['php'] = $php;

		if (!is_file($cliScriptPath) || !is_readable($csvPath))
		{
			return $out;
		}

		$phpE = escapeshellarg($php);
		$scrE = escapeshellarg($cliScriptPath);
		$fileE = escapeshellarg($csvPath);
		$logIdN = max(1, $logId);

		$doc = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
		$logFile = $doc . '/upload/tmp/mf_cdc_cli_' . $logIdN . '.log';
		$dir = dirname($logFile);
		if (!is_dir($dir))
		{
			@mkdir($dir, 0775, true);
		}
		@file_put_contents(
			$logFile,
			'[' . date('Y-m-d H:i:s') . "] Фон из админки: передан процесс в shell (nohup). log_id={$logIdN} php={$php}\n",
			FILE_APPEND | LOCK_EX
		);

		if (stripos(PHP_OS, 'WIN') === 0)
		{
			$cmd = "start /B {$phpE} -f {$scrE} -- --apply --file={$fileE} --log-id={$logIdN}";
			$out['cmd'] = $cmd;
			@pclose(@popen($cmd, 'r'));
			$out['ok'] = true;

			return $out;
		}

		// Важно: exec() без оболочки не обрабатывает >>, 2>&1 &, поэтому только через sh -c.
		// proc_open([sh,-c]) на части FPM надёжее; явный путь к nohup — у воркера часто урезан PATH.
		$nohupBin = 'nohup';
		foreach (['/usr/bin/nohup', '/bin/nohup'] as $nohupPath)
		{
			if (is_executable($nohupPath))
			{
				$nohupBin = $nohupPath;
				break;
			}
		}
		$nohupE = escapeshellarg($nohupBin);

		$inner = "{$nohupE} {$phpE} -f {$scrE} -- --apply --file={$fileE} --log-id={$logIdN} >> "
			. escapeshellarg($logFile) . " 2>&1 </dev/null &";

		$sh = '/bin/sh';
		if (!is_executable($sh))
		{
			$sh = is_executable('/usr/bin/sh') ? '/usr/bin/sh' : 'sh';
		}

		$ret = -1;
		$method = 'none';
		$execOut = [];
		$null = '/dev/null';

		if (is_executable($sh))
		{
			$proc = @proc_open(
				[$sh, '-c', $inner],
				[
					0 => ['file', $null, 'r'],
					1 => ['file', $null, 'w'],
					2 => ['file', $null, 'w'],
				],
				$pipes,
				null,
				null,
				['bypass_shell' => true]
			);
			if (is_resource($proc))
			{
				$method = 'proc_open';
				$ret = proc_close($proc);
			}
		}

		if ($ret !== 0)
		{
			$cmdLine = $sh . ' -c ' . escapeshellarg($inner);
			$out['cmd'] = $cmdLine;
			@exec($cmdLine . ' 2>&1', $execOut, $ret);
			$method = 'exec';
		}
		else
		{
			$out['cmd'] = $sh . ' -c ' . escapeshellarg($inner);
		}

		$diagTail = $execOut !== [] ? (function_exists('mb_substr')
			? (string)mb_substr(implode("\n", $execOut), 0, 400)
			: substr(implode("\n", $execOut), 0, 400)) : '';
		@file_put_contents(
			$logFile,
			'[' . date('Y-m-d H:i:s') . "] spawn: sh={$sh} nohup={$nohupBin} method={$method} exit={$ret}"
				. ($diagTail !== '' ? (' sh_out=' . $diagTail) : '') . "\n",
			FILE_APPEND | LOCK_EX
		);

		$out['ok'] = $ret === 0;
		if (!$out['ok'])
		{
			$tail = $execOut !== [] ? implode("\n", array_slice($execOut, 0, 5)) : ('код ' . $ret);
			@file_put_contents(
				$logFile,
				'[' . date('Y-m-d H:i:s') . "] Ошибка запуска фона ({$tail})\n",
				FILE_APPEND | LOCK_EX
			);
		}

		return $out;
	}
}
