<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}
IncludeTemplateLangFile(__FILE__);
/** @var array $arParams */
/** @var array $arResult */
/** @global CMain $APPLICATION */
/** @var CBitrixComponent $component */

$queryValue = (string)($arResult['REQUEST']['QUERY'] ?? '');
$queryValueAttr = htmlspecialcharsbx($queryValue);
$how = (($arResult['REQUEST']['HOW'] ?? '') === 'd') ? 'd' : 'r';

// Motor-Force search cards helpers (catalog-only).
$mfCatalogIblockId = 4;
$mfPlaceholder = (function_exists('mf_mf_placeholder_img_url') ? mf_mf_placeholder_img_url() : '/bitrix/templates/eshop_bootstrap_v4/images/mf-no-photo.svg');

$mfMoney = static function (?float $price): string {
	$p = (float)($price ?? 0);
	if ($p <= 0) return '';
	// IMPORTANT: return plain text, no HTML entities (so it can be safely escaped).
	return number_format($p, 0, '.', ' ') . ' ₽';
};

$mfTrimText = static function (string $html, int $limit = 160): string {
	$s = trim((string)strip_tags($html));
	if ($s === '') return '';
	if (mb_strlen($s) <= $limit) return $s;
	return rtrim(mb_substr($s, 0, $limit), " \t\n\r\0\x0B.,;:") . '…';
};

$mfStoresForProduct = static function (int $productId) use ($mfMoney): array {
	$out = [];
	$productId = (int)$productId;
	if ($productId <= 0) return $out;
	if (!class_exists('CCatalogStoreProduct') || !class_exists('CCatalogStore'))
	{
		return $out;
	}
	try { \Bitrix\Main\Loader::includeModule('catalog'); } catch (\Throwable $e) {}

	$rs = \CCatalogStoreProduct::GetList(
		['STORE_ID' => 'ASC'],
		['PRODUCT_ID' => $productId, '>AMOUNT' => 0],
		false,
		false,
		['STORE_ID', 'AMOUNT']
	);
	while ($r = $rs->Fetch())
	{
		$storeId = (int)($r['STORE_ID'] ?? 0);
		$amt = (float)($r['AMOUNT'] ?? 0);
		if ($storeId <= 0 || $amt <= 0) continue;

		$s = function_exists('mf_store_row') ? mf_store_row($storeId) : null;
		$title = is_array($s) ? trim((string)($s['TITLE'] ?? '')) : '';
		if ($title === '') $title = 'Склад ' . $storeId;

		$price = function_exists('mf_calc_store_price') ? mf_calc_store_price($productId, $storeId) : null;
		if (function_exists('mf_user_is_wholesale') && function_exists('mf_calc_store_price') && mf_user_is_wholesale() && $price !== null && $price > 0)
		{
			$price = round((float)$price * 0.9, 2);
		}

		$out[] = [
			'store_id' => $storeId,
			'title' => $title,
			'amount' => $amt,
			'price' => $price,
			'price_fmt' => $mfMoney($price),
		];
	}

	usort($out, static function ($a, $b) {
		$pa = (float)($a['price'] ?? 0);
		$pb = (float)($b['price'] ?? 0);
		if ($pa > 0 && $pb > 0 && $pa !== $pb)
		{
			return $pa <=> $pb;
		}
		return (int)($a['store_id'] ?? 0) <=> (int)($b['store_id'] ?? 0);
	});

	return $out;
};

