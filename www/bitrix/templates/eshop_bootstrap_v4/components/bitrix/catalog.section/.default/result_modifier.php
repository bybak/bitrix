<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$__mfCsRm = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/components/bitrix/catalog.section/templates/.default/result_modifier.php';
if (is_file($__mfCsRm))
{
	require $__mfCsRm;
}
unset($__mfCsRm);

if (empty($arResult['ITEMS']))
{
	return;
}

if (function_exists('mf_catalog_listing_display_price'))
{
	foreach ($arResult['ITEMS'] as &$arItem)
	{
		$pid = (int)($arItem['ID'] ?? 0);
		if ($pid <= 0)
		{
			continue;
		}

		$dp = mf_catalog_listing_display_price($pid);
		if ($dp === null || $dp <= 0)
		{
			continue;
		}

		if (!empty($arItem['MIN_PRICE']) && is_array($arItem['MIN_PRICE']) && function_exists('mf_catalog_patch_bitrix_min_price_display'))
		{
			mf_catalog_patch_bitrix_min_price_display($arItem['MIN_PRICE'], (float)$dp);
		}

		if (empty($arItem['ITEM_PRICES']) || !is_array($arItem['ITEM_PRICES']))
		{
			continue;
		}

		$rounded = function_exists('mf_round_price') ? mf_round_price((float)$dp) : (float)ceil((float)$dp);
		$currency = (string)($arItem['ITEM_PRICES'][0]['CURRENCY'] ?? 'RUB');
		$print = function_exists('mf_format_display_price_rub')
			? mf_format_display_price_rub($rounded)
			: (number_format($rounded, 0, '.', ' ') . ' ₽');

		foreach ($arItem['ITEM_PRICES'] as &$priceRow)
		{
			if (!is_array($priceRow))
			{
				continue;
			}
			$priceRow['RATIO_PRICE'] = $rounded;
			$priceRow['RATIO_BASE_PRICE'] = $rounded;
			$priceRow['PRICE'] = $rounded;
			$priceRow['BASE_PRICE'] = $rounded;
			$priceRow['PRINT_RATIO_PRICE'] = $print;
			$priceRow['PRINT_RATIO_BASE_PRICE'] = $print;
			$priceRow['PRINT_PRICE'] = $print;
			$priceRow['PRINT_BASE_PRICE'] = $print;
			if ($currency !== '')
			{
				$priceRow['CURRENCY'] = $currency;
			}
		}
		unset($priceRow);
	}
	unset($arItem);
}
