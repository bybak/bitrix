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
	$mfClearFeedRaw = trim((string)($_POST['feed_code'] ?? ''));
	$mfClearFeedArg = $mfClearFeedRaw === '' ? null : $mfClearFeedRaw;
	if (function_exists('mf_ep_clear_external_warehouse'))
	{
		$mfClearWarehouseResult = mf_ep_clear_external_warehouse($mfClearSid, $mfClearFeedArg);
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
			$mfMode = (string)($mfClearWarehouseResult['mode'] ?? '');
			if ($mfMode === 'feed')
			{
				$mfMsg = sprintf(
					'Готово. Прайс %s: снята привязка товаров, остатки на складе обнулены где нужно. Затронуто позиций: %d; цен сброшено (если других прайсов на позицию нет): %d; пересчитано в каталоге: %d.',
					(string)($mfClearWarehouseResult['feed_code'] ?? ''),
					(int)($mfClearWarehouseResult['affected_products'] ?? 0),
					(int)($mfClearWarehouseResult['products_price_cleared'] ?? 0),
					(int)($mfClearWarehouseResult['products_recalc'] ?? 0)
				);
				$mHint = trim((string)($mfClearWarehouseResult['hint'] ?? ''));
				\CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => $mfMsg]);
				if ($mHint !== '')
				{
					echo '<div class="adm-info-message" style="max-width:960px;margin-top:8px;">' . htmlspecialcharsbx($mHint) . '</div>';
				}
			}
			else
			{
				$mfMsg = sprintf(
					'Готово. Удалено записей об остатках: %d. Обновлено позиций в каталоге: %d.',
					(int)($mfClearWarehouseResult['deleted_store_rows'] ?? 0),
					(int)($mfClearWarehouseResult['products_recalc'] ?? 0)
				);
				\CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => $mfMsg]);
			}
		}
		else
		{
			$mfErr = trim((string)($mfClearWarehouseResult['error'] ?? 'Ошибка.'));
			\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => $mfErr !== '' ? $mfErr : 'Операция не выполнена.']);
		}
	}
	?>
	<p class="adm-info-message">
		Остатки на внешнем складе хранятся в каталоге (<code>b_catalog_store_product</code>). Частичная очистка по коду прайса обнуляет остатки для товаров из этого прайса (если позиция не привязана к другим прайсам на том же складе) и снимает привязку строк загрузки.
		Полная очистка удаляет все остатки по складу и все привязки прайсов. Код прайса задаётся при загрузке внешних цен. Операция <strong>необратима</strong>.
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
				<td>Прайсы</td>
				<td>Очистка остатков</td>
			</tr>
			<?php foreach ($mfExtRows as $ex): ?>
				<?php
				$eid = (int)($ex['ID'] ?? 0);
				$etitle = trim((string)($ex['TITLE'] ?? ''));
				$mfFeedCodes = [];
				if (function_exists('mf_esf_list_feed_codes_for_store'))
				{
					$mfFeedCodes = mf_esf_list_feed_codes_for_store($eid);
				}
				?>
				<tr>
					<td><?= (int)$eid ?></td>
					<td><?= htmlspecialcharsbx($etitle !== '' ? $etitle : '—') ?></td>
					<td><code><?= htmlspecialcharsbx(trim((string)($ex['XML_ID'] ?? '')) !== '' ? trim((string)($ex['XML_ID'] ?? '')) : '—') ?></code></td>
					<td style="max-width:280px;font-size:12px;line-height:1.4;color:#333;">
						<?php if ($mfFeedCodes === []): ?>
							<span style="color:#888">Пока нет — загрузите внешний прайс CSV, указав код прайса в форме импорта.</span>
						<?php else: ?>
							<?= htmlspecialcharsbx(implode(', ', $mfFeedCodes)) ?>
						<?php endif; ?>
					</td>
					<td>
						<form
							method="post"
							action="<?= htmlspecialcharsbx($curPage) ?>?lang=<?= htmlspecialcharsbx($langForm) ?>"
							style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;max-width:480px;"
							onsubmit="var s=this.querySelector('[name=feed_code]');var v=s&amp;&amp;s.value?s.value:'';var q=v?('Снять остатки только с прайса «'+v+'» на этом складе? Действие нельзя отменить.'):('Снять все остатки с этого внешнего склада (все прайсы)? Действие нельзя отменить.'); return confirm(q);"
						>
							<?= bitrix_sessid_post() ?>
							<input type="hidden" name="mf_clear_external_warehouse" value="Y" />
							<input type="hidden" name="store_id" value="<?= (int)$eid ?>" />
							<select name="feed_code" style="min-width:220px;max-width:100%;">
								<option value="">Весь склад (все прайсы)</option>
								<?php foreach ($mfFeedCodes as $fc): ?>
									<option value="<?= htmlspecialcharsbx((string)$fc) ?>"><?= htmlspecialcharsbx((string)$fc) ?></option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="adm-btn adm-btn-delete">Очистить</button>
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
