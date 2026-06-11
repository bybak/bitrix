<?php

/**
 * Диагностика MF-статуса оплаты заказа и повторный импорт из XML обмена 1С.
 *
 * php local/tools/mf_1c_import_diag_order.php 347
 * php local/tools/mf_1c_import_diag_order.php 347 --apply
 */

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../..');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

require $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_1c_import_statuses.php';

use Bitrix\Main\Loader;
use Bitrix\Sale\Order;

$orderId = (int)($argv[1] ?? 0);
$doApply = in_array('--apply', $argv, true);
if ($orderId <= 0)
{
	fwrite(STDERR, "Usage: php local/tools/mf_1c_import_diag_order.php <order_id> [--apply]\n");
	exit(1);
}

if (!Loader::includeModule('sale') || !Loader::includeModule('highloadblock'))
{
	fwrite(STDERR, "Required modules not loaded.\n");
	exit(1);
}

$order = Order::load($orderId);
if (!$order)
{
	fwrite(STDERR, "Order not found: {$orderId}\n");
	exit(1);
}

echo "Order #{$orderId}\n";
echo 'ACCOUNT_NUMBER=' . (string)$order->getField('ACCOUNT_NUMBER') . PHP_EOL;
echo 'PRICE=' . (float)$order->getPrice() . PHP_EOL;
echo 'PAYED=' . (string)$order->getField('PAYED') . PHP_EOL;
echo 'UF_1C_PAYMENT_STATUS=' . (string)$order->getField('UF_1C_PAYMENT_STATUS') . PHP_EOL;

foreach ($order->getPaymentCollection() as $payment)
{
	if (!$payment)
	{
		continue;
	}
	echo 'PAYMENT id=' . (int)$payment->getId() . ' PAID=' . (string)$payment->getField('PAID') . ' SUM=' . (float)$payment->getSum() . PHP_EOL;
}

if (function_exists('mf_order_custom_status_get'))
{
	echo 'HL=' . json_encode(mf_order_custom_status_get($orderId), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}

$matched = false;
foreach (mf_1c_import_collect_exchange_xml_files() as $filePath)
{
	$updates = mf_1c_import_parse_xml_file($filePath);
	foreach ($updates as $parsed)
	{
		if (!is_array($parsed))
		{
			continue;
		}
		if (mf_1c_import_find_order_id($parsed) !== $orderId)
		{
			continue;
		}

		$matched = true;
		$mf = is_array($parsed['mf'] ?? null) ? $parsed['mf'] : [];
		$amounts = mf_1c_import_collect_payment_amounts($mf, $order, $parsed);
		$resolved = mf_1c_import_resolve_payment_status_for_import(
			$mf,
			$order,
			mf_1c_import_is_cancelled_mf($mf, (string)($parsed['status_id'] ?? '')),
			$parsed
		);

		echo PHP_EOL . 'XML match: ' . $filePath . PHP_EOL;
		echo 'xml_number=' . (string)($parsed['xml_number'] ?? '') . PHP_EOL;
		echo 'mf=' . mf_1c_import_format_mf_log($mf) . PHP_EOL;
		echo 'amounts=' . json_encode($amounts, JSON_UNESCAPED_UNICODE) . PHP_EOL;
		echo 'resolved_payment=' . (string)$resolved . PHP_EOL;

		if ($doApply)
		{
			mf_1c_import_apply_updates([$parsed]);
			echo 'Applied MF statuses from XML.' . PHP_EOL;
			if (function_exists('mf_order_custom_status_get'))
			{
				echo 'HL after=' . json_encode(mf_order_custom_status_get($orderId), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
			}
			$order = Order::load($orderId);
			if ($order)
			{
				echo 'PAYED after=' . (string)$order->getField('PAYED') . PHP_EOL;
				echo 'UF_1C_PAYMENT_STATUS after=' . (string)$order->getField('UF_1C_PAYMENT_STATUS') . PHP_EOL;
			}
		}

		break 2;
	}
}

if (!$matched)
{
	fwrite(STDERR, "Order {$orderId} not found in exchange XML files.\n");
	exit(2);
}

echo PHP_EOL . "Done.\n";
