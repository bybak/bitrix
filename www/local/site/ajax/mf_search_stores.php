<?php
define('STOP_STATISTICS', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

header('Content-Type: application/json; charset=UTF-8');

try
{
	$productIds = [];
	if (isset($_REQUEST['productIds']))
	{
		$raw = $_REQUEST['productIds'];
		if (is_array($raw))
		{
			foreach ($raw as $v)
			{
				$id = (int)$v;
				if ($id > 0)
				{
					$productIds[$id] = true;
				}
			}
		}
	}
	$productIds = array_keys($productIds);
	if (count($productIds) > 40)
	{
		$productIds = array_slice($productIds, 0, 40);
	}

	$renderLib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_search_render.php';
	if (is_file($renderLib))
	{
		require_once $renderLib;
	}

	$blocks = [];
	if (!empty($productIds) && function_exists('mf_search_stores_payload_for_products'))
	{
		$blocks = mf_search_stores_payload_for_products($productIds);
	}

	echo json_encode([
		'ok' => true,
		'blocks' => $blocks,
	], JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e)
{
	echo json_encode([
		'ok' => false,
		'error' => 'Не удалось загрузить склады.',
	], JSON_UNESCAPED_UNICODE);
}
