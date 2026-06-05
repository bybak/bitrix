<?php

/**
 * Установка HL-блока кастомных статусов заказа.
 *
 * php local/tools/mf_order_custom_status_install.php
 * php local/tools/mf_order_custom_status_install.php set 1271 "В работе" "Оплачен" "Не отгружен"
 * php local/tools/mf_order_custom_status_install.php get 1271
 */

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../..');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!function_exists('mf_order_custom_status_ensure_hl'))
{
	fwrite(STDERR, "mf_order_custom_status.php is not loaded.\n");
	exit(1);
}

$cmd = $argv[1] ?? 'install';

try
{
	if ($cmd === 'install')
	{
		$hl = mf_order_custom_status_ensure_hl();
		echo "OK HL block mf_order_custom_status\n";
		echo "HL_ID=" . (int)$hl['HL_ID'] . "\n";
		echo "TABLE=" . (string)$hl['TABLE'] . "\n";
		echo "ENTITY_ID=" . (string)$hl['ENTITY_ID'] . "\n";
		exit(0);
	}

	if ($cmd === 'get')
	{
		$orderId = (int)($argv[2] ?? 0);
		$row = mf_order_custom_status_get($orderId);
		if ($row === null)
		{
			echo "Not found for ORDER_ID={$orderId}\n";
			exit(0);
		}
		echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
		exit(0);
	}

	if ($cmd === 'set')
	{
		$orderId = (int)($argv[2] ?? 0);
		$row = mf_order_custom_status_set($orderId, [
			'ORDER_STATUS' => $argv[3] ?? null,
			'PAYMENT_STATUS' => $argv[4] ?? null,
			'SHIPMENT_STATUS' => $argv[5] ?? null,
		]);
		echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
		exit(0);
	}

	fwrite(STDERR, "Unknown command: {$cmd}\n");
	exit(1);
}
catch (Throwable $e)
{
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
