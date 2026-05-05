<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!function_exists('mf_order_account_number_for_display') || empty($arResult['ORDERS']) || !is_array($arResult['ORDERS']))
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
	$orderRow['ORDER']['ACCOUNT_NUMBER_DISPLAY'] = mf_order_account_number_for_display($uid, $acc);
}
unset($orderRow);
