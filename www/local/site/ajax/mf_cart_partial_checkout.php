<?php
declare(strict_types=1);

define('STOP_STATISTICS', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: application/json; charset=UTF-8');

try
{
	if (!function_exists('check_bitrix_sessid') || !check_bitrix_sessid())
	{
		throw new RuntimeException('Сессия истекла. Обновите страницу.');
	}

	$mfFile = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_cart_partial.php';
	if (!is_file($mfFile))
	{
		throw new RuntimeException('Сервис недоступен');
	}
	require_once $mfFile;

	if (!function_exists('mf_cart_partial_apply_delay'))
	{
		throw new RuntimeException('Сервис недоступен');
	}

	$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
	$raw = $request->getPost('selected_ids');
	if (!is_array($raw))
	{
		$raw = [];
	}

	$result = mf_cart_partial_apply_delay($raw);
	if (empty($result['ok']))
	{
		throw new RuntimeException((string)($result['error'] ?: 'Не удалось подготовить корзину'));
	}

	$path = '/personal/order/make/';
	echo \Bitrix\Main\Web\Json::encode([
		'ok' => true,
		'redirect' => $path,
	]);
}
catch (Throwable $e)
{
	http_response_code(400);
	echo \Bitrix\Main\Web\Json::encode([
		'ok' => false,
		'error' => $e->getMessage(),
	]);
}
