<?php
define('STOP_STATISTICS', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

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
	$query = trim((string)($_REQUEST['q'] ?? $_REQUEST['query'] ?? ''));
	$stage = (int)($_REQUEST['stage'] ?? 0);
	if ($query === '' || !in_array($stage, [2, 3], true))
	{
		echo json_encode([
			'ok' => false,
			'error' => 'bad request',
		], JSON_UNESCAPED_UNICODE);
		return;
	}

	$excludeIds = [];
	if (isset($_REQUEST['productIds']))
	{
		$raw = $_REQUEST['productIds'];
		if (!is_array($raw))
		{
			$raw = [$raw];
		}
		foreach ($raw as $v)
		{
			$id = (int)$v;
			if ($id > 0)
			{
				$excludeIds[$id] = true;
			}
		}
	}
	$excludeIds = array_keys($excludeIds);

	$searchLib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_catalog_search_lib.php';
	if (is_file($searchLib))
	{
		require_once $searchLib;
	}

	$renderLib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_search_render.php';
	if (is_file($renderLib))
	{
		require_once $renderLib;
	}

	if (!function_exists('mf_catalog_search_collect_stage'))
	{
		throw new RuntimeException('search lib unavailable');
	}

	$collected = mf_catalog_search_collect_stage($query, $stage, $excludeIds);
	$ids = array_values(array_map('intval', (array)($collected['ids'] ?? [])));

	$html = '';
	if (!empty($ids) && function_exists('mf_search_render_product_cards_html'))
	{
		$html = mf_search_render_product_cards_html($ids);
	}

	echo json_encode([
		'ok' => true,
		'stage' => $stage,
		'added' => count($ids),
		'html' => $html,
		'nextStage' => ($stage === 2 ? 3 : null),
		'done' => ($stage === 3),
	], JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e)
{
	echo json_encode([
		'ok' => false,
		'error' => 'Не удалось выполнить поиск.',
	], JSON_UNESCAPED_UNICODE);
}
