<?php

declare(strict_types=1);

/**
 * Админка: пакетное удаление товаров по списку ID из CSV + журнал + запуск CLI.
 */

use Bitrix\Main\Loader;

if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
{
	die('Admin only');
}

global $APPLICATION, $USER;

if (!is_object($USER) || !$USER->IsAdmin())
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Недостаточно прав.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog'))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Нужны модули iblock и catalog.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

$libCandidates = [
	$_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_catalog_delete_by_csv_lib.php',
	$_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_catalog_delete_by_csv_lib.php',
];
$libPath = null;
foreach ($libCandidates as $p)
{
	if (is_file($p))
	{
		$libPath = $p;
		break;
	}
}
if ($libPath === null)
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не найден mf_catalog_delete_by_csv_lib.php']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}
require_once $libPath;

$mfCdcIblockId = mf_cdc_catalog_iblock_id();

const MF_CDC_SESSION_TOKEN = 'mf_cdc_del_token';
const MF_CDC_PENDING_PREFIX = 'mf_cdc_pending_';

function mf_cdc_pending_file_path(string $token): string
{
	$token = strtolower(preg_replace('~[^a-f0-9]~', '', $token) ?? '');
	if (strlen($token) !== 32)
	{
		return '';
	}
	$dir = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/upload/tmp';
	if (!is_dir($dir))
	{
		@mkdir($dir, 0775, true);
	}

	return $dir . '/' . MF_CDC_PENDING_PREFIX . $token . '.json';
}

/**
 * @param list<int> $ids
 */
function mf_cdc_save_pending_ids(array $ids): string
{
	$token = bin2hex(random_bytes(16));
	$path = mf_cdc_pending_file_path($token);
	if ($path === '')
	{
		return '';
	}
	$payload = json_encode(['ids' => array_values($ids)]);
	if ($payload === false || file_put_contents($path, $payload) === false)
	{
		return '';
	}

	return $token;
}

/**
 * @return list<int>|null
 */
function mf_cdc_load_pending_ids(string $token): ?array
{
	$path = mf_cdc_pending_file_path($token);
	if ($path === '' || !is_file($path))
	{
		return null;
	}
	$raw = file_get_contents($path);
	if ($raw === false || $raw === '')
	{
		return null;
	}
	$data = json_decode($raw, true);
	if (!is_array($data) || !isset($data['ids']) || !is_array($data['ids']))
	{
		return null;
	}
	$out = [];
	foreach ($data['ids'] as $v)
	{
		$i = (int)$v;
		if ($i > 0)
		{
			$out[] = $i;
		}
	}

	return $out;
}

function mf_cdc_unlink_pending_file(string $token): void
{
	$p = mf_cdc_pending_file_path($token);
	if ($p !== '' && is_file($p))
	{
		@unlink($p);
	}
}

function mf_cdc_clear_pending_session(?string $token = null): void
{
	if ($token !== null && $token !== '')
	{
		mf_cdc_unlink_pending_file($token);
	}
	elseif (!empty($_SESSION[MF_CDC_SESSION_TOKEN]) && is_string($_SESSION[MF_CDC_SESSION_TOKEN]))
	{
		mf_cdc_unlink_pending_file($_SESSION[MF_CDC_SESSION_TOKEN]);
	}
	unset($_SESSION[MF_CDC_SESSION_TOKEN]);
}

