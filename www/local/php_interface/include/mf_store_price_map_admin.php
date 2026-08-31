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

$langForm = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
$curPage = (string)$APPLICATION->GetCurPage();

/**
 * @param array{feed_code: string, product_count: int} $feedRow
 */
function mf_spm_format_feed_label(array $feedRow, bool $forSelect = false): string
{
	$code = trim((string)($feedRow['feed_code'] ?? ''));
	$cnt = (int)($feedRow['product_count'] ?? 0);
	if ($code === '')
	{
		return '—';
	}
	$n = number_format($cnt, 0, '', ' ');
	if ($forSelect)
	{
		return $code . ' — ' . $n . ' ' . mf_spm_plural_products($cnt);
	}

	return $code . ' (' . $n . ' ' . mf_spm_plural_products($cnt) . ')';
}

function mf_spm_plural_products(int $n): string
{
	$n = abs($n) % 100;
	$n1 = $n % 10;
	if ($n > 10 && $n < 20)
	{
		return 'товаров';
	}
	if ($n1 > 1 && $n1 < 5)
	{
		return 'товара';
	}
	if ($n1 === 1)
	{
		return 'товар';
	}

	return 'товаров';
}

/**
 * @param list<array{feed_code: string, product_count: int}> $feeds
 */
function mf_spm_render_feeds_list_html(array $feeds): string
{
	if ($feeds === [])
	{
		return '<span style="color:#888">Пока нет — загрузите внешний прайс CSV, указав код прайса в форме импорта.</span>';
	}
	$parts = [];
	foreach ($feeds as $row)
	{
		$parts[] = htmlspecialcharsbx(mf_spm_format_feed_label($row));
	}

	return implode('<br>', $parts);
}

/**
 * @param array<string, mixed> $payload
 */
function mf_spm_json(array $payload): void
{
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

if (
	$_SERVER['REQUEST_METHOD'] === 'POST'
	&& check_bitrix_sessid()
	&& (string)($_POST['mf_clear_ajax'] ?? '') === 'Y'
)
{
	@set_time_limit(0);
	$action = (string)($_POST['action'] ?? '');
	$storeId = (int)($_POST['store_id'] ?? 0);
	$feedRaw = trim((string)($_POST['feed_code'] ?? ''));
	$feedArg = $feedRaw === '' ? null : $feedRaw;

	if (!function_exists('mf_ep_clear_job_start') || !function_exists('mf_ep_clear_job_run_step'))
	{
		mf_spm_json(['ok' => false, 'error' => 'Не подключён mf_ep_clear_warehouse_job.php']);
		die();
	}

	if ($action === 'start')
	{
		mf_spm_json(mf_ep_clear_job_start($storeId, $feedArg));
		die();
	}

	if ($action === 'step')
	{
		$token = preg_replace('~[^a-f0-9]~', '', strtolower((string)($_POST['token'] ?? '')));
		$batch = max(5, min(100, (int)($_POST['batch'] ?? 30)));
		mf_spm_json(mf_ep_clear_job_run_step($token, $batch));
		die();
	}

	mf_spm_json(['ok' => false, 'error' => 'Неизвестное действие.']);
	die();
}

$mfClearWarehouseResult = null;
if (
	$_SERVER['REQUEST_METHOD'] === 'POST'
	&& (string)($_POST['mf_clear_external_warehouse'] ?? '') === 'Y'
	&& check_bitrix_sessid()
	&& (string)($_POST['mf_clear_ajax'] ?? '') !== 'Y'
)
{
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
			'error' => 'Не найдена функция mf_ep_clear_external_warehouse.',
			'deleted_store_rows' => 0,
			'products_price_cleared' => 0,
			'products_recalc' => 0,
		];
	}
}

$APPLICATION->SetTitle('Очистка внешних складов');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

