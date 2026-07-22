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
	if (function_exists('mf_statistic_apply_request_skip'))
	{
		mf_statistic_apply_request_skip();
	}
	elseif (function_exists('mf_statistic_apply_crawler_activity_skip'))
	{
		mf_statistic_apply_crawler_activity_skip();
	}
}
unset($mfStatisticDefence);

$mfUrlCanonical = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_url_canonical.php';
if (is_file($mfUrlCanonical))
{
	require_once $mfUrlCanonical;
	if (function_exists('mf_url_apply_catalog_trailing_slash_redirect'))
	{
		mf_url_apply_catalog_trailing_slash_redirect();
	}
}
unset($mfUrlCanonical);

mb_internal_encoding("UTF-8");
