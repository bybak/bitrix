<?php
const BX_SESSION_ID_CHANGE = false;
const BX_SKIP_POST_UNQUOTE = true;
const NO_AGENT_CHECK = true;
const STATISTIC_SKIP_ACTIVITY_CHECK = true;
const BX_FORCE_DISABLE_SEPARATED_SESSION_MODE = true;
// Обмен 1С: авторизация через mode=checkauth, не через HTML-форму /bitrix/admin/
define('NOT_CHECK_PERMISSIONS', true);
define('NOT_CHECK_FILE_PERMISSIONS', true);

if (!function_exists('mf1c_exchange_www_root'))
{
	function mf1c_exchange_www_root(): string
	{
		static $root = null;
		if (is_string($root))
		{
			return $root;
		}

		$moduleRoot = dirname(__DIR__, 4);
		$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
		$root = ($docRoot !== '' && is_dir($docRoot)) ? $docRoot : $moduleRoot;

		return $root;
	}
}

if (!function_exists('mf1c_exchange_upload_file_candidates'))
{
	function mf1c_exchange_upload_file_candidates(string $exchangeFilename): array
	{
		$exchangeFilename = basename($exchangeFilename);
		$candidates = [];
		$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
		if ($docRoot !== '')
		{
			$candidates[] = $docRoot . '/upload/1c_exchange/' . $exchangeFilename;
		}
		$candidates[] = dirname(__DIR__, 4) . '/upload/1c_exchange/' . $exchangeFilename;
		$candidates[] = '/var/www/html/upload/1c_exchange/' . $exchangeFilename;
		$candidates[] = '/www/bitrix_motor_force/www/upload/1c_exchange/' . $exchangeFilename;
		$candidates[] = mf1c_exchange_www_root() . '/upload/1c_exchange/' . $exchangeFilename;

		return array_values(array_unique(array_filter($candidates)));
	}
}

if (!function_exists('mf1c_exchange_resolve_upload_file'))
{
	function mf1c_exchange_resolve_upload_file(string $exchangeFilename): string
	{
		$exchangeFile = '';
		foreach (mf1c_exchange_upload_file_candidates($exchangeFilename) as $candidate)
		{
			mf1c_exchange_debug_log(
				'XML candidate: ' . $candidate . ' exists=' . (is_file($candidate) ? 'Y' : 'N')
			);
			if (is_file($candidate))
			{
				$exchangeFile = $candidate;
				break;
			}
		}

		return $exchangeFile;
	}
}

if (!function_exists('mf1c_exchange_debug_log_file'))
{
	function mf1c_exchange_debug_log_file(): string
	{
		static $logFile = null;
		if (is_string($logFile))
		{
			return $logFile;
		}

		$logFile = mf1c_exchange_www_root() . '/upload/1c_exchange_debug.log';
		$dir = dirname($logFile);
		if (!is_dir($dir))
		{
			@mkdir($dir, 0775, true);
		}
		if (!is_file($logFile))
		{
			@touch($logFile);
		}
		if (is_file($logFile) && !is_writable($logFile))
		{
			@chmod($logFile, 0666);
		}

		return $logFile;
	}
}

if (!function_exists('mf1c_exchange_debug_log'))
{
	function mf1c_exchange_debug_log(string $message): void
	{
		$line = date('Y-m-d H:i:s') . ' ' . $message . "\n";
		$logFile = mf1c_exchange_debug_log_file();
		$ok = file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
		if ($ok === false)
		{
			$fallback = mf1c_exchange_www_root() . '/upload/1c_exchange_debug.fpm.log';
			file_put_contents(
				$fallback,
				$line . '[primary log not writable: ' . $logFile . "]\n",
				FILE_APPEND | LOCK_EX
			);
		}
	}
}

mf1c_exchange_debug_log(
	'ENTRY[v20260607] type=' . (string)($_REQUEST['type'] ?? '')
	. ' mode=' . (string)($_REQUEST['mode'] ?? '')
	. ' filename=' . (string)($_REQUEST['filename'] ?? '')
	. ' method=' . (string)($_SERVER['REQUEST_METHOD'] ?? '')
	. ' www_root=' . mf1c_exchange_www_root()
	. ' log=' . mf1c_exchange_debug_log_file()
);

$__mf1cExchangeDebugBootstrap = __DIR__ . '/../../../../local/php_interface/include/mf_1c_exchange_debug.php';
if (is_file($__mf1cExchangeDebugBootstrap))
{
	require_once $__mf1cExchangeDebugBootstrap;
}
unset($__mf1cExchangeDebugBootstrap);

