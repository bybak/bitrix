<?php
/**
 * Установка целевого инфоблока для обмена с 1С
 * Загрузите на сервер и откройте в браузере
 */

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Iblock\IblockTable;

Loader::includeModule("iblock");
Loader::includeModule("catalog");

echo "<h1>Настройка целевого каталога для обмена с 1С</h1>";

// Если передан параметр - устанавливаем
if (isset($_GET['set_iblock']) && intval($_GET['set_iblock']) > 0) {
    $iblockId = intval($_GET['set_iblock']);
    
    // Устанавливаем инфоблок для обмена с 1С
    COption::SetOptionString("catalog", "default_iblock_id_1c", $iblockId);
    COption::SetOptionString("sale", "1C_SALE_ACCOUNT_NUMBER_SHOP_PREFIX", "s1");
    
    echo "<div style='background: #d4edda; padding: 15px; margin: 20px 0; border: 1px solid #c3e6cb; border-radius: 5px;'>";
    echo "<h3 style='color: #155724; margin: 0;'>✓ Инфоблок ID=$iblockId установлен для обмена с 1С</h3>";
    echo "<p>Теперь при обмене из 1С товары будут попадать в этот каталог.</p>";
    echo "</div>";
}

// Показываем все каталоги
echo "<h2>Выберите каталог для обмена с 1С:</h2>";

$iblocks = IblockTable::getList([
    'select' => ['ID', 'NAME', 'CODE', 'IBLOCK_TYPE_ID'],
    'order' => ['ID' => 'ASC']
]);

echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
echo "<tr style='background: #007bff; color: white;'>";
echo "<th>ID</th><th>Название</th><th>Код</th><th>Тип</th><th>Каталог?</th><th>Товаров</th><th>Действие</th>";
echo "</tr>";

while ($iblock = $iblocks->fetch()) {
    $isCatalog = CCatalog::GetByID($iblock['ID']);
    
    // Подсчитываем товары
    $elementCount = CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => $iblock['ID']],
        []
    );
    
    echo "<tr>";
    echo "<td><strong>{$iblock['ID']}</strong></td>";
    echo "<td>{$iblock['NAME']}</td>";
    echo "<td>{$iblock['CODE']}</td>";
    echo "<td>{$iblock['IBLOCK_TYPE_ID']}</td>";
    echo "<td>" . ($isCatalog ? "✓ Да" : "✗ Нет") . "</td>";
    echo "<td>" . number_format($elementCount, 0, '.', ' ') . "</td>";
    
    if ($isCatalog) {
        echo "<td><a href='?set_iblock={$iblock['ID']}' style='background: #28a745; color: white; padding: 8px 15px; text-decoration: none; border-radius: 4px;'>Использовать для 1С</a></td>";
    } else {
        echo "<td style='color: #999;'>Не торговый каталог</td>";
    }
    
    echo "</tr>";
}

echo "</table>";

// Показываем текущие настройки
echo "<hr><h2>Текущие настройки:</h2>";

$currentIblock = COption::GetOptionString("catalog", "default_iblock_id_1c", "не установлен");
echo "<p><strong>Инфоблок для обмена с 1С:</strong> $currentIblock</p>";

// Показываем инфоблоки типа 1c_catalog
echo "<h3>Инфоблоки созданные через обмен с 1С:</h3>";

$iblocks1c = IblockTable::getList([
    'filter' => ['IBLOCK_TYPE_ID' => '1c_catalog'],
    'select' => ['ID', 'NAME']
]);

$found1c = false;
while ($ib1c = $iblocks1c->fetch()) {
    $found1c = true;
    echo "<p>→ ID: {$ib1c['ID']} - {$ib1c['NAME']}</p>";
}

if (!$found1c) {
    echo "<p><em>Нет инфоблоков созданных через 1С. При первом обмене создастся автоматически.</em></p>";
}

echo "<hr>";
echo "<h3>💡 Рекомендации:</h3>";
echo "<ul>";
echo "<li><strong>Для нового обмена:</strong> Не нажимайте ничего - 1С создаст новый каталог автоматически</li>";
echo "<li><strong>Для обновления существующего:</strong> Выберите нужный каталог выше</li>";
echo "<li><strong>ID=4 (152,845 товаров):</strong> Каталог запчастей Motor Force (импортированный из CSV)</li>";
echo "<li><strong>ID=5:</strong> Каталог из 1С-Предприятие (создан при обмене)</li>";
echo "</ul>";

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_after.php");

