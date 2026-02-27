<?php

// Global config for external Motor-Force image host.
// We intentionally generate URLs deterministically (no downloads into Bitrix).
if (!defined('MF_MOTOR_FORCE_IMG_HOST'))
{
	// Use HTTPS by default to avoid mixed-content blocking on HTTPS pages.
	define('MF_MOTOR_FORCE_IMG_HOST', 'https://img-motor-force.ru');
}

if (!function_exists('mf_mf_img_host'))
{
	function mf_mf_img_host(): string
	{
		return rtrim((string)MF_MOTOR_FORCE_IMG_HOST, '/');
	}
}

if (!function_exists('mf_mf_placeholder_img_url'))
{
	function mf_mf_placeholder_img_url(): string
	{
		// Local placeholder in current site template.
		$rel = '/bitrix/templates/eshop_bootstrap_v4/images/mf-no-photo.svg';
		$docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
		if ($docRoot === '')
		{
			// Fallback (CLI / unusual env): /bitrix/php_interface -> go up to site root.
			$docRoot = dirname(__DIR__, 2);
		}
		$abs = $docRoot . $rel;
		if ($docRoot !== '' && is_file($abs))
		{
			return $rel . '?v=' . (int)filemtime($abs);
		}
		return $rel;
	}
}

if (!function_exists('mf_mf_section_img_url'))
{
	function mf_mf_section_img_url(int $sectionId): string
	{
		$sectionId = (int)$sectionId;
		if ($sectionId <= 0)
		{
			return '';
		}
		return mf_mf_img_host() . '/sections/' . $sectionId . '.jpg';
	}
}

if (!function_exists('mf_mf_product_img_url'))
{
	function mf_mf_product_img_url(string $code, int $num = 1): string
	{
		$code = trim($code);
		if ($code === '')
		{
			return '';
		}
		$num = (int)$num;
		if ($num <= 0)
		{
			$num = 1;
		}
		$fname = str_pad((string)$num, 4, '0', STR_PAD_LEFT) . '.jpg';
		return mf_mf_img_host() . '/products/' . rawurlencode($code) . '/' . $fname;
	}
}

