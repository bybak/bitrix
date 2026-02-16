<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

// ID вашего каталога
$IBLOCK_ID = 4; // Каталог из 1С

// Получаем код раздела из URL
$sectionCode = $_REQUEST["SECTION_CODE"];

// Получаем информацию о разделе
CModule::IncludeModule("iblock");

$arFilter = array(
    "IBLOCK_ID" => $IBLOCK_ID,
    "CODE" => $sectionCode,
    "GLOBAL_ACTIVE" => "Y"
);

$rsSection = CIBlockSection::GetList(
    array(),
    $arFilter,
    false,
    array("ID", "NAME", "DESCRIPTION")
);

if ($arSection = $rsSection->Fetch()) {
    $APPLICATION->SetTitle($arSection["NAME"]);
    $SECTION_ID = $arSection["ID"];
} else {
    // Раздел не найден - 404
    CHTTP::SetStatus("404 Not Found");
    @define("ERROR_404", "Y");
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
    exit();
}

// Сначала показываем подкатегории (если есть)
$APPLICATION->IncludeComponent(
    "bitrix:catalog.section.list",
    "",
    array(
        "IBLOCK_TYPE" => "catalog",
        "IBLOCK_ID" => $IBLOCK_ID,
        
        // Показываем подразделы текущей категории
        "SECTION_ID" => $SECTION_ID,
        "COUNT_ELEMENTS" => "Y",
        
        // URL
        "SECTION_URL" => "/products/category/#SECTION_CODE#/",
        
        // Отображение
        "TOP_DEPTH" => "1",
        "VIEW_MODE" => "LIST",
        "SHOW_PARENT_NAME" => "N",
        
        // Кэш
        "CACHE_TYPE" => "A",
        "CACHE_TIME" => "36000000",
        "CACHE_GROUPS" => "Y",
        
        "ADD_SECTIONS_CHAIN" => "N",
    ),
    false
);

// Затем показываем товары текущей категории
$arParams = array(
    "IBLOCK_TYPE" => "catalog",
    "IBLOCK_ID" => $IBLOCK_ID,
    
    // ID текущего раздела
    "SECTION_ID" => $SECTION_ID,
    "SECTION_CODE" => $lastCode,
    
    // URL шаблоны - категории с префиксом, товары без
    "SECTION_URL" => "/products/category/#SECTION_CODE#/",
    "DETAIL_URL" => "/products/#ELEMENT_CODE#/",
    
    // Показывать подразделы
    "SHOW_ALL_WO_SECTION" => "N",
    "INCLUDE_SUBSECTIONS" => "Y",
    
    // Количество элементов
    "PAGE_ELEMENT_COUNT" => 30,
    "LINE_ELEMENT_COUNT" => 3,
    
    // Сортировка
    "ELEMENT_SORT_FIELD" => "sort",
    "ELEMENT_SORT_ORDER" => "asc",
    "ELEMENT_SORT_FIELD2" => "name",
    "ELEMENT_SORT_ORDER2" => "asc",
    
    // Свойства
    "PROPERTY_CODE" => array(""),
    
    // Цены
    "PRICE_CODE" => array("BASE"),
    "USE_PRICE_COUNT" => "Y",
    "SHOW_PRICE_COUNT" => 1,
    
    // Корзина
    "USE_PRODUCT_QUANTITY" => "Y",
    "ADD_SECTIONS_CHAIN" => "Y",
    
    // Кэширование
    "CACHE_TYPE" => "A",
    "CACHE_TIME" => "36000000",
    "CACHE_FILTER" => "Y",
    "CACHE_GROUPS" => "Y",
    
    // SEO
    "SET_TITLE" => "Y",
    "SET_BROWSER_TITLE" => "Y",
    "SET_META_KEYWORDS" => "Y",
    "SET_META_DESCRIPTION" => "Y",
    "SET_LAST_MODIFIED" => "N",
    
    // Пагинация
    "PAGER_TEMPLATE" => ".default",
    "DISPLAY_TOP_PAGER" => "N",
    "DISPLAY_BOTTOM_PAGER" => "Y",
    "PAGER_TITLE" => "Товары",
    "PAGER_SHOW_ALWAYS" => "N",
    "PAGER_DESC_NUMBERING" => "N",
    
    // Шаблон
    "TEMPLATE_THEME" => "blue",
    
    // Разделы
    "SECTION_COUNT_ELEMENTS" => "Y",
    "SECTION_TOP_DEPTH" => "1",
    
    // Сообщения
    "MESS_BTN_BUY" => "Купить",
    "MESS_BTN_ADD_TO_BASKET" => "В корзину",
    "MESS_BTN_SUBSCRIBE" => "Подписаться",
    "MESS_BTN_DETAIL" => "Подробнее",
    "MESS_NOT_AVAILABLE" => "Нет в наличии",
);

// Подключаем компонент каталога
$APPLICATION->IncludeComponent(
    "bitrix:catalog.section",
    "",
    $arParams,
    false
);

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");