?>
<style>
.adm-detail-content-wrap .js-mf-clear-warehouse-form {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	max-width: 480px;
}
.adm-detail-content-wrap .js-mf-clear-warehouse-form .js-mf-clear-feed {
	min-width: 220px;
	max-width: 100%;
	flex: 1 1 220px;
}
.adm-detail-content-wrap .js-mf-clear-warehouse-form .js-mf-clear-submit {
	flex-shrink: 0;
	box-sizing: border-box;
	cursor: pointer;
}
.adm-detail-content-wrap .js-mf-clear-warehouse-form .js-mf-clear-submit:active {
	padding: 1px 13px 3px !important;
	height: 29px !important;
	margin: 2px !important;
	transform: none !important;
}
</style>
<div class="adm-detail-content-wrap" style="padding:12px;">
	<div id="mf-clear-progress-global" style="display:none;max-width:960px;margin:0 0 16px;padding:14px;border:1px solid #dce1e5;background:#fafbfc;">
		<div style="font-weight:600;margin-bottom:8px;">Очистка склада…</div>
		<div style="height:22px;background:#e4e8eb;border-radius:3px;overflow:hidden;">
			<div id="mf-clear-progress-bar" style="height:100%;width:0;background:#7cb342;transition:width .25s ease;"></div>
		</div>
		<div id="mf-clear-progress-text" style="margin-top:8px;font-size:13px;color:#333;">Подготовка…</div>
		<div id="mf-clear-progress-eta" style="margin-top:4px;font-size:12px;color:#666;"></div>
	</div>
	<div id="mf-clear-result-slot"></div>
	<?php
	if (is_array($mfClearWarehouseResult))
	{
		mf_spm_render_clear_result($mfClearWarehouseResult);
	}
	?>
	<p class="adm-info-message">
		Остатки на внешнем складе хранятся в каталоге (<code>b_catalog_store_product</code>). 		Частичная очистка по коду прайса обнуляет остатки для товаров из этого прайса (если позиция не привязана к другим прайсам на том же складе), снимает привязку строк загрузки и убирает код прайса из списка.
		Полная очистка удаляет все остатки по складу и все привязки прайсов. В списке показано число привязанных товаров по каждому коду. Код прайса задаётся при загрузке внешних цен. Операция <strong>необратима</strong>.
		При большом числе позиций показывается прогресс и примерное время до завершения.
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
				$mfFeedRows = [];
				if (function_exists('mf_esf_list_feeds_for_store'))
				{
					$mfFeedRows = mf_esf_list_feeds_for_store($eid);
				}
				?>
				<tr>
					<td><?= (int)$eid ?></td>
					<td><?= htmlspecialcharsbx($etitle !== '' ? $etitle : '—') ?></td>
					<td><code><?= htmlspecialcharsbx(trim((string)($ex['XML_ID'] ?? '')) !== '' ? trim((string)($ex['XML_ID'] ?? '')) : '—') ?></code></td>
					<td class="js-mf-clear-feeds-list" style="max-width:280px;font-size:12px;line-height:1.4;color:#333;">
						<?= mf_spm_render_feeds_list_html($mfFeedRows) ?>
					</td>
					<td>
						<form
							method="post"
							action="<?= htmlspecialcharsbx($curPage) ?>?lang=<?= htmlspecialcharsbx($langForm) ?>"
							class="js-mf-clear-warehouse-form"
							data-store-id="<?= (int)$eid ?>"
						>
							<?= bitrix_sessid_post() ?>
							<input type="hidden" name="mf_clear_external_warehouse" value="Y" />
							<input type="hidden" name="store_id" value="<?= (int)$eid ?>" />
							<select name="feed_code" class="js-mf-clear-feed">
								<option value="">Весь склад (все прайсы)</option>
								<?php foreach ($mfFeedRows as $feedRow): ?>
									<?php $fc = trim((string)($feedRow['feed_code'] ?? '')); ?>
									<?php if ($fc === '') { continue; } ?>
									<option
										value="<?= htmlspecialcharsbx($fc) ?>"
										data-product-count="<?= (int)($feedRow['product_count'] ?? 0) ?>"
									><?= htmlspecialcharsbx(mf_spm_format_feed_label($feedRow, true)) ?></option>
								<?php endforeach; ?>
							</select>
							<input type="submit" class="adm-btn adm-btn-delete js-mf-clear-submit" value="Очистить" />
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}
	?>
