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
global $USER;
$mfIsAuthorized = is_object($USER) && method_exists($USER, 'IsAuthorized') && $USER->IsAuthorized();
$mfCurrentUserName = '';
$mfCurrentUserEmail = '';
if ($mfIsAuthorized)
{
	$mfCurrentUserName = trim((string)$USER->GetFirstName() . ' ' . (string)$USER->GetLastName());
	if ($mfCurrentUserName === '')
	{
		$mfCurrentUserName = trim((string)$USER->GetLogin());
	}
	$mfCurrentUserEmail = trim((string)$USER->GetEmail());
}

global $APPLICATION;
$mfAuthBackParamsDelete = [
	'login',
	'login_form',
	'logout',
	'register',
	'forgot_password',
	'change_password',
	'confirm_registration',
	'confirm_code',
	'confirm_user_id',
	'logout_butt',
	'auth_service_id',
	'clear_cache',
	'backurl',
];
$mfRequestPriceBackUrlEnc = urlencode((string)$APPLICATION->GetCurPageParam('', $mfAuthBackParamsDelete));
$mfLoginWithBackUrl = SITE_DIR . 'login/?login=yes&backurl=' . $mfRequestPriceBackUrlEnc;
$mfRegisterWithBackUrl = SITE_DIR . 'login/?register=yes&backurl=' . $mfRequestPriceBackUrlEnc;

// Motor-Force search cards helpers (catalog-only).
$mfCatalogIblockId = 4;
$mfPlaceholder = (function_exists('mf_mf_placeholder_img_url') ? mf_mf_placeholder_img_url() : '/bitrix/templates/eshop_bootstrap_v4/images/mf-no-photo.svg');
$mfProductSearchCardLib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/bitrix/php_interface/include/mf_product_search_card.php';
if (is_file($mfProductSearchCardLib))
{
	require_once $mfProductSearchCardLib;
}

$mfMoney = static function (?float $price): string {
	return function_exists('mf_product_search_card_money') ? mf_product_search_card_money($price) : '';
};

$mfSearchMinPricePrint = static function (int $productId): string {
	return function_exists('mf_product_search_card_min_price_print') ? mf_product_search_card_min_price_print($productId) : '';
};

$mfStoresForProduct = static function (int $productId): array {
	return function_exists('mf_product_search_card_stores') ? mf_product_search_card_stores($productId) : [];
};

