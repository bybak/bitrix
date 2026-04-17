<?php

declare(strict_types=1);

use Bitrix\Main\Application;

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

$lib = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_external_price_lib.php';
if (!is_file($lib))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не найден mf_external_price_lib.php']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}
require_once $lib;

$epLogOk = function_exists('mf_external_price_import_log_ensure_table') && mf_external_price_import_log_ensure_table();

function mf_eh_escape(string $s): string
{
	return function_exists('htmlspecialcharsbx')
		? (string)htmlspecialcharsbx($s)
		: htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mf_eh_fmt_duration($ms): string
{
	if ($ms === null)
	{
		return '';
	}
	$ms = (int)$ms;
	if ($ms <= 0)
	{
		return '';
	}
	if ($ms < 1000)
	{
		return '<1s';
	}

	$sec = intdiv($ms, 1000);
	$h = intdiv($sec, 3600);
	$m = intdiv($sec % 3600, 60);
	$s = $sec % 60;

	return sprintf('%d:%02d:%02d', $h, $m, $s);
}

function mf_eh_table_exists(): bool
{
	try
	{
		$conn = Application::getConnection();
		$driver = method_exists($conn, 'getType') ? (string)$conn->getType() : '';
		if ($driver !== '' && stripos($driver, 'mysql') === false)
		{
			return false;
		}
		$r = $conn->query("SHOW TABLES LIKE 'mf_external_price_import_log'")->fetch();

		return (bool)$r;
	}
	catch (\Throwable $e)
	{
		return false;
	}
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$APPLICATION->SetTitle('История импорта внешних прайсов');

if (!$epLogOk || !mf_eh_table_exists())
{
	\CAdminMessage::ShowMessage([
		'TYPE' => 'ERROR',
		'MESSAGE' => 'Не удалось создать или открыть таблицу mf_external_price_import_log (проверьте MySQL и права).',
	]);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

$viewId = (int)($_REQUEST['ID'] ?? 0);
$isView = ((string)($_REQUEST['view'] ?? '') === 'Y') && $viewId > 0;

if ($isView && mf_eh_table_exists())
{
	$conn = Application::getConnection();
	$h = $conn->getSqlHelper();
	$row = $conn->query('SELECT * FROM mf_external_price_import_log WHERE ID=' . (int)$viewId . ' LIMIT 1')->fetch();

	if (!$row)
	{
		\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Запись не найдена.']);
		require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

		return;
	}

	$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
	$backUrl = 'mf_external_price_history.php?lang=' . urlencode($lang);
	$aContext = [
		[
			'TEXT' => 'Назад к списку',
			'LINK' => $backUrl,
			'TITLE' => 'К списку',
			'ICON' => 'btn_list',
		],
	];
	(new \CAdminContextMenu($aContext))->Show();

	$kv = static function (string $k, $v): void {
		echo '<tr><td width="280" style="color:#555;">' . mf_eh_escape($k) . '</td><td>' . nl2br(mf_eh_escape((string)($v ?? ''))) . '</td></tr>';
	};

	echo '<div style="max-width:1100px">';
	echo '<h2 style="margin:8px 0 12px 0;">Импорт #' . (int)$viewId . '</h2>';
	echo '<table class="adm-detail-content-table edit-table" style="width:100%;background:#fff;"><tbody>';
	$kv('Статус', $row['UF_STATUS'] ?? '');
	$kv('Начало', $row['UF_STARTED_AT'] ?? '');
	$kv('Окончание', $row['UF_FINISHED_AT'] ?? '');
	$kv('Длительность', mf_eh_fmt_duration($row['UF_DURATION_MS'] ?? null));
	$kv('Пользователь (ID)', ($row['UF_USER_ID'] ?? '') . ' / ' . ($row['UF_USER_LOGIN'] ?? ''));
	$kv('Склад ID', $row['UF_STORE_ID'] ?? '');
	$kv('Склад XML_ID', $row['UF_STORE_XML_ID'] ?? '');
	$kv('Склад название', $row['UF_STORE_TITLE'] ?? '');
	$kv('Тип цены (ID)', $row['UF_PRICE_GROUP_ID'] ?? '');
	$kv('Файл', $row['UF_INPUT_FILENAME'] ?? '');
	$kv('Размер файла, байт', $row['UF_FILE_SIZE'] ?? '');
	$kv('Валюта прайса', $row['UF_CURRENCY'] ?? '');
	$kv('Обнулять отсутствующие', $row['UF_ZERO_MISSING'] ?? '');
	$kv('Учёт веса', $row['UF_WEIGHT_USE'] ?? '');
	$kv('₽ за кг', $row['UF_WEIGHT_RUB_PER_KG'] ?? '');
	$kv('Строк данных в файле (непустых)', $row['UF_TOTAL_DATA_ROWS'] ?? '');
	$kv('Сопоставлено (обновлено цен)', $row['UF_MATCHED'] ?? '');
	$kv('Не найдено в каталоге', $row['UF_NOT_FOUND'] ?? '');
	$kv('Пропуск / битые строки', $row['UF_BAD_ROWS'] ?? '');
	$kv('Обнулено на складе', $row['UF_ZEROED'] ?? '');
	$kv('Строка заголовка CSV', $row['UF_HEADER_LINE'] ?? '');
	$kv('Ошибка', $row['UF_ERROR_MESSAGE'] ?? '');
	echo '</tbody></table>';

	$ex = trim((string)($row['UF_EXAMPLES_NOT_FOUND'] ?? ''));
	if ($ex !== '')
	{
		echo '<h3 style="margin:16px 0 8px 0;">Примеры не найденных (brand/article)</h3>';
		echo '<textarea readonly rows="12" style="width:100%;font-family:monospace;">' . mf_eh_escape($ex) . '</textarea>';
	}
	echo '</div>';

	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

$sTableID = 'tbl_mf_ext_price_import_log';
$oSort = new \CAdminSorting($sTableID, 'UF_STARTED_AT', 'desc');
$lAdmin = new \CAdminList($sTableID, $oSort);

$filterFields = [
	'find_started_from',
	'find_started_to',
	'find_status',
	'find_store_id',
	'find_file',
];
$lAdmin->InitFilter($filterFields);

$find_started_from = (string)($find_started_from ?? '');
$find_started_to = (string)($find_started_to ?? '');
$find_status = (string)($find_status ?? '');
$find_store_id = (string)($find_store_id ?? '');
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
	$where[] = "UF_STARTED_AT <= '" . $h->forSql(date('Y-m-d H:i:s', $tsTo)) . "'";
}
if (trim($find_status) !== '')
{
	$where[] = "UF_STATUS='" . $h->forSql(trim($find_status)) . "'";
}
if (trim($find_store_id) !== '' && ctype_digit(trim($find_store_id)))
{
	$where[] = 'UF_STORE_ID=' . (int)$find_store_id;
}
if (trim($find_file) !== '')
{
	$where[] = "UF_INPUT_FILENAME LIKE '%" . $h->forSql(trim($find_file)) . "%'";
}

$whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

$by = strtoupper((string)$oSort->getField());
$order = strtoupper((string)$oSort->getOrder());
$allowedSort = ['ID', 'UF_STARTED_AT', 'UF_STATUS', 'UF_STORE_ID', 'UF_MATCHED', 'UF_DURATION_MS'];
if (!in_array($by, $allowedSort, true))
{
	$by = 'UF_STARTED_AT';
}
if (!in_array($order, ['ASC', 'DESC'], true))
{
	$order = 'DESC';
}

$limit = 500;
$sql = "SELECT ID, UF_STARTED_AT, UF_FINISHED_AT, UF_DURATION_MS, UF_STATUS, UF_USER_LOGIN,
	UF_STORE_ID, UF_STORE_XML_ID, UF_INPUT_FILENAME, UF_CURRENCY, UF_ZERO_MISSING, UF_WEIGHT_USE,
	UF_MATCHED, UF_NOT_FOUND, UF_BAD_ROWS, UF_ZEROED
FROM mf_external_price_import_log
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
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Ошибка: ' . mf_eh_escape($e->getMessage())]);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

$lAdmin->AddHeaders([
	['id' => 'ID', 'content' => 'ID', 'default' => true, 'sort' => 'ID'],
	['id' => 'UF_STARTED_AT', 'content' => 'Начало', 'default' => true, 'sort' => 'UF_STARTED_AT'],
	['id' => 'UF_DURATION_MS', 'content' => 'Длительность', 'default' => true, 'sort' => 'UF_DURATION_MS'],
	['id' => 'UF_STATUS', 'content' => 'Статус', 'default' => true, 'sort' => 'UF_STATUS'],
	['id' => 'UF_USER_LOGIN', 'content' => 'Кто', 'default' => true],
	['id' => 'UF_STORE_ID', 'content' => 'Склад', 'default' => true, 'sort' => 'UF_STORE_ID'],
	['id' => 'UF_STORE_XML_ID', 'content' => 'XML_ID', 'default' => false],
	['id' => 'UF_INPUT_FILENAME', 'content' => 'Файл', 'default' => true],
	['id' => 'UF_CURRENCY', 'content' => 'Валюта', 'default' => true],
	['id' => 'UF_ZERO_MISSING', 'content' => 'Обнул.', 'default' => false],
	['id' => 'UF_WEIGHT_USE', 'content' => 'Вес', 'default' => false],
	['id' => 'UF_MATCHED', 'content' => 'Ок', 'default' => true, 'sort' => 'UF_MATCHED'],
	['id' => 'UF_NOT_FOUND', 'content' => 'Нет', 'default' => true],
	['id' => 'UF_BAD_ROWS', 'content' => 'Брак', 'default' => true],
	['id' => 'UF_ZEROED', 'content' => 'Обнул.', 'default' => true],
]);

$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';

foreach ($rows as $r)
{
	$id = (int)($r['ID'] ?? 0);
	if ($id <= 0)
	{
		continue;
	}

	$viewUrl = 'mf_external_price_history.php?lang=' . urlencode($lang) . '&view=Y&ID=' . $id;
	$row = &$lAdmin->AddRow((string)$id, $r, $viewUrl, 'Открыть');

	$fn = (string)($r['UF_INPUT_FILENAME'] ?? '');
	$fnShort = $fn !== '' ? basename($fn) : '';
	$dur = mf_eh_fmt_duration($r['UF_DURATION_MS'] ?? null);

	$row->AddViewField('ID', '<a href="' . mf_eh_escape($viewUrl) . '">' . $id . '</a>');
	$row->AddViewField('UF_INPUT_FILENAME', mf_eh_escape($fnShort));
	$row->AddViewField('UF_DURATION_MS', mf_eh_escape($dur));
	$row->AddActions([
		[
			'ICON' => 'view',
			'TEXT' => 'Подробно',
			'ACTION' => $lAdmin->ActionRedirect($viewUrl),
			'DEFAULT' => true,
		],
	]);
}

$filter = new \CAdminFilter($sTableID . '_filter', [
	'Дата с',
	'Дата по',
	'Статус',
	'Склад ID',
	'Файл содержит',
]);

?>
<div style="max-width:1250px">
	<p style="margin:8px 0;color:#666">Последние <?= (int)$limit ?> записей. Лог пишется при каждой попытке загрузки (в т.ч. ошибки валидации).</p>

	<form name="find_form" method="get" action="<?= mf_eh_escape($APPLICATION->GetCurPage()) ?>">
		<input type="hidden" name="lang" value="<?= mf_eh_escape($lang) ?>" />
		<?php $filter->Begin(); ?>
		<tr>
			<td>Дата с:</td>
			<td><?= \CalendarDate('find_started_from', mf_eh_escape($find_started_from), 'find_form', '19') ?></td>
		</tr>
		<tr>
			<td>Дата по:</td>
			<td><?= \CalendarDate('find_started_to', mf_eh_escape($find_started_to), 'find_form', '19') ?></td>
		</tr>
		<tr>
			<td>Статус:</td>
			<td>
				<select name="find_status">
					<option value="">—</option>
					<option value="ok" <?= ($find_status === 'ok' ? 'selected' : '') ?>>ok</option>
					<option value="failed" <?= ($find_status === 'failed' ? 'selected' : '') ?>>failed</option>
					<option value="validation_failed" <?= ($find_status === 'validation_failed' ? 'selected' : '') ?>>validation_failed</option>
				</select>
			</td>
		</tr>
		<tr>
			<td>Склад ID:</td>
			<td><input type="text" name="find_store_id" size="10" value="<?= mf_eh_escape($find_store_id) ?>" /></td>
		</tr>
		<tr>
			<td>Файл содержит:</td>
			<td><input type="text" name="find_file" size="40" value="<?= mf_eh_escape($find_file) ?>" /></td>
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
