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

/**
 * Безопасный вывод в HTML (устранение XSS).
 */
if (!function_exists('usfc_h')) {
	function usfc_h(string $s): string
	{
		return function_exists('htmlspecialcharsbx')
			? (string)htmlspecialcharsbx($s)
			: htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}

// Параметры: имя файла только в текущей директории (без ../)
$CSV_FILE = basename((string)($_GET['file'] ?? 'supplier_stock.csv'));
if ($CSV_FILE === '' || $CSV_FILE === '.' || $CSV_FILE === '..') {
	$CSV_FILE = 'supplier_stock.csv';
}
$IBLOCK_ID = (int)($_GET['iblock_id'] ?? 4);
if ($IBLOCK_ID <= 0) {
	$IBLOCK_ID = 4;
}
$PRICE_TYPE = trim((string)($_GET['price_type'] ?? 'BASE'));
if ($PRICE_TYPE === '') {
	$PRICE_TYPE = 'BASE';
}
$SUPPLIER_NAME = (string)($_GET['supplier'] ?? 'Поставщик');

echo '<h1>Обновление остатков из CSV</h1>';
echo '<p>Файл: ' . usfc_h($CSV_FILE) . '</p>';
echo '<p>Инфоблок: ' . (int)$IBLOCK_ID . '</p>';
echo '<p>Поставщик: ' . usfc_h($SUPPLIER_NAME) . '</p>';
echo '<hr>';

$csvPath = __DIR__ . '/' . $CSV_FILE;

if (!file_exists($csvPath)) {
	die('<p style="color: red;">✗ Файл не найден: ' . usfc_h($csvPath) . '</p>');
}

// Получаем ID типа цены
$priceTypeId = CCatalogGroup::GetList([], ['NAME' => $PRICE_TYPE])->Fetch()['ID'];

if (!$priceTypeId) {
	die('<p style="color: red;">✗ Тип цены ' . usfc_h($PRICE_TYPE) . ' не найден</p>');
}

echo '<p>✓ Тип цены: ' . usfc_h($PRICE_TYPE) . ' (ID: ' . (int)$priceTypeId . ')</p>';

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

$hdrArt = usfc_h((string)($headers[$artPos] ?? ''));
$hdrQty = usfc_h((string)($headers[$qtyPos] ?? ''));
$hdrPricePart = '';
if ($pricePos !== false) {
	$hdrPricePart = ', Цена=' . usfc_h((string)($headers[$pricePos] ?? ''));
}
echo '<p>✓ Найдены колонки: Артикул=' . $hdrArt . ', Остаток=' . $hdrQty . $hdrPricePart . '</p>';

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
        echo '<td>' . usfc_h($article) . '</td><td colspan="3">Не найден</td><td>⚠️ Пропущен</td>';
        echo '</tr>';
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
        echo '<td>' . usfc_h($article) . '</td>';
        echo '<td>' . usfc_h($productName) . '...</td>';
        echo '<td>' . (int)$qty . '</td>';
        echo '<td>' . ($priceUpdated ? usfc_h((string)$price) . ' ₽' : '-') . '</td>';
        echo '<td>✓ Обновлен</td>';
        echo '</tr>';
        $updated++;
    } else {
        echo "<tr style='background: #f8d7da;'>";
        echo '<td>' . usfc_h($article) . '</td><td>' . usfc_h($productName) . '...</td><td colspan="2">-</td><td>✗ Ошибка</td>';
        echo '</tr>';
        $errors++;
    }
    
    if (($updated + $notFound + $errors) % 50 === 0) {
        $progress = (int)($updated + $notFound + $errors);
        echo '<tr><td colspan="5"><em>Обработано: ' . $progress . '...</em></td></tr>';
    }
}

fclose($handle);

echo "</table>";
echo "<hr>";
echo "<h2>✅ Обновление завершено</h2>";
echo '<p><strong>Обновлено:</strong> ' . (int)$updated . ' товаров</p>';
echo '<p><strong>Не найдено:</strong> ' . (int)$notFound . ' товаров</p>';
echo '<p><strong>Ошибок:</strong> ' . (int)$errors . '</p>';

echo "<hr>";
echo "<h3>📝 Что дальше:</h3>";
echo "<ul>";
echo "<li>Остатки обновлены на сайте</li>";
echo "<li>Клиенты видят актуальную информацию о наличии</li>";
echo "<li>При заказе товара - он зарезервируется</li>";
echo "<li>Заказ передастся в 1С для обработки</li>";
echo "</ul>";

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_after.php");

