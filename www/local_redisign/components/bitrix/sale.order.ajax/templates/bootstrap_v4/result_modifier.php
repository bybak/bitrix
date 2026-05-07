<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * @var array $arParams
 * @var array $arResult
 * @var SaleOrderAjax $component
 */

$arParams['SERVICES_IMAGES_SCALING'] = (string)($arParams['SERVICES_IMAGES_SCALING'] ?? 'adaptive');

$component = $this->__component;
$component::scaleImages($arResult['JS_DATA'], $arParams['SERVICES_IMAGES_SCALING']);

if (function_exists('mf_sale_order_ajax_enrich_grid_rows'))
{
	$basketImgScaleRm = (string)($arParams['BASKET_IMAGES_SCALING'] ?? 'adaptive');
	if (!empty($arResult['JS_DATA']['GRID']['ROWS']) && is_array($arResult['JS_DATA']['GRID']['ROWS']))
	{
		mf_sale_order_ajax_enrich_grid_rows($arResult['JS_DATA']['GRID']['ROWS'], $basketImgScaleRm);
	}
	if (!empty($arResult['GRID']['ROWS']) && is_array($arResult['GRID']['ROWS']))
	{
		mf_sale_order_ajax_enrich_grid_rows($arResult['GRID']['ROWS'], $basketImgScaleRm);
	}
}

if (function_exists('mf_order_account_number_for_display'))
{
	if (!empty($arResult['ORDER']) && is_array($arResult['ORDER']))
	{
		$acc = (string)($arResult['ORDER']['ACCOUNT_NUMBER'] ?? '');
		if ($acc !== '')
		{
			$uid = (int)($arResult['ORDER']['USER_ID'] ?? 0);
			$arResult['ORDER']['ACCOUNT_NUMBER_DISPLAY'] = mf_order_account_number_for_display($uid, $acc);
		}
	}
	$accTop = (string)($arResult['ACCOUNT_NUMBER'] ?? '');
	if ($accTop !== '')
	{
		$uTop = 0;
		if (!empty($arResult['ORDER']['USER_ID']))
		{
			$uTop = (int)$arResult['ORDER']['USER_ID'];
		}
		elseif (isset($GLOBALS['USER']) && is_object($GLOBALS['USER']) && $GLOBALS['USER']->IsAuthorized())
		{
			$uTop = (int)$GLOBALS['USER']->GetID();
		}
		$arResult['ACCOUNT_NUMBER_DISPLAY'] = mf_order_account_number_for_display($uTop, $accTop);
	}
}
