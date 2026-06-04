<?php
const BX_SESSION_ID_CHANGE = false;
const BX_SKIP_POST_UNQUOTE = true;
const NO_AGENT_CHECK = true;
const STATISTIC_SKIP_ACTIVITY_CHECK = true;
const BX_FORCE_DISABLE_SEPARATED_SESSION_MODE = true;
// Обмен 1С: авторизация через mode=checkauth, не через HTML-форму /bitrix/admin/
define('NOT_CHECK_PERMISSIONS', true);
define('NOT_CHECK_FILE_PERMISSIONS', true);

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

	//--------------------------------------------------
	file_put_contents(
		$_SERVER["DOCUMENT_ROOT"]."/upload/1c_exchange_debug.log",
		date("Y-m-d H:i:s")." REQUEST: ".print_r($_REQUEST, true)."\n",
		FILE_APPEND
	);
	
	if (!empty($_FILES)) {
		file_put_contents(
			$_SERVER["DOCUMENT_ROOT"]."/upload/1c_exchange_debug.log",
			"FILES: ".print_r($_FILES, true)."\n",
			FILE_APPEND
		);
	}

	$statusUpdates = [];

	if (
		($_REQUEST['mode'] ?? '') === 'import'
		&& !empty($_REQUEST['filename'])
	) {
		$file = $_SERVER['DOCUMENT_ROOT'] . '/upload/1c_exchange/' . basename((string)$_REQUEST['filename']);

		file_put_contents(
			$_SERVER['DOCUMENT_ROOT'] . '/upload/1c_exchange_debug.log',
			"IMPORT PATCH CHECK FILE: {$file}\n",
			FILE_APPEND
		);

		if (is_file($file)) {
			$xmlString = file_get_contents($file);

			$xmlString = str_replace(
				'<Наименование>Статуса заказа ИД</Наименование>',
				'<Наименование>Статус заказа ИД</Наименование>',
				$xmlString
			);

			file_put_contents($file, $xmlString);

			$dom = new DOMDocument();
			$dom->loadXML($xmlString);

			$xpath = new DOMXPath($dom);
			$xpath->registerNamespace('cml', 'urn:1C.ru:commerceml_210');

			foreach ($xpath->query('//cml:Документ') as $docNode) {
				$numberNode = $xpath->query('cml:Номер', $docNode)->item(0);
				$number = $numberNode ? trim($numberNode->nodeValue) : '';

				$statusId = '';

				foreach ($xpath->query('cml:ЗначенияРеквизитов/cml:ЗначениеРеквизита', $docNode) as $reqNode) {
					$nameNode = $xpath->query('cml:Наименование', $reqNode)->item(0);
					$valueNode = $xpath->query('cml:Значение', $reqNode)->item(0);

					$name = $nameNode ? trim($nameNode->nodeValue) : '';
					$value = $valueNode ? trim($valueNode->nodeValue) : '';

					if ($name === 'Статус заказа ИД') {
						$statusId = $value;
					}
				}

				if ($number !== '' && $statusId !== '') {
					$statusUpdates[] = [
						'number' => $number,
						'status' => $statusId,
					];
				}
			}

			file_put_contents(
				$_SERVER['DOCUMENT_ROOT'] . '/upload/1c_exchange_debug.log',
				'STATUS UPDATES PARSED: ' . print_r($statusUpdates, true) . "\n",
				FILE_APPEND
			);
		}
	}

	if (!empty($statusUpdates)) {
		register_shutdown_function(static function () use ($statusUpdates) {
			if (!CModule::IncludeModule('sale')) {
				file_put_contents(
					$_SERVER['DOCUMENT_ROOT'] . '/upload/1c_exchange_debug.log',
					"FORCE STATUS UPDATE: sale module not loaded\n",
					FILE_APPEND
				);
				return;
			}

			foreach ($statusUpdates as $update) {
				$number = $update['number'];
				$status = $update['status'];

				$digits = preg_replace('/\D+/', '', $number);

				// s1288 / 1c-001288 -> 288
				$orderNumber = $digits;
				if (strlen($digits) > 3) {
					$orderNumber = (string)((int)substr($digits, -3));
				}

				$filter = [
					'LOGIC' => 'OR',
					['ID' => (int)$orderNumber],
					['ACCOUNT_NUMBER' => $orderNumber],
					['ACCOUNT_NUMBER' => $number],
				];

				$orderRow = \Bitrix\Sale\Order::getList([
					'filter' => $filter,
					'select' => ['ID', 'ACCOUNT_NUMBER', 'STATUS_ID'],
					'limit' => 1,
				])->fetch();

				if (!$orderRow) {
					file_put_contents(
						$_SERVER['DOCUMENT_ROOT'] . '/upload/1c_exchange_debug.log',
						"FORCE STATUS UPDATE: order not found for number={$number}\n",
						FILE_APPEND
					);
					continue;
				}

				$order = \Bitrix\Sale\Order::load((int)$orderRow['ID']);

				if (!$order) {
					file_put_contents(
						$_SERVER['DOCUMENT_ROOT'] . '/upload/1c_exchange_debug.log',
						"FORCE STATUS UPDATE: order load failed id={$orderRow['ID']}\n",
						FILE_APPEND
					);
					continue;
				}

				if ($order->getField('STATUS_ID') === $status) {
					file_put_contents(
						$_SERVER['DOCUMENT_ROOT'] . '/upload/1c_exchange_debug.log',
						"FORCE STATUS UPDATE: already status={$status} order={$orderRow['ID']}\n",
						FILE_APPEND
					);
					continue;
				}

				$order->setField('STATUS_ID', $status);
				$result = $order->save();

				file_put_contents(
					$_SERVER['DOCUMENT_ROOT'] . '/upload/1c_exchange_debug.log',
					'FORCE STATUS UPDATE: order=' . $orderRow['ID'] .
					' account=' . $orderRow['ACCOUNT_NUMBER'] .
					' old=' . $orderRow['STATUS_ID'] .
					' new=' . $status .
					' result=' . ($result->isSuccess() ? 'OK' : implode('; ', $result->getErrorMessages())) .
					"\n",
					FILE_APPEND
				);
			}
		});
	}

	if (
		($_REQUEST['mode'] ?? '') === 'import'
		&& !empty($_REQUEST['filename'])
	) {
		$file = $_SERVER['DOCUMENT_ROOT'] . '/upload/1c_exchange/' . basename($_REQUEST['filename']);
	
		if (file_exists($file)) {
	
			copy(
				$file,
				$_SERVER['DOCUMENT_ROOT'] . '/upload/debug_' . time() . '_' . basename($file)
			);
	
			file_put_contents(
				$_SERVER['DOCUMENT_ROOT'].'/upload/1c_exchange_debug.log',
				"\n\n==================== XML DUMP ====================\n".
				file_get_contents($file).
				"\n================== END XML DUMP ==================\n\n",
				FILE_APPEND
			);
		}
	}
	//--------------------------------------------------
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
