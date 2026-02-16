<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Каталог товаров");

// ID вашего каталога
$IBLOCK_ID = 4; // Каталог запчастей Motor Force

// Показываем список категорий
$APPLICATION->IncludeComponent(
    "bitrix:catalog.section.list",
    "",
    array(
        "IBLOCK_TYPE" => "catalog",
        "IBLOCK_ID" => $IBLOCK_ID,
        
        // Показываем категории верхнего уровня
        "SECTION_ID" => 0,
        "SECTION_CODE" => "",
        "COUNT_ELEMENTS" => "Y",  // Считать количество товаров
        
        // URL шаблоны
        "SECTION_URL" => "/products/category/#SECTION_CODE#/",
        
        // Отображение
        "TOP_DEPTH" => "1",  // Показывать только категории верхнего уровня
        "VIEW_MODE" => "LIST",  // Режим списка
        "SHOW_PARENT_NAME" => "N",
        
        // Дополнительные поля
        "ADD_SECTIONS_CHAIN" => "Y",
        
        // Кэширование
        "CACHE_TYPE" => "A",
        "CACHE_TIME" => "36000000",
        "CACHE_GROUPS" => "Y",
    ),
    false
);

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");

