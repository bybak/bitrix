<?php

/**
 * Admin page stub for analogs import.
 * Actual logic lives in /bitrix/php_interface/include/mf_import_analogs_admin.php
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

$impl = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_import_analogs_admin.php';
if (is_file($impl))
{
	require $impl;
}
else
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	echo '<div style="padding:12px">Файл импорта не найден: <code>' . htmlspecialchars($impl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></div>';
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
}

