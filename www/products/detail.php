<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Товары");

// ID вашего каталога (измените на свой: 4 или 5)
$IBLOCK_ID = 4; // Каталог запчастей Motor Force

// Получаем код товара из URL
$elementCode = $_REQUEST["ELEMENT_CODE"];

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
    
    // Картинки
    "DETAIL_PICTURE_MODE" => array("POPUP"),
    "ADD_DETAIL_TO_SLIDER" => "Y",
    
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

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");

