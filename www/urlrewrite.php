<?php
$arUrlRewrite=array (
  // Правило для категории: /products/category/{категория}/ (должно быть ПЕРВЫМ!)
  4 => 
  array (
    'CONDITION' => '#^/products/category/([^/]+)/?$#',
    'RULE' => 'SECTION_CODE=$1',
    'ID' => NULL,
    'PATH' => '/products/section.php',
    'SORT' => 100,
  ),
  // Правило для товара: /products/{товар}/ (БЕЗ category)
  5 => 
  array (
    'CONDITION' => '#^/products/([^/]+)/?$#',
    'RULE' => 'ELEMENT_CODE=$1',
    'ID' => NULL,
    'PATH' => '/products/detail.php',
    'SORT' => 200,
  ),
  // Правило для корня каталога: /products/
  6 => 
  array (
    'CONDITION' => '#^/products/?$#',
    'RULE' => '',
    'ID' => NULL,
    'PATH' => '/products/index.php',
    'SORT' => 300,
  ),
  1 => 
  array (
    'CONDITION' => '#^\\/?\\/mobileapp/jn\\/(.*)\\/.*#',
    'RULE' => 'componentName=$1',
    'ID' => NULL,
    'PATH' => '/bitrix/services/mobileapp/jn.php',
    'SORT' => 100,
  ),
  3 => 
  array (
    'CONDITION' => '#^/bitrix/services/ymarket/#',
    'RULE' => '',
    'ID' => '',
    'PATH' => '/bitrix/services/ymarket/index.php',
    'SORT' => 100,
  ),
  0 => 
  array (
    'CONDITION' => '#^/stssync/calendar/#',
    'RULE' => '',
    'ID' => 'bitrix:stssync.server',
    'PATH' => '/bitrix/services/stssync/calendar/index.php',
    'SORT' => 100,
  ),
  2 => 
  array (
    'CONDITION' => '#^/rest/#',
    'RULE' => '',
    'ID' => NULL,
    'PATH' => '/bitrix/services/rest/index.php',
    'SORT' => 100,
  ),
);
