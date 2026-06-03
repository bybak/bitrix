<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

$candidates = [
	$_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_import_analogs_bulk_admin.php',
	$_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_import_analogs_bulk_admin.php',
];
$impl = null;
foreach ($candidates as $path)
{
	if (is_file($path))
	{
		$impl = $path;
		break;
	}
}
if ($impl !== null)
{
	require $impl;
}
else
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	echo '<div style="padding:12px">Файл не найден: mf_import_analogs_bulk_admin.php (local или bitrix php_interface)</div>';
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
}
