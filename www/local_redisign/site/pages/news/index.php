<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

$uri = (string)($_SERVER["REQUEST_URI"] ?? "/news/");
$path = parse_url($uri, PHP_URL_PATH);
$path = is_string($path) ? $path : "/news/";

// /news/ -> /posts/
if ($path === "/news/" || $path === "/news" || $path === "/news/index.php")
{
	LocalRedirect("/posts/", true, "301 Moved Permanently");
}

// /news/rss/ -> /posts/rss/
if (preg_match("#^/news/rss/?$#i", $path))
{
	LocalRedirect("/posts/rss/", true, "301 Moved Permanently");
}

// /news/{code}/ -> /posts/{code}/
if (preg_match("#^/news/([^/]+)/?$#i", $path, $m))
{
	$code = $m[1];
	LocalRedirect("/posts/" . $code . "/", true, "301 Moved Permanently");
}

LocalRedirect("/posts/", true, "301 Moved Permanently");
