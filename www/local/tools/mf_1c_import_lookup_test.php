<?php

/**
 * Диагностика сопоставления номера 1С с заказом Bitrix.
 *
 * php local/tools/mf_1c_import_lookup_test.php s1295
 * php local/tools/mf_1c_import_lookup_test.php orders-....xml
 */

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../..');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_1c_import_statuses.php';

$arg = trim((string)($argv[1] ?? ''));
if ($arg === '')
{
	fwrite(STDERR, "Usage: php local/tools/mf_1c_import_lookup_test.php <s1295|xml-filename>\n");
	exit(1);
}

if (is_file($arg) || str_contains($arg, '.xml'))
{
	$filePath = is_file($arg) ? $arg : mf1c_exchange_resolve_upload_file(basename($arg));
	if ($filePath === '' || !is_file($filePath))
	{
		fwrite(STDERR, "XML not found\n");
		exit(1);
	}
	$updates = mf_1c_import_parse_xml_file($filePath);
	foreach ($updates as $parsed)
	{
		$orderId = mf_1c_import_find_order_id($parsed);
		echo 'xml_number=' . ($parsed['xml_number'] ?? '') . PHP_EOL;
		echo 'id_1c=' . ($parsed['id_1c'] ?? '') . PHP_EOL;
		echo 'candidates=' . implode(',', $parsed['order_candidates'] ?? []) . PHP_EOL;
		echo 'order_id=' . (int)$orderId . PHP_EOL;
		echo '---' . PHP_EOL;
	}
	exit(0);
}

echo 'code=' . $arg . PHP_EOL;
echo 'candidates=' . implode(',', mf_1c_import_order_number_candidates($arg)) . PHP_EOL;
$orderId = mf_1c_import_find_order_id_by_code($arg);
echo 'getOrderIdByDocument=' . (int)$orderId . PHP_EOL;
foreach (mf_1c_import_order_number_candidates($arg) as $candidate)
{
	$found = mf_1c_import_find_order_id_by_code($candidate);
	echo $candidate . ' => ' . (int)$found . PHP_EOL;
}
