<?php

declare(strict_types=1);

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

\Bitrix\Main\Loader::includeModule('catalog');
\Bitrix\Main\Loader::includeModule('iblock');

$mfClearWarehouseResult = null;
if (
	$_SERVER['REQUEST_METHOD'] === 'POST'
	&& (string)($_POST['mf_clear_external_warehouse'] ?? '') === 'Y'
	&& check_bitrix_sessid()
) {
	@set_time_limit(0);
	$mfClearSid = (int)($_POST['store_id'] ?? 0);
	if (function_exists('mf_ep_clear_external_warehouse'))
	{
		$mfClearWarehouseResult = mf_ep_clear_external_warehouse($mfClearSid);
	}
	else
	{
		$mfClearWarehouseResult = [
			'ok' => false,
			'error' => 'Не найдена функция mf_ep_clear_external_warehouse (mf_external_price_lib).',
			'deleted_store_rows' => 0,
			'products_price_cleared' => 0,
			'products_recalc' => 0,
		];
	}
}

$APPLICATION->SetTitle('Очистка внешних складов');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

?>
<div class="adm-detail-content-wrap" style="padding:12px;">
	<?php
	if (is_array($mfClearWarehouseResult))
	{
		if (!empty($mfClearWarehouseResult['ok']))
		{
			$mfMsg = sprintf(
				'Готово. Удалено записей об остатках: %d. Обновлено позиций в каталоге: %d.',
				(int)($mfClearWarehouseResult['deleted_store_rows'] ?? 0),
				(int)($mfClearWarehouseResult['products_recalc'] ?? 0)
			);
			\CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => $mfMsg]);
		}
		else
		{
			$mfErr = trim((string)($mfClearWarehouseResult['error'] ?? 'Ошибка.'));
			\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => $mfErr !== '' ? $mfErr : 'Операция не выполнена.']);
		}
	}
	?>
	<p class="adm-info-message">
		Для выбранного <strong>внешнего</strong> склада удаляются все учтённые на нём остатки; каталог обновляется для затронутых позиций.
		В списке только внешние склады. Операция <strong>необратима</strong> — при необходимости сделайте резервную копию.
	</p>
	<?php
	$mfExtRows = [];
	$rsExt = \CCatalogStore::GetList(['ID' => 'ASC'], ['ACTIVE' => 'Y'], false, false, ['ID', 'TITLE', 'XML_ID', 'CODE']);
	while ($ex = $rsExt->Fetch())
	{
		$eid = (int)($ex['ID'] ?? 0);
		if ($eid <= 0)
		{
			continue;
		}
		if (function_exists('mf_ep_store_is_external_warehouse') && mf_ep_store_is_external_warehouse($eid))
		{
			$mfExtRows[] = $ex;
		}
	}
	if ($mfExtRows === [])
	{
		?>
		<p style="color:#666">Внешних складов не найдено (не настроен признак «Внешний склад» у склада).</p>
		<?php
	}
	else
	{
		$langForm = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
		$curPage = (string)$APPLICATION->GetCurPage();
		?>
		<table class="internal" style="width:100%; max-width:960px; border-collapse:collapse;">
			<tr class="heading">
				<td>ID</td>
				<td>Название</td>
				<td>XML_ID</td>
				<td></td>
			</tr>
			<?php foreach ($mfExtRows as $ex): ?>
				<?php
				$eid = (int)($ex['ID'] ?? 0);
				$etitle = trim((string)($ex['TITLE'] ?? ''));
				?>
				<tr>
					<td><?= (int)$eid ?></td>
					<td><?= htmlspecialcharsbx($etitle !== '' ? $etitle : '—') ?></td>
					<td><code><?= htmlspecialcharsbx(trim((string)($ex['XML_ID'] ?? '')) !== '' ? trim((string)($ex['XML_ID'] ?? '')) : '—') ?></code></td>
					<td>
						<form
							method="post"
							action="<?= htmlspecialcharsbx($curPage) ?>?lang=<?= htmlspecialcharsbx($langForm) ?>"
							style="display:inline"
							onsubmit="return confirm('Снять все остатки с этого внешнего склада? Действие нельзя отменить.');"
						>
							<?= bitrix_sessid_post() ?>
							<input type="hidden" name="mf_clear_external_warehouse" value="Y" />
							<input type="hidden" name="store_id" value="<?= (int)$eid ?>" />
							<button type="submit" class="adm-btn adm-btn-delete">Очистить склад</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}
	?>
</div>
<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
