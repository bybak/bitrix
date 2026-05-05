<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!function_exists('mf_order_account_number_for_display'))
{
	return;
}

$acc = (string)($arResult['ACCOUNT_NUMBER'] ?? '');
if ($acc === '')
{
	return;
}

$uid = (int)($arResult['USER_ID'] ?? 0);
$arResult['ACCOUNT_NUMBER_DISPLAY'] = mf_order_account_number_for_display($uid, $acc);
