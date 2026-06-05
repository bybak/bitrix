<?php

/**
 * Проверка записи лога обмена 1С на сервере.
 * php local/tools/mf_1c_exchange_log_test.php
 */

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../..');
$paths = [
	$_SERVER['DOCUMENT_ROOT'] . '/upload/1c_exchange_debug.log',
	dirname(__DIR__, 2) . '/upload/1c_exchange_debug.log',
	'/tmp/1c_exchange_debug.log',
];

$line = date('Y-m-d H:i:s') . " TEST from mf_1c_exchange_log_test.php DOCUMENT_ROOT=" . $_SERVER['DOCUMENT_ROOT'] . "\n";

foreach ($paths as $path)
{
	$dir = dirname($path);
	if (!is_dir($dir))
	{
		@mkdir($dir, 0775, true);
	}
	$ok = @file_put_contents($path, $line, FILE_APPEND);
	echo $path . ' => ' . ($ok !== false ? 'OK' : 'FAIL') . PHP_EOL;
}

$exchangeFile = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/sale/admin/1c_exchange.php';
echo PHP_EOL . '1c_exchange.php has ENTRY marker: ';
echo (is_file($exchangeFile) && strpos((string)file_get_contents($exchangeFile), 'ENTRY[v20260606]') !== false) ? 'YES' : 'NO';
echo PHP_EOL;
