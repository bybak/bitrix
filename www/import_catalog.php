<?php
/**
 * Импорт каталога товаров из CSV в Битрикс
 * Использование: php import_catalog.php
 */

// Подключаем ядро Битрикс
$_SERVER["DOCUMENT_ROOT"] = __DIR__;
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define('BX_NO_ACCELERATOR_RESET', true);

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Iblock\ElementTable;

// Загружаем модули
Loader::includeModule("iblock");
Loader::includeModule("catalog");

// Настройки импорта
$CSV_FILE = __DIR__ . "/catalog_mf.csv";
$IBLOCK_ID = 0; // ID инфоблока (будет найден автоматически)
$BATCH_SIZE = 100; // Количество товаров в одной итерации
$ENCODING = "Windows-1251"; // Исходная кодировка CSV

// Поиск или создание инфоблока
function getOrCreateIblock() {
    $iblock = IblockTable::getList([
        'filter' => ['CODE' => 'catalog_motor_force'],
        'select' => ['ID', 'NAME']
    ])->fetch();
    
    if (!$iblock) {
        echo "Создание нового инфоблока каталога...\n";
        
        // Получаем ID типа инфоблока для каталога
        $ibType = CIBlockType::GetList([], ['=ID' => 'catalog'])->Fetch();
        if (!$ibType) {
            // Создаем тип инфоблока
            $iblockType = new CIBlockType;
            $iblockType->Add([
                'ID' => 'catalog',
                'SECTIONS' => 'Y',
                'LANG' => [
                    'ru' => ['NAME' => 'Каталоги']
                ]
            ]);
        }
        
        $ib = new CIBlock;
        $iblockId = $ib->Add([
            'ACTIVE' => 'Y',
            'NAME' => 'Каталог запчастей Motor Force',
            'CODE' => 'catalog_motor_force',
            'IBLOCK_TYPE_ID' => 'catalog',
            'SITE_ID' => ['s1'],
            'LID' => 's1',
            'GROUP_ID' => [2 => 'R'],
            'FIELDS' => [
                'DETAIL_TEXT_TYPE' => 'html',
                'DETAIL_TEXT' => ['IS_REQUIRED' => 'N'],
                'DETAIL_PICTURE' => ['IS_REQUIRED' => 'N'],
            ]
        ]);
        
        if (!$iblockId) {
            die("Ошибка создания инфоблока: " . $ib->LAST_ERROR . "\n");
        }
        
        // Подключаем к каталогу
        $catalog = new CCatalog;
        $catalog->Add([
            'IBLOCK_ID' => $iblockId,
            'PRODUCT_IBLOCK_ID' => $iblockId,
            'SKU_PROPERTY_ID' => 0,
            'VAT_ID' => 0,
            'YANDEX_EXPORT' => 'N'
        ]);
        
        echo "Инфоблок создан с ID: $iblockId\n";
        return $iblockId;
    }
    
    echo "Используем существующий инфоблок: {$iblock['NAME']} (ID: {$iblock['ID']})\n";
    return $iblock['ID'];
}

// Создание или получение раздела
function getOrCreateSection($path, $iblockId) {
    static $sectionCache = [];
    
    $cacheKey = md5($path);
    if (isset($sectionCache[$cacheKey])) {
        return $sectionCache[$cacheKey];
    }
    
    $parts = array_map('trim', explode(' => ', $path));
    $parentId = 0;
    
    foreach ($parts as $name) {
        $section = SectionTable::getList([
            'filter' => [
                'IBLOCK_ID' => $iblockId,
                'NAME' => $name,
                'IBLOCK_SECTION_ID' => $parentId ?: false
            ],
            'select' => ['ID']
        ])->fetch();
        
        if (!$section) {
            $bs = new CIBlockSection;
            $sectionId = $bs->Add([
                'IBLOCK_ID' => $iblockId,
                'IBLOCK_SECTION_ID' => $parentId,
                'NAME' => $name,
                'CODE' => CUtil::translit($name, 'ru'),
                'ACTIVE' => 'Y'
            ]);
            
            if (!$sectionId) {
                echo "Ошибка создания раздела '$name': " . $bs->LAST_ERROR . "\n";
                continue;
            }
            
            $parentId = $sectionId;
        } else {
            $parentId = $section['ID'];
        }
    }
    
    $sectionCache[$cacheKey] = $parentId;
    return $parentId;
}

