<?php

/**
 * Одноразовое исправление HL: «paid» без фактической оплаты в Bitrix → not_paid.
 *
 * php local/tools/mf_fix_false_paid_hl.php
 * php local/tools/mf_fix_false_paid_hl.php 313
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

require $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_order_custom_status.php';

use Bitrix\Main\Loader;

if (!Loader::includeModule('highloadblock') || !Loader::includeModule('sale'))
{
	fwrite(STDERR, "Required modules not loaded.\n");
	exit(1);
}

$hl = mf_order_custom_status_ensure_hl();
$dataClass = $hl['DATA_CLASS'];

$filter = ['=UF_PAYMENT_STATUS' => 'paid'];
$onlyOrderId = (int)($argv[1] ?? 0);
if ($onlyOrderId > 0)
{
	$filter['=UF_ORDER_ID'] = $onlyOrderId;
}

$fixed = 0;
$rs = $dataClass::getList([
	'filter' => $filter,
	'select' => ['ID', 'UF_ORDER_ID'],
]);
while ($row = $rs->fetch())
{
	$orderId = (int)($row['UF_ORDER_ID'] ?? 0);
	if ($orderId <= 0 || mf_order_custom_status_order_has_bitrix_payment_by_id($orderId))
	{
		continue;
	}

	$dataClass::update((int)$row['ID'], [
		'UF_PAYMENT_STATUS' => 'not_paid',
		'UF_UPDATED_AT' => new \Bitrix\Main\Type\DateTime(),
	]);
	$fixed++;
	echo "order_id={$orderId} PAYMENT_STATUS not_paid\n";
}

echo "Done. Fixed: {$fixed}\n";
