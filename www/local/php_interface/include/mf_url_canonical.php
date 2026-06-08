<?php

declare(strict_types=1);

/**
 * Канонические URL каталога: /products/{code}/ и /products/category/{code}/ (со слэшем).
 * Без слэша часть ссылок (Jivo, почта) открывается с ошибкой «Элемент не найден».
 */

if (!function_exists('mf_url_catalog_path_needs_trailing_slash'))
{
	function mf_url_catalog_path_needs_trailing_slash(string $path): bool
	{
		$path = '/' . trim($path, '/');
		if ($path === '/')
		{
			return false;
		}
		if ($path !== '/' && substr($path, -1) === '/')
		{
			return false;
		}

		if ($path === '/products')
		{
			return true;
		}
		if (preg_match('#^/products/category/[^/]+$#', $path))
		{
			return true;
		}
		if (preg_match('#^/products/(?!category(?:/|$)|search(?:/|$))[^/]+$#', $path))
		{
			return true;
		}

		return false;
	}
}

if (!function_exists('mf_url_catalog_canonical_path'))
{
	function mf_url_catalog_canonical_path(string $path): string
	{
		$path = '/' . trim($path, '/');
		if (!mf_url_catalog_path_needs_trailing_slash($path))
		{
			return $path === '/' ? '/' : $path;
		}

		return $path . '/';
	}
}

if (!function_exists('mf_url_apply_catalog_trailing_slash_redirect'))
{
	/**
	 * 301 на канонический путь до загрузки Bitrix (dbconn.php).
	 */
	function mf_url_apply_catalog_trailing_slash_redirect(): void
	{
		if (PHP_SAPI === 'cli')
		{
			return;
		}

		$method = strtoupper(trim((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')));
		if ($method !== 'GET' && $method !== 'HEAD')
		{
			return;
		}

		$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
		if ($uri === '' || $uri[0] !== '/')
		{
			return;
		}

		$qPos = strpos($uri, '?');
		$path = $qPos !== false ? substr($uri, 0, $qPos) : $uri;
		$query = $qPos !== false ? substr($uri, $qPos) : '';

		$canonicalPath = mf_url_catalog_canonical_path($path);
		if ($canonicalPath === $path)
		{
			return;
		}

		$target = $canonicalPath . $query;
		if (!headers_sent())
		{
			header('Location: ' . $target, true, 301);
			exit;
		}
	}
}
