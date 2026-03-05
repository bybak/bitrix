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

if ($mfBrand !== '' || $mfArticle !== '')
{
	?>
	<div class="mf-product-meta" aria-label="Бренд и артикул">
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
	<?php
}

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

		?>
		<div class="mt-4 mb-4">
			<h2 class="h5 mb-3">Наличие на складах</h2>
			<div class="table-responsive">
				<table class="table table-sm table-striped mb-0">
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
			document.addEventListener('click', function(e){
				var t = e.target;
				if(t && t.classList && t.classList.contains('js-mf-add-store')){
					e.preventDefault();
					addToBasket(t);
				}
			});
		})();
		</script>
		<?php
	}
}

?>
	</section>
</div>
<?php

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");