$mfRenderProductCard = static function (array $data) use (&$mfRenderProductCard, $mfPlaceholder, $mfTrimText, $mfStoresForProduct) {
	$id = (int)($data['id'] ?? 0);
	$url = (string)($data['url'] ?? '');
	$titleHtml = (string)($data['title_html'] ?? '');
	$descHtml = (string)($data['desc_html'] ?? '');
	$code = trim((string)($data['code'] ?? ''));
	$isAnalog = !empty($data['is_analog']);
	$analogs = (is_array($data['analogs'] ?? null) ? (array)$data['analogs'] : []);

	$img = $mfPlaceholder;
	if ($code !== '' && function_exists('mf_mf_product_img_url'))
	{
		$u = (string)mf_mf_product_img_url($code, 1);
		if ($u !== '') $img = $u;
	}

	$desc = $mfTrimText($descHtml, 170);
	$stores = $mfStoresForProduct($id);
	$wrapTag = $isAnalog ? 'div' : 'article';
	?>
	<<?=$wrapTag?> class="mf-search-card<?=($isAnalog ? ' mf-search-card--analog' : ' mf-search-card--root')?>">
		<div class="mf-search-card__top">
			<a class="mf-search-card__img" href="<?=htmlspecialcharsbx($url)?>">
				<img src="<?=htmlspecialcharsbx($img)?>" alt="" loading="lazy" />
			</a>
			<div class="mf-search-card__main">
				<a class="mf-search-card__title" href="<?=htmlspecialcharsbx($url)?>"><?=$titleHtml?></a>
				<?php if ($desc !== ''): ?>
					<div class="mf-search-card__desc"><?=htmlspecialcharsbx($desc)?></div>
				<?php endif; ?>
			</div>
		</div>

		<div class="mf-search-card__avail">
			<div class="mf-search-card__avail-title">В наличии</div>
			<?php if (!empty($stores)): ?>
				<table class="mf-search-stock-table">
					<thead>
						<tr>
							<th>Склад</th>
							<th class="mf-ta-r">Остаток</th>
							<th class="mf-ta-r">Цена</th>
							<th class="mf-ta-r"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($stores as $s): ?>
							<tr>
								<td><?=htmlspecialcharsbx((string)$s['title'])?></td>
								<td class="mf-ta-r"><?=htmlspecialcharsbx((string)round((float)$s['amount'], 3))?></td>
								<td class="mf-ta-r"><?=htmlspecialcharsbx((string)($s['price_fmt'] ?: '—'))?></td>
								<td class="mf-ta-r">
									<button
										type="button"
										class="btn btn-sm btn-warning mf-search-stock__btn js-mf-add-store"
										data-product-id="<?=$id?>"
										data-store-id="<?= (int)$s['store_id'] ?>"
										data-qty="1"
									>В корзину</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else: ?>
				<div class="mf-search-card__no-stock">Отсутствует на складах</div>
			<?php endif; ?>
		</div>

		<?php if (!$isAnalog && !empty($analogs)): ?>
			<div class="mf-search-card__analogs">
				<div class="mf-search-card__analogs-title">Аналоги</div>
				<div class="mf-search-analogs">
					<?php foreach ($analogs as $a): ?>
						<?php $mfRenderProductCard($a); ?>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

	</<?=$wrapTag?>>
	<?php
};
?>

