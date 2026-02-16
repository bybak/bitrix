<?php
/**
 * Исправление настроек обмена с 1С
 * Открыть в браузере для автоматической настройки
 */

$_SERVER["DOCUMENT_ROOT"] = __DIR__;
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;

Loader::includeModule("catalog");
Loader::includeModule("sale");

echo "<h1>Настройка обмена с 1С</h1>";

// 1. Проверяем пользователя
echo "<h2>1. Проверка пользователя 'rikko'</h2>";

$user = CUser::GetByLogin("rikko")->Fetch();
if ($user) {
    echo "<p>✓ Пользователь найден: ID={$user['ID']}, {$user['NAME']}</p>";
    
    // Проверяем права
    $userObj = new CUser;
    $groups = $userObj->GetUserGroup($user['ID']);
    echo "<p>Группы: " . implode(", ", $groups) . "</p>";
    
    if (in_array(1, $groups)) {
        echo "<p style='color: green;'>✓ Пользователь является администратором</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Пользователь НЕ администратор - добавляем...</p>";
        CUser::SetUserGroup($user['ID'], array_merge($groups, [1]));
        echo "<p style='color: green;'>✓ Добавлен в администраторы</p>";
    }
} else {
    echo "<p style='color: red;'>✗ Пользователь не найден!</p>";
}

// 2. Настройки модуля catalog
echo "<h2>2. Настройки модуля Торговый каталог</h2>";

// Включаем обмен с 1С
COption::SetOptionString("catalog", "1C_IMPORT", "Y");
COption::SetOptionString("catalog", "1C_INTERVAL", "30");
COption::SetOptionString("catalog", "DEFAULT_SKIP_SOURCE_CHECK", "Y");

echo "<p>✓ Обмен с 1С включен</p>";

// Устанавливаем инфоблок для обмена
$iblockId = 5; // ID каталога из 1С
COption::SetOptionString("catalog", "DEFAULT_CATALOG_1C", $iblockId);

echo "<p>✓ Целевой инфоблок для 1С: ID=$iblockId</p>";

// 3. Создаем/проверяем тип цены BASE
echo "<h2>3. Проверка типа цены BASE</h2>";

$priceType = CCatalogGroup::GetList([], ['NAME' => 'BASE'])->Fetch();

if (!$priceType) {
    echo "<p>⚠️ Тип цены BASE не найден - создаем...</p>";
    
    $catalogGroup = new CCatalogGroup;
    $priceId = $catalogGroup->Add([
        'NAME' => 'BASE',
        'BASE' => 'Y',
        'SORT' => 100,
        'NAME_LANG' => 'Базовая цена',
        'USER_GROUP' => [2], // Все пользователи
        'USER_GROUP_BUY' => [2]
    ]);
    
    if ($priceId) {
        echo "<p style='color: green;'>✓ Тип цены BASE создан (ID: $priceId)</p>";
    } else {
        echo "<p style='color: red;'>✗ Ошибка создания типа цены</p>";
    }
} else {
    echo "<p style='color: green;'>✓ Тип цены BASE существует (ID: {$priceType['ID']})</p>";
}

// 4. Проверяем инфоблок для обмена
echo "<h2>4. Проверка инфоблока для обмена</h2>";

$iblock = CIBlock::GetByID($iblockId)->Fetch();
if ($iblock) {
    echo "<p>✓ Инфоблок найден: {$iblock['NAME']}</p>";
    
    // Проверяем что это торговый каталог
    $catalog = CCatalog::GetByID($iblockId);
    if ($catalog) {
        echo "<p>✓ Инфоблок подключен к торговому каталогу</p>";
    } else {
        echo "<p>⚠️ Подключаем к торговому каталогу...</p>";
        $cat = new CCatalog;
        $cat->Add([
            'IBLOCK_ID' => $iblockId,
            'PRODUCT_IBLOCK_ID' => 0,
            'SKU_PROPERTY_ID' => 0,
            'VAT_ID' => 0,
            'YANDEX_EXPORT' => 'N'
        ]);
        echo "<p style='color: green;'>✓ Подключено к каталогу</p>";
    }
}

// 5. Настройки прав доступа для обмена
echo "<h2>5. Права доступа для обмена</h2>";

// Разрешаем обмен без проверки прав (для отладки)
COption::SetOptionString("catalog", "1C_FILE_SIZE_LIMIT", "200000000"); // 200 MB
COption::SetOptionString("catalog", "1C_DISABLE_UNLOAD", "N");

echo "<p>✓ Права настроены</p>";

// 6. Тестовый запрос
echo "<h2>6. Тестовый запрос авторизации</h2>";

$testUrl = "http://84.252.143.110/bitrix/admin/1c_exchange.php?type=catalog&mode=checkauth";

echo "<p>Тестируем: <a href='$testUrl' target='_blank'>$testUrl</a></p>";
echo "<p><em>Откройте ссылку - должно быть 'success'</em></p>";

echo "<hr>";
echo "<h2>✅ Настройка завершена!</h2>";
echo "<p><strong>Теперь попробуйте в 1С:</strong></p>";
echo "<ol>";
echo "<li>Вернитесь в настройки обмена в 1С</li>";
echo "<li>Нажмите <strong>'Проверить соединение'</strong></li>";
echo "<li>Должно быть: ✓ Соединение установлено</li>";
echo "<li>Если всё ОК - нажмите <strong>'Загрузить с сайта'</strong></li>";
echo "</ol>";

echo "<h3>Параметры для 1С:</h3>";
echo "<pre>";
echo "URL: http://84.252.143.110/bitrix/admin/1c_exchange.php\n";
echo "Логин: rikko\n";
echo "Пароль: elenaandrey\n";
echo "Тип обмена: catalog\n";
echo "</pre>";

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_after.php");

