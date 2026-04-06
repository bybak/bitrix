<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Товары");
$APPLICATION->AddChainItem("Магазин", SITE_DIR."products/");

// ID вашего каталога (измените на свой: 4 или 5)
$IBLOCK_ID = 4; // Каталог запчастей Motor Force

// Search panel for the whole Shop section
?>
<div class="mf-shop mf-shop--detail">
	<section class="mf-shop-main" aria-label="Товар">
		<?include($_SERVER["DOCUMENT_ROOT"]."/include/mf_catalog_search.php");?>
<?php

// Получаем код товара из URL
$elementCode = $_REQUEST["ELEMENT_CODE"];
$elementId = 0;
$mfBrand = '';
$mfArticle = '';
$mfPreviewText = '';
$mfPreviewTextType = 'text';
$mfDetailText = '';
$mfDetailTextType = 'text';

// 301 redirect support for duplicate SKUs (redirect elements)
if ($elementCode)
{
	CModule::IncludeModule("iblock");
	$e = CIBlockElement::GetList(
		[],
		['IBLOCK_ID' => $IBLOCK_ID, '=CODE' => $elementCode],
		false,
		false,
		[
			'ID',
			'CODE',
			'NAME',
			'PREVIEW_TEXT',
			'PREVIEW_TEXT_TYPE',
			'DETAIL_TEXT',
			'DETAIL_TEXT_TYPE',
			'PROPERTY_MF_IS_REDIRECT',
			'PROPERTY_MF_CANONICAL_CODE',
			'PROPERTY_MF_BRAND',
			'PROPERTY_CML2_ARTICLE',
		]
	)->Fetch();

	if ($e && isset($e['ID']))
	{
		$elementId = (int)$e['ID'];
		$mfBrand = trim((string)($e['PROPERTY_MF_BRAND_VALUE'] ?? ''));
		$mfArticle = trim((string)($e['PROPERTY_CML2_ARTICLE_VALUE'] ?? ''));
		$mfPreviewText = (string)($e['PREVIEW_TEXT'] ?? '');
		$mfPreviewTextType = (string)($e['PREVIEW_TEXT_TYPE'] ?? 'text');
		$mfDetailText = (string)($e['DETAIL_TEXT'] ?? '');
		$mfDetailTextType = (string)($e['DETAIL_TEXT_TYPE'] ?? 'text');
	}

	if ($e && ($e['PROPERTY_MF_IS_REDIRECT_VALUE'] === 'Y' || $e['PROPERTY_MF_IS_REDIRECT_VALUE'] === '1'))
	{
		$canon = trim((string)($e['PROPERTY_MF_CANONICAL_CODE_VALUE'] ?? ''));
		if ($canon !== '')
		{
			LocalRedirect("/products/" . $canon . "/", true, "301 Moved Permanently");
			require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
			exit;
		}
	}
}