// Импорт товара
function importProduct($data, $iblockId) {
    $el = new CIBlockElement;
    
    // Получаем раздел
    $sectionId = 0;
    if (!empty($data['section'])) {
        $sectionId = getOrCreateSection($data['section'], $iblockId);
    }
    
    // Проверяем существование товара
    $existing = CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => $iblockId, 'XML_ID' => $data['id']],
        false,
        false,
        ['ID']
    )->Fetch();
    
    $fields = [
        'IBLOCK_ID' => $iblockId,
        'NAME' => $data['name'],
        'CODE' => $data['slug'] ?: CUtil::translit($data['name'], 'ru'),
        'XML_ID' => $data['id'],
        'ACTIVE' => $data['active'] ? 'Y' : 'N',
        'IBLOCK_SECTION_ID' => $sectionId,
        'DETAIL_TEXT' => $data['detail_text'],
        'DETAIL_TEXT_TYPE' => 'html',
        'PREVIEW_TEXT' => $data['preview_text'],
        'PREVIEW_TEXT_TYPE' => 'html',
    ];
    
    if ($data['title']) {
        $fields['IPROPERTY_TEMPLATES']['ELEMENT_META_TITLE'] = $data['title'];
    }
    if ($data['description']) {
        $fields['IPROPERTY_TEMPLATES']['ELEMENT_META_DESCRIPTION'] = $data['description'];
    }
    if ($data['keywords']) {
        $fields['IPROPERTY_TEMPLATES']['ELEMENT_META_KEYWORDS'] = $data['keywords'];
    }
    
    if ($existing) {
        // Обновляем
        $el->Update($existing['ID'], $fields);
        $productId = $existing['ID'];
    } else {
        // Создаем
        $productId = $el->Add($fields);
    }
    
    if (!$productId) {
        return false;
    }
    
    // Устанавливаем цену
    if ($data['price'] > 0) {
        CPrice::SetBasePrice($productId, $data['price'], 'RUB');
    }
    
    // Устанавливаем количество
    CCatalogProduct::Add([
        'ID' => $productId,
        'QUANTITY' => $data['quantity'] > 0 ? $data['quantity'] : 0,
        'AVAILABLE' => $data['available'] ? 'Y' : 'N',
        'QUANTITY_TRACE' => 'N',
        'CAN_BUY_ZERO' => 'Y'
    ]);
    
    return $productId;
}

// Основная функция импорта
function runImport($csvFile, $batchSize, $encoding) {
    echo "==========================================\n";
    echo "ИМПОРТ КАТАЛОГА В БИТРИКС\n";
    echo "==========================================\n\n";
    
    if (!file_exists($csvFile)) {
        die("Файл не найден: $csvFile\n");
    }
    
    $fileSize = filesize($csvFile);
    echo "Файл: $csvFile\n";
    echo "Размер: " . round($fileSize / 1024 / 1024, 2) . " MB\n";
    echo "Кодировка: $encoding\n";
    echo "Порция: $batchSize товаров\n\n";
    
    // Получаем или создаем инфоблок
    $iblockId = getOrCreateIblock();
    
    // Открываем файл
    $handle = fopen($csvFile, 'r');
    if (!$handle) {
        die("Не удалось открыть файл\n");
    }
    
    // Читаем заголовки
    $headers = fgetcsv($handle, 0, ';');
    if (!$headers) {
        die("Не удалось прочитать заголовки\n");
    }
    
    // Конвертируем заголовки
    $headers = array_map(function($h) use ($encoding) {
        return mb_convert_encoding($h, 'UTF-8', $encoding);
    }, $headers);
    
    echo "Найдено полей: " . count($headers) . "\n";
    echo "Начинаем импорт...\n\n";
    
    $total = 0;
    $imported = 0;
    $errors = 0;
    $startTime = time();
    
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        $total++;
        
        // Конвертируем кодировку
        $row = array_map(function($field) use ($encoding) {
            return mb_convert_encoding($field, 'UTF-8', $encoding);
        }, $row);
        
        // Создаем ассоциативный массив
        $data = array_combine($headers, $row);
        
        // Подготавливаем данные
        $productData = [
            'id' => $data['id'],
            'name' => $data['Название товара *'],
            'article' => $data['Артикул *'],
            'price' => floatval($data['Стоимость товара *']),
            'section' => $data['Раздел товара *'],
            'preview_text' => $data['Краткий текст'] ?? '',
            'detail_text' => $data['Текст полностью'] ?? '',
            'title' => $data['Заголовок страницы (title)'] ?? '',
            'description' => $data['Описание страницы (description)'] ?? '',
            'keywords' => $data['Ключевые слова страницы (keywords)'] ?? '',
            'slug' => $data['ЧПУ страницы (slug)'] ?? '',
            'active' => intval($data['Показывать на сайте *']) === 1,
            'available' => intval($data['Товар в наличии']) === 1,
            'quantity' => intval($data['Товар в наличии']) === 1 ? 10 : 0,
        ];
        
        // Импортируем товар
        if (importProduct($productData, $iblockId)) {
            $imported++;
        } else {
            $errors++;
        }
        
        // Показываем прогресс
        if ($total % $batchSize === 0) {
            $elapsed = time() - $startTime;
            $speed = $total / max($elapsed, 1);
            $remaining = round((669471 - $total) / max($speed, 1));
            
            echo sprintf(
                "Обработано: %d | Импортировано: %d | Ошибок: %d | Скорость: %.1f/сек | Осталось: ~%d мин\n",
                $total,
                $imported,
                $errors,
                $speed,
                round($remaining / 60)
            );
            
            // Даем отдохнуть серверу
            sleep(1);
        }
    }
    
    fclose($handle);
    
    $totalTime = time() - $startTime;
    
    echo "\n==========================================\n";
    echo "ИМПОРТ ЗАВЕРШЕН\n";
    echo "==========================================\n";
    echo "Всего обработано: $total\n";
    echo "Успешно импортировано: $imported\n";
    echo "Ошибок: $errors\n";
    echo "Время выполнения: " . gmdate("H:i:s", $totalTime) . "\n";
    echo "Средняя скорость: " . round($total / max($totalTime, 1), 2) . " товаров/сек\n";
}

// Запуск
try {
    runImport($CSV_FILE, $BATCH_SIZE, $ENCODING);
} catch (Exception $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

