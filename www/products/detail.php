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
$mfElementName = '';
$mfBrand = '';
$mfArticle = '';
$mfOem = '';
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
			'PROPERTY_OEM',
		]
	)->Fetch();

	if ($e && isset($e['ID']))
	{
		$elementId = (int)$e['ID'];
		$mfElementName = trim((string)($e['NAME'] ?? ''));
		$mfBrand = trim((string)($e['PROPERTY_MF_BRAND_VALUE'] ?? ''));
		$mfArticle = trim((string)($e['PROPERTY_CML2_ARTICLE_VALUE'] ?? ''));
		$mfOem = trim((string)($e['PROPERTY_OEM_VALUE'] ?? ''));
		$mfPreviewText = (string)($e['PREVIEW_TEXT'] ?? '');
		$mfPreviewTextType = (string)($e['PREVIEW_TEXT_TYPE'] ?? 'text');
		$mfDetailText = (string)($e['DETAIL_TEXT'] ?? '');
		$mfDetailTextType = (string)($e['DETAIL_TEXT_TYPE'] ?? 'text');
		if (function_exists('mf_catalog_strip_stock_disclaimer'))
		{
			$mfPreviewText = mf_catalog_strip_stock_disclaimer($mfPreviewText);
			$mfDetailText = mf_catalog_strip_stock_disclaimer($mfDetailText);
		}
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

// Верхний блок: форматированный анонс (PREVIEW_TEXT); если анонса нет — короткая выжимка из детального текста (без полного HTML, чтобы не дублировать вкладку «Описание»).
$mfBriefDescriptionHtml = '';
if (trim($mfPreviewText) !== '')
{
	$mfBriefDescriptionHtml = $mfRenderHtmlField($mfPreviewText, $mfPreviewTextType);
}
elseif (trim($mfDetailText) !== '')
{
	$plainBrief = $mfPlainText($mfDetailText);
	if ($plainBrief !== '' && mb_strlen($plainBrief) > 320)
	{
		$plainBrief = rtrim(mb_substr($plainBrief, 0, 320), " \t\n\r\0\x0B.,;:") . '…';
	}
	if ($plainBrief !== '')
	{
		$mfBriefDescriptionHtml = '<p>' . nl2br(htmlspecialcharsbx($plainBrief)) . '</p>';
	}
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
		"OEM",
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

// Replace the component price with storefront display price (min across all mapped stores, same as listings).
if ($elementId > 0 && function_exists('mf_catalog_listing_display_price'))
{
	$minP = mf_catalog_listing_display_price($elementId);
	if ($minP !== null && (float)$minP > 0)
	{
		$minP = (float)$minP;
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
	elseif (
		function_exists('mf_catalog_use_bitrix_base_price_fallback')
		&& !mf_catalog_use_bitrix_base_price_fallback()
	)
	{
		?>
		<script>
		(function(){
			var el = document.querySelector(".product-item-detail-price-current[data-entity='panel-price'], .product-item-detail-price-current");
			if (el) el.innerHTML = "Цена по запросу";
			var meta = document.querySelector("meta[itemprop='price']");
			if (meta) meta.setAttribute("content", "");
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
	$clusterIds = function_exists('mf_catalog_product_cluster_ids')
		? mf_catalog_product_cluster_ids($elementId)
		: [$elementId];
	foreach ($clusterIds as $cid)
	{
		$rsAmt = CCatalogStoreProduct::GetList(
			[],
			['PRODUCT_ID' => (int)$cid],
			false,
			false,
			['STORE_ID', 'AMOUNT']
		);
		while ($r = $rsAmt->Fetch())
		{
			$sid = (int)$r['STORE_ID'];
			if ($sid <= 0) continue;
			$storeAmounts[$sid] = ($storeAmounts[$sid] ?? 0.0) + (float)$r['AMOUNT'];
		}
	}

	// Таб «Склад» на detail.php не использует mf_product_search_card_stores — дублируем логику ожидания из заказов поставщику.
	// Если по motor_force_internal нет строки в b_catalog_store_product, но в mf_supplier_order_line есть matched — добавляем склад с нулём.
	if ($elementId > 0
		&& function_exists('mf_supplier_orders_internal_store_id')
		&& function_exists('mf_supplier_orders_cluster_amount_on_store')
		&& function_exists('mf_supplier_orders_pending_arrival_for_product'))
	{
		$mfIntSidRow = mf_supplier_orders_internal_store_id();
		if ($mfIntSidRow > 0 && mf_supplier_orders_cluster_amount_on_store($elementId, $mfIntSidRow) <= 1e-9)
		{
			$mfPenRow = mf_supplier_orders_pending_arrival_for_product($elementId);
			if (is_array($mfPenRow) && (float)($mfPenRow['qty'] ?? 0) > 1e-9 && !isset($storeAmounts[$mfIntSidRow]))
			{
				$storeAmounts[$mfIntSidRow] = 0.0;
			}
		}
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

		$mfOrderedStoreIds = array_keys($storeAmounts);
		usort($mfOrderedStoreIds, static function ($a, $b) use ($elementId, $storeAmounts, $stores) {
			$a = (int)$a;
			$b = (int)$b;
			$sa = $stores[$a] ?? null;
			$sb = $stores[$b] ?? null;
			$codeA = is_array($sa) ? mb_strtoupper(trim((string)($sa['CODE'] ?? ''))) : '';
			$codeB = is_array($sb) ? mb_strtoupper(trim((string)($sb['CODE'] ?? ''))) : '';
			$xmlA = is_array($sa) ? mb_strtoupper(trim((string)($sa['XML_ID'] ?? ''))) : '';
			$xmlB = is_array($sb) ? mb_strtoupper(trim((string)($sb['XML_ID'] ?? ''))) : '';
			$aInt = $codeA === 'MOTOR_FORCE_INTERNAL' || ($xmlA !== '' && mb_strpos($xmlA, 'MOTOR_FORCE_INTERNAL') !== false);
			$bInt = $codeB === 'MOTOR_FORCE_INTERNAL' || ($xmlB !== '' && mb_strpos($xmlB, 'MOTOR_FORCE_INTERNAL') !== false);
			if ($aInt !== $bInt)
			{
				return $bInt <=> $aInt;
			}
			$amtA = (float)($storeAmounts[$a] ?? 0);
			$amtB = (float)($storeAmounts[$b] ?? 0);
			$pa = null;
			$pb = null;
			if ($amtA > 0 && function_exists('mf_ep_display_price_for_store'))
			{
				$pa = mf_ep_display_price_for_store($elementId, $a, 1.0);
			}
			if ($amtB > 0 && function_exists('mf_ep_display_price_for_store'))
			{
				$pb = mf_ep_display_price_for_store($elementId, $b, 1.0);
			}
			$fpa = (float)($pa ?? 0);
			$fpb = (float)($pb ?? 0);
			if ($fpa > 0 && $fpb > 0 && abs($fpa - $fpb) > 1e-9)
			{
				return $fpa <=> $fpb;
			}

			return $a <=> $b;
		});

		ob_start();
		global $USER;
		$mfDetReqN = '';
		$mfDetReqE = '';
		$mfDetReqLocked = false;
		if (is_object($USER) && method_exists($USER, 'IsAuthorized') && $USER->IsAuthorized())
		{
			$mfDetReqLocked = true;
			$mfDetReqN = trim((string)$USER->GetFirstName() . ' ' . (string)$USER->GetLastName());
			if ($mfDetReqN === '')
			{
				$mfDetReqN = trim((string)$USER->GetLogin());
			}
			$mfDetReqE = trim((string)$USER->GetEmail());
		}
		$mfDetProductUrl = ($elementCode !== '' ? '/products/' . rawurlencode($elementCode) . '/' : '/');
		$mfDetNameForReq = $mfElementName !== '' ? $mfElementName : ('Товар #' . (int)$elementId);
		?>
		<div class="mf-detail-stock-wrap">
			<div class="table-responsive">
				<table class="table table-sm table-striped mb-0 mf-detail-stock-table">
					<thead>
					<tr>
						<th>Склад</th>
						<th>Срок доставки</th>
						<th class="text-center mf-detail-stock-table__spb-col">Доставка</th>
						<th class="text-right">Цена</th>
						<th class="text-right">Остаток</th>
						<th class="text-right"></th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ($mfOrderedStoreIds as $sid): ?>
						<?php $s = $stores[$sid] ?? []; ?>
						<?php $amt = (float)($storeAmounts[$sid] ?? 0); ?>
						<?php
						// Цена по складу из RAW+наценка — показываем и при нулевом остатке (внешний прайс мог обновить только цену).
						$storePrice = null;
						if (function_exists('mf_ep_display_price_for_store'))
						{
							$storePrice = mf_ep_display_price_for_store($elementId, (int)$sid, 1.0);
						}
						?>
						<tr>
							<td>
								<?=htmlspecialcharsbx((string)($s['TITLE'] ?? ('Склад #' . $sid)))?>
							</td>
							<td class="mf-detail-stock-table__delivery">
								<?=htmlspecialcharsbx(function_exists('mf_store_delivery_term') ? mf_store_delivery_term((int)$sid) : '—')?>
							</td>
							<td class="text-center mf-detail-stock-table__spb">
								<?php
								if (function_exists('mf_store_delivery_spb_icon_html'))
								{
									echo mf_store_delivery_spb_icon_html((int)$sid, (int)$elementId);
								}
								else
								{
									echo '—';
								}
								?>
							</td>
							<td class="text-right">
								<?php if ($storePrice !== null): ?>
									<?=htmlspecialcharsbx(number_format((float)$storePrice, 2, '.', ' '))?> &#8381;
								<?php else: ?>
									—
								<?php endif; ?>
							</td>
							<td class="text-right mf-detail-stock-table__qty">
								<?php
								$mfCodeRow = mb_strtoupper(trim((string)($s['CODE'] ?? '')));
								$mfXmlRow = mb_strtoupper(trim((string)($s['XML_ID'] ?? '')));
								$mfIsInternalSku = ($mfCodeRow === 'MOTOR_FORCE_INTERNAL'
									|| ($mfXmlRow !== '' && mb_strpos($mfXmlRow, 'MOTOR_FORCE_INTERNAL') !== false));
								$mfPendingTxt = '';
								$mfIsExtRow = function_exists('mf_ep_store_is_external_warehouse') && mf_ep_store_is_external_warehouse((int)$sid);
								if ($mfIsInternalSku && $amt <= 1e-9 && function_exists('mf_supplier_orders_pending_arrival_for_product'))
								{
									$mfPr = mf_supplier_orders_pending_arrival_for_product($elementId);
									if (is_array($mfPr) && trim((string)($mfPr['label'] ?? '')) !== '')
									{
										$mfPendingTxt = (string)$mfPr['label'];
									}
								}
								?>
								<?php if ($mfIsExtRow && $amt <= 1e-9): ?>
									Под заказ
								<?php elseif ($mfPendingTxt !== ''): ?>
									<?=htmlspecialcharsbx($mfPendingTxt)?>
								<?php else: ?>
									<?=htmlspecialcharsbx((string)($amt))?>
								<?php endif; ?>
							</td>
							<td class="text-right">
								<?php
								$mfRowExternal = function_exists('mf_ep_store_is_external_warehouse') && mf_ep_store_is_external_warehouse((int)$sid);
								$mfNoPriceRow = ($storePrice === null || (float)$storePrice <= 0);
								$mfPodZakazRow = $mfRowExternal && $amt <= 1e-9;
								$mfRequestPriceRow = $mfPodZakazRow || $mfNoPriceRow;
								$mfShowCartBtn = !$mfRequestPriceRow
									&& $storePrice !== null
									&& (float)$storePrice > 0
									&& ($amt > 0 || $mfRowExternal);
								?>
								<?php if ($mfRequestPriceRow): ?>
									<button
										type="button"
										class="btn btn-sm btn-warning js-mf-request-price-global"
										data-product-id="<?= (int)$elementId ?>"
										data-product-name="<?=htmlspecialcharsbx($mfDetNameForReq)?>"
										data-product-url="<?=htmlspecialcharsbx($mfDetProductUrl)?>"
										data-user-name="<?=htmlspecialcharsbx($mfDetReqN)?>"
										data-user-email="<?=htmlspecialcharsbx($mfDetReqE)?>"
										data-user-locked="<?=$mfDetReqLocked ? '1' : '0'?>"
									>Запросить цену</button>
								<?php elseif ($mfShowCartBtn): ?>
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
			while ($r = $rs->Fetch())
			{
				$id = (int)($r['ID'] ?? 0);
				if ($id > 0)
				{
					$analogRows[$id] = $r;
				}
			}

			$mfCardLib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/bitrix/php_interface/include/mf_product_search_card.php';
			if (is_file($mfCardLib))
			{
				require_once $mfCardLib;
			}

			global $USER;
			$mfAnalogReqName = '';
			$mfAnalogReqEmail = '';
			$mfAnalogReqLocked = is_object($USER) && method_exists($USER, 'IsAuthorized') && $USER->IsAuthorized();
			if ($mfAnalogReqLocked)
			{
				$mfAnalogReqName = trim((string)$USER->GetFirstName() . ' ' . (string)$USER->GetLastName());
				if ($mfAnalogReqName === '')
				{
					$mfAnalogReqName = trim((string)$USER->GetLogin());
				}
				$mfAnalogReqEmail = trim((string)$USER->GetEmail());
			}

			ob_start();
			?>
			<div class="mf-product-analogs">
				<div class="mf-search-analogs">
					<?php foreach ($analogIds as $aid): ?>
						<?php $r = $analogRows[$aid] ?? null; ?>
						<?php if (!$r || !function_exists('mf_product_search_card_render')) continue; ?>
						<?php
						$analogId = (int)$aid;
						$code = trim((string)($r['CODE'] ?? ''));
						$url = ($code !== '' ? '/products/' . $code . '/' : '#');
						$name = (string)($r['NAME'] ?? '');
						$titleHtml = htmlspecialcharsbx($name);
						$anBrand = trim((string)($r['PROPERTY_MF_BRAND_VALUE'] ?? ($r['PROPERTY_MF_BRAND_NORM_VALUE'] ?? '')));
						$anArticle = trim((string)($r['PROPERTY_CML2_ARTICLE_VALUE'] ?? ''));
						$anOem = trim((string)($r['PROPERTY_OEM_VALUE'] ?? ''));
						$plainName = trim(html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
						if ($plainName === '')
						{
							$plainName = 'Товар #' . $analogId;
						}
						mf_product_search_card_render([
							'id' => $analogId,
							'url' => $url,
							'code' => $code,
							'title_html' => $titleHtml,
							'brand' => $anBrand,
							'article' => $anArticle,
							'oem' => $anOem,
							'product_name_plain' => $plainName,
							'req_user_name' => $mfAnalogReqName,
							'req_user_email' => $mfAnalogReqEmail,
							'req_user_locked' => $mfAnalogReqLocked,
						]);
						?>
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
		<?php if ($mfMinPriceText !== ''): ?>
			<div class="mf-detail-shell__min-price">От <span><?=$mfMinPriceText?></span></div>
		<?php endif; ?>

		<?php if ($mfBrand !== '' || $mfArticle !== '' || $mfOem !== ''): ?>
			<div class="mf-product-meta mf-product-meta--detail" aria-label="Бренд, артикул и OEM">
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
				<?php if ($mfOem !== ''): ?>
					<div class="mf-product-meta__item">
						<span class="mf-product-meta__label">OEM:</span>
						<span class="mf-product-meta__value"><?=htmlspecialcharsbx($mfOem)?></span>
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

		<?php if ($mfBriefDescriptionHtml !== ''): ?>
			<div class="mf-detail-shell__brief mf-detail-shell__brief--below-tabs" role="region" aria-label="Краткое описание"><?=$mfBriefDescriptionHtml?></div>
		<?php endif; ?>
	</div>

	<script>
	(function(){
		function addToBasket(btn){
			var pid = btn.getAttribute('data-product-id');
			var sid = btn.getAttribute('data-store-id');
			if(!pid || !sid) return;
			var qty = '1';
			var row = btn.closest ? btn.closest('tr') : null;
			var qtyWrap = row && row.querySelector ? row.querySelector('.mf-search-qty') : null;
			var qtyInput = qtyWrap && qtyWrap.querySelector ? qtyWrap.querySelector('.js-mf-qty-input') : null;
			if (qtyInput) {
				var maxQ = 0;
				if (qtyWrap && qtyWrap.getAttribute) {
					maxQ = parseFloat(qtyWrap.getAttribute('data-max-qty') || '0', 10);
					if (!isFinite(maxQ)) maxQ = 0;
				}
				var q = parseInt(qtyInput.value || '1', 10);
				if (!isFinite(q) || q < 1) q = 1;
				if (maxQ > 0 && q > maxQ) q = Math.floor(maxQ);
				qtyInput.value = String(q);
				qty = String(q);
			}
			btn.disabled = true;
			fetch('/ajax/mf_add_to_basket_store.php?productId='+encodeURIComponent(pid)+'&storeId='+encodeURIComponent(sid)+'&qty='+encodeURIComponent(qty), {
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
			var minusBtn = e.target && e.target.closest ? e.target.closest('.js-mf-qty-minus') : null;
			if (minusBtn) {
				e.preventDefault();
				var mw = minusBtn.closest ? minusBtn.closest('.mf-search-qty') : null;
				var inp = mw ? mw.querySelector('.js-mf-qty-input') : null;
				if (!inp) return;
				var mv = parseInt(inp.value || '1', 10);
				if (!isFinite(mv) || mv < 1) mv = 1;
				inp.value = String(Math.max(1, mv - 1));
				return;
			}
			var plusBtn = e.target && e.target.closest ? e.target.closest('.js-mf-qty-plus') : null;
			if (plusBtn) {
				e.preventDefault();
				var pw = plusBtn.closest ? plusBtn.closest('.mf-search-qty') : null;
				var inp2 = pw ? pw.querySelector('.js-mf-qty-input') : null;
				if (!inp2) return;
				var maxQ = 0;
				if (pw && pw.getAttribute) {
					maxQ = parseFloat(pw.getAttribute('data-max-qty') || '0', 10);
					if (!isFinite(maxQ)) maxQ = 0;
				}
				var pv = parseInt(inp2.value || '1', 10);
				if (!isFinite(pv) || pv < 1) pv = 1;
				var nx = pv + 1;
				if (maxQ > 0 && nx > maxQ) nx = Math.floor(maxQ);
				inp2.value = String(nx);
				return;
			}
			var t = e.target && e.target.closest ? e.target.closest('.js-mf-add-store') : null;
			if (t) {
				e.preventDefault();
				addToBasket(t);
			}
		});
		document.addEventListener('input', function(e){
			var input = e.target && e.target.classList && e.target.classList.contains('js-mf-qty-input') ? e.target : null;
			if (!input) return;
			var cleaned = String(input.value || '').replace(/[^\d]/g, '');
			var val = parseInt(cleaned || '1', 10);
			if (!isFinite(val) || val < 1) val = 1;
			var wrap = input.closest ? input.closest('.mf-search-qty') : null;
			var maxQ = 0;
			if (wrap && wrap.getAttribute) {
				maxQ = parseFloat(wrap.getAttribute('data-max-qty') || '0', 10);
				if (!isFinite(maxQ)) maxQ = 0;
			}
			if (maxQ > 0 && val > maxQ) val = Math.floor(maxQ);
			input.value = String(val);
		});
		document.addEventListener('blur', function(e){
			var input = e.target && e.target.classList && e.target.classList.contains('js-mf-qty-input') ? e.target : null;
			if (!input) return;
			var val = parseInt(input.value || '1', 10);
			if (!isFinite(val) || val < 1) val = 1;
			input.value = String(val);
		}, true);

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

