<?php

declare(strict_types=1);

use Bitrix\Main\Loader;

if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
{
	die('Admin only');
}

global $APPLICATION;

if (!Loader::includeModule('iblock'))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Модуль iblock не подключён.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

$analogsLib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_analogs.php';
if (!is_file($analogsLib))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не найден файл: ' . $analogsLib]);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}
require_once $analogsLib;

$iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? 0);
$elementId = (int)($_REQUEST['ID'] ?? 0);
$backUrl = (string)($_REQUEST['back_url'] ?? '');

if ($iblockId <= 0 || $elementId <= 0)
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не указан IBLOCK_ID или ID элемента.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

$perm = \CIBlock::GetPermission($iblockId);
if ($perm < 'W')
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Недостаточно прав.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

// Handle delete
if (
	($_REQUEST['action'] ?? '') === 'delete'
	&& check_bitrix_sessid()
	&& isset($_REQUEST['analog_id'])
)
{
	$analogId = (int)$_REQUEST['analog_id'];
	mf_analogs_delete_link($elementId, $analogId);
	LocalRedirect($APPLICATION->GetCurPageParam('', ['action', 'analog_id', 'sessid']));
}

$el = \CIBlockElement::GetList([], ['=ID' => $elementId, '=IBLOCK_ID' => $iblockId], false, false, ['ID', 'NAME', 'CODE'])->Fetch();
$elementName = (string)($el['NAME'] ?? '');

function mf_analogs_escape(string $s): string
{
	return function_exists('htmlspecialcharsbx')
		? (string)htmlspecialcharsbx($s)
		: htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$APPLICATION->SetTitle('Список аналогов');

$aContext = [];
if ($backUrl !== '')
{
	$aContext[] = [
		'TEXT' => 'Назад к товару',
		'LINK' => $backUrl,
		'TITLE' => 'Вернуться на товар',
		'ICON' => 'btn_list',
	];
}
(new \CAdminContextMenu($aContext))->Show();

$analogIds = function_exists('mf_analogs_ids_for_product') ? mf_analogs_ids_for_product($elementId, 200) : [];
$analogs = [];
if (!empty($analogIds))
{
	$rs = \CIBlockElement::GetList(
		['NAME' => 'ASC', 'ID' => 'ASC'],
		['=IBLOCK_ID' => $iblockId, '=ID' => $analogIds],
		false,
		false,
		['ID', 'NAME', 'CODE', 'PROPERTY_CML2_ARTICLE', 'PROPERTY_MF_BRAND']
	);
	while ($r = $rs->Fetch())
	{
		$id = (int)($r['ID'] ?? 0);
		if ($id > 0)
		{
			$analogs[$id] = $r;
		}
	}
}

?>
<div style="max-width: 980px;">
	<h2 style="margin: 8px 0 12px 0;">Товар: <?= mf_analogs_escape($elementName) ?> (ID <?= (int)$elementId ?>)</h2>

	<?php if (empty($analogIds)): ?>
		<div style="padding:10px;background:#fff3cd;border:1px solid #ffeeba;">
			Аналоги не заданы.
		</div>
	<?php else: ?>
		<table class="adm-list-table" style="width:100%;margin-top:10px;">
			<thead>
			<tr class="adm-list-table-header">
				<td class="adm-list-table-cell">ID</td>
				<td class="adm-list-table-cell">Название</td>
				<td class="adm-list-table-cell">Бренд</td>
				<td class="adm-list-table-cell">Артикул</td>
				<td class="adm-list-table-cell" style="width:1%;">Действия</td>
			</tr>
			</thead>
			<tbody>
			<?php foreach ($analogIds as $aid): ?>
				<?php
				$row = $analogs[$aid] ?? null;
				$name = is_array($row) ? (string)($row['NAME'] ?? '') : ('Товар #' . (int)$aid);
				$code = is_array($row) ? (string)($row['CODE'] ?? '') : '';
				$brand = is_array($row) ? (string)($row['PROPERTY_MF_BRAND_VALUE'] ?? '') : '';
				$article = is_array($row) ? (string)($row['PROPERTY_CML2_ARTICLE_VALUE'] ?? '') : '';
				$edit = 'iblock_element_edit.php?lang=' . urlencode((string)LANGUAGE_ID) . '&IBLOCK_ID=' . $iblockId . '&type=catalog&ID=' . (int)$aid . '&WF=Y';
				$del = $APPLICATION->GetCurPageParam('action=delete&analog_id=' . (int)$aid . '&sessid=' . bitrix_sessid(), ['action', 'analog_id', 'sessid']);
				?>
				<tr class="adm-list-table-row">
					<td class="adm-list-table-cell"><?= (int)$aid ?></td>
					<td class="adm-list-table-cell">
						<a href="<?= mf_analogs_escape($edit) ?>"><?= mf_analogs_escape($name) ?></a>
						<?php if ($code !== ''): ?>
							<div style="color:#666;font-size:12px;">CODE: <?= mf_analogs_escape($code) ?></div>
						<?php endif; ?>
					</td>
					<td class="adm-list-table-cell"><?= mf_analogs_escape($brand) ?></td>
					<td class="adm-list-table-cell"><?= mf_analogs_escape($article) ?></td>
					<td class="adm-list-table-cell">
						<a href="<?= mf_analogs_escape($del) ?>" onclick="return confirm('Удалить связь аналога?')">Удалить</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