/** @global CMain $APPLICATION */
/** @global CUserTypeManager $CACHE_MANAGER */

$type = (string)($_REQUEST['type'] ?? '');
if ($type === "crm")
{
	define("ADMIN_SECTION", true);
}

if (isset($_SERVER["REQUEST_METHOD"]) && $_SERVER["REQUEST_METHOD"] === "GET")
{
	//from main 20.0.1300 only POST allowed
	if(isset($_GET["USER_LOGIN"]) && isset($_GET["USER_PASSWORD"]) && isset($_GET["AUTH_FORM"]) && isset($_GET["TYPE"]))
	{
		$_POST["USER_LOGIN"] = $_GET["USER_LOGIN"];
		$_POST["USER_PASSWORD"] = $_GET["USER_PASSWORD"];
		$_POST["AUTH_FORM"] = $_GET["AUTH_FORM"];
		$_POST["TYPE"] = $_GET["TYPE"];
	}
}

$mf1cAuthInclude = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_1c_exchange_auth.php';
if (is_file($mf1cAuthInclude))
{
	require_once $mf1cAuthInclude;
	if (function_exists('mf_1c_exchange_restore_basic_auth_server_vars'))
	{
		mf_1c_exchange_restore_basic_auth_server_vars();
	}
}

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

if (function_exists('mf_1c_exchange_ensure_user_authorized'))
{
	mf_1c_exchange_ensure_user_authorized();
}