function mf_cdc_h(?string $s): string
{
	$s = (string)$s;

	return function_exists('htmlspecialcharsbx') ? (string)htmlspecialcharsbx($s) : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$mode = (string)($_GET['mode'] ?? '');
$APPLICATION->SetTitle($mode === 'history' ? 'Журнал: удаление товаров по CSV' : 'Удаление товаров по CSV');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$allowedIblocks = mf_cdc_allowed_catalog_iblocks($mfCdcIblockId);
$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';

mf_cdc_log_ensure_table();
$cdcRunBlock = mf_cdc_log_get_blocking_run(null);
$cdcRunStale = ($cdcRunBlock === null) ? mf_cdc_log_get_stale_running_row() : null;

$adminPage = (string)$APPLICATION->GetCurPage();
$adminPageQs = $adminPage . '?lang=' . rawurlencode($lang);
$historyQs = $adminPage . '?lang=' . rawurlencode($lang) . '&mode=history';

$resultBlock = null;
$confirmState = null;

if ($mode === 'history')
{
	$rows = mf_cdc_log_fetch_recent(80);
	echo '<p><a href="' . mf_cdc_h($adminPageQs) . '">← К форме удаления</a></p>';
	echo '<p class="adm-info-message">Таблица <code>mf_catalog_delete_run</code>. Для фоновых CLI смотрите также лог <code>/upload/tmp/mf_cdc_cli_<i>ID</i>.log</code>.</p>';
	if (is_array($cdcRunBlock))
	{
		$rid = (int)($cdcRunBlock['ID'] ?? 0);
		echo '<p class="adm-info-message" style="background:#f8d7da;border-color:#f5c6cb;"><strong>Сейчас выполняется удаление.</strong> Журнал №' . $rid
			. ' (см. лог <code>/upload/tmp/mf_cdc_cli_' . $rid . '.log</code>).</p>';
	}
	echo '<table class="internal" cellspacing="0" style="width:100%;max-width:1200px;">';
	echo '<tr class="heading"><td>ID</td><td>Создан</td><td>Источник</td><td>User</td><td>Файл</td><td>Всего ID</td><td>Удалено</td><td>Ошибок</td><td>сек</td><td>Статус</td></tr>';
	foreach ($rows as $r)
	{
		$sum = trim((string)($r['ERROR_SUMMARY'] ?? ''));
		$sumShort = $sum !== '' ? mf_cdc_h(mb_substr($sum, 0, 120)) . (mb_strlen($sum) > 120 ? '…' : '') : '—';
		echo '<tr>';
		echo '<td>' . (int)($r['ID'] ?? 0) . '</td>';
		echo '<td>' . mf_cdc_h((string)($r['CREATED_AT'] ?? '')) . '</td>';
		echo '<td>' . mf_cdc_h((string)($r['SOURCE'] ?? '')) . '</td>';
		echo '<td>' . (int)($r['INIT_USER_ID'] ?? 0) . '</td>';
		echo '<td title="' . mf_cdc_h((string)($r['FILE_PATH'] ?? '')) . '">' . mf_cdc_h((string)($r['FILE_NAME'] ?? '')) . '</td>';
		echo '<td>' . (int)($r['IDS_TOTAL'] ?? 0) . '</td>';
		echo '<td>' . (int)($r['IDS_DELETED'] ?? 0) . '</td>';
		echo '<td>' . (int)($r['IDS_FAILED'] ?? 0) . '</td>';
		$dur = $r['DURATION_SECONDS'] ?? null;
		echo '<td>' . ($dur !== null && $dur !== '' ? mf_cdc_h((string)$dur) : '—') . '</td>';
		echo '<td>' . mf_cdc_h((string)($r['STATUS'] ?? '')) . '<div style="color:#666;font-size:11px;margin-top:4px;">' . $sumShort . '</div></td>';
		echo '</tr>';
	}
	echo '</table>';
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

if (
	$_SERVER['REQUEST_METHOD'] === 'GET'
	&& (string)($_GET['mf_cdc_cancel'] ?? '') === 'Y'
	&& check_bitrix_sessid()
) {
	mf_cdc_clear_pending_session();
	LocalRedirect($adminPageQs);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid())
{
	$action = (string)($_POST['mf_cdc_action'] ?? '');

	if ($action === 'cancel')
	{
		mf_cdc_clear_pending_session();
		LocalRedirect($adminPageQs);
	}

	if ($action === 'confirm')
	{
		@set_time_limit(0);
		$postToken = (string)($_POST['mf_cdc_token'] ?? '');
		$sessToken = isset($_SESSION[MF_CDC_SESSION_TOKEN]) ? (string)$_SESSION[MF_CDC_SESSION_TOKEN] : '';
		$useCli = (string)($_POST['mf_cdc_cli'] ?? '') === 'Y';

		if ($postToken === '' || $sessToken === '' || !hash_equals($sessToken, $postToken))
		{
			$resultBlock = ['TYPE' => 'ERROR', 'MESSAGE' => 'Сессия подтверждения устарела. Загрузите CSV снова.'];
			mf_cdc_clear_pending_session($sessToken !== '' ? $sessToken : null);
		}
		else
		{
			$ids = mf_cdc_load_pending_ids($sessToken);
			if ($ids === null || $ids === [])
			{
				$resultBlock = ['TYPE' => 'ERROR', 'MESSAGE' => 'Не удалось прочитать сохранённый список ID. Повторите загрузку файла.'];
				mf_cdc_clear_pending_session($sessToken);
			}
			else
			{
				$blockRow = mf_cdc_log_get_blocking_run(null);
				if (is_array($blockRow))
				{
					$bid = (int)($blockRow['ID'] ?? 0);
					$bst = mf_cdc_h((string)($blockRow['STARTED_AT'] ?? ''));
					$src = mf_cdc_h((string)($blockRow['SOURCE'] ?? ''));
					$n = (int)($blockRow['IDS_TOTAL'] ?? 0);
					$resultBlock = [
						'TYPE' => 'ERROR',
						'MESSAGE' => 'Сейчас уже выполняется удаление товаров (журнал №' . $bid . ', источник ' . $src . ', старт ' . $bst . ', в файле ID: ' . $n . '). Дождитесь завершения. Откройте <a href="' . mf_cdc_h($historyQs) . '">журнал</a> или смотрите лог <code>/upload/tmp/mf_cdc_cli_' . $bid . '.log</code>.',
						'HTML' => true,
					];
				}
				elseif ($useCli)
				{
					$uid = (int)$USER->GetID();
					$logId = mf_cdc_log_insert_running([
						'source' => 'admin_cli',
						'init_user_id' => $uid > 0 ? $uid : null,
						'file_name' => 'web_confirm_cli.csv',
						'ids_total' => count($ids),
					]);
					if ($logId <= 0)
					{
						$resultBlock = ['TYPE' => 'ERROR', 'MESSAGE' => 'Не создана запись журнала. Проверьте таблицу mf_catalog_delete_run.'];
					}
					else
					{
						$doc = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/');
						$csvPath = $doc . '/upload/tmp/mf_cdc_run_' . $logId . '.csv';
						if (!mf_cdc_save_ids_to_csv_file($ids, $csvPath))
						{
							mf_cdc_log_fail($logId, 0.0, 'Не удалось записать CSV для CLI');
							$resultBlock = ['TYPE' => 'ERROR', 'MESSAGE' => 'Не удалось сохранить список ID в upload/tmp для CLI.'];
						}
						else
						{
							$conn = mf_cdc_log_conn();
							if ($conn)
							{
								$fp = mf_cdc_log_escape(mb_substr($csvPath, 0, 1024));
								$conn->queryExecute("UPDATE mf_catalog_delete_run SET FILE_PATH='{$fp}' WHERE ID=" . $logId);
							}
							$cliScript = $doc . '/mf_catalog_delete_cli.php';
							$spawn = mf_cdc_spawn_cli_delete($cliScript, $csvPath, $logId);
							if (!$spawn['ok'])
							{
								mf_cdc_log_fail($logId, 0.0, 'Не удалось запустить CLI. Проверьте MF_PHP_CLI, права, open_basedir.');
								$resultBlock = ['TYPE' => 'ERROR', 'MESSAGE' => 'Фоновый процесс не запущен. Запустите вручную: <code>php ' . mf_cdc_h($cliScript) . ' --apply --file=' . mf_cdc_h($csvPath) . ' --log-id=' . $logId . '</code>'];
							}
							else
							{
								$resultBlock = [
									'TYPE' => 'OK',
									'MESSAGE' => 'Запущено фоновое удаление, запись журнала №' . $logId . '.',
									'DETAILS' => 'Через некоторое время откройте <a href="' . mf_cdc_h($historyQs) . '">журнал</a>. '
										. 'Лог процесса: <code>/upload/tmp/mf_cdc_cli_' . $logId . '.log</code>. PHP: ' . mf_cdc_h($spawn['php']),
									'HTML' => true,
								];
							}
						}
					}
					mf_cdc_clear_pending_session($sessToken);
				}
				else
				{
					$uid = (int)$USER->GetID();
					$logId = mf_cdc_log_insert_running([
						'source' => 'admin_web',
						'init_user_id' => $uid > 0 ? $uid : null,
						'file_name' => 'web_confirm',
						'ids_total' => count($ids),
					]);
					$t0 = microtime(true);
					$st = mf_cdc_run_delete_list($ids, $allowedIblocks, $logId, 0);
					$dt = microtime(true) - $t0;
					if ($logId > 0)
					{
						mf_cdc_log_complete(
							$logId,
							(int)$st['deleted'],
							(int)$st['failed'],
							$dt,
							'completed',
							mf_cdc_errors_to_summary($st['errors'])
						);
					}
					$resultBlock = mf_cdc_format_admin_result_delete($ids, $st);
					if ($logId > 0)
					{
						$resultBlock['MESSAGE'] .= ' Журнал №' . $logId . '.';
					}
					mf_cdc_clear_pending_session($sessToken);
				}
			}
		}
	}

	if (
		$resultBlock === null
		&& (string)($_POST['mf_cdc_go'] ?? '') === 'Y'
	) {
		@set_time_limit(0);
		$dry = ((string)($_POST['mf_cdc_dry'] ?? '') === 'Y');

		if (!isset($_FILES['mf_cdc_file']) || !is_array($_FILES['mf_cdc_file']))
		{
			$resultBlock = ['TYPE' => 'ERROR', 'MESSAGE' => 'Файл не получен.'];
		}
		else
		{
			$err = (int)($_FILES['mf_cdc_file']['error'] ?? UPLOAD_ERR_NO_FILE);
			if ($err !== UPLOAD_ERR_OK)
			{
				$resultBlock = ['TYPE' => 'ERROR', 'MESSAGE' => 'Ошибка загрузки файла (код ' . $err . ').'];
			}
			else
			{
				$tmp = (string)($_FILES['mf_cdc_file']['tmp_name'] ?? '');
				if ($tmp === '' || !is_uploaded_file($tmp))
				{
					$resultBlock = ['TYPE' => 'ERROR', 'MESSAGE' => 'Временный файл недоступен.'];
				}
				else
				{
					$ids = mf_cdc_parse_ids_from_csv($tmp);
					if ($ids === [])
					{
						$resultBlock = ['TYPE' => 'ERROR', 'MESSAGE' => 'В файле нет ни одной строки с числовым ID в первой колонке.'];
					}
					elseif ($dry)
					{
						$st = mf_cdc_run_dry_list($ids, $allowedIblocks);
						$resultBlock = mf_cdc_format_admin_result_dry($ids, $st);
					}
					else
					{
						$blockRow = mf_cdc_log_get_blocking_run(null);
						if (is_array($blockRow))
						{
							$bid = (int)($blockRow['ID'] ?? 0);
							$bst = mf_cdc_h((string)($blockRow['STARTED_AT'] ?? ''));
							$resultBlock = [
								'TYPE' => 'ERROR',
								'MESSAGE' => 'Уже выполняется удаление (журнал №' . $bid . ', старт ' . $bst . '). Дождитесь окончания. Доступна только проверка CSV без удаления.',
							];
						}
						else
						{
						mf_cdc_clear_pending_session();

						$eligible = mf_cdc_filter_eligible_ids($ids, $allowedIblocks);
						$token = mf_cdc_save_pending_ids($ids);
						if ($token === '')
						{
							$resultBlock = ['TYPE' => 'ERROR', 'MESSAGE' => 'Не удалось сохранить список для подтверждения (проверьте каталог /upload/tmp).'];
						}
						else
						{
							$_SESSION[MF_CDC_SESSION_TOKEN] = $token;
							$first10 = array_slice($eligible, 0, 10);
							$confirmState = [
								'token' => $token,
								'eligible_count' => count($eligible),
								'total_ids' => count($ids),
								'first10' => $first10,
							];
						}
						}
					}
				}
			}
		}
	}
}

if (is_array($resultBlock))
{
	\CAdminMessage::ShowMessage($resultBlock);
}

if (is_array($cdcRunBlock))
{
	$cbId = (int)($cdcRunBlock['ID'] ?? 0);
	$cbSt = mf_cdc_h((string)($cdcRunBlock['STARTED_AT'] ?? ''));
	$cbSrc = mf_cdc_h((string)($cdcRunBlock['SOURCE'] ?? ''));
	$cbN = (int)($cdcRunBlock['IDS_TOTAL'] ?? 0);
	\CAdminMessage::ShowMessage([
		'TYPE' => 'ERROR',
		'MESSAGE' => 'Сейчас выполняется удаление товаров (журнал №' . $cbId . ', ' . $cbSrc . ', старт ' . $cbSt . ', ID в задании: ' . $cbN . '). Новое удаление и переход к подтверждению недоступны; проверка CSV без удаления — доступна.',
		'DETAILS' => 'Журнал: <a href="' . mf_cdc_h($historyQs) . '">' . mf_cdc_h($historyQs) . '</a>. Лог CLI: <code>/upload/tmp/mf_cdc_cli_' . $cbId . '.log</code>',
		'HTML' => true,
	]);
}

if ($cdcRunBlock === null && is_array($cdcRunStale))
{
	$csId = (int)($cdcRunStale['ID'] ?? 0);
	$csSt = mf_cdc_h((string)($cdcRunStale['STARTED_AT'] ?? ''));
	$ttl = (int)mf_cdc_log_running_ttl_minutes();
	\CAdminMessage::ShowMessage([
		'TYPE' => 'OK',
		'MESSAGE' => 'В журнале есть незавершённая запись №' . $csId . ' (старт ' . $csSt . '), статус running дольше ' . $ttl . ' мин. Возможно, процесс завершился с ошибкой. Новое удаление разрешено; при необходимости пометьте запись вручную в БД.',
	]);
}

$cliExample = 'php ' . mf_cdc_h(rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/mf_catalog_delete_cli.php') . ' --dry-run --file=/path/to/ids.csv';
$cliApplyExample = 'php ' . mf_cdc_h(rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/mf_catalog_delete_cli.php') . ' --apply --file=/path/to/ids.csv';

?>
<div class="adm-detail-content-wrap" style="padding:12px;">
	<p><a href="<?= mf_cdc_h($historyQs) ?>">Журнал запусков (таблица mf_catalog_delete_run)</a></p>
	<?php
	if (is_array($confirmState))
	{
		$ec = (int)($confirmState['eligible_count'] ?? 0);
		$ti = (int)($confirmState['total_ids'] ?? 0);
		$f10 = $confirmState['first10'] ?? [];
		$f10s = is_array($f10) ? implode(', ', array_map(static fn ($v) => (string)(int)$v, $f10)) : '';
		$tok = mf_cdc_h((string)($confirmState['token'] ?? ''));
		?>
		<div class="adm-info-message" style="background:#fff3cd;border-color:#ffc107;">
			<p style="margin:0 0 12px 0;font-size:14px;">
				<strong>Подтверждение удаления</strong>
			</p>
			<ul style="margin:0 0 12px 18px;line-height:1.5;">
				<li>В файле уникальных ID: <strong><?= $ti ?></strong>.</li>
				<li>Будет предпринята попытка удалить элементы каталога (есть в нужном инфоблоке): <strong><?= $ec ?></strong>.</li>
				<li>Первые 10 ID из списка допустимых к удалению: <strong><?= $f10s !== '' ? mf_cdc_h($f10s) : '—' ?></strong></li>
			</ul>
			<p style="margin:0 0 12px 0;color:#666;">
				Остальные ID из файла будут обработаны при подтверждении; по ним возможны ошибки (нет элемента, неверный инфоблок) — они попадут в отчёт и в журнал.
			</p>
			<?php
			$cdcLocked = is_array($cdcRunBlock);
			if ($cdcLocked)
			{
				?>
				<p style="color:#c00;font-weight:bold;">Сейчас уже выполняется другое удаление — кнопка подтверждения отключена. Дождитесь окончания или нажмите «Отмена», чтобы сбросить черновик.</p>
				<?php
			}
			else
			{
				?>
			<form method="post" style="display:inline-block;margin-right:8px;vertical-align:top;" action="">
				<?= bitrix_sessid_post() ?>
				<input type="hidden" name="mf_cdc_action" value="confirm">
				<input type="hidden" name="mf_cdc_token" value="<?= $tok ?>">
				<p style="margin:0 0 8px 0;">
					<label>
						<input type="checkbox" name="mf_cdc_cli" value="Y" checked>
						<strong>Фон (CLI)</strong> — для больших списков (рекомендуется). Иначе удаление в этом HTTP-запросе.
					</label>
				</p>
				<input type="submit" class="adm-btn-save" value="OK — подтвердить удаление">
			</form>
				<?php
			}
			?>
			<form method="post" style="display:inline-block;" action="">
				<?= bitrix_sessid_post() ?>
				<input type="hidden" name="mf_cdc_action" value="cancel">
				<input type="submit" value="Отмена">
			</form>
		</div>
		<p><a href="<?= mf_cdc_h($adminPageQs . '&mf_cdc_cancel=Y&' . bitrix_sessid_get()) ?>">Сбросить и загрузить другой файл</a></p>
		<?php
	}
	else
	{
		?>
	<p class="adm-info-message">
		Загрузите CSV: в <strong>первой колонке</strong> — ID элемента каталога (как в выгрузке).
		Разделитель — точка с запятой или запятая; строка с нечисловым первым полем пропускается (удобно для заголовка <code>id</code>).
		Удалению подлежат только элементы инфоблока товаров (<code>IBLOCK_ID=<?= (int)$mfCdcIblockId ?></code>)
		и инфоблока ТП: <code><?= mf_cdc_h(implode(', ', $allowedIblocks)) ?></code>.
		Для товара с SKU сначала удаляются офферы, затем родитель. Операция <strong>необратима</strong>.
	</p>
	<p class="adm-info-message" style="background:#f8f9fa;">
		<strong>Консоль (без таймаута браузера):</strong><br>
		<code><?= $cliExample ?></code><br>
		<code><?= $cliApplyExample ?></code><br>
		Переменная <code>MF_PHP_CLI</code> задаёт бинарник PHP для фонового запуска из админки (по приоритету над <code>PHP_BINDIR</code>).
		<br>Порог «активного» running для блокировки новых удалений: <code>MF_CDC_RUNNING_TTL_MINUTES</code> (по умолчанию 180 мин); старше — только предупреждение о «зависшей» записи.
	</p>
	<p class="adm-info-message" style="background:#f8f9fa;">
		Если снять галку «Только проверить», после загрузки откроется подтверждение (в т.ч. флаг «Фон (CLI)»).
	</p>
	<form method="post" enctype="multipart/form-data" action="">
		<?= bitrix_sessid_post() ?>
		<input type="hidden" name="mf_cdc_go" value="Y">
		<table class="internal" style="max-width:640px;">
			<tr>
				<td>CSV-файл</td>
				<td><input type="file" name="mf_cdc_file" accept=".csv,text/csv" required></td>
			</tr>
			<tr>
				<td></td>
				<td>
					<label>
						<input type="checkbox" name="mf_cdc_dry" value="Y" checked>
						Только проверить (не удалять, посчитать строки с ID)
					</label>
				</td>
			</tr>
			<tr>
				<td></td>
				<td><input type="submit" class="adm-btn-save" value="Выполнить"></td>
			</tr>
		</table>
	</form>
		<?php
	}
	?>
</div>
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
