<?php
/**
 * Обновление остатков товаров из CSV поставщика
 * 
 * ИСПОЛЬЗОВАНИЕ:
 * 1. Получите CSV от поставщика
 * 2. Загрузите на сервер
 * 3. Откройте: http://ваш-сайт/update_stock_from_csv.php?file=supplier_stock.csv
 * 
 * Формат CSV:
 * Артикул;Остаток;Цена (необязательно)
 * 1HP-F582T-00-00;5;8500
 */

$_SERVER["DOCUMENT_ROOT"] = __DIR__;
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;

Loader::includeModule("iblock");
Loader::includeModule("catalog");

// Параметры
$CSV_FILE = $_GET['file'] ?? 'supplier_stock.csv';
$IBLOCK_ID = $_GET['iblock_id'] ?? 4; // ID каталога
$PRICE_TYPE = $_GET['price_type'] ?? 'BASE'; // Тип цены
$SUPPLIER_NAME = $_GET['supplier'] ?? 'Поставщик';

echo "<h1>Обновление остатков из CSV</h1>";
echo "<p>Файл: $CSV_FILE</p>";
echo "<p>Инфоблок: $IBLOCK_ID</p>";
echo "<p>Поставщик: $SUPPLIER_NAME</p>";
echo "<hr>";

$csvPath = __DIR__ . '/' . $CSV_FILE;

if (!file_exists($csvPath)) {
    die("<p style='color: red;'>✗ Файл не найден: $csvPath</p>");
}

// Получаем ID типа цены
$priceTypeId = CCatalogGroup::GetList([], ['NAME' => $PRICE_TYPE])->Fetch()['ID'];

if (!$priceTypeId) {
    die("<p style='color: red;'>✗ Тип цены $PRICE_TYPE не найден</p>");
}

echo "<p>✓ Тип цены: $PRICE_TYPE (ID: $priceTypeId)</p>";

// Читаем CSV
$handle = fopen($csvPath, 'r');
$headers = fgetcsv($handle, 0, ';', '"', '\\');

// Определяем позиции колонок
$artPos = false;
$qtyPos = false;
$pricePos = false;

foreach ($headers as $i => $header) {
    $h = mb_strtolower(trim($header));
    if (in_array($h, ['артикул', 'article', 'sku'])) $artPos = $i;
    if (in_array($h, ['остаток', 'количество', 'кол-во', 'остаток', 'qty', 'stock'])) $qtyPos = $i;
    if (in_array($h, ['цена', 'price', 'стоимость'])) $pricePos = $i;
}

if ($artPos === false || $qtyPos === false) {
    die("<p style='color: red;'>✗ Не найдены обязательные колонки (Артикул, Остаток)</p>");
}

echo "<p>✓ Найдены колонки: Артикул={$headers[$artPos]}, Остаток={$headers[$qtyPos]}" . 
     ($pricePos !== false ? ", Цена={$headers[$pricePos]}" : "") . "</p>";

echo "<hr>";
echo "<h2>Обработка...</h2>";
echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr><th>Артикул</th><th>Товар</th><th>Остаток</th><th>Цена</th><th>Статус</th></tr>";

$updated = 0;
$notFound = 0;
$errors = 0;

while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
    $article = trim($row[$artPos]);
    $qty = intval($row[$qtyPos]);
    $price = ($pricePos !== false) ? floatval($row[$pricePos]) : null;
    
    if (empty($article)) continue;
    
    // Ищем товар по артикулу (в свойствах или в поле CODE)
    $element = CIBlockElement::GetList(
        [],
        [
            'IBLOCK_ID' => $IBLOCK_ID,
            '=PROPERTY_CML2_ARTICLE' => $article, // Стандартное поле артикула
        ],
        false,
        false,
        ['ID', 'NAME']
    )->Fetch();
    
    // Если не нашли - пробуем по коду элемента
    if (!$element) {
        $element = CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $IBLOCK_ID,
                '=CODE' => $article,
            ],
            false,
            false,
            ['ID', 'NAME']
        )->Fetch();
    }
    
    if (!$element) {
        echo "<tr style='background: #fff3cd;'>";
        echo "<td>$article</td><td colspan='3'>Не найден</td><td>⚠️ Пропущен</td>";
        echo "</tr>";
        $notFound++;
        continue;
    }
    
    $productId = $element['ID'];
    $productName = mb_substr($element['NAME'], 0, 50);
    
    // Обновляем остаток
    $result = CCatalogProduct::Update($productId, [
        'QUANTITY' => $qty,
        'QUANTITY_TRACE' => 'Y', // Включаем учет остатков
        'CAN_BUY_ZERO' => 'N',   // Запрещаем покупку при нулевом остатке
    ]);
    
    // Обновляем цену (если есть)
    $priceUpdated = false;
    if ($price !== null && $price > 0) {
        CPrice::SetBasePrice($productId, $price, 'RUB');
        $priceUpdated = true;
    }
    
    if ($result) {
        echo "<tr style='background: #d4edda;'>";
        echo "<td>$article</td>";
        echo "<td>$productName...</td>";
        echo "<td>$qty</td>";
        echo "<td>" . ($priceUpdated ? "$price ₽" : "-") . "</td>";
        echo "<td>✓ Обновлен</td>";
        echo "</tr>";
        $updated++;
    } else {
        echo "<tr style='background: #f8d7da;'>";
        echo "<td>$article</td><td>$productName...</td><td colspan='2'>-</td><td>✗ Ошибка</td>";
        echo "</tr>";
        $errors++;
    }
    
    if (($updated + $notFound + $errors) % 50 === 0) {
        echo "<tr><td colspan='5'><em>Обработано: " . ($updated + $notFound + $errors) . "...</em></td></tr>";
    }
}

fclose($handle);

echo "</table>";
echo "<hr>";
echo "<h2>✅ Обновление завершено</h2>";
echo "<p><strong>Обновлено:</strong> $updated товаров</p>";
echo "<p><strong>Не найдено:</strong> $notFound товаров</p>";
echo "<p><strong>Ошибок:</strong> $errors</p>";

echo "<hr>";
echo "<h3>📝 Что дальше:</h3>";
echo "<ul>";
echo "<li>Остатки обновлены на сайте</li>";
echo "<li>Клиенты видят актуальную информацию о наличии</li>";
echo "<li>При заказе товара - он зарезервируется</li>";
echo "<li>Заказ передастся в 1С для обработки</li>";
echo "</ul>";

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_after.php");

