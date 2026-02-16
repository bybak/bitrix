<?php
/**
 * ТЕСТОВЫЙ импорт каталога - первые 1000 товаров
 * Использование: php import_catalog_test.php
 * Время выполнения: ~1-2 минуты
 */

$_SERVER["DOCUMENT_ROOT"] = __DIR__;
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define('BX_NO_ACCELERATOR_RESET', true);

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

echo "==========================================\n";
echo "ТЕСТОВЫЙ ИМПОРТ - ПЕРВЫЕ 1000 ТОВАРОВ\n";
echo "==========================================\n\n";

$csvFile = __DIR__ . "/catalog_mf.csv";
$maxProducts = 1000; // Ограничение для теста

if (!file_exists($csvFile)) {
    die("Файл не найден: $csvFile\n");
}

echo "Создание тестового файла с первыми 1000 товарами...\n";

// Создаем тестовый CSV
$testFile = __DIR__ . "/catalog_test.csv";
$handle = fopen($csvFile, 'r');
$testHandle = fopen($testFile, 'w');

// Копируем заголовок и первые 1000 строк
$line = 0;
while (($data = fgets($handle)) !== false && $line <= $maxProducts) {
    fputs($testHandle, $data);
    $line++;
}

fclose($handle);
fclose($testHandle);

echo "Тестовый файл создан: $testFile\n";
echo "Строк: $line\n\n";

// Теперь запускаем обычный импорт на тестовом файле
$_SERVER['argv'] = [$testFile];
include __DIR__ . "/import_catalog.php";

echo "\n==========================================\n";
echo "ТЕСТ ЗАВЕРШЕН\n";
echo "==========================================\n";
echo "Проверьте результат в админке Битрикс:\n";
echo "Контент → Инфоблоки → Каталог запчастей Motor Force\n\n";
echo "Если всё работает, запустите полный импорт:\n";
echo "php import_catalog.php\n";

