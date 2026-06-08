<?php
$DBDebug = false;
$DBDebugToFile = false;

define("BX_FILE_PERMISSIONS", 0644);
define("BX_DIR_PERMISSIONS", 0755);
@umask(~(BX_FILE_PERMISSIONS | BX_DIR_PERMISSIONS) & 0777);

define("BX_DISABLE_INDEX_PAGE", true);

$mfStatisticDefence = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_statistic_defence.php';
if (is_file($mfStatisticDefence))
{
	require_once $mfStatisticDefence;
	if (function_exists('mf_statistic_apply_crawler_activity_skip'))
	{
		mf_statistic_apply_crawler_activity_skip();
	}
}
unset($mfStatisticDefence);

mb_internal_encoding("UTF-8");
