<?php
/**
 * Автозаполнение символьных кодов для товаров
 * Использование: php fill_product_codes.php или открыть в браузере
 */

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

$APPLICATION->SetTitle("Заполнение символьных кодов товаров");

// ID вашего каталога
$IBLOCK_ID = 4; // Каталог запчастей Motor Force

CModule::IncludeModule("iblock");

echo "<h1>Заполнение символьных кодов товаров</h1>";
echo "<p>Инфоблок ID: <strong>$IBLOCK_ID</strong></p>";
echo "<hr>";

// Считаем сколько товаров без кода
$resCount = CIBlockElement::GetList(
    array(),
    array("IBLOCK_ID" => $IBLOCK_ID, "CODE" => false),
    array()
);

echo "<p>Товаров без символьного кода: <strong>{$resCount}</strong></p>";

if ($resCount == 0) {
    echo "<p style='color: green;'>✓ Все товары уже имеют символьные коды!</p>";
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
    exit;
}

echo "<p>Начинаем обработку...</p>";
echo "<pre>";

// Получаем товары без кода
$res = CIBlockElement::GetList(
    array("ID" => "ASC"),
    array("IBLOCK_ID" => $IBLOCK_ID, "CODE" => false),
    false,
    array("nPageSize" => 100), // Порциями по 100
    array("ID", "NAME", "CODE")
);

$count = 0;
$el = new CIBlockElement;

while ($item = $res->Fetch()) {
    // Генерируем код из названия или артикула
    $name = $item["NAME"];
    
    // Транслитерация
    $code = CUtil::translit(
        $name,
        "ru",
        array(
            "max_len" => 100,
            "change_case" => "L",
            "replace_space" => "-",
            "replace_other" => "-",
            "delete_repeat_replace" => true,
        )
    );
    
    // Проверяем уникальность
    $checkCode = $code;
    $suffix = 1;
    
    while (true) {
        $exist = CIBlockElement::GetList(
            array(),
            array("IBLOCK_ID" => $IBLOCK_ID, "CODE" => $checkCode),
            false,
            false,
            array("ID")
        )->Fetch();
        
        if (!$exist) {
            $code = $checkCode;
            break;
        }
        
        $checkCode = $code . "-" . $suffix;
        $suffix++;
    }
    
    // Обновляем
    if ($el->Update($item["ID"], array("CODE" => $code))) {
        $count++;
        echo "[{$count}] ID: {$item['ID']} → Code: {$code}\n";
        flush();
        ob_flush();
    } else {
        echo "[ERROR] ID: {$item['ID']} → {$el->LAST_ERROR}\n";
    }
}

echo "</pre>";
echo "<hr>";
echo "<h2>✓ Готово!</h2>";
echo "<p>Обновлено товаров: <strong>$count</strong></p>";
echo "<p><a href='/products/'>→ Перейти в каталог</a></p>";

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");