if ($type === 'sale')
{
	$requestLog = $_REQUEST;
	foreach (['USER_PASSWORD', 'PASSWORD', 'USER_PASS'] as $secretKey)
	{
		if (isset($requestLog[$secretKey]))
		{
			$requestLog[$secretKey] = '***';
		}
	}
	mf1c_exchange_debug_log('REQUEST: ' . print_r($requestLog, true));
	if (!empty($_FILES))
	{
		mf1c_exchange_debug_log('FILES: ' . print_r($_FILES, true));
	}

	$mf1cImportStatusesInclude = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_1c_import_statuses.php';
	if (is_file($mf1cImportStatusesInclude))
	{
		require_once $mf1cImportStatusesInclude;
	}

	$exchangeMode = (string)($_REQUEST['mode'] ?? '');
	$exchangeFilename = !empty($_REQUEST['filename'])
		? basename((string)$_REQUEST['filename'])
		: '';

	if ($exchangeFilename !== '')
	{
		if ($exchangeMode === 'import' && function_exists('mf_1c_import_register_shutdown'))
		{
			$exchangeFile = mf1c_exchange_resolve_upload_file($exchangeFilename);
			if ($exchangeFile !== '')
			{
				mf1c_exchange_debug_log('MF IMPORT: xml=' . $exchangeFile);
				mf_1c_import_register_shutdown($exchangeFile);
			}
			else
			{
				mf1c_exchange_debug_log('MF IMPORT: xml NOT FOUND filename=' . $exchangeFilename);
			}
		}
		elseif ($exchangeMode === 'file')
		{
			register_shutdown_function(static function () use ($exchangeFilename): void {
				$resolvedFile = mf1c_exchange_resolve_upload_file($exchangeFilename);
				if ($resolvedFile === '')
				{
					mf1c_exchange_debug_log('XML DUMP file mode: not found filename=' . $exchangeFilename);
					return;
				}
				$xmlBody = file_get_contents($resolvedFile);
				if (!is_string($xmlBody) || $xmlBody === '')
				{
					mf1c_exchange_debug_log('XML DUMP file mode: empty ' . $resolvedFile);
					return;
				}
				$size = strlen($xmlBody);
				mf1c_exchange_debug_log(
					"==================== XML DUMP (file mode): " . basename($resolvedFile) . " ({$size} bytes) ====================\n"
					. $xmlBody
					. "\n================== END XML DUMP =================="
				);
			});
		}
	}

	$APPLICATION->IncludeComponent("bitrix:sale.export.1c", "", Array(
		"SITE_LIST" => COption::GetOptionString("sale", "1C_SALE_SITE_LIST", ""),
		"EXPORT_PAYED_ORDERS" => COption::GetOptionString("sale", "1C_EXPORT_PAYED_ORDERS", ""),
		"EXPORT_ALLOW_DELIVERY_ORDERS" => COption::GetOptionString("sale", "1C_EXPORT_ALLOW_DELIVERY_ORDERS", ""),
		"EXPORT_FINAL_ORDERS" => COption::GetOptionString("sale", "1C_EXPORT_FINAL_ORDERS", ""),
		"CHANGE_STATUS_FROM_1C" => "Y",
		"FINAL_STATUS_ON_DELIVERY" => COption::GetOptionString("sale", "1C_FINAL_STATUS_ON_DELIVERY", "F"),
		"REPLACE_CURRENCY" => COption::GetOptionString("sale", "1C_REPLACE_CURRENCY", ""),
		"GROUP_PERMISSIONS" => explode(",", COption::GetOptionString("sale", "1C_SALE_GROUP_PERMISSIONS", "1")),
		"USE_ZIP" => COption::GetOptionString("sale", "1C_SALE_USE_ZIP", "Y"),
		"INTERVAL" => COption::GetOptionString("sale", "1C_INTERVAL", 30),
		"FILE_SIZE_LIMIT" => COption::GetOptionString("sale", "1C_FILE_SIZE_LIMIT", 200*1024),
		"SITE_NEW_ORDERS" => COption::GetOptionString("sale", "1C_SITE_NEW_ORDERS", "s1"),
		"IMPORT_NEW_ORDERS" => COption::GetOptionString("sale", "1C_IMPORT_NEW_ORDERS", "N"),
	));
	
	die();
}
elseif ($type === "crm")
{
	if($_SERVER["REQUEST_METHOD"] == "POST")
	{
		$orderId = intval($_POST["ORDER_ID"]);
		$modifLabel = intval($_POST["MODIFICATION_LABEL"]);
		$ZZZ = intval($_POST["ZZZ"]);
		$IMPORT_SIZE = intval($_POST["IMPORT_SIZE"]);
		$GZ_COMPRESSION_SUPPORTED = intval($_POST["GZ_COMPRESSION_SUPPORTED"]);
	}
	else
	{
		$orderId = intval($_GET["ORDER_ID"]);
		$modifLabel = intval($_GET["MODIFICATION_LABEL"]);
		$ZZZ = intval($_GET["ZZZ"]);
		$IMPORT_SIZE = intval($_GET["IMPORT_SIZE"]);
		$GZ_COMPRESSION_SUPPORTED = intval($_GET["GZ_COMPRESSION_SUPPORTED"]);
	}

	$APPLICATION->IncludeComponent("bitrix:sale.export.1c", "", Array(
			"CRM_MODE" => "Y",
			"ORDER_ID" => $orderId,
			"MODIFICATION_LABEL" => $modifLabel,
			"ZZZ" => $ZZZ,
			"IMPORT_SIZE" => $IMPORT_SIZE,
			"GZ_COMPRESSION_SUPPORTED" => $GZ_COMPRESSION_SUPPORTED,
			"GROUP_PERMISSIONS" => explode(",", COption::GetOptionString("sale", "1C_SALE_GROUP_PERMISSIONS", "1")),
			"REPLACE_CURRENCY" => COption::GetOptionString("sale", "1C_REPLACE_CURRENCY", ""),
			"USE_ZIP" => "N",
		)
	);
	die();
}
elseif ($type === "catalog")
{
	$APPLICATION->IncludeComponent(
		"bitrix:catalog.import.1c",
		"",
		[
			"IBLOCK_TYPE" => COption::GetOptionString("catalog", "1C_IBLOCK_TYPE", "-"),
			"SITE_LIST" => [COption::GetOptionString("catalog", "1C_SITE_LIST", "-")],
			"INTERVAL" => COption::GetOptionString("catalog", "1C_INTERVAL", "-"),
			"GROUP_PERMISSIONS" => explode(",", COption::GetOptionString("catalog", "1C_GROUP_PERMISSIONS", "1")),
			"GENERATE_PREVIEW" => COption::GetOptionString("catalog", "1C_GENERATE_PREVIEW", "Y"),
			"PREVIEW_WIDTH" => COption::GetOptionString("catalog", "1C_PREVIEW_WIDTH", "100"),
			"PREVIEW_HEIGHT" => COption::GetOptionString("catalog", "1C_PREVIEW_HEIGHT", "100"),
			"DETAIL_RESIZE" => COption::GetOptionString("catalog", "1C_DETAIL_RESIZE", "Y"),
			"DETAIL_WIDTH" => COption::GetOptionString("catalog", "1C_DETAIL_WIDTH", "300"),
			"DETAIL_HEIGHT" => COption::GetOptionString("catalog", "1C_DETAIL_HEIGHT", "300"),
			"ELEMENT_ACTION" => COption::GetOptionString("catalog", "1C_ELEMENT_ACTION", "D"),
			"SECTION_ACTION" => COption::GetOptionString("catalog", "1C_SECTION_ACTION", "D"),
			"FILE_SIZE_LIMIT" => COption::GetOptionString("catalog", "1C_FILE_SIZE_LIMIT", 200*1024),
			"USE_CRC" => COption::GetOptionString("catalog", "1C_USE_CRC", "Y"),
			"USE_ZIP" => COption::GetOptionString("catalog", "1C_USE_ZIP", "Y"),
			"USE_OFFERS" => COption::GetOptionString("catalog", "1C_USE_OFFERS", "N"),
			"FORCE_OFFERS" => COption::GetOptionString("catalog", "1C_FORCE_OFFERS", "N"),
			"USE_IBLOCK_TYPE_ID" => COption::GetOptionString("catalog", "1C_USE_IBLOCK_TYPE_ID", "N"),
			"USE_IBLOCK_PICTURE_SETTINGS" => COption::GetOptionString("catalog", "1C_USE_IBLOCK_PICTURE_SETTINGS", "N"),
			"TRANSLIT_ON_ADD" => COption::GetOptionString("catalog", "1C_TRANSLIT_ON_ADD", "Y"),
			"TRANSLIT_ON_UPDATE" => COption::GetOptionString("catalog", "1C_TRANSLIT_ON_UPDATE", "Y"),
			"TRANSLIT_REPLACE_CHAR" => COption::GetOptionString("catalog", "1C_TRANSLIT_REPLACE_CHAR", "_"),
			"SKIP_ROOT_SECTION" => COption::GetOptionString("catalog", "1C_SKIP_ROOT_SECTION", "N"),
			"DISABLE_CHANGE_PRICE_NAME" => COption::GetOptionString("catalog", "1C_DISABLE_CHANGE_PRICE_NAME"),
			"IBLOCK_CACHE_MODE" => COption::GetOptionString("catalog", "1C_IBLOCK_CACHE_MODE"),
		]
	);
}
elseif ($type ==="reference")
{
	$APPLICATION->IncludeComponent("bitrix:catalog.import.hl", "", Array(
		"INTERVAL" => COption::GetOptionString("catalog", "1C_INTERVAL", "-"),
		"GROUP_PERMISSIONS" => explode(",", COption::GetOptionString("catalog", "1C_GROUP_PERMISSIONS", "1")),
		"FILE_SIZE_LIMIT" => COption::GetOptionString("catalog", "1C_FILE_SIZE_LIMIT", 200*1024),
		"USE_CRC" => COption::GetOptionString("catalog", "1C_USE_CRC", "Y"),
		"USE_ZIP" => COption::GetOptionString("catalog", "1C_USE_ZIP", "Y"),
		)
	);
}
elseif ($type === "get_catalog")
{
	$APPLICATION->IncludeComponent("bitrix:catalog.export.1c", "", Array(
		"IBLOCK_ID" => COption::GetOptionString("catalog", "1CE_IBLOCK_ID", ""),
		"INTERVAL" => COption::GetOptionString("catalog", "1CE_INTERVAL", "-"),
		"ELEMENTS_PER_STEP" => COption::GetOptionString("catalog", "1CE_ELEMENTS_PER_STEP", 100),
		"GROUP_PERMISSIONS" => explode(",", COption::GetOptionString("catalog", "1CE_GROUP_PERMISSIONS", "1")),
		"USE_ZIP" => COption::GetOptionString("catalog", "1CE_USE_ZIP", "Y"),
		)
	);
}
elseif ($type === "listen")
{
	$APPLICATION->RestartBuffer();

	CModule::IncludeModule('sale');

	$timeLimit = 60;//1 minute
	$startExecTime = time();
	$max_execution_time = (intval(ini_get("max_execution_time")) * 0.75);
	$max_execution_time = ($max_execution_time > $timeLimit )? $timeLimit:$max_execution_time;

	if(CModule::IncludeModule("sale") && defined("CACHED_b_sale_order"))
	{
		while(!$CACHE_MANAGER->getImmediate(CACHED_b_sale_order, "sale_orders"))
		{
			usleep(1000);

			if(intval(time() - $startExecTime) > $max_execution_time)
			{
				break;
			}
		}
	}

	if($CACHE_MANAGER->getImmediate(CACHED_b_sale_order, "sale_orders"))
	{
		echo "success\n";
	}
	else
	{
		CHTTP::SetStatus("304 Not Modified");
	}
}
else
{
	$APPLICATION->RestartBuffer();
	echo "failure\n";
	echo "Unknown command type.";
}

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_after.php");