$mfRenderProductCard = static function (array $data) use (&$mfRenderProductCard, $mfPlaceholder, $mfStoresForProduct, $mfSearchMinPricePrint) {
	$id = (int)($data['id'] ?? 0);
	$url = (string)($data['url'] ?? '');
	$titleHtml = (string)($data['title_html'] ?? '');
	$code = trim((string)($data['code'] ?? ''));
	$brand = trim((string)($data['brand'] ?? ''));
	$article = trim((string)($data['article'] ?? ''));
	$isAnalog = !empty($data['is_analog']);
	$analogs = (is_array($data['analogs'] ?? null) ? (array)$data['analogs'] : []);

	$img = $mfPlaceholder;
	if ($code !== '' && function_exists('mf_mf_product_img_url'))
	{
		$u = (string)mf_mf_product_img_url($code, 1);
		if ($u !== '') $img = $u;
	}

	$priceFrom = $mfSearchMinPricePrint($id);
	$stores = $mfStoresForProduct($id);
	$wrapTag = $isAnalog ? 'div' : 'article';
	$titlePlain = trim(html_entity_decode(strip_tags($titleHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	if ($titlePlain === '')
	{
		$titlePlain = 'Товар #' . $id;
	}
	?>
	<<?=$wrapTag?> class="mf-search-card<?=($isAnalog ? ' mf-search-card--analog' : ' mf-search-card--root')?>">
		<div class="mf-search-card__top">
			<a class="mf-search-card__img" href="<?=htmlspecialcharsbx($url)?>">
				<img src="<?=htmlspecialcharsbx($img)?>" alt="" loading="lazy" />
			</a>
			<div class="mf-search-card__main">
				<a class="mf-search-card__title" href="<?=htmlspecialcharsbx($url)?>"><?=$titleHtml?></a>
				<div class="mf-product-meta" aria-label="Цена, бренд и артикул">
					<div class="mf-product-meta__item">
						<span class="mf-product-meta__label">От</span>
						<span class="mf-product-meta__value"><?= $priceFrom !== '' ? htmlspecialcharsbx($priceFrom) : 'Запросить цену' ?></span>
					</div>
					<div class="mf-product-meta__item">
						<span class="mf-product-meta__label">Бренд</span>
						<span class="mf-product-meta__value"><?= $brand !== '' ? htmlspecialcharsbx($brand) : '—' ?></span>
					</div>
					<div class="mf-product-meta__item">
						<span class="mf-product-meta__label">Артикул</span>
						<span class="mf-product-meta__value"><?= $article !== '' ? htmlspecialcharsbx($article) : '—' ?></span>
					</div>
				</div>
			</div>
		</div>

		<div class="mf-search-card__avail">
			<?php if (!empty($stores)): ?>
				<table class="mf-search-stock-table">
					<thead>
						<tr>
							<th>Склад</th>
							<th>Срок доставки</th>
							<th class="mf-search-stock-table__spb text-center">Доставка</th>
							<th class="mf-ta-r">Наличие</th>
							<th class="mf-ta-r">Цена</th>
							<th class="mf-ta-r">Кол-во</th>
							<th class="mf-ta-r"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($stores as $s): ?>
							<?php
							$mfOrdOnly = !empty($s['order_only']);
							$mfAmtRounded = round((float)$s['amount'], 3);
							$mfPendingDisp = trim((string)($s['pending_supplier_display'] ?? ''));
							$mfStockCell = '';
							if ($mfOrdOnly)
							{
								$mfStockCell = 'Под заказ';
							}
							elseif ($mfPendingDisp !== '')
							{
								$mfStockCell = htmlspecialcharsbx($mfPendingDisp);
							}
							else
							{
								$mfStockCell = htmlspecialcharsbx((string)$mfAmtRounded);
							}
							$mfMaxQtyRounded = isset($s['pending_supplier_qty'])
								? round((float)$s['pending_supplier_qty'], 3)
								: $mfAmtRounded;
							?>
							<tr>
								<td><?=htmlspecialcharsbx((string)$s['title'])?></td>
								<td class="mf-search-stock-table__delivery"><?=htmlspecialcharsbx((string)($s['delivery_term'] ?? '—'))?></td>
								<td class="mf-search-stock-table__spb text-center"><?php
									$mfSpbSid = (int)($s['store_id'] ?? 0);
									if ($mfSpbSid > 0 && function_exists('mf_store_delivery_spb_icon_html')) {
										echo mf_store_delivery_spb_icon_html($mfSpbSid, $id);
									} else {
										echo '—';
									}
								?></td>
								<td class="mf-ta-r mf-search-stock-table__pending"><?=$mfStockCell?></td>
								<td class="mf-ta-r"><?=htmlspecialcharsbx((string)($s['price_fmt'] ?: '—'))?></td>
								<td class="mf-ta-r">
									<?php
									$mfNoPrice = (($s['price'] ?? null) === null || (float)$s['price'] <= 0);
									$mfRequestPrice = $mfOrdOnly || $mfNoPrice;
									?>
									<?php if ($mfOrdOnly): ?>
										<span class="mf-search-stock__order-only">Под заказ</span>
									<?php elseif ($mfNoPrice): ?>
										<span class="mf-search-stock__order-only">—</span>
									<?php else: ?>
										<div class="mf-search-stock__actions">
											<div class="mf-search-qty" data-max-qty="<?=htmlspecialcharsbx((string)$mfMaxQtyRounded)?>">
												<button type="button" class="mf-search-qty__btn js-mf-qty-minus" aria-label="Уменьшить количество">-</button>
												<input
													type="number"
													class="mf-search-qty__input js-mf-qty-input"
													value="1"
													min="1"
													step="1"
													inputmode="numeric"
													aria-label="Количество"
												>
												<button type="button" class="mf-search-qty__btn js-mf-qty-plus" aria-label="Увеличить количество">+</button>
											</div>
										</div>
									<?php endif; ?>
								</td>
								<td class="mf-ta-r">
									<?php if ($mfRequestPrice): ?>
										<button
											type="button"
											class="btn btn-sm btn-warning mf-search-stock__btn mf-search-stock__btn--request js-mf-request-price"
											data-product-id="<?=$id?>"
											data-product-name="<?=htmlspecialcharsbx($titlePlain)?>"
											data-product-url="<?=htmlspecialcharsbx($url)?>"
										>Запросить цену</button>
									<?php else: ?>
										<button
											type="button"
											class="btn btn-sm btn-warning mf-search-stock__btn js-mf-add-store"
											data-product-id="<?=$id?>"
											data-store-id="<?= (int)$s['store_id'] ?>"
											data-qty="1"
										>В корзину</button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php else: ?>
					<div class="mf-search-card__no-stock-row">
						<div class="mf-search-card__no-stock">Нет данных по складам</div>
						<button
							type="button"
							class="btn btn-sm btn-warning mf-search-stock__btn mf-search-stock__btn--request js-mf-request-price"
							data-product-id="<?=$id?>"
							data-product-name="<?=htmlspecialcharsbx($titlePlain)?>"
							data-product-url="<?=htmlspecialcharsbx($url)?>"
						>Запросить цену</button>
					</div>
				<?php endif; ?>
		</div>

		<?php if (!$isAnalog && !empty($analogs)): ?>
			<div class="mf-search-card__catalog-note">Также Вы можете заказать данный товар или его аналог в каталогах</div>
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
							[
								'ID',
								'NAME',
								'CODE',
								'PREVIEW_TEXT',
								'DETAIL_TEXT',
								'PROPERTY_CML2_ARTICLE',
								'PROPERTY_MF_BRAND',
								'PROPERTY_MF_BRAND_NORM',
								'PROPERTY_OEM',
							]
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
								[
									'ID',
									'NAME',
									'CODE',
									'PREVIEW_TEXT',
									'DETAIL_TEXT',
									'PROPERTY_CML2_ARTICLE',
									'PROPERTY_MF_BRAND',
									'PROPERTY_MF_BRAND_NORM',
									'PROPERTY_OEM',
								]
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
						$mfBrand = '';
						$mfArticle = '';
						$mfOem = '';
						if (is_array($mfRow))
						{
							$mfBrand = trim((string)($mfRow['PROPERTY_MF_BRAND_VALUE'] ?? ($mfRow['PROPERTY_MF_BRAND_NORM_VALUE'] ?? '')));
							$mfArticle = trim((string)($mfRow['PROPERTY_CML2_ARTICLE_VALUE'] ?? ''));
							$mfOem = trim((string)($mfRow['PROPERTY_OEM_VALUE'] ?? ''));
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
								$analogsData[] = [
									'id' => $aid2,
									'url' => $urlA,
									'code' => $codeA,
									'title_html' => htmlspecialcharsbx($nameA),
									'brand' => trim((string)($rA['PROPERTY_MF_BRAND_VALUE'] ?? ($rA['PROPERTY_MF_BRAND_NORM_VALUE'] ?? ''))),
									'article' => trim((string)($rA['PROPERTY_CML2_ARTICLE_VALUE'] ?? '')),
									'oem' => trim((string)($rA['PROPERTY_OEM_VALUE'] ?? '')),
									'is_analog' => true,
								];
							}
						}

						$mfRenderProductCard([
							'id' => $mfItemId,
							'url' => $href,
							'code' => $mfCode,
							'title_html' => (string)($arItem['TITLE_FORMATED'] ?? htmlspecialcharsbx((string)($arItem['TITLE'] ?? ''))),
							'brand' => $mfBrand,
							'article' => $mfArticle,
							'oem' => $mfOem,
							'is_analog' => false,
							'analogs' => $analogsData,
						]);
						?>
					<?php endforeach; ?>
				</div>

				<div class="mf-search-modal" id="mf-request-price-modal" hidden>
					<div class="mf-search-modal__backdrop js-mf-request-price-close"></div>
					<div class="mf-search-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="mf-request-price-title">
						<button type="button" class="mf-search-modal__close js-mf-request-price-close" aria-label="Закрыть">×</button>
						<div class="mf-search-modal__title" id="mf-request-price-title">Запросить цену</div>
						<div class="mf-search-modal__subtitle" id="mf-request-price-product"></div>
						<?php if (!$mfIsAuthorized): ?>
						<div class="mf-search-modal__auth">
							<p class="mf-search-modal__auth-text">Войдите или зарегистрируйтесь — после возврата на эту страницу данные профиля подставятся в форму, и вы сможете отправить запрос.</p>
							<div class="mf-search-modal__auth-actions">
								<a class="btn btn-outline-dark mf-search-modal__auth-btn js-mf-request-price-auth-link" href="<?=htmlspecialcharsbx($mfLoginWithBackUrl)?>">Войти</a>
								<a class="btn btn-outline-dark mf-search-modal__auth-btn js-mf-request-price-auth-link" href="<?=htmlspecialcharsbx($mfRegisterWithBackUrl)?>">Регистрация</a>
							</div>
						</div>
						<?php endif; ?>
						<div class="mf-search-modal__message" id="mf-request-price-message" hidden></div>
						<form class="mf-search-modal__form" id="mf-request-price-form">
							<input type="hidden" name="sessid" value="<?=htmlspecialcharsbx(bitrix_sessid())?>">
							<input type="hidden" name="product_id" value="">
							<input type="hidden" name="product_name" value="">
							<input type="hidden" name="product_url" value="">

							<div class="form-group">
								<label for="mf-request-price-name">Имя</label>
								<input
									id="mf-request-price-name"
									type="text"
									class="form-control"
									name="name"
									value="<?=htmlspecialcharsbx($mfCurrentUserName)?>"
									<?=$mfIsAuthorized && $mfCurrentUserName !== '' ? 'readonly' : ''?>
								>
							</div>
							<div class="form-group">
								<label for="mf-request-price-email">E-mail</label>
								<input
									id="mf-request-price-email"
									type="email"
									class="form-control"
									name="email"
									value="<?=htmlspecialcharsbx($mfCurrentUserEmail)?>"
									<?=$mfIsAuthorized && $mfCurrentUserEmail !== '' ? 'readonly' : ''?>
								>
							</div>
							<div class="form-group">
								<label for="mf-request-price-comment">Комментарий</label>
								<textarea id="mf-request-price-comment" class="form-control" name="comment" rows="5"></textarea>
							</div>
							<div class="mf-search-modal__actions">
								<button type="submit" class="btn btn-warning mf-search-modal__submit">Отправить</button>
							</div>
						</form>
					</div>
				</div>

				<script>
				(function(){
					if (window.__mfSearchAddStoreBound) return;
					window.__mfSearchAddStoreBound = true;
					var requestPriceCfg = <?=\CUtil::PhpToJSObject([
						'isAuthorized' => $mfIsAuthorized,
						'userName' => $mfCurrentUserName,
						'userEmail' => $mfCurrentUserEmail,
					])?>;
					var MF_REQ_PRICE_RESUME_KEY = 'mf_request_price_resume';
					var requestPriceModal = document.getElementById('mf-request-price-modal');
					var requestPriceForm = document.getElementById('mf-request-price-form');
					var requestPriceProduct = document.getElementById('mf-request-price-product');
					var requestPriceMessage = document.getElementById('mf-request-price-message');
					function mfSaveRequestPriceResumeForAuth()
					{
						if (!requestPriceForm) return;
						try
						{
							sessionStorage.setItem(MF_REQ_PRICE_RESUME_KEY, JSON.stringify({
								v: 1,
								scope: 'search',
								product_id: requestPriceForm.elements.product_id ? requestPriceForm.elements.product_id.value : '',
								product_name: requestPriceForm.elements.product_name ? requestPriceForm.elements.product_name.value : '',
								product_url: requestPriceForm.elements.product_url ? requestPriceForm.elements.product_url.value : ''
							}));
						}
						catch (e0) {}
					}
					function mfOpenRequestPriceFromResume(data)
					{
						if (!requestPriceModal || !requestPriceForm || !data) return;
						requestPriceForm.elements.product_id.value = data.product_id || '';
						requestPriceForm.elements.product_name.value = data.product_name || '';
						requestPriceForm.elements.product_url.value = data.product_url || '';
						if (requestPriceProduct)
						{
							requestPriceProduct.textContent = requestPriceForm.elements.product_name.value;
						}
						if (requestPriceCfg && requestPriceForm.elements.name)
						{
							requestPriceForm.elements.name.value = requestPriceCfg.userName || '';
							requestPriceForm.elements.name.readOnly = !!(requestPriceCfg.isAuthorized && requestPriceCfg.userName);
						}
						if (requestPriceCfg && requestPriceForm.elements.email)
						{
							requestPriceForm.elements.email.value = requestPriceCfg.userEmail || '';
							requestPriceForm.elements.email.readOnly = !!(requestPriceCfg.isAuthorized && requestPriceCfg.userEmail);
						}
						if (requestPriceForm.elements.comment)
						{
							requestPriceForm.elements.comment.value = '';
						}
						mfRequestPriceMessage('', false);
						requestPriceModal.hidden = false;
						document.documentElement.classList.add('mf-search-modal-open');
						document.body.classList.add('mf-search-modal-open');
						setTimeout(function(){
							try
							{
								if (requestPriceForm.elements.comment) requestPriceForm.elements.comment.focus();
							}
							catch (e1) {}
						}, 0);
					}
					function mfTryResumeRequestPriceSearch()
					{
						try
						{
							var raw = sessionStorage.getItem(MF_REQ_PRICE_RESUME_KEY);
							if (!raw) return;
							var data = JSON.parse(raw);
							if (!data || data.v !== 1 || data.scope !== 'search') return;
							sessionStorage.removeItem(MF_REQ_PRICE_RESUME_KEY);
							mfOpenRequestPriceFromResume(data);
						}
						catch (e2) {}
					}
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
					function mfRequestPriceMessage(text, isError)
					{
						if (!requestPriceMessage) return;
						requestPriceMessage.textContent = String(text || '');
						requestPriceMessage.className = 'mf-search-modal__message' + (isError ? ' is-error' : ' is-success');
						requestPriceMessage.hidden = !text;
					}
					function mfOpenRequestPrice(btn)
					{
						if (!requestPriceModal || !requestPriceForm) return;
						requestPriceForm.elements.product_id.value = btn.getAttribute('data-product-id') || '';
						requestPriceForm.elements.product_name.value = btn.getAttribute('data-product-name') || '';
						requestPriceForm.elements.product_url.value = btn.getAttribute('data-product-url') || '';
						if (requestPriceProduct)
						{
							requestPriceProduct.textContent = requestPriceForm.elements.product_name.value;
						}
						if (requestPriceCfg && requestPriceCfg.userName && requestPriceForm.elements.name && !requestPriceForm.elements.name.value)
						{
							requestPriceForm.elements.name.value = requestPriceCfg.userName;
						}
						if (requestPriceCfg && requestPriceCfg.userEmail && requestPriceForm.elements.email && !requestPriceForm.elements.email.value)
						{
							requestPriceForm.elements.email.value = requestPriceCfg.userEmail;
						}
						if (requestPriceForm.elements.comment)
						{
							requestPriceForm.elements.comment.value = '';
						}
						mfRequestPriceMessage('', false);
						requestPriceModal.hidden = false;
						document.documentElement.classList.add('mf-search-modal-open');
						document.body.classList.add('mf-search-modal-open');
						setTimeout(function(){
							try
							{
								if (requestPriceForm.elements.comment) requestPriceForm.elements.comment.focus();
							}
							catch (e0) {}
						}, 0);
					}
					function mfCloseRequestPrice()
					{
						if (!requestPriceModal) return;
						requestPriceModal.hidden = true;
						document.documentElement.classList.remove('mf-search-modal-open');
						document.body.classList.remove('mf-search-modal-open');
					}
					function mfSubmitRequestPrice()
					{
						if (!requestPriceForm) return;
						var submitBtn = requestPriceForm.querySelector('button[type="submit"]');
						var oldText = submitBtn ? submitBtn.textContent : '';
						if (submitBtn)
						{
							submitBtn.disabled = true;
							submitBtn.textContent = 'Отправляем…';
						}
						mfRequestPriceMessage('', false);

						var done = function(resp){
							if (!resp || !resp.ok)
							{
								mfRequestPriceMessage((resp && resp.error) ? resp.error : 'Не удалось отправить сообщение.', true);
								if (submitBtn)
								{
									submitBtn.disabled = false;
									submitBtn.textContent = oldText;
								}
								return;
							}
							mfRequestPriceMessage('Сообщение отправлено. Мы свяжемся с вами.', false);
							if (submitBtn)
							{
								submitBtn.disabled = false;
								submitBtn.textContent = oldText;
							}
							setTimeout(function(){
								mfCloseRequestPrice();
							}, 900);
						};

						if (window.BX && BX.ajax)
						{
							BX.ajax({
								url: '/ajax/mf_request_price.php',
								method: 'POST',
								dataType: 'json',
								data: {
									sessid: requestPriceForm.elements.sessid.value,
									product_id: requestPriceForm.elements.product_id.value,
									product_name: requestPriceForm.elements.product_name.value,
									product_url: requestPriceForm.elements.product_url.value,
									name: requestPriceForm.elements.name.value,
									email: requestPriceForm.elements.email.value,
									comment: requestPriceForm.elements.comment.value
								},
								onsuccess: done,
								onfailure: function(){
									done({ok: false, error: 'Не удалось отправить сообщение.'});
								}
							});
							return;
						}
						if (window.fetch)
						{
							var fd = new FormData(requestPriceForm);
							fetch('/ajax/mf_request_price.php', {
								method: 'POST',
								credentials: 'same-origin',
								body: fd
							}).then(function(r){ return r.json(); })
								.then(done)
								.catch(function(){
									done({ok: false, error: 'Не удалось отправить сообщение.'});
								});
						}
					}

					// Initial sync after page render.
					try { setTimeout(mfSyncBasketState, 0); } catch (e0) {}
					document.addEventListener('click', function(e){
						var requestBtn = e && e.target && e.target.closest ? e.target.closest('.js-mf-request-price') : null;
						if (requestBtn)
						{
							e.preventDefault();
							mfOpenRequestPrice(requestBtn);
							return;
						}
						var closeBtn = e && e.target && e.target.closest ? e.target.closest('.js-mf-request-price-close') : null;
						if (closeBtn)
						{
							e.preventDefault();
							mfCloseRequestPrice();
							return;
						}
						var minusBtn = e && e.target && e.target.closest ? e.target.closest('.js-mf-qty-minus') : null;
						if (minusBtn)
						{
							e.preventDefault();
							var minusWrap = minusBtn.closest('.mf-search-qty');
							var minusInput = minusWrap ? minusWrap.querySelector('.js-mf-qty-input') : null;
							if (minusInput)
							{
								var minusVal = parseInt(minusInput.value || '1', 10);
								if (!isFinite(minusVal) || minusVal < 1) minusVal = 1;
								minusInput.value = String(Math.max(1, minusVal - 1));
							}
							return;
						}
						var plusBtn = e && e.target && e.target.closest ? e.target.closest('.js-mf-qty-plus') : null;
						if (plusBtn)
						{
							e.preventDefault();
							var plusWrap = plusBtn.closest('.mf-search-qty');
							var plusInput = plusWrap ? plusWrap.querySelector('.js-mf-qty-input') : null;
							if (plusInput)
							{
								var plusVal = parseInt(plusInput.value || '1', 10);
								if (!isFinite(plusVal) || plusVal < 1) plusVal = 1;
								plusInput.value = String(plusVal + 1);
							}
							return;
						}
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
						var actionCell = btn.closest ? btn.closest('td') : null;
						var row = actionCell && actionCell.parentNode ? actionCell.parentNode : null;
						var qtyWrap = row && row.querySelector ? row.querySelector('.mf-search-qty') : null;
						var qtyInput = qtyWrap && qtyWrap.querySelector ? qtyWrap.querySelector('.js-mf-qty-input') : null;
						if (qtyInput)
						{
							var qtyParsed = parseInt(qtyInput.value || '1', 10);
							if (!isFinite(qtyParsed) || qtyParsed < 1) qtyParsed = 1;
							qtyInput.value = String(qtyParsed);
							qty = String(qtyParsed);
						}
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
					document.addEventListener('keydown', function(e){
						if (e && e.key === 'Escape')
						{
							mfCloseRequestPrice();
						}
					});
					document.addEventListener('input', function(e){
						var input = e && e.target && e.target.closest ? e.target.closest('.js-mf-qty-input') : null;
						if (!input) return;
						var cleaned = String(input.value || '').replace(/[^\d]/g, '');
						var val = parseInt(cleaned || '1', 10);
						if (!isFinite(val) || val < 1) val = 1;
						input.value = String(val);
					});
					document.addEventListener('blur', function(e){
						var input = e && e.target && e.target.closest ? e.target.closest('.js-mf-qty-input') : null;
						if (!input) return;
						var val = parseInt(input.value || '1', 10);
						if (!isFinite(val) || val < 1) val = 1;
						input.value = String(val);
					}, true);
					if (requestPriceForm)
					{
						requestPriceForm.addEventListener('submit', function(e){
							e.preventDefault();
							mfSubmitRequestPrice();
						});
					}
					document.addEventListener('click', function(e){
						var a = e && e.target && e.target.closest ? e.target.closest('a.js-mf-request-price-auth-link') : null;
						if (!a || !requestPriceModal || requestPriceModal.hidden || !requestPriceModal.contains(a)) return;
						mfSaveRequestPriceResumeForAuth();
					}, true);
					try { setTimeout(mfTryResumeRequestPriceSearch, 0); } catch (e3) {}
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

