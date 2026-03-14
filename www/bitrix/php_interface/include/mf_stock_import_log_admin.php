<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
{
	die('Admin only');
}

global $APPLICATION, $USER, $DB;

if (!is_object($USER) || !$USER->IsAdmin())
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Недостаточно прав.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

if (!class_exists(Application::class))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Bitrix\\Main\\Application недоступен.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

// Ensure $DB exists (legacy DB wrapper), because CAdminList works best with it.
if (!isset($DB) || !is_object($DB))
{
	// Fallback: use Connection for queries in detail mode.
}

function mf_sl_escape(string $s): string
{
	return function_exists('htmlspecialcharsbx')
		? (string)htmlspecialcharsbx($s)
		: htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mf_sl_fmt_duration($ms): string
{
	if ($ms === null) return '';
	$ms = (int)$ms;
	if ($ms <= 0) return '';
	if ($ms < 1000) return '<1s';

	$sec = intdiv($ms, 1000);
	$h = intdiv($sec, 3600);
	$m = intdiv($sec % 3600, 60);
	$s = $sec % 60;
	return sprintf('%d:%02d:%02d', $h, $m, $s);
}

function mf_sl_table_exists(): bool
{
	try
	{
		$conn = Application::getConnection();
		$driver = method_exists($conn, 'getType') ? (string)$conn->getType() : '';
		if ($driver !== '' && stripos($driver, 'mysql') === false)
		{
			return false;
		}
		$r = $conn->query("SHOW TABLES LIKE 'mf_supplier_stock_run_log'")->fetch();
		return (bool)$r;
	}
	catch (\Throwable $e)
	{
		return false;
	}
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$APPLICATION->SetTitle('Логи импорта остатков');

if (!mf_sl_table_exists())
{
	\CAdminMessage::ShowMessage([
		'TYPE' => 'ERROR',
		'MESSAGE' => 'Таблица логов не найдена: mf_supplier_stock_run_log. Запусти импорт остатков хотя бы один раз (в apply), чтобы таблица создалась.',
	]);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

$viewId = (int)($_REQUEST['ID'] ?? 0);
$isView = ((string)($_REQUEST['view'] ?? '') === 'Y') && $viewId > 0;

if ($isView)
{
	$conn = Application::getConnection();
	$h = $conn->getSqlHelper();
	$row = $conn->query("SELECT * FROM mf_supplier_stock_run_log WHERE ID=" . (int)$viewId . " LIMIT 1")->fetch();

	if (!$row)
	{
		\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Запуск не найден.']);
		require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
		return;
	}

	$backUrl = 'mf_stock_import_log.php?lang=ru';
	$aContext = [
		[
			'TEXT' => 'Назад к списку',
			'LINK' => $backUrl,
			'TITLE' => 'Вернуться к списку запусков',
			'ICON' => 'btn_list',
		],
	];
	(new \CAdminContextMenu($aContext))->Show();

	$kv = static function (string $k, $v): void
	{
		echo '<tr><td width="260" style="color:#555;">' . mf_sl_escape($k) . '</td><td>' . mf_sl_escape((string)($v ?? '')) . '</td></tr>';
	};

	echo '<div style="max-width: 1100px;">';
	echo '<h2 style="margin: 8px 0 12px 0;">Запуск #' . (int)$viewId . '</h2>';
	echo '<table class="adm-detail-content-table edit-table" style="width:100%;background:#fff;">';
	echo '<tbody>';
	$kv('Статус', $row['UF_STATUS'] ?? '');
	$kv('Started', $row['UF_STARTED_AT'] ?? '');
	$kv('Finished', $row['UF_FINISHED_AT'] ?? '');
	$kv('Duration', mf_sl_fmt_duration($row['UF_DURATION_MS'] ?? null));
	$kv('Склад (код)', $row['UF_WAREHOUSE_CODE'] ?? '');
	$kv('Склад (название)', $row['UF_WAREHOUSE_TITLE'] ?? '');
	$kv('STORE_ID', $row['UF_STORE_ID'] ?? '');
	$kv('STORE_XML_ID', $row['UF_STORE_XML_ID'] ?? '');
	$kv('Файл', $row['UF_INPUT_FILE'] ?? '');
	$kv('ENCODING', $row['UF_ENCODING'] ?? '');
	$kv('MODE', $row['UF_MODE'] ?? '');
	$kv('PRICE_UPDATE', $row['UF_PRICE_UPDATE'] ?? '');
	$kv('RECALC_BASE', $row['UF_RECALC_BASE'] ?? '');
	$kv('SYNC_MISSING', $row['UF_SYNC_MISSING'] ?? '');
	$kv('TOTAL', $row['UF_TOTAL'] ?? '');
	$kv('UPDATED', $row['UF_UPDATED'] ?? '');
	$kv('NOT_FOUND', $row['UF_NOT_FOUND'] ?? '');
	$kv('ZEROED', $row['UF_ZEROED'] ?? '');
	$kv('ERRORS', $row['UF_ERRORS'] ?? '');
	$kv('Host', $row['UF_HOST'] ?? '');
	$kv('PID', $row['UF_PID'] ?? '');
	$kv('SAPI', $row['UF_PHP_SAPI'] ?? '');
	$kv('Memory peak, MB', $row['UF_MEMORY_PEAK_MB'] ?? '');
	$kv('Note', $row['UF_NOTE'] ?? '');
	echo '</tbody></table>';

	$notFoundItems = trim((string)($row['UF_NOT_FOUND_ITEMS'] ?? ''));
	$errorItems = trim((string)($row['UF_ERROR_ITEMS'] ?? ''));

	echo '<div style="margin-top:14px;display:flex;gap:12px;flex-wrap:wrap;">';
	echo '<div style="flex: 1 1 520px;min-width:360px;">';
	echo '<h3 style="margin: 8px 0;">Не найдены (brand;article)</h3>';
	echo '<textarea readonly rows="16" style="width:100%;font-family:monospace;">' . mf_sl_escape($notFoundItems) . '</textarea>';
	echo '</div>';
	echo '<div style="flex: 1 1 520px;min-width:360px;">';
	echo '<h3 style="margin: 8px 0;">Ошибки обработки (brand;article)</h3>';
	echo '<textarea readonly rows="16" style="width:100%;font-family:monospace;">' . mf_sl_escape($errorItems) . '</textarea>';
	echo '</div>';
	echo '</div>';

	echo '</div>';

	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

// --- List view ---
$sTableID = 'tbl_mf_supplier_stock_runs';
$oSort = new \CAdminSorting($sTableID, 'UF_STARTED_AT', 'desc');
$lAdmin = new \CAdminList($sTableID, $oSort);

$filterFields = [
	'find_started_from',
	'find_started_to',
	'find_warehouse_code',
	'find_status',
	'find_mode',
	'find_file',
];
$lAdmin->InitFilter($filterFields);

$find_started_from = (string)($find_started_from ?? '');
$find_started_to = (string)($find_started_to ?? '');
$find_warehouse_code = (string)($find_warehouse_code ?? '');
$find_status = (string)($find_status ?? '');
$find_mode = (string)($find_mode ?? '');
$find_file = (string)($find_file ?? '');

$conn = Application::getConnection();
$h = $conn->getSqlHelper();
$where = [];

$tsFrom = $find_started_from !== '' ? (int)\MakeTimeStamp($find_started_from) : 0;
if ($tsFrom > 0)
{
	$where[] = "UF_STARTED_AT >= '" . $h->forSql(date('Y-m-d H:i:s', $tsFrom)) . "'";
}
$tsTo = $find_started_to !== '' ? (int)\MakeTimeStamp($find_started_to) : 0;
if ($tsTo > 0)
{
	// include the whole day if time not specified
	$where[] = "UF_STARTED_AT <= '" . $h->forSql(date('Y-m-d H:i:s', $tsTo)) . "'";
}
if (trim($find_warehouse_code) !== '')
{
	$where[] = "UF_WAREHOUSE_CODE LIKE '%" . $h->forSql(trim($find_warehouse_code)) . "%'";
}
if (trim($find_status) !== '')
{
	$where[] = "UF_STATUS='" . $h->forSql(trim($find_status)) . "'";
}
if (trim($find_mode) !== '')
{
	$where[] = "UF_MODE='" . $h->forSql(trim($find_mode)) . "'";
}
if (trim($find_file) !== '')
{
	$where[] = "UF_INPUT_FILE LIKE '%" . $h->forSql(trim($find_file)) . "%'";
}

$whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

$by = strtoupper((string)$oSort->getField());
$order = strtoupper((string)$oSort->getOrder());
$allowedSort = ['ID', 'UF_STARTED_AT', 'UF_STATUS', 'UF_WAREHOUSE_CODE', 'UF_MODE', 'UF_TOTAL', 'UF_UPDATED', 'UF_ERRORS'];
if (!in_array($by, $allowedSort, true))
{
	$by = 'UF_STARTED_AT';
}
if (!in_array($order, ['ASC', 'DESC'], true))
{
	$order = 'DESC';
}

// Keep list fast: show last 500 runs (filters can narrow it down).
$limit = 500;
$sql = "SELECT
	ID,
	UF_STARTED_AT,
	UF_FINISHED_AT,
	UF_DURATION_MS,
	UF_STATUS,
	UF_WAREHOUSE_CODE,
	UF_STORE_XML_ID,
	UF_INPUT_FILE,
	UF_MODE,
	UF_PRICE_UPDATE,
	UF_RECALC_BASE,
	UF_SYNC_MISSING,
	UF_TOTAL,
	UF_UPDATED,
	UF_NOT_FOUND,
	UF_ZEROED,
	UF_ERRORS
FROM mf_supplier_stock_run_log
$whereSql
ORDER BY $by $order, ID DESC
LIMIT $limit";

$rows = [];
try
{
	$res = $conn->query($sql);
	while ($r = $res->fetch())
	{
		$rows[] = $r;
	}
}
catch (\Throwable $e)
{
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Ошибка чтения логов: ' . mf_sl_escape($e->getMessage())]);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

$lAdmin->AddHeaders([
	['id' => 'ID', 'content' => 'ID', 'default' => true, 'sort' => 'ID'],
	['id' => 'UF_STARTED_AT', 'content' => 'Started', 'default' => true, 'sort' => 'UF_STARTED_AT'],
	['id' => 'UF_DURATION_MS', 'content' => 'Duration', 'default' => true, 'sort' => 'UF_DURATION_MS'],
	['id' => 'UF_STATUS', 'content' => 'Status', 'default' => true, 'sort' => 'UF_STATUS'],
	['id' => 'UF_WAREHOUSE_CODE', 'content' => 'Warehouse', 'default' => true, 'sort' => 'UF_WAREHOUSE_CODE'],
	['id' => 'UF_STORE_XML_ID', 'content' => 'Store XML_ID', 'default' => false],
	['id' => 'UF_INPUT_FILE', 'content' => 'File', 'default' => true],
	['id' => 'UF_MODE', 'content' => 'Mode', 'default' => true, 'sort' => 'UF_MODE'],
	['id' => 'UF_PRICE_UPDATE', 'content' => 'Price', 'default' => false],
	['id' => 'UF_SYNC_MISSING', 'content' => 'SyncMissing', 'default' => false],
	['id' => 'UF_TOTAL', 'content' => 'Total', 'default' => true, 'sort' => 'UF_TOTAL'],
	['id' => 'UF_UPDATED', 'content' => 'Updated', 'default' => true, 'sort' => 'UF_UPDATED'],
	['id' => 'UF_NOT_FOUND', 'content' => 'NotFound', 'default' => true],
	['id' => 'UF_ZEROED', 'content' => 'Zeroed', 'default' => true],
	['id' => 'UF_ERRORS', 'content' => 'Errors', 'default' => true, 'sort' => 'UF_ERRORS'],
]);

foreach ($rows as $r)
{
	$id = (int)($r['ID'] ?? 0);
	if ($id <= 0) continue;

	$viewUrl = 'mf_stock_import_log.php?lang=ru&view=Y&ID=' . $id;
	$row = &$lAdmin->AddRow((string)$id, $r, $viewUrl, 'Открыть');

	$file = (string)($r['UF_INPUT_FILE'] ?? '');
	$fileShort = $file !== '' ? basename($file) : '';
	$dur = mf_sl_fmt_duration($r['UF_DURATION_MS'] ?? null);

	$row->AddViewField('ID', '<a href="' . mf_sl_escape($viewUrl) . '">' . (int)$id . '</a>');
	$row->AddViewField('UF_INPUT_FILE', mf_sl_escape($fileShort));
	$row->AddViewField('UF_DURATION_MS', mf_sl_escape($dur));

	$actions = [
		[
			'ICON' => 'view',
			'TEXT' => 'Просмотр',
			'ACTION' => $lAdmin->ActionRedirect($viewUrl),
			'DEFAULT' => true,
		],
	];
	$row->AddActions($actions);
}

$filter = new \CAdminFilter($sTableID . '_filter', [
	'Дата запуска (с)',
	'Дата запуска (по)',
	'Код склада',
	'Статус',
	'Режим',
	'Файл содержит',
]);

?>
<div style="max-width: 1200px;">
	<div style="margin: 8px 0 12px 0; color:#666;">
		Показываем последние <?= (int)$limit ?> запусков (фильтры могут сузить выборку).
	</div>

	<form name="find_form" method="get" action="<?= mf_sl_escape($APPLICATION->GetCurPage()) ?>">
		<?php $filter->Begin(); ?>
		<tr>
			<td>Дата запуска (с):</td>
			<td><?= \CalendarDate('find_started_from', mf_sl_escape($find_started_from), 'find_form', '19') ?></td>
		</tr>
		<tr>
			<td>Дата запуска (по):</td>
			<td><?= \CalendarDate('find_started_to', mf_sl_escape($find_started_to), 'find_form', '19') ?></td>
		</tr>
		<tr>
			<td>Код склада:</td>
			<td><input type="text" name="find_warehouse_code" size="30" value="<?= mf_sl_escape($find_warehouse_code) ?>"></td>
		</tr>
		<tr>
			<td>Статус:</td>
			<td>
				<select name="find_status">
					<option value="" <?= ($find_status === '' ? 'selected' : '') ?>>—</option>
					<option value="running" <?= ($find_status === 'running' ? 'selected' : '') ?>>running</option>
					<option value="ok" <?= ($find_status === 'ok' ? 'selected' : '') ?>>ok</option>
					<option value="failed" <?= ($find_status === 'failed' ? 'selected' : '') ?>>failed</option>
				</select>
			</td>
		</tr>
		<tr>
			<td>Режим:</td>
			<td>
				<select name="find_mode">
					<option value="" <?= ($find_mode === '' ? 'selected' : '') ?>>—</option>
					<option value="APPLY" <?= ($find_mode === 'APPLY' ? 'selected' : '') ?>>APPLY</option>
					<option value="DRY-RUN" <?= ($find_mode === 'DRY-RUN' ? 'selected' : '') ?>>DRY-RUN</option>
				</select>
			</td>
		</tr>
		<tr>
			<td>Файл содержит:</td>
			<td><input type="text" name="find_file" size="40" value="<?= mf_sl_escape($find_file) ?>"></td>
		</tr>
		<?php
		$filter->Buttons(['table_id' => $sTableID, 'url' => $APPLICATION->GetCurPage(), 'form' => 'find_form']);
		$filter->End();
		?>
	</form>

	<?php
	$lAdmin->CheckListMode();
	$lAdmin->DisplayList();
	?>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