</div>
<script>
(function () {
	var pageUrl = <?= json_encode($curPage . '?lang=' . $langForm, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
	var sessid = <?= json_encode(bitrix_sessid(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
	var box = document.getElementById('mf-clear-progress-global');
	var bar = document.getElementById('mf-clear-progress-bar');
	var txt = document.getElementById('mf-clear-progress-text');
	var eta = document.getElementById('mf-clear-progress-eta');
	var slot = document.getElementById('mf-clear-result-slot');

	function fmtEta(sec) {
		sec = parseInt(sec, 10);
		if (!sec || sec < 0) return '';
		if (sec < 60) return 'Осталось примерно ' + sec + ' сек.';
		var m = Math.floor(sec / 60);
		var s = sec % 60;
		return 'Осталось примерно ' + m + ' мин ' + s + ' сек.';
	}

	function showProgress(p) {
		if (!box) return;
		box.style.display = 'block';
		var pct = p && typeof p.pct === 'number' ? p.pct : 0;
		if (bar) bar.style.width = Math.max(0, Math.min(100, pct)) + '%';
		if (txt) txt.textContent = (p && p.note) ? p.note : ('Выполнено ' + pct + '%');
		if (eta) {
			eta.textContent = (p && p.eta_sec != null) ? fmtEta(p.eta_sec) : '';
		}
	}

	function hideProgress() {
		if (box) box.style.display = 'none';
	}

	function postForm(data) {
		var body = new URLSearchParams();
		body.set('mf_clear_ajax', 'Y');
		body.set('sessid', sessid);
		Object.keys(data).forEach(function (k) {
			if (data[k] !== undefined && data[k] !== null) body.set(k, String(data[k]));
		});
		return fetch(pageUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); });
	}

	function pluralProducts(n) {
		n = Math.abs(parseInt(n, 10) || 0) % 100;
		var n1 = n % 10;
		if (n > 10 && n < 20) return 'товаров';
		if (n1 > 1 && n1 < 5) return 'товара';
		if (n1 === 1) return 'товар';
		return 'товаров';
	}

	function emptyFeedsListHtml() {
		return '<span style="color:#888">Пока нет — загрузите внешний прайс CSV, указав код прайса в форме импорта.</span>';
	}

	function rebuildFeedsCellFromSelect(sel, feedsCell) {
		if (!feedsCell || !sel) return;
		var parts = [];
		Array.prototype.forEach.call(sel.options, function (opt) {
			if (!opt.value) return;
			var cnt = parseInt(opt.getAttribute('data-product-count') || '0', 10);
			parts.push(opt.value + ' (' + cnt + ' ' + pluralProducts(cnt) + ')');
		});
		feedsCell.innerHTML = parts.length ? parts.join('<br>') : emptyFeedsListHtml();
	}

	function updateFeedsListAfterClear(storeId, res, clearedFeedCode) {
		if (!res || !res.ok) return;
		document.querySelectorAll('.js-mf-clear-warehouse-form').forEach(function (form) {
			if (parseInt(form.getAttribute('data-store-id') || '0', 10) !== storeId) return;
			var sel = form.querySelector('.js-mf-clear-feed');
			var feedsCell = form.closest('tr').querySelector('.js-mf-clear-feeds-list');
			if (!sel) return;

			if (res.mode === 'feed' && clearedFeedCode) {
				Array.prototype.forEach.call(sel.options, function (opt) {
					if (opt.value === clearedFeedCode) opt.remove();
				});
				rebuildFeedsCellFromSelect(sel, feedsCell);
				return;
			}

			if (res.mode !== 'feed') {
				Array.prototype.forEach.call(sel.options, function (opt) {
					if (opt.value) opt.remove();
				});
				if (feedsCell) feedsCell.innerHTML = emptyFeedsListHtml();
			}
		});
	}

	function renderResultHtml(res, storeId, clearedFeedCode) {
		if (!slot || !res) return;
		var html = '';
		if (res.ok) {
			if (res.mode === 'feed') {
				var affected = parseInt(res.affected_products || 0, 10);
				if (affected > 0) {
					html = '<div class="adm-info-message" style="max-width:960px;">Готово. Прайс <strong>' + (res.feed_code || '') + '</strong>: затронуто позиций '
						+ affected + '; цен сброшено: ' + (res.products_price_cleared || 0)
						+ '; пересчитано: ' + (res.products_recalc || 0) + '. Код прайса удалён из списка.</div>';
				} else {
					html = '<div class="adm-info-message" style="max-width:960px;">Готово. Прайс <strong>' + (res.feed_code || '')
						+ '</strong> удалён из списка. Привязанных товаров не было.</div>';
				}
				if (res.hint) {
					html += '<div class="adm-info-message" style="max-width:960px;margin-top:8px;">' + res.hint + '</div>';
				}
			} else {
				html = '<div class="adm-info-message" style="max-width:960px;">Готово. Удалено записей об остатках: '
					+ (res.deleted_store_rows || 0) + '. Обновлено позиций: ' + (res.products_recalc || 0)
					+ '. Все коды прайсов удалены из списка.</div>';
			}
			updateFeedsListAfterClear(storeId, res, clearedFeedCode);
		} else {
			html = '<div class="adm-info-message-wrap adm-info-message-red" style="max-width:960px;"><div class="adm-info-message">'
				+ (res.error || 'Ошибка') + '</div></div>';
		}
		slot.innerHTML = html;
	}

	function runClear(storeId, feedCode, btn) {
		if (btn) { btn.disabled = true; }
		showProgress({ pct: 0, note: 'Подготовка списка позиций…', eta_sec: null });
		if (slot) slot.innerHTML = '';

		return postForm({ action: 'start', store_id: storeId, feed_code: feedCode })
			.then(function (start) {
				if (!start || !start.ok) {
					throw new Error((start && start.error) ? start.error : 'Не удалось начать очистку');
				}
				if (start.done && start.result) {
					showProgress({ pct: 100, note: 'Готово', done: true });
					renderResultHtml(start.result, storeId, feedCode);
					return;
				}
				var token = start.token || '';
				var total = start.total || 0;
				if (start.hint && txt) {
					txt.textContent = start.hint + ' — обработка ' + total + ' поз.';
				}

				function stepLoop() {
					return postForm({ action: 'step', token: token, batch: 30 })
						.then(function (st) {
							if (!st || !st.ok) {
								throw new Error((st && st.error) ? st.error : 'Ошибка шага');
							}
							var p = st.progress || {};
							showProgress(p);
							if (st.done && st.result) {
								renderResultHtml(st.result, storeId, feedCode);
								return;
							}
							return stepLoop();
						});
				}
				return stepLoop();
			})
			.catch(function (err) {
				renderResultHtml({ ok: false, error: err && err.message ? err.message : String(err) }, storeId, feedCode);
			})
			.finally(function () {
				hideProgress();
				if (btn) { btn.disabled = false; }
			});
	}

	document.querySelectorAll('.js-mf-clear-warehouse-form').forEach(function (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var storeId = parseInt(form.getAttribute('data-store-id') || '0', 10);
			var sel = form.querySelector('.js-mf-clear-feed');
			var feed = sel && sel.value ? sel.value : '';
			var q = feed
				? ('Снять остатки только с прайса «' + feed + '» на этом складе? Действие нельзя отменить.')
				: ('Снять все остатки с этого внешнего склада (все прайсы)? Действие нельзя отменить.');
			if (!confirm(q)) return;
			var btn = form.querySelector('.js-mf-clear-submit');
			runClear(storeId, feed, btn);
		});
	});
})();
</script>
<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

/**
 * @param array<string, mixed> $mfClearWarehouseResult
 */
function mf_spm_render_clear_result(array $mfClearWarehouseResult): void
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
