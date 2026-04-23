<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

$impl = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_supplier_orders_admin.php';
if (is_file($impl))
{
	require $impl;
}
else
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	echo '<div style="padding:12px">Файл не найден: <code>' . htmlspecialcharsbx($impl) . '</code></div>';
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
}
