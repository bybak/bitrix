<?php
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_PUBLIC_MODE', true);
define('NO_AGENT_STATISTIC', 'Y');
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
define('BX_PULL_SKIP_INIT', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include.php';

$lib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_sitemap.php';
if (is_file($lib))
{
	require_once $lib;
}

\Bitrix\Main\Loader::includeModule('iblock');

$requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
$path = (string)(parse_url($requestUri, PHP_URL_PATH) ?: '');

$type = 'index';
$page = 1;

if (preg_match('#^/sitemap-pages\\.xml$#', $path))
{
	$type = 'pages';
}
elseif (preg_match('#^/sitemap-products-(\\d+)\\.xml$#', $path, $matches))
{
	$type = 'products';
	$page = max(1, (int)$matches[1]);
}

header('Content-Type: application/xml; charset=UTF-8');
header('X-Robots-Tag: noindex');

if (function_exists('mf_sitemap_generate_xml'))
{
	echo mf_sitemap_generate_xml($type, $page);
}
else
{
	echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
}

die();