$mfPlainText = static function (string $html): string {
	$plain = trim((string)html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	return preg_replace('~\s+~u', ' ', $plain) ?? $plain;
};

$mfRenderHtmlField = static function (string $value, string $type): string {
	if (trim($value) === '')
	{
		return '';
	}
	return strtolower(trim($type)) === 'html' ? $value : '<p>' . nl2br(htmlspecialcharsbx($value)) . '</p>';
};

$mfBriefDescription = $mfPlainText($mfPreviewText);
if ($mfBriefDescription === '')
{
	$mfBriefDescription = $mfPlainText($mfDetailText);
}
if ($mfBriefDescription !== '' && mb_strlen($mfBriefDescription) > 320)
{
	$mfBriefDescription = rtrim(mb_substr($mfBriefDescription, 0, 320), " \t\n\r\0\x0B.,;:") . '...';
}

$mfFullDescriptionParts = [];
if (trim($mfPreviewText) !== '')
{
	$mfFullDescriptionParts[] = $mfRenderHtmlField($mfPreviewText, $mfPreviewTextType);
}
if (trim($mfDetailText) !== '')
{
	$detailHtml = $mfRenderHtmlField($mfDetailText, $mfDetailTextType);
	if ($detailHtml !== '' && !in_array($detailHtml, $mfFullDescriptionParts, true))
	{
		$mfFullDescriptionParts[] = $detailHtml;
	}
}
$mfFullDescriptionHtml = implode("\n", array_filter($mfFullDescriptionParts, static fn($v) => trim((string)$v) !== ''));
$mfMinPriceValue = null;
$mfMinPriceText = '';

$arParams = [
    "IBLOCK_TYPE" => "catalog",
    "IBLOCK_ID" => $IBLOCK_ID,
    "ELEMENT_CODE" => $elementCode,
    
    // URL шаблоны - товары без категории в URL
    "SECTION_URL" => "/products/category/#SECTION_CODE#/",
    "DETAIL_URL" => "/products/#ELEMENT_CODE#/",
    
    // Шаблон
    "TEMPLATE_THEME" => "blue",
    "USE_PRICE_COUNT" => "Y",
    "SHOW_PRICE_COUNT" => 1,
    
    // Свойства для показа
    "PROPERTY_CODE" => array(
		"CML2_ARTICLE",
		"MF_BRAND",
		"MF_ARTICLE_NORM",
		"MF_BRAND_NORM",
		"MF_EXT_IMAGES",
    ),
    
    // Корзина
    "USE_PRODUCT_QUANTITY" => "Y",
    // Required for Bitrix templates on PHP 8 (expects arrays)
    "ADD_TO_BASKET_ACTION" => array("ADD"),
    "ADD_TO_BASKET_ACTION_PRIMARY" => array("ADD"),
    "ADD_SECTIONS_CHAIN" => "Y",
    "ADD_ELEMENT_CHAIN" => "Y",
    
    // Цены
    "PRICE_CODE" => array("BASE"),
    "USE_MAIN_ELEMENT_SECTION" => "Y",
    
    // SEO
    "SET_TITLE" => "Y",
    "SET_BROWSER_TITLE" => "Y",
    "SET_META_KEYWORDS" => "Y",
    "SET_META_DESCRIPTION" => "Y",
    "SET_STATUS_404" => "Y",

    // Title is already shown by site template (<h1> via ShowTitle()).
    // Disable component's internal H1 to avoid duplicate titles.
    "DISPLAY_NAME" => "N",
    
    // Картинки
    "DETAIL_PICTURE_MODE" => array("POPUP"),
    "ADD_DETAIL_TO_SLIDER" => "Y",

    // Disable "Gifts" blocks (sale.products.gift / sale.gift.main.products).
    "USE_GIFTS_DETAIL" => "N",
    "USE_GIFTS_MAIN_PR_SECTION_LIST" => "N",
    // Some templates/components use this flag for section gifts too.
    "USE_GIFTS_SECTION" => "N",
    
    // Кэширование
	// IMPORTANT: price/availability are dynamic (per-store RAW price + markup, stocks).
	// Disable component cache to reflect updates immediately after imports/markup edits.
    "CACHE_TYPE" => "N",
    "CACHE_TIME" => "0",
];

$APPLICATION->IncludeComponent(
    "bitrix:catalog.element",
    "", // или ваш шаблон
    $arParams,
    false
);

// Replace the component price with dynamic min-store price (RAW+markup; optional wholesale -10%).
if ($elementId > 0 && function_exists('mf_min_price_from_available_stores'))
{
	[$minP] = mf_min_price_from_available_stores($elementId);
	if ($minP !== null && (float)$minP > 0)
	{
		$minP = (float)$minP;
		if (function_exists('mf_user_is_wholesale') && mf_user_is_wholesale())
		{
			$minP = round($minP * 0.9, 2);
		}
		$mfMinPriceValue = $minP;
		$mfMinPriceText = number_format($minP, 2, '.', ' ') . ' &#8381;';
		$minPPrint = number_format($minP, 2, '.', ' ') . ' &#8381;';
		?>
		<script>
		(function(){
			var price = "<?=CUtil::JSEscape($minPPrint)?>";
			var el = document.querySelector(".product-item-detail-price-current[data-entity='panel-price'], .product-item-detail-price-current");
			if (el) el.innerHTML = price;
			var meta = document.querySelector("meta[itemprop='price']");
			if (meta) meta.setAttribute("content", "<?=CUtil::JSEscape((string)$minP)?>");
		})();
		</script>
		<?php
	}
}

$mfStockTabHtml = '';
$mfAnalogsTabHtml = '';

// Show store availability block (our supplier stock updates write per-store amounts).
if ($elementId > 0 && CModule::IncludeModule("catalog"))
{
	$storeAmounts = [];
	$rsAmt = CCatalogStoreProduct::GetList(
		[],
		['PRODUCT_ID' => $elementId],
		false,
		false,
		['STORE_ID', 'AMOUNT']
	);
	while ($r = $rsAmt->Fetch())
	{
		$sid = (int)$r['STORE_ID'];
		if ($sid <= 0) continue;
		$storeAmounts[$sid] = (float)$r['AMOUNT'];
	}

	if (!empty($storeAmounts))
	{
		$storeIds = array_keys($storeAmounts);
		$stores = [];
		$rsStore = CCatalogStore::GetList(
			['SORT' => 'ASC', 'ID' => 'ASC'],
			['ID' => $storeIds, 'ACTIVE' => 'Y'],
			false,
			false,
			['ID', 'TITLE', 'ADDRESS', 'XML_ID', 'CODE']
		);
		while ($s = $rsStore->Fetch())
		{
			$id = (int)$s['ID'];
			if ($id <= 0) continue;
			$stores[$id] = $s;
		}

		// If some stores are inactive/missing, still show them by ID.
		foreach ($storeIds as $sid)
		{
			if (!isset($stores[$sid]))
			{
				$stores[$sid] = ['ID' => $sid, 'TITLE' => 'Склад #' . $sid, 'ADDRESS' => '', 'XML_ID' => '', 'CODE' => ''];
			}
		}

		ob_start();
		?>
		<div class="mf-detail-stock-wrap">
			<div class="table-responsive">
				<table class="table table-sm table-striped mb-0 mf-detail-stock-table">
					<thead>
					<tr>
						<th>Склад</th>
						<th class="text-right">Цена</th>
						<th class="text-right">Остаток</th>
						<th class="text-right"></th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ($stores as $sid => $s): ?>
						<?php $amt = (float)($storeAmounts[$sid] ?? 0); ?>
						<?php
						$storePrice = null;
						if (function_exists('mf_calc_store_price') && $amt > 0)
						{
							$storePrice = mf_calc_store_price($elementId, (int)$sid);
							if ($storePrice !== null && function_exists('mf_user_is_wholesale') && mf_user_is_wholesale())
							{
								$storePrice = round((float)$storePrice * 0.9, 2);
							}
						}
						?>
						<tr>
							<td>
								<?=htmlspecialcharsbx((string)($s['TITLE'] ?? ('Склад #' . $sid)))?>
							</td>
							<td class="text-right">
								<?php if ($storePrice !== null): ?>
									<?=htmlspecialcharsbx(number_format((float)$storePrice, 2, '.', ' '))?> &#8381;
								<?php else: ?>
									—
								<?php endif; ?>
							</td>
							<td class="text-right">
								<?=htmlspecialcharsbx((string)($amt))?>
							</td>
							<td class="text-right">
								<?php if ($amt > 0 && $storePrice !== null): ?>
									<button
										type="button"
										class="btn btn-sm btn-warning js-mf-add-store"
										data-product-id="<?= (int)$elementId ?>"
										data-store-id="<?= (int)$sid ?>"
									>В корзину</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
		$mfStockTabHtml = trim((string)ob_get_clean());
	}
}

// === Analogs (from HL mf_product_analogs) ===
if ($elementId > 0)
{
	$analogsLib = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_analogs.php';
	if (is_file($analogsLib))
	{
		require_once $analogsLib;
	}

	if (function_exists('mf_analogs_ids_for_product'))
	{
		$analogIds = function_exists('mf_analogs_related_ids_for_product')
			? mf_analogs_related_ids_for_product($elementId, 24)
			: mf_analogs_ids_for_product($elementId, 24);
		if (!empty($analogIds))
		{
			$analogMeta = function_exists('mf_analogs_meta_map_for_product')
				? mf_analogs_meta_map_for_product($elementId, $analogIds)
				: [];
			CModule::IncludeModule("iblock");
			$analogRows = [];
			$rs = CIBlockElement::GetList(
				['NAME' => 'ASC', 'ID' => 'ASC'],
				[
					'IBLOCK_ID' => $IBLOCK_ID,
					'ID' => $analogIds,
					'ACTIVE' => 'Y',
				],
				false,
				false,
				['ID', 'NAME', 'CODE', 'PROPERTY_CML2_ARTICLE', 'PROPERTY_MF_BRAND']
			);
			while ($r = $rs->Fetch())
			{
				$id = (int)($r['ID'] ?? 0);
				if ($id > 0)
				{
					$analogRows[$id] = $r;
				}
			}

			ob_start();
			?>
			<div class="mf-product-analogs">
				<div class="list-group mf-detail-analogs-list">
					<?php foreach ($analogIds as $aid): ?>
						<?php $r = $analogRows[$aid] ?? null; ?>
						<?php if (!$r) continue; ?>
						<?php
						$code = trim((string)($r['CODE'] ?? ''));
						$url = ($code !== '' ? '/products/' . $code . '/' : '#');
						$brand = trim((string)($r['PROPERTY_MF_BRAND_VALUE'] ?? ''));
						$article = trim((string)($r['PROPERTY_CML2_ARTICLE_VALUE'] ?? ''));
						$m = $analogMeta[(int)$aid] ?? null;
						$mStock = is_array($m) ? ($m['stock'] ?? null) : null;
						$mPrice = is_array($m) ? ($m['price'] ?? null) : null;
						?>
						<a class="list-group-item list-group-item-action" href="<?=htmlspecialcharsbx($url)?>">
							<div class="d-flex w-100 justify-content-between">
								<div>
									<strong><?=htmlspecialcharsbx((string)($r['NAME'] ?? ''))?></strong>
									<?php if ($brand !== '' || $article !== ''): ?>
										<div class="text-muted" style="font-size:13px;">
											<?php if ($brand !== ''): ?>Бренд: <?=htmlspecialcharsbx($brand)?><?php endif; ?>
											<?php if ($brand !== '' && $article !== ''): ?> · <?php endif; ?>
											<?php if ($article !== ''): ?>Артикул: <?=htmlspecialcharsbx($article)?><?php endif; ?>
										</div>
									<?php endif; ?>
									<?php if ($mStock !== null || $mPrice !== null): ?>
										<div class="text-muted" style="font-size:13px;">
											<?php if ($mStock !== null): ?>Остаток: <?=htmlspecialcharsbx((string)$mStock)?><?php endif; ?>
											<?php if ($mStock !== null && $mPrice !== null): ?> · <?php endif; ?>
											<?php if ($mPrice !== null): ?>Цена: <?=htmlspecialcharsbx(number_format((float)$mPrice, 2, '.', ' '))?> &#8381;<?php endif; ?>
										</div>
									<?php endif; ?>
								</div>
								<small class="text-muted">ID <?= (int)$aid ?></small>
							</div>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
			$mfAnalogsTabHtml = trim((string)ob_get_clean());
		}
	}
}

?>
	<div id="mf-detail-shell" class="mf-detail-shell" hidden>
		<?php if ($mfBriefDescription !== ''): ?>
			<div class="mf-detail-shell__brief"><?=htmlspecialcharsbx($mfBriefDescription)?></div>
		<?php endif; ?>

		<?php if ($mfMinPriceText !== ''): ?>
			<div class="mf-detail-shell__min-price">От <span><?=$mfMinPriceText?></span></div>
		<?php endif; ?>

		<?php if ($mfBrand !== '' || $mfArticle !== ''): ?>
			<div class="mf-product-meta mf-product-meta--detail" aria-label="Бренд и артикул">
				<?php if ($mfBrand !== ''): ?>
					<div class="mf-product-meta__item">
						<span class="mf-product-meta__label">Бренд:</span>
						<span class="mf-product-meta__value"><?=htmlspecialcharsbx($mfBrand)?></span>
					</div>
				<?php endif; ?>
				<?php if ($mfArticle !== ''): ?>
					<div class="mf-product-meta__item">
						<span class="mf-product-meta__label">Артикул:</span>
						<span class="mf-product-meta__value"><?=htmlspecialcharsbx($mfArticle)?></span>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="mf-detail-tabs" data-mf-detail-tabs>
			<div class="mf-detail-tabs__nav" role="tablist" aria-label="Информация о товаре">
				<button type="button" class="mf-detail-tabs__btn is-active" data-tab-target="stock" role="tab" aria-selected="true">Склад</button>
				<button type="button" class="mf-detail-tabs__btn" data-tab-target="analogs" role="tab" aria-selected="false">Аналоги</button>
				<button type="button" class="mf-detail-tabs__btn" data-tab-target="description" role="tab" aria-selected="false">Описание</button>
			</div>
			<div class="mf-detail-tabs__content">
				<div class="mf-detail-tabs__pane is-active" data-tab-pane="stock">
					<?php if ($mfStockTabHtml !== ''): ?>
						<?=$mfStockTabHtml?>
					<?php else: ?>
						<div class="mf-detail-tabs__empty">Нет данных по складам.</div>
					<?php endif; ?>
				</div>
				<div class="mf-detail-tabs__pane" data-tab-pane="analogs" hidden>
					<?php if ($mfAnalogsTabHtml !== ''): ?>
						<?=$mfAnalogsTabHtml?>
					<?php else: ?>
						<div class="mf-detail-tabs__empty">Аналоги не найдены.</div>
					<?php endif; ?>
				</div>
				<div class="mf-detail-tabs__pane mf-detail-tabs__pane--description" data-tab-pane="description" hidden>
					<?php if ($mfFullDescriptionHtml !== ''): ?>
						<?=$mfFullDescriptionHtml?>
					<?php else: ?>
						<div class="mf-detail-tabs__empty">Описание отсутствует.</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function(){
		function addToBasket(btn){
			var pid = btn.getAttribute('data-product-id');
			var sid = btn.getAttribute('data-store-id');
			if(!pid || !sid) return;
			btn.disabled = true;
			fetch('/ajax/mf_add_to_basket_store.php?productId='+encodeURIComponent(pid)+'&storeId='+encodeURIComponent(sid)+'&qty=1', {
				credentials: 'same-origin'
			}).then(function(r){ return r.json(); }).then(function(data){
				if(!data || !data.ok){
					throw new Error((data && data.error) ? data.error : 'Ошибка');
				}
				window.location.href = '/personal/cart/';
			}).catch(function(e){
				alert(e && e.message ? e.message : 'Ошибка');
				btn.disabled = false;
			});
		}

		function initDetailTabs(scope){
			if (!scope || scope.__mfTabsInited) return;
			scope.__mfTabsInited = true;
			scope.addEventListener('click', function(e){
				var btn = e.target && e.target.closest ? e.target.closest('[data-tab-target]') : null;
				if (!btn) return;
				var target = btn.getAttribute('data-tab-target') || '';
				if (!target) return;
				var buttons = scope.querySelectorAll('[data-tab-target]');
				var panes = scope.querySelectorAll('[data-tab-pane]');
				for (var i = 0; i < buttons.length; i++){
					var isActiveBtn = buttons[i] === btn;
					buttons[i].classList.toggle('is-active', isActiveBtn);
					buttons[i].setAttribute('aria-selected', isActiveBtn ? 'true' : 'false');
				}
				for (var j = 0; j < panes.length; j++){
					var isActivePane = panes[j].getAttribute('data-tab-pane') === target;
					panes[j].classList.toggle('is-active', isActivePane);
					panes[j].hidden = !isActivePane;
				}
			});
		}

		function mountDetailShell(){
			var shell = document.getElementById('mf-detail-shell');
			if (!shell) return;
			var rightCol = document.querySelector('.mf-shop--detail .bx-catalog-element > .container-fluid > .row:first-child > [class*="col-"]:nth-child(2)');
			if (!rightCol) return;
			if (shell.parentNode !== rightCol){
				rightCol.insertBefore(shell, rightCol.firstChild);
			}
			shell.hidden = false;
			initDetailTabs(shell.querySelector('[data-mf-detail-tabs]'));

			var tabsRow = rightCol.parentNode ? rightCol.parentNode.querySelector('[id$="_tabs"]') : null;
			if (tabsRow && tabsRow.parentNode) {
				var row1 = tabsRow.closest('.row');
				if (row1) row1.style.display = 'none';
			}
			var tabsContainers = rightCol.parentNode ? rightCol.parentNode.querySelector('[id$="_tab_containers"]') : null;
			if (tabsContainers) {
				var row2 = tabsContainers.closest('.row');
				if (row2) row2.style.display = 'none';
			}
		}

		document.addEventListener('click', function(e){
			var t = e.target;
			if(t && t.classList && t.classList.contains('js-mf-add-store')){
				e.preventDefault();
				addToBasket(t);
			}
		});

		if (document.readyState === 'loading'){
			document.addEventListener('DOMContentLoaded', mountDetailShell);
		} else {
			mountDetailShell();
		}
	})();
	</script>
	</section>
</div>
<?php

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");

