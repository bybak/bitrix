<?php

declare(strict_types=1);

/**
 * Админка: заказы поставщику (таблицы mf_supplier_order / mf_supplier_order_line).
 */

use Bitrix\Main\Application;
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

$lib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_supplier_orders_lib.php';
if (!is_file($lib))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не найден mf_supplier_orders_lib.php']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}
require_once $lib;

function mf_so_esc(string $s): string
{
	return function_exists('htmlspecialcharsbx')
		? (string)htmlspecialcharsbx($s)
		: htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mf_so_iblock_id(): int
{
	if (function_exists('mf_supplier_orders_catalog_iblock_id'))
	{
		return mf_supplier_orders_catalog_iblock_id();
	}

	return 4;
}

function mf_so_lang(): string
{
	return defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
}

function mf_so_table_ok(): bool
{
	try
	{
		$conn = Application::getConnection();

		return $conn->isTableExists('mf_supplier_order') && $conn->isTableExists('mf_supplier_order_line');
	}
	catch (\Throwable $e)
	{
		return false;
	}
}

try
{
	mf_supplier_orders_ensure_schema();
}
catch (\Throwable $e)
{
	// таблицы могут отсутствовать до первого sync — ниже покажем сообщение
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$APPLICATION->SetTitle('Заказы поставщику (UNF)');

$lang = mf_so_lang();
$langQ = rawurlencode($lang);

if (!mf_so_table_ok())
{
	\CAdminMessage::ShowMessage([
		'TYPE' => 'ERROR',
		'MESSAGE' => 'Таблицы mf_supplier_order не найдены. Запустите синхронизацию: <code>php tools/mf_supplier_orders_sync.php</code>',
	]);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

Loader::includeModule('iblock');

$viewId = (int)($_REQUEST['ID'] ?? 0);
$isView = ((string)($_REQUEST['view'] ?? '') === 'Y') && $viewId > 0;

if ($isView)
{
	$conn = Application::getConnection();
	$h = $conn->getSqlHelper();
	$row = $conn->query(
		'SELECT * FROM ' . $h->quote('mf_supplier_order') . ' WHERE `ID`=' . $viewId . ' LIMIT 1'
	)->fetch();

	if (!$row)
	{
		\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Заказ не найден.']);
		require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

		return;
	}

	$lines = [];
	try
	{
		$res = $conn->query(
			'SELECT * FROM ' . $h->quote('mf_supplier_order_line') . '
			WHERE `ORDER_ID`=' . $viewId . '
			ORDER BY `LINE_NO` ASC'
		);
		while ($ln = $res->fetch())
		{
			$lines[] = $ln;
		}
	}
	catch (\Throwable $e)
	{
		\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Ошибка чтения строк: ' . mf_so_esc($e->getMessage())]);
		require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

		return;
	}

	$backUrl = 'mf_supplier_orders.php?lang=' . $langQ;
	$aContext = [
		[
			'TEXT' => 'Назад к списку',
			'LINK' => $backUrl,
			'TITLE' => 'К списку заказов',
			'ICON' => 'btn_list',
		],
	];
	(new \CAdminContextMenu($aContext))->Show();

	$iblockId = mf_so_iblock_id();

	$kv = static function (string $k, $v): void {
		echo '<tr><td width="280" style="color:#555;">' . mf_so_esc($k) . '</td><td>' . mf_so_esc((string)($v ?? '')) . '</td></tr>';
	};

	echo '<div style="max-width: 1280px;">';
	echo '<h2 style="margin: 8px 0 12px 0;">Заказ #' . $viewId . '</h2>';
	echo '<table class="adm-detail-content-table edit-table" style="width:100%;background:#fff;"><tbody>';
	$kv('Документ (номер)', $row['DOC_NUMBER'] ?? '');
	$kv('UUID 1С', $row['DOC_UUID'] ?? '');
	$kv('Дата документа', $row['DOC_DATE'] ?? '');
	$kv('Ссылка ref', $row['DOC_REF'] ?? '');
	$kv('Состояние', ($row['STATE_NAME'] ?? '') . ' (' . ($row['STATE_KEY'] ?? '') . ')');
	$kv('Поставщик', ($row['SUPPLIER_NAME'] ?? '') . ' [' . ($row['SUPPLIER_UUID'] ?? '') . ']');
	$kv('Синхронизация', $row['SYNCED_AT'] ?? '');
	echo '</tbody></table>';

	echo '<h3 style="margin:18px 0 8px 0;">Строки заказа (' . count($lines) . ')</h3>';
	echo '<table class="internal" style="width:100%;border-collapse:collapse;background:#fff;">';
	echo '<thead><tr>';
	$ths = ['№', 'Номенклатура', 'Артикул', 'Бренд', 'Кол-во', 'Ед.', 'Дата поступления', 'Матчинг', 'Товар сайта'];
	foreach ($ths as $th)
	{
		echo '<td style="padding:8px;border:1px solid #e0e4e9;font-weight:bold;">' . mf_so_esc($th) . '</td>';
	}
	echo '</tr></thead><tbody>';

	foreach ($lines as $ln)
	{
		$pid = (int)($ln['PRODUCT_ID'] ?? 0);
		$m = (string)($ln['MATCH_STATUS'] ?? '');
		$nom = trim((string)($ln['NOM_NAME'] ?? ''));
		if (mb_strlen($nom) > 120)
		{
			$nom = mb_substr($nom, 0, 117) . '…';
		}

		$prodCell = '—';
		if ($pid > 0)
		{
			$editUrl = '/bitrix/admin/iblock_element_edit.php?IBLOCK_ID=' . $iblockId . '&type=catalog&lang=' . $langQ . '&ID=' . $pid . '&WF=Y';
			$prodCell = '<a href="' . mf_so_esc($editUrl) . '">ID ' . $pid . '</a>';
		}

		echo '<tr>';
		echo '<td style="padding:6px 8px;border:1px solid #e0e4e9;">' . (int)($ln['LINE_NO'] ?? 0) . '</td>';
		echo '<td style="padding:6px 8px;border:1px solid #e0e4e9;">' . mf_so_esc($nom) . '</td>';
		echo '<td style="padding:6px 8px;border:1px solid #e0e4e9;">' . mf_so_esc((string)($ln['ARTICLE'] ?? '')) . '</td>';
		echo '<td style="padding:6px 8px;border:1px solid #e0e4e9;">' . mf_so_esc((string)($ln['BRAND'] ?? '')) . '</td>';
		echo '<td style="padding:6px 8px;border:1px solid #e0e4e9;">' . mf_so_esc((string)($ln['QTY'] ?? '')) . '</td>';
		echo '<td style="padding:6px 8px;border:1px solid #e0e4e9;">' . mf_so_esc((string)($ln['UNIT'] ?? '')) . '</td>';
		echo '<td style="padding:6px 8px;border:1px solid #e0e4e9;">' . mf_so_esc((string)($ln['RECEIPT_DATE'] ?? '')) . '</td>';
		echo '<td style="padding:6px 8px;border:1px solid #e0e4e9;">' . mf_so_esc($m) . '</td>';
		echo '<td style="padding:6px 8px;border:1px solid #e0e4e9;">' . $prodCell . '</td>';
		echo '</tr>';
	}

	if ($lines === [])
	{
		echo '<tr><td colspan="9" style="padding:12px;color:#888;">Нет строк в заказе.</td></tr>';
	}

	echo '</tbody></table>';
	echo '</div>';

	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

// --- Список ---
$sTableID = 'tbl_mf_supplier_orders';
$oSort = new \CAdminSorting($sTableID, 'SYNCED_AT', 'desc');
$lAdmin = new \CAdminList($sTableID, $oSort);

$filterFields = [
	'find_number',
	'find_supplier',
	'find_uuid',
];
$lAdmin->InitFilter($filterFields);

$find_number = (string)($find_number ?? '');
$find_supplier = (string)($find_supplier ?? '');
$find_uuid = (string)($find_uuid ?? '');

$conn = Application::getConnection();
$h = $conn->getSqlHelper();

$where = [];
if (trim($find_number) !== '')
{
	$where[] = '`DOC_NUMBER` LIKE \'%' . $h->forSql(trim($find_number)) . '%\'';
}
if (trim($find_supplier) !== '')
{
	$where[] = '`SUPPLIER_NAME` LIKE \'%' . $h->forSql(trim($find_supplier)) . '%\'';
}
if (trim($find_uuid) !== '')
{
	$where[] = '`DOC_UUID` LIKE \'%' . $h->forSql(trim($find_uuid)) . '%\'';
}

$whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

$by = strtoupper((string)$oSort->getField());
$order = strtoupper((string)$oSort->getOrder());
$allowedSort = ['ID', 'DOC_NUMBER', 'DOC_DATE', 'SYNCED_AT', 'SUPPLIER_NAME', 'STATE_NAME', 'LINE_CNT'];
if (!in_array($by, $allowedSort, true))
{
	$by = 'SYNCED_AT';
}
if (!in_array($order, ['ASC', 'DESC'], true))
{
	$order = 'DESC';
}

if ($by === 'LINE_CNT')
{
	$orderSql = 'LINE_CNT ' . $order . ', `ID` DESC';
}
else
{
	$orderSql = '`' . str_replace('`', '', $by) . '` ' . $order . ', `ID` DESC';
}

$limit = 500;

$sql = 'SELECT o.*,
	(SELECT COUNT(*) FROM ' . $h->quote('mf_supplier_order_line') . ' l WHERE l.`ORDER_ID` = o.`ID`) AS LINE_CNT
FROM ' . $h->quote('mf_supplier_order') . ' o
' . $whereSql . '
ORDER BY ' . $orderSql . '
LIMIT ' . (int)$limit;

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
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Ошибка запроса: ' . mf_so_esc($e->getMessage())]);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

$lAdmin->AddHeaders([
	['id' => 'ID', 'content' => 'ID', 'default' => true, 'sort' => 'ID'],
	['id' => 'DOC_NUMBER', 'content' => 'Номер', 'default' => true, 'sort' => 'DOC_NUMBER'],
	['id' => 'DOC_DATE', 'content' => 'Дата док.', 'default' => true, 'sort' => 'DOC_DATE'],
	['id' => 'SUPPLIER_NAME', 'content' => 'Поставщик', 'default' => true, 'sort' => 'SUPPLIER_NAME'],
	['id' => 'STATE_NAME', 'content' => 'Состояние', 'default' => true, 'sort' => 'STATE_NAME'],
	['id' => 'LINE_CNT', 'content' => 'Строк', 'default' => true, 'sort' => 'LINE_CNT'],
	['id' => 'SYNCED_AT', 'content' => 'Синхронизация', 'default' => true, 'sort' => 'SYNCED_AT'],
	['id' => 'DOC_REF', 'content' => 'Ref (кратко)', 'default' => false],
]);

foreach ($rows as $r)
{
	$id = (int)($r['ID'] ?? 0);
	if ($id <= 0)
	{
		continue;
	}

	$viewUrl = 'mf_supplier_orders.php?lang=' . $langQ . '&view=Y&ID=' . $id;
	$ar = $r;
	$row = &$lAdmin->AddRow((string)$id, $ar, $viewUrl, 'Открыть');

	$ref = trim((string)($r['DOC_REF'] ?? ''));
	if (mb_strlen($ref) > 80)
	{
		$ref = mb_substr($ref, 0, 77) . '…';
	}

	$row->AddViewField('ID', '<a href="' . mf_so_esc($viewUrl) . '">' . $id . '</a>');
	$row->AddViewField('DOC_REF', mf_so_esc($ref));

	$row->AddActions([
		[
			'ICON' => 'view',
			'TEXT' => 'Строки заказа',
			'ACTION' => $lAdmin->ActionRedirect($viewUrl),
			'DEFAULT' => true,
		],
	]);
}

$filter = new \CAdminFilter($sTableID . '_filter', [
	'Номер документа',
	'Поставщик',
	'UUID документа',
]);

?>
<div style="max-width: 1320px;">
	<div style="margin: 8px 0 12px 0; color:#666;">
		Данные из синхронизации UNF (<code>supplier_order_get</code>). Показано до <?= (int)$limit ?> заказов (в работе).
	</div>

	<form name="find_form" method="get" action="<?= mf_so_esc($APPLICATION->GetCurPage()) ?>">
		<input type="hidden" name="lang" value="<?= mf_so_esc($lang) ?>">
		<?php $filter->Begin(); ?>
		<tr>
			<td>Номер документа содержит:</td>
			<td><input type="text" name="find_number" size="36" value="<?= mf_so_esc($find_number) ?>"></td>
		</tr>
		<tr>
			<td>Поставщик содержит:</td>
			<td><input type="text" name="find_supplier" size="46" value="<?= mf_so_esc($find_supplier) ?>"></td>
		</tr>
		<tr>
			<td>UUID документа содержит:</td>
			<td><input type="text" name="find_uuid" size="46" value="<?= mf_so_esc($find_uuid) ?>"></td>
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