<div class="mf-search">
	<div class="mf-search__panel">
		<div class="mf-search__top">
			<div class="mf-shop-search">
				<form class="mf-shop-search__form" action="" method="get">
						<label class="mf-shop-search__label" for="mfSearchInput">Поиск</label>
						<input
							id="mfSearchInput"
							class="mf-shop-search__input"
							type="text"
							name="q"
							value="<?=$queryValueAttr?>"
							placeholder="Введите запрос…"
							autocomplete="off"
						/>
						<button class="mf-shop-search__btn" type="submit"><?=GetMessage('SEARCH_GO')?></button>
						<input type="hidden" name="how" value="<?=$how?>" />
						<?php if ($arParams['SHOW_WHERE']): ?>
							<select name="where" class="mf-search__where">
								<option value=""><?=GetMessage('SEARCH_ALL')?></option>
								<?php foreach (($arResult['DROPDOWN'] ?? []) as $key => $value): ?>
									<option value="<?=htmlspecialcharsbx($key)?>"<?=((string)($arResult['REQUEST']['WHERE'] ?? '') === (string)$key) ? ' selected' : ''?>>
										<?=htmlspecialcharsbx($value)?>
									</option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>
				</form>
			</div>

				<?php if (isset($arResult['REQUEST']['ORIGINAL_QUERY'])): ?>
				<?php
				$keyboardWarning = GetMessage(
					'CT_BSP_KEYBOARD_WARNING',
					[
						'#query#' => '<a href="' . htmlspecialcharsbx($arResult['ORIGINAL_QUERY_URL']) . '">' . htmlspecialcharsbx($arResult['REQUEST']['ORIGINAL_QUERY']) . '</a>',
					]
				);
				?>
				<?php if ($keyboardWarning !== ''): ?>
					<div class="mf-search__note mf-search__note--info">
						<?=$keyboardWarning?>
					</div>
				<?php endif; ?>
				<?php endif; ?>
		</div>

			<?php if ($arResult['REQUEST']['QUERY'] === false && $arResult['REQUEST']['TAGS'] === false): ?>
				<div class="mf-search__empty">
					<strong>Введите запрос</strong>, чтобы начать поиск.
				</div>
			<?php elseif ((int)($arResult['ERROR_CODE'] ?? 0) !== 0): ?>
				<div class="mf-search__note mf-search__note--error">
					<strong><?=GetMessage('SEARCH_ERROR')?></strong><br />
					<?php ShowError($arResult['ERROR_TEXT']); ?>
				</div>
			<?php elseif (!empty($arResult['SEARCH'])): ?>
				<?php
				$total = 0;
				if (is_object($arResult['NAV_RESULT'] ?? null))
				{
					$total = (int)$arResult['NAV_RESULT']->NavRecordCount;
				}

				// Preload analogs for catalog items (IBLOCK_ID=4) so they show in search results
				// even if analog items themselves don't match the query.
				$mfAnalogsByProductId = [];
				$mfAnalogRowsById = [];
				$mfProductRowsById = [];
				$mfProductIds = [];
				foreach ($arResult['SEARCH'] as $it)
				{
					if ((string)($it['MODULE_ID'] ?? '') !== 'iblock') continue;
					if ((int)($it['PARAM2'] ?? 0) !== 4) continue;
					$id = (int)($it['ITEM_ID'] ?? 0);
					if ($id > 0) $mfProductIds[$id] = true;
				}
				$mfProductIds = array_keys($mfProductIds);
				if (!empty($mfProductIds))
				{
					// Prefetch base product rows for descriptions/codes.
					if (class_exists('CIBlockElement'))
					{
						$rsP = \CIBlockElement::GetList(
							['ID' => 'ASC'],
							['IBLOCK_ID' => $mfCatalogIblockId, 'ID' => $mfProductIds, 'ACTIVE' => 'Y'],
							false,
							false,
							['ID', 'NAME', 'CODE', 'PREVIEW_TEXT', 'DETAIL_TEXT']
						);
						while ($r = $rsP->Fetch())
						{
							$pid = (int)($r['ID'] ?? 0);
							if ($pid > 0) $mfProductRowsById[$pid] = $r;
						}
					}

					$analogsLib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/bitrix/php_interface/include/mf_analogs.php';
					if (is_file($analogsLib))
					{
						require_once $analogsLib;
					}
					if ((function_exists('mf_analogs_related_ids_for_product') || function_exists('mf_analogs_ids_for_product')) && class_exists('CIBlockElement'))
					{
						$allAnalogIds = [];
						foreach ($mfProductIds as $pid)
						{
							$ids = function_exists('mf_analogs_related_ids_for_product')
								? mf_analogs_related_ids_for_product((int)$pid, 12)
								: mf_analogs_ids_for_product((int)$pid, 12);
							if (!empty($ids))
							{
								$mfAnalogsByProductId[(int)$pid] = $ids;
								foreach ($ids as $aid)
								{
									$allAnalogIds[(int)$aid] = true;
								}
							}
						}

						$allAnalogIds = array_keys($allAnalogIds);
						if (!empty($allAnalogIds))
						{
							$rsA = \CIBlockElement::GetList(
								['NAME' => 'ASC', 'ID' => 'ASC'],
								[
									'IBLOCK_ID' => 4,
									'ID' => $allAnalogIds,
									'ACTIVE' => 'Y',
								],
								false,
								false,
								['ID', 'NAME', 'CODE', 'PREVIEW_TEXT', 'DETAIL_TEXT', 'PROPERTY_CML2_ARTICLE', 'PROPERTY_MF_BRAND']
							);
							while ($r = $rsA->Fetch())
							{
								$aid = (int)($r['ID'] ?? 0);
								if ($aid > 0)
								{
									$mfAnalogRowsById[$aid] = $r;
								}
							}
						}
					}
				}
				?>
				<div class="mf-search__summary">
					<span>Найдено: <strong><?=$total ?: count($arResult['SEARCH'])?></strong></span>
				</div>

				<?php if (($arParams['DISPLAY_TOP_PAGER'] ?? 'N') !== 'N'): ?>
					<?=$arResult['NAV_STRING']?>
				<?php endif; ?>

				<div class="mf-search__results">
					<?php foreach ($arResult['SEARCH'] as $arItem): ?>
						<?php
						// Motor-Force: показываем в поиске только товары каталога (IBLOCK_ID=4).
						if ((string)($arItem['MODULE_ID'] ?? '') !== 'iblock' || (int)($arItem['PARAM2'] ?? 0) !== 4)
						{
							continue;
						}
						// Fix broken URLs for catalog items when IBLOCK URL templates are empty.
						$href = (string)($arItem['URL'] ?? '');
						$qs = '';
						if ($href !== '' && $href[0] === '?')
						{
							$qs = $href;
							$href = '';
						}
						if ($href === '' && (string)($arItem['MODULE_ID'] ?? '') === 'iblock')
						{
							$iblockId = (int)($arItem['PARAM2'] ?? 0);
							$itemId = (int)($arItem['ITEM_ID'] ?? 0);
							if ($iblockId === 4 && $itemId > 0 && class_exists('CIBlockElement'))
							{
								static $mfCodeCache = [];
								if (!isset($mfCodeCache[$itemId]))
								{
									$row = \CIBlockElement::GetList(
										[],
										['IBLOCK_ID' => $iblockId, 'ID' => $itemId],
										false,
										['nTopCount' => 1],
										['ID', 'CODE']
									)->Fetch();
									$mfCodeCache[$itemId] = $row ? trim((string)($row['CODE'] ?? '')) : '';
								}
								$code = (string)$mfCodeCache[$itemId];
								if ($code !== '')
								{
									$href = '/products/' . rawurlencode($code) . '/';
								}
							}
						}
						if ($href === '')
						{
							$href = (string)($arItem['URL'] ?? '');
						}
						if ($qs !== '' && $href !== '' && strpos($href, '?') === false)
						{
							$href .= $qs;
						}

						$mfItemId = (int)($arItem['ITEM_ID'] ?? 0);
						$mfRow = ($mfItemId > 0 && isset($mfProductRowsById[$mfItemId])) ? $mfProductRowsById[$mfItemId] : null;
						$mfCode = is_array($mfRow) ? trim((string)($mfRow['CODE'] ?? '')) : '';
						$mfDesc = '';
						if (is_array($mfRow))
						{
							$mfDesc = (string)($mfRow['PREVIEW_TEXT'] ?? '');
							if (trim($mfDesc) === '')
							{
								$mfDesc = (string)($mfRow['DETAIL_TEXT'] ?? '');
							}
						}
						if (trim($mfDesc) === '')
						{
							$mfDesc = (string)($arItem['BODY_FORMATED'] ?? '');
						}

						// Prepare analog cards to render inside the main card.
						$mfAnalogs = ($mfItemId > 0 && isset($mfAnalogsByProductId[$mfItemId])) ? (array)$mfAnalogsByProductId[$mfItemId] : [];
						$analogsData = [];
						if (!empty($mfAnalogs))
						{
							foreach ($mfAnalogs as $aid)
							{
								$rA = $mfAnalogRowsById[(int)$aid] ?? null;
								if (!is_array($rA)) continue;
								$aid2 = (int)($rA['ID'] ?? 0);
								if ($aid2 <= 0) continue;
								$codeA = trim((string)($rA['CODE'] ?? ''));
								$urlA = ($codeA !== '' ? '/products/' . rawurlencode($codeA) . '/' : '');
								if ($urlA === '') continue;
								$nameA = (string)($rA['NAME'] ?? ('ID ' . $aid2));
								$descA = (string)($rA['PREVIEW_TEXT'] ?? '');
								if (trim($descA) === '')
								{
									$descA = (string)($rA['DETAIL_TEXT'] ?? '');
								}
								$analogsData[] = [
									'id' => $aid2,
									'url' => $urlA,
									'code' => $codeA,
									'title_html' => htmlspecialcharsbx($nameA),
									'desc_html' => $descA,
									'is_analog' => true,
								];
							}
						}

						$mfRenderProductCard([
							'id' => $mfItemId,
							'url' => $href,
							'code' => $mfCode,
							'title_html' => (string)($arItem['TITLE_FORMATED'] ?? htmlspecialcharsbx((string)($arItem['TITLE'] ?? ''))),
							'desc_html' => $mfDesc,
							'is_analog' => false,
							'analogs' => $analogsData,
						]);
						?>
					<?php endforeach; ?>
				</div>

				<script>
				(function(){
					if (window.__mfSearchAddStoreBound) return;
					window.__mfSearchAddStoreBound = true;
					function mfSetHeaderBasketCount(cnt)
					{
						var n = 0;
						try { n = parseInt(cnt, 10); } catch (e0) { n = 0; }
						if (!isFinite(n) || n < 0) n = 0;

						// Update existing counters.
						try
						{
							var els = document.querySelectorAll('[data-role="mf-cart-count"]');
							for (var i = 0; i < els.length; i++)
							{
								els[i].textContent = String(n);
							}
						}
						catch (e1) {}

						// If counter spans were not rendered for 0 items, create them.
						if (n > 0)
						{
							try
							{
								var links = document.querySelectorAll('a.mf-cart-link');
								for (var j = 0; j < links.length; j++)
								{
									if (links[j].querySelector('[data-role="mf-cart-count"]')) continue;
									var s = document.createElement('span');
									s.className = 'mf-cart-count';
									s.setAttribute('data-role', 'mf-cart-count');
									s.textContent = String(n);
									links[j].appendChild(s);
								}
							}
							catch (e2) {}
						}
					}
					function mfApplyInBasketState(productQtyMap)
					{
						productQtyMap = productQtyMap || {};
						try
						{
							var btns = document.querySelectorAll('.js-mf-add-store');
							for (var i = 0; i < btns.length; i++)
							{
								var b = btns[i];
								if (!b) continue;
								var pid = b.getAttribute('data-product-id') || '';
								var q = 0;
								try { q = parseInt(productQtyMap[String(pid)] || 0, 10); } catch (e0) { q = 0; }
								if (q > 0)
								{
									b.setAttribute('data-in-basket', '1');
									b.textContent = 'В корзине';
									try { b.classList.remove('btn-warning'); b.classList.add('btn-secondary'); } catch(e1) {}
								}
								else
								{
									b.removeAttribute('data-in-basket');
									b.textContent = 'В корзину';
									try { b.classList.remove('btn-secondary'); b.classList.add('btn-warning'); } catch(e2) {}
								}
							}
						}
						catch (e3) {}
					}
					function mfSyncBasketState()
					{
						var ids = [];
						try
						{
							var btns = document.querySelectorAll('.js-mf-add-store');
							var seen = {};
							for (var i = 0; i < btns.length; i++)
							{
								var pid = btns[i].getAttribute('data-product-id') || '';
								if (!pid) continue;
								if (seen[pid]) continue;
								seen[pid] = 1;
								ids.push(pid);
							}
						}
						catch (e0) { ids = []; }

						var done = function(resp){
							if (!resp || !resp.ok) return;
							try { mfSetHeaderBasketCount(resp.basket_count); } catch(e1) {}
							try { mfApplyInBasketState(resp.products || {}); } catch(e2) {}
						};

						if (window.BX && BX.ajax)
						{
							BX.ajax({
								url: '/ajax/mf_basket_state.php',
								method: 'POST',
								dataType: 'json',
								data: {productIds: ids},
								onsuccess: done
							});
						}
						else if (window.fetch)
						{
							try
							{
								var fd = new FormData();
								for (var i2 = 0; i2 < ids.length; i2++) fd.append('productIds[]', ids[i2]);
								fetch('/ajax/mf_basket_state.php', {method: 'POST', credentials: 'same-origin', body: fd})
									.then(function(r){ return r.json(); })
									.then(done);
							}
							catch (e3) {}
						}
					}

					// Initial sync after page render.
					try { setTimeout(mfSyncBasketState, 0); } catch (e0) {}
					document.addEventListener('click', function(e){
						var btn = e && e.target && e.target.closest ? e.target.closest('.js-mf-add-store') : null;
						if (!btn) return;
						e.preventDefault();
						if (btn.getAttribute('data-in-basket') === '1')
						{
							window.location.href = '/personal/cart/';
							return;
						}
						var pid = btn.getAttribute('data-product-id') || '';
						var sid = btn.getAttribute('data-store-id') || '';
						var qty = btn.getAttribute('data-qty') || '1';
						if (!pid || !sid) return;
						if (btn.disabled) return;
						btn.disabled = true;
						btn.textContent = 'Добавляем…';

						if (window.BX && BX.ajax)
						{
							BX.ajax({
								url: '/ajax/mf_add_to_basket_store.php',
								method: 'POST',
								dataType: 'json',
								data: {productId: pid, storeId: sid, qty: qty},
								onsuccess: function(resp){
									// Keep the state: item is already in basket.
									btn.disabled = false;
									btn.setAttribute('data-in-basket', '1');
									btn.textContent = 'В корзине';

									try { btn.classList.remove('btn-warning'); btn.classList.add('btn-secondary'); } catch(e0) {}
									try {
										if (resp && (resp.basket_count !== undefined) && (resp.basket_count !== null))
										{
											mfSetHeaderBasketCount(resp.basket_count);
										}
									} catch(e00) {}
									// Ensure the page state (buttons + header) matches real basket.
									try { setTimeout(mfSyncBasketState, 30); } catch (e1) {}
								},
								onfailure: function(){
									btn.disabled = false;
									btn.textContent = 'В корзину';
									try { btn.classList.remove('btn-secondary'); btn.classList.add('btn-warning'); btn.removeAttribute('data-in-basket'); } catch(e0) {}
								}
							});
						}
						else
						{
							// fallback: navigate
							window.location.href = '/ajax/mf_add_to_basket_store.php?productId=' + encodeURIComponent(pid) + '&storeId=' + encodeURIComponent(sid) + '&qty=' + encodeURIComponent(qty);
						}
					}, true);
				})();
				</script>

				<?php if (($arParams['DISPLAY_BOTTOM_PAGER'] ?? 'N') !== 'N'): ?>
					<div style="margin-top: 14px;">
						<?=$arResult['NAV_STRING']?>
					</div>
				<?php endif; ?>
			<?php else: ?>
				<div class="mf-search__empty">
					<strong>Ничего не найдено</strong><?php if (!empty($queryValue)): ?> по запросу «<?=htmlspecialcharsbx($queryValue)?>»<?php endif; ?>.
					<div style="margin-top: 8px;">
						Попробуйте изменить формулировку или сократить запрос.
					</div>
				</div>
			<?php endif; ?>
	</div>
</div>

