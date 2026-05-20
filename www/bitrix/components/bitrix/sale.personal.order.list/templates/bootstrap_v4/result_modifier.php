<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (empty($arResult['ORDERS']) || !is_array($arResult['ORDERS']))
{
	return;
}

foreach ($arResult['ORDERS'] as &$orderRow)
{
	if (empty($orderRow['ORDER']) || !is_array($orderRow['ORDER']))
	{
		continue;
	}
	$uid = (int)($orderRow['ORDER']['USER_ID'] ?? 0);
	$acc = (string)($orderRow['ORDER']['ACCOUNT_NUMBER'] ?? '');
	$orderRow['ORDER']['ACCOUNT_NUMBER_DISPLAY'] = function_exists('mf_order_account_number_for_display')
		? mf_order_account_number_for_display($uid, $acc)
		: $acc;

	if (empty($orderRow['BASKET_ITEMS']) || !is_array($orderRow['BASKET_ITEMS']) || !function_exists('mf_basket_item_display_name'))
	{
		continue;
	}
	foreach ($orderRow['BASKET_ITEMS'] as &$basketItem)
	{
		if (!is_array($basketItem))
		{
			continue;
		}
		$name = mf_basket_item_display_name($basketItem);
		if ($name === '')
		{
			continue;
		}
		$basketItem['NAME'] = htmlspecialcharsbx($name);
		$basketItem['NAME~'] = $name;
	}
	unset($basketItem);
}
unset($orderRow);
