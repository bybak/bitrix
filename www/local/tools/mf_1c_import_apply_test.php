<?php

/**
 * Ручной прогон MF-статусов из XML обмена 1С.
 *
 * php local/tools/mf_1c_import_apply_test.php orders-....xml
 * php local/tools/mf_1c_import_apply_test.php /full/path/to/orders.xml
 */

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../..');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

require $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_1c_import_statuses.php';

$arg = $argv[1] ?? '';
if ($arg === '')
{
	fwrite(STDERR, "Usage: php local/tools/mf_1c_import_apply_test.php <filename-or-path>\n");
	exit(1);
}

$filePath = is_file($arg)
	? $arg
	: mf1c_exchange_resolve_upload_file(basename($arg));

if ($filePath === '' || !is_file($filePath))
{
	fwrite(STDERR, "XML not found: {$arg}\n");
	exit(1);
}

echo "Apply MF statuses from: {$filePath}\n";
mf_1c_import_apply_file($filePath);

if (function_exists('mf_1c_import_parse_xml_file') && function_exists('mf_1c_import_find_order_id') && function_exists('mf_order_custom_status_get'))
{
	$updates = mf_1c_import_parse_xml_file($filePath);
	foreach ($updates as $parsed)
	{
		$orderId = mf_1c_import_find_order_id($parsed);
		if ($orderId === null || $orderId <= 0)
		{
			continue;
		}
		$row = mf_order_custom_status_get((int)$orderId);
		echo 'HL after apply order_id=' . (int)$orderId . PHP_EOL;
		echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
	}
}

echo "Done. See upload/1c_exchange_debug.log\n";
