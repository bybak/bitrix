<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/** @var array $arResult */

if (!function_exists('mf_order_custom_status_get_bulk'))
{
	return;
}

$orderIds = [];
foreach ($arResult['ORDERS'] ?? [] as $order)
{
	$orderId = (int)($order['ORDER']['ID'] ?? 0);
	if ($orderId > 0)
	{
		$orderIds[] = $orderId;
	}
}

$mfStatusMap = $orderIds !== [] ? mf_order_custom_status_get_bulk($orderIds) : [];
$mfLabels = mf_order_custom_status_labels();
$mfFilters = mf_order_custom_status_active_filters();
$mfHasFilters = ($mfFilters['order'] !== '' || $mfFilters['payment'] !== '' || $mfFilters['shipment'] !== '');

$filteredOrders = [];
foreach ($arResult['ORDERS'] ?? [] as $order)
{
	$orderId = (int)($order['ORDER']['ID'] ?? 0);
	$mfStatus = $mfStatusMap[$orderId] ?? null;
	$order['MF_CUSTOM_STATUS'] = $mfStatus;

	if (!mf_order_custom_status_order_matches_filters($mfStatus, $mfFilters))
	{
		continue;
	}

	$filteredOrders[] = $order;
}

$arResult['ORDERS'] = $filteredOrders;
$arResult['MF_STATUS_LABELS'] = $mfLabels;
$arResult['MF_STATUS_FILTERS'] = $mfFilters;
$arResult['MF_STATUS_FILTERS_ACTIVE'] = $mfHasFilters;
