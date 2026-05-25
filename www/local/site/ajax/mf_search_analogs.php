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
		elseif (is_scalar($raw))
		{
			foreach (preg_split('/\s*,\s*/', (string)$raw) as $v)
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

	$limit = (int)($_REQUEST['limit'] ?? 8);
	if ($limit <= 0 || $limit > 12)
	{
		$limit = 8;
	}

	$cardLib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_product_search_card.php';
	if (is_file($cardLib))
	{
		require_once $cardLib;
	}
	$renderLib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_search_render.php';
	if (is_file($renderLib))
	{
		require_once $renderLib;
	}

	$blocks = [];
	if (!empty($productIds) && function_exists('mf_search_analogs_html_for_products'))
	{
		$htmlBlocks = mf_search_analogs_html_for_products($productIds, $limit);
		foreach ($htmlBlocks as $pid => $html)
		{
			$blocks[(string)(int)$pid] = $html;
		}
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
		'error' => 'Не удалось загрузить аналоги.',
	], JSON_UNESCAPED_UNICODE);
}
