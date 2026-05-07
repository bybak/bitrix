<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Каталог из 1С-предприятие");
?>

<?
// Компонент каталога из 1С
$APPLICATION->IncludeComponent(
    "bitrix:catalog.section",
    "",
    array(
        "IBLOCK_TYPE" => "1c_catalog",
        "IBLOCK_ID" => "5",
        "SECTION_ID" => $_REQUEST["SECTION_ID"],
        "SECTION_CODE" => $_REQUEST["SECTION_CODE"],
        "ELEMENT_SORT_FIELD" => "name",
        "ELEMENT_SORT_ORDER" => "asc",
        "PAGE_ELEMENT_COUNT" => "30",
        "LINE_ELEMENT_COUNT" => "3",
        "PROPERTY_CODE" => array(),
        "PRICE_CODE" => array("BASE"),
        "USE_PRICE_COUNT" => "Y",
        "SHOW_PRICE_COUNT" => "1",
        "PRICE_VAT_INCLUDE" => "Y",
        "BASKET_URL" => "/personal/cart/",
        "SECTION_URL" => "/katalog-iz-1s-predpriyatie/#SECTION_CODE#/",
        "DETAIL_URL" => "/katalog-iz-1s-predpriyatie/#SECTION_CODE#/#ELEMENT_CODE#/",
        "CACHE_TYPE" => "A",
        "CACHE_TIME" => "36000000",
        "DISPLAY_TOP_PAGER" => "Y",
        "DISPLAY_BOTTOM_PAGER" => "Y",
        "PAGER_TITLE" => "Товары",
    )
);
?>

<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>
