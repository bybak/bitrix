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

// 301 redirect support for duplicate SKUs (redirect elements)
if ($elementCode)
{
	CModule::IncludeModule("iblock");
	$e = CIBlockElement::GetList(
		[],
		['IBLOCK_ID' => $IBLOCK_ID, '=CODE' => $elementCode],
		false,
		false,
		['ID', 'CODE', 'PROPERTY_MF_IS_REDIRECT', 'PROPERTY_MF_CANONICAL_CODE']
	)->Fetch();

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
        0 => "",
        1 => "",
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
    "CACHE_TYPE" => "A",
    "CACHE_TIME" => "36000000",
];

$APPLICATION->IncludeComponent(
    "bitrix:catalog.element",
    "", // или ваш шаблон
    $arParams,
    false
);

?>
	</section>
</div>
<?php

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");

