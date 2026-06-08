<?php

declare(strict_types=1);

/**
 * Канонические URL каталога: только карточки товаров и разделы /products/category/{code}/.
 * Слэш только ДОБАВЛЯЕМ, никогда не снимаем (иначе /products/search/ → цикл редиректов).
 */

if (!function_exists('mf_url_normalize_request_path'))
{
	function mf_url_normalize_request_path(string $path): string
	{
		if ($path === '' || $path[0] !== '/')
		{
			$path = '/' . ltrim($path, '/');
		}

		return $path;
	}
}

if (!function_exists('mf_url_catalog_path_needs_trailing_slash'))
{
	function mf_url_catalog_path_needs_trailing_slash(string $path): bool
	{
		$path = mf_url_normalize_request_path($path);
		if ($path === '/' || (strlen($path) > 1 && substr($path, -1) === '/'))
		{
			return false;
		}

		if ($path === '/products')
		{
			return true;
		}

		// Поиск и прочие служебные пути каталога — не трогаем.
		if (preg_match('#^/products/(search|index\\.php)(?:/|$)#', $path))
		{
			return false;
		}

		if (preg_match('#^/products/category/[^/]+$#', $path))
		{
			return true;
		}

		// Одна сегментная карточка: /products/{code}
		if (preg_match('#^/products/[^/]+$#', $path))
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
		$path = mf_url_normalize_request_path($path);
		if (!mf_url_catalog_path_needs_trailing_slash($path))
		{
			return $path;
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
