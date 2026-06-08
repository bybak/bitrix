<?php
$arUrlRewrite=array (
  // SEO: sitemap.xml и части карты сайта
  14 =>
  array (
    'CONDITION' => '#^/sitemap-pages\\.xml$#',
    'RULE' => '',
    'ID' => NULL,
    'PATH' => '/sitemap/index.php',
    'SORT' => 48,
  ),
  15 =>
  array (
    'CONDITION' => '#^/sitemap-products-(\\d+)\\.xml$#',
    'RULE' => 'page=$1',
    'ID' => NULL,
    'PATH' => '/sitemap/index.php',
    'SORT' => 49,
  ),
  16 =>
  array (
    'CONDITION' => '#^/sitemap\\.xml$#',
    'RULE' => '',
    'ID' => NULL,
    'PATH' => '/sitemap/index.php',
    'SORT' => 50,
  ),
  // Персональный раздел (SEF-компонент sale.personal.section)
  // Важно: правило должно быть выше остальных "общих" обработчиков, чтобы /personal/* не давало 404.
  1000 =>
  array (
    'CONDITION' => '#^/personal/.*$#',
    'RULE' => '',
    'ID' => NULL,
    'PATH' => '/personal/index.php',
    'SORT' => 305,
  ),
  // Поиск по каталогу: /products/search/ (до правила карточки товара)
  3 =>
  array (
    'CONDITION' => '#^/products/search/?(\\?.*)?$#',
    'RULE' => '',
    'ID' => NULL,
    'PATH' => '/products/search/index.php',
    'SORT' => 90,
  ),
  // Правило для категории: /products/category/{категория}/ (должно быть ПЕРВЫМ!)
  4 => 
  array (
    // Bitrix checks CONDITION against REQUEST_URI which may include query string.
    // Allow optional "?..." so view/sort switches don't cause 404.
    'CONDITION' => '#^/products/category/([^/]+)/?(\\?.*)?$#',
    'RULE' => 'SECTION_CODE=$1',
    'ID' => NULL,
    'PATH' => '/products/section.php',
    'SORT' => 100,
  ),
  // Правило для товара: /products/{товар}/ (БЕЗ category)
  5 => 
  array (
    'CONDITION' => '#^/products/([^/]+)/?(\\?.*)?$#',
    'RULE' => 'ELEMENT_CODE=$1',
    'ID' => NULL,
    'PATH' => '/products/detail.php',
    'SORT' => 200,
  ),
  // Правило для корня каталога: /products/
  6 => 
  array (
    'CONDITION' => '#^/products/?(\\?.*)?$#',
    'RULE' => '',
    'ID' => NULL,
    'PATH' => '/products/index.php',
    'SORT' => 300,
  ),
  // Новости: /posts/year/{YYYY|all}/{page}
  7 =>
  array (
    'CONDITION' => '#^/posts/year/(all|\\d{4})/(\\d+)/?$#',
    'RULE' => 'POSTS_YEAR=$1&POSTS_PAGE=$2',
    'ID' => NULL,
    'PATH' => '/posts/index.php',
    'SORT' => 310,
  ),
  // Новости (детальная): /posts/{code}/
  8 =>
  array (
    'CONDITION' => '#^/posts/([^/]+)/?$#',
    'RULE' => 'ELEMENT_CODE=$1',
    'ID' => NULL,
    'PATH' => '/posts/detail.php',
    'SORT' => 320,
  ),
  // Блог: /blog/page/{page}
  9 =>
  array (
    'CONDITION' => '#^/blog/page/(\\d+)/?$#',
    'RULE' => 'BLOG_PAGE=$1',
    'ID' => NULL,
    'PATH' => '/blog/index.php',
    'SORT' => 330,
  ),
  // Блог: /blog/tag/{tag}/page/{page}
  11 =>
  array (
    'CONDITION' => '#^/blog/tag/([^/]+)/page/(\\d+)/?$#',
    'RULE' => 'BLOG_TAG=$1&BLOG_PAGE=$2',
    'ID' => NULL,
    'PATH' => '/blog/index.php',
    'SORT' => 331,
  ),
  // Блог: /blog/tag/{tag}
  12 =>
  array (
    'CONDITION' => '#^/blog/tag/([^/]+)/?$#',
    'RULE' => 'BLOG_TAG=$1',
    'ID' => NULL,
    'PATH' => '/blog/index.php',
    'SORT' => 332,
  ),
  // Блог RSS: /blog/rss/
  13 =>
  array (
    'CONDITION' => '#^/blog/rss/?$#',
    'RULE' => '',
    'ID' => NULL,
    'PATH' => '/blog/rss/index.php',
    'SORT' => 333,
  ),
  // Блог (детальная): /blog/{code}/
  10 =>
  array (
    'CONDITION' => '#^/blog/([^/]+)/?$#',
    'RULE' => 'ELEMENT_CODE=$1',
    'ID' => NULL,
    'PATH' => '/blog/detail.php',
    'SORT' => 340,
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
