<?php

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../..');
require $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_order_account_display.php';
require $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_1c_import_statuses.php';

$numbers = ['s1291', 's11234', 's1234', 's112345', 's12345', '291', '1234', '28-313', '0-313'];

foreach ($numbers as $number)
{
	$candidates = mf_1c_import_order_number_candidates($number);
	echo $number . ' => primary=' . mf_1c_import_resolve_order_number($number)
		. ' all=[' . implode(',', $candidates) . ']' . PHP_EOL;
}
