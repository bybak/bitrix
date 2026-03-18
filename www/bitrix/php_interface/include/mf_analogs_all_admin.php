<?php

declare(strict_types=1);

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

$analogsLib = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_analogs.php';
if (!is_file($analogsLib))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не найден файл: ' . $analogsLib]);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}
require_once $analogsLib;

if (!class_exists(Application::class))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Bitrix\\Main\\Application недоступен.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

$iblockId = 4;

function mf_aa_escape(string $s): string
{
	return function_exists('htmlspecialcharsbx')
		? (string)htmlspecialcharsbx($s)
		: htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mf_aa_find_element_id_by_xml(int $iblockId, string $xmlId): int
{
	$xmlId = trim($xmlId);
	if ($xmlId === '') return 0;
	if (!class_exists(Application::class)) return 0;
	try
	{
		$conn = Application::getConnection();
		$h = $conn->getSqlHelper();
		$xmlSql = "'" . $h->forSql($xmlId) . "'";
		$r = $conn->query("
			SELECT ID
			FROM b_iblock_element
			WHERE IBLOCK_ID=" . (int)$iblockId . " AND XML_ID=" . $xmlSql . "
			ORDER BY ID ASC
			LIMIT 1
		")->fetch();
		return (int)($r['ID'] ?? 0);
	}
	catch (\Throwable $e)
	{
		return 0;
	}
}

$sTableID = 'tbl_mf_analogs_all';
$oSort = new \CAdminSorting($sTableID, 'ID', 'desc');
$lAdmin = new \CAdminList($sTableID, $oSort);

// Filters
$filterFields = [
	'find_pid',
	'find_pxml',
	'find_source',
];
$lAdmin->InitFilter($filterFields);

$find_pid = trim((string)($find_pid ?? ''));
$find_pxml = trim((string)($find_pxml ?? ''));
$find_source = trim((string)($find_source ?? ''));

if ($find_pid === '' && array_key_exists('find_pid', $_GET))
{
	$find_pid = trim((string)$_GET['find_pid']);
}
if ($find_pxml === '' && array_key_exists('find_pxml', $_GET))
{
	$find_pxml = trim((string)$_GET['find_pxml']);
}
if ($find_source === '' && array_key_exists('find_source', $_GET))
{
	$find_source = trim((string)$_GET['find_source']);
}

$conn = Application::getConnection();
$h = $conn->getSqlHelper();

// Actions
$adminNotice = null;
if (($arID = $lAdmin->GroupAction()) !== false)
{
	if ($lAdmin->IsGroupActionToAll())
	{
		$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Действие "ко всем" не поддержано. Выдели нужные строки.'];
	}
	else
	{
		$ids = array_values(array_filter(array_map('intval', (array)$arID), static fn($v) => $v > 0));
		$action = (string)$lAdmin->GetAction();
		if (empty($ids))
		{
			$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Не выбраны строки.'];
		}
		elseif ($action === 'delete')
		{
			$deleted = 0;
			foreach ($ids as $id)
			{
				$r = $conn->query("SELECT UF_P1_ID, UF_P2_ID FROM mf_product_analogs WHERE ID=" . (int)$id)->fetch();
				if (!$r) continue;
				$p1 = (int)($r['UF_P1_ID'] ?? 0);
				$p2 = (int)($r['UF_P2_ID'] ?? 0);
				if ($p1 > 0 && $p2 > 0 && function_exists('mf_analogs_delete_link'))
				{
					mf_analogs_delete_link($p1, $p2);
					$deleted++;
				}
			}
			$adminNotice = ['TYPE' => 'OK', 'MESSAGE' => 'Удалено связей: ' . $deleted];
		}
	}
}

// Resolve filter product id
$pid = (int)$find_pid;
if ($pid <= 0 && $find_pxml !== '')
{
	$pid = mf_aa_find_element_id_by_xml($iblockId, $find_pxml);
}

$where = [];
if ($pid > 0)
{
	$where[] = "(a.UF_P1_ID=" . $pid . " OR a.UF_P2_ID=" . $pid . ")";
}
if ($find_source !== '')
{
	$where[] = "a.UF_SOURCE='" . $h->forSql($find_source) . "'";
}
$whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

$by = strtoupper((string)$oSort->getField());
$order = strtoupper((string)$oSort->getOrder());
$allowedSort = ['ID', 'UF_P1_ID', 'UF_P2_ID', 'UF_SOURCE', 'UF_CREATED_AT'];
if (!in_array($by, $allowedSort, true)) $by = 'ID';
if (!in_array($order, ['ASC', 'DESC'], true)) $order = 'DESC';

$limit = 500;
$sql = "
	SELECT
		a.ID,
		a.UF_P1_ID,
		a.UF_P2_ID,
		a.UF_SOURCE,
		a.UF_CREATED_AT,
		e1.NAME AS P1_NAME,
		e1.XML_ID AS P1_XML_ID,
		e1.CODE AS P1_CODE,
		e2.NAME AS P2_NAME,
		e2.XML_ID AS P2_XML_ID,
		e2.CODE AS P2_CODE
	FROM mf_product_analogs a
	LEFT JOIN b_iblock_element e1 ON e1.ID = a.UF_P1_ID
	LEFT JOIN b_iblock_element e2 ON e2.ID = a.UF_P2_ID
	{$whereSql}
	ORDER BY {$by} {$order}, a.ID DESC
	LIMIT {$limit}
";

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
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Ошибка чтения: ' . mf_aa_escape($e->getMessage())]);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

$lAdmin->AddHeaders([
	['id' => 'ID', 'content' => 'ID', 'default' => true, 'sort' => 'ID'],
	['id' => 'UF_P1_ID', 'content' => 'Товар 1', 'default' => true, 'sort' => 'UF_P1_ID'],
	['id' => 'UF_P2_ID', 'content' => 'Товар 2', 'default' => true, 'sort' => 'UF_P2_ID'],
	['id' => 'UF_SOURCE', 'content' => 'Источник', 'default' => true, 'sort' => 'UF_SOURCE'],
	['id' => 'UF_CREATED_AT', 'content' => 'Создано', 'default' => true, 'sort' => 'UF_CREATED_AT'],
]);

foreach ($rows as $r)
{
	$id = (int)($r['ID'] ?? 0);
	if ($id <= 0) continue;

	$row = &$lAdmin->AddRow((string)$id, $r);

	$edit1 = 'iblock_element_edit.php?lang=' . urlencode((string)LANGUAGE_ID) . '&IBLOCK_ID=' . $iblockId . '&type=catalog&ID=' . (int)($r['UF_P1_ID'] ?? 0) . '&WF=Y';
	$edit2 = 'iblock_element_edit.php?lang=' . urlencode((string)LANGUAGE_ID) . '&IBLOCK_ID=' . $iblockId . '&type=catalog&ID=' . (int)($r['UF_P2_ID'] ?? 0) . '&WF=Y';

	$p1Name = (string)($r['P1_NAME'] ?? '');
	$p2Name = (string)($r['P2_NAME'] ?? '');

	$p1 = (int)($r['UF_P1_ID'] ?? 0);
	$p2 = (int)($r['UF_P2_ID'] ?? 0);

	$row->AddViewField('UF_P1_ID',
		($p1 > 0 ? '<a href="' . mf_aa_escape($edit1) . '">' . (int)$p1 . '</a>' : '—')
		. ($p1Name !== '' ? '<div style="color:#666;font-size:12px;">' . mf_aa_escape($p1Name) . '</div>' : '')
		. (!empty($r['P1_XML_ID']) ? '<div style="color:#999;font-size:11px;">XML_ID: ' . mf_aa_escape((string)$r['P1_XML_ID']) . '</div>' : '')
	);
	$row->AddViewField('UF_P2_ID',
		($p2 > 0 ? '<a href="' . mf_aa_escape($edit2) . '">' . (int)$p2 . '</a>' : '—')
		. ($p2Name !== '' ? '<div style="color:#666;font-size:12px;">' . mf_aa_escape($p2Name) . '</div>' : '')
		. (!empty($r['P2_XML_ID']) ? '<div style="color:#999;font-size:11px;">XML_ID: ' . mf_aa_escape((string)$r['P2_XML_ID']) . '</div>' : '')
	);

	$actions = [
		[
			'ICON' => 'delete',
			'TEXT' => 'Удалить',
			'ACTION' => "if(confirm('Удалить связь?')) " . $lAdmin->ActionDoGroup($id, 'delete'),
		],
	];
	$row->AddActions($actions);
}

$lAdmin->AddGroupActionTable([
	'delete' => 'Удалить',
]);

$filter = new \CAdminFilter($sTableID . '_filter', [
	'ID товара (Bitrix)',
	'XML_ID товара (из CSV)',
	'Источник',
]);

$lAdmin->CheckListMode();

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$APPLICATION->SetTitle('Все аналоги');

?>
<div style="max-width: 1200px;">
	<?php if (is_array($adminNotice)): ?>
		<?php \CAdminMessage::ShowMessage($adminNotice); ?>
	<?php endif; ?>

	<div style="margin: 8px 0 12px 0; color:#666;">
		Показываем последние <?= (int)$limit ?> связей (для скорости). Используй фильтры, чтобы сузить выборку.
	</div>

	<form name="find_form" method="get" action="<?= mf_aa_escape($APPLICATION->GetCurPage()) ?>">
		<input type="hidden" name="lang" value="<?= mf_aa_escape((string)LANGUAGE_ID) ?>">
		<?php $filter->Begin(); ?>
		<tr>
			<td>ID товара:</td>
			<td><input type="text" name="find_pid" size="20" value="<?= mf_aa_escape($find_pid) ?>"></td>
		</tr>
		<tr>
			<td>XML_ID товара:</td>
			<td><input type="text" name="find_pxml" size="30" value="<?= mf_aa_escape($find_pxml) ?>"></td>
		</tr>
		<tr>
			<td>Источник:</td>
			<td><input type="text" name="find_source" size="30" value="<?= mf_aa_escape($find_source) ?>" placeholder="catalog_bitrix / admin / ..."></td>
		</tr>
		<?php
		$filter->Buttons(['table_id' => $sTableID, 'url' => $APPLICATION->GetCurPage(), 'form' => 'find_form']);
		$filter->End();
		?>
	</form>

	<?php $lAdmin->DisplayList(); ?>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

