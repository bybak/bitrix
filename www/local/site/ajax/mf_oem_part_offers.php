<?php
define('STOP_STATISTICS', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

$mfAjaxFile = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_ajax.php';
if (is_file($mfAjaxFile))
{
	require_once $mfAjaxFile;
}
if (function_exists('mf_ajax_session_release'))
{
	mf_ajax_session_release();
}

header('Content-Type: application/json; charset=UTF-8');

try
{
	$partNumber = trim((string)($_REQUEST['partNumber'] ?? $_REQUEST['part_number'] ?? ''));
	$brandHint = trim((string)($_REQUEST['brand'] ?? ''));
	$rootHint = trim((string)($_REQUEST['root'] ?? $_REQUEST['root_arib'] ?? ''));

	$lib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_oem_part_offers.php';
	if (!is_file($lib))
	{
		throw new RuntimeException('Offers module not found');
	}
	require_once $lib;

	$result = mf_oem_part_offers_payload($partNumber, $brandHint, $rootHint);
	if (empty($result['ok']))
	{
		http_response_code(400);
	}

	echo json_encode($result, JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e)
{
	http_response_code(400);
	echo json_encode([
		'ok' => false,
		'part_number' => trim((string)($_REQUEST['partNumber'] ?? '')),
		'products' => [],
		'empty_message' => '',
		'error' => 'Не удалось загрузить предложения.',
	], JSON_UNESCAPED_UNICODE);
}
