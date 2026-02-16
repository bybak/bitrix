<?php
/**
 * Автозаполнение символьных кодов для разделов (категорий) каталога
 * Использование: открыть в браузере или php fill_section_codes.php
 */

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

$APPLICATION->SetTitle("Заполнение символьных кодов разделов");

// ID вашего каталога
$IBLOCK_ID = 4; // Каталог запчастей Motor Force

CModule::IncludeModule("iblock");

echo "<h1>Заполнение символьных кодов разделов (категорий)</h1>";
echo "<p>Инфоблок ID: <strong>$IBLOCK_ID</strong></p>";
echo "<hr>";

// Считаем разделы без кода
$resCount = CIBlockSection::GetList(
    array(),
    array("IBLOCK_ID" => $IBLOCK_ID, "CODE" => false),
    array()
);

echo "<p>Разделов без символьного кода: <strong>{$resCount}</strong></p>";

if ($resCount == 0) {
    echo "<p style='color: green;'>✓ Все разделы уже имеют символьные коды!</p>";
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
    exit;
}

echo "<p>Начинаем обработку...</p>";
echo "<pre>";

// Получаем разделы без кода
$res = CIBlockSection::GetList(
    array("LEFT_MARGIN" => "ASC"),
    array("IBLOCK_ID" => $IBLOCK_ID, "CODE" => false),
    false,
    array("ID", "NAME", "CODE", "DEPTH_LEVEL", "IBLOCK_SECTION_ID")
);

$count = 0;
$bs = new CIBlockSection;

while ($section = $res->Fetch()) {
    // Генерируем код из названия
    $name = $section["NAME"];
    
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
        $exist = CIBlockSection::GetList(
            array(),
            array("IBLOCK_ID" => $IBLOCK_ID, "CODE" => $checkCode),
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
    if ($bs->Update($section["ID"], array("CODE" => $code))) {
        $count++;
        $indent = str_repeat("  ", $section["DEPTH_LEVEL"] - 1);
        echo "{$indent}[{$count}] ID: {$section['ID']} → Code: {$code} (Level: {$section['DEPTH_LEVEL']})\n";
        flush();
        ob_flush();
    } else {
        echo "[ERROR] ID: {$section['ID']} → {$bs->LAST_ERROR}\n";
    }
}

echo "</pre>";
echo "<hr>";
echo "<h2>✓ Готово!</h2>";
echo "<p>Обновлено разделов: <strong>$count</strong></p>";
echo "<p><a href='/products/'>→ Перейти в каталог</a></p>";

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");

