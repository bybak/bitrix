<?php

const STOP_STATISTICS = true;
const NO_KEEP_STATISTIC = 'Y';
const NO_AGENT_STATISTIC = 'Y';
const DisableEventsCheck = true;
const BX_SECURITY_SHOW_MESSAGE = true;
const NOT_CHECK_PERMISSIONS = true;

$siteId = isset($_REQUEST['SITE_ID']) && is_string($_REQUEST['SITE_ID']) ? $_REQUEST['SITE_ID'] : '';
$siteId = mb_substr(preg_replace('/[^a-z0-9_]/i', '', $siteId), 0, 2);
if (!empty($siteId) && is_string($siteId))
{
	define('SITE_ID', $siteId);
}

require_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');

$request = Bitrix\Main\Application::getInstance()->getContext()->getRequest();

if (!Bitrix\Main\Loader::includeModule('sale'))
{
	return;
}

Bitrix\Main\Localization\Loc::loadMessages(__DIR__.'/class.php');

$signer = new \Bitrix\Main\Security\Sign\Signer;
try
{
	$signedParamsString = $request->get('signedParamsString') ?: '';
	$params = $signer->unsign($signedParamsString, 'sale.order.ajax');
	$params = unserialize(base64_decode($params), ['allowed_classes' => false]);
}
catch (\Bitrix\Main\Security\Sign\BadSignatureException $e)
{
	die();
}

if (!is_array($params))
{
	return;
}

// AJAX sale.order.ajax вызывается с пути ajax.php, а не /personal/order/make/ — здесь подстраховываем
// кастомный чекаут, если в подписанных параметрах потерялся MF_CUSTOM_GUEST_FLOW.
if (($params['MF_ORDER_MAKE_SIGNATURE'] ?? 'N') === 'Y')
{
	$params['MF_CUSTOM_GUEST_FLOW'] = 'Y';
}
elseif (
	($params['MF_CUSTOM_GUEST_FLOW'] ?? 'N') !== 'Y'
	&& ($params['ALLOW_AUTO_REGISTER'] ?? '') === 'N'
	&& ($params['DELIVERY_NO_AJAX'] ?? '') === 'H'
	&& !empty($params['PATH_TO_ORDER'])
	&& is_string($params['PATH_TO_ORDER'])
	&& str_contains(str_replace('\\', '/', $params['PATH_TO_ORDER']), 'personal/order/make')
)
{
	$params['MF_CUSTOM_GUEST_FLOW'] = 'Y';
}

$action = $request->get($params['ACTION_VARIABLE']);
if (empty($action))
{
	return;
}

// Motor-Force debug: log incoming location/profile values on localhost.
// Helps diagnose "location resets to Moscow" issues.
try
{
	$host = (string)($_SERVER['HTTP_HOST'] ?? '');
	// In Docker, browser requests usually come from 172.* addresses.
	// We treat localhost host header as sufficient signal for local debug.
	$isLocal = ($host === 'localhost' || $host === '127.0.0.1');

	if ($isLocal && in_array($action, ['refreshOrderAjax', 'saveOrderAjax'], true))
	{
		$order = $request->get('order');
		$order = is_array($order) ? $order : [];

		$pick = static function(array $src, array $keys): array {
			$out = [];
			foreach ($keys as $k)
			{
				if (array_key_exists($k, $src))
				{
					$out[$k] = $src[$k];
				}
			}
			return $out;
		};

		$interesting = $pick($order, [
			'PERSON_TYPE',
			'PERSON_TYPE_OLD',
			'PROFILE_ID',
			'profile_change',
			'location_type',
			'RECENT_DELIVERY_VALUE',
			'ZIP_PROPERTY_CHANGED',
			'ORDER_PROP_6',
			'ORDER_PROP_4',
			'ORDER_PROP_5',
			'ORDER_PROP_22',
			'ORDER_PROP_23',
			'ORDER_PROP_24',
			'ORDER_PROP_18',
			'ORDER_PROP_16',
			'ORDER_PROP_17',
			'ORDER_PROP_25',
			'ORDER_PROP_26',
			'ORDER_PROP_27',
			'MF_DELIVERY_MODE',
			'MF_DELIVERY_ADDRESS',
			'MF_DELIVERY_LOCATION_TEXT',
			'MF_DELIVERY_ZIP',
			'MF_EDOST_TARIF_ID',
		]);

		if ($action === 'saveOrderAjax')
		{
			$postKeys = array_keys($interesting);
			foreach ($postKeys as $pk)
			{
				if ($request->getPost($pk) !== null)
				{
					$interesting[$pk] = $request->getPost($pk);
				}
			}
		}

		$line = date('c') . ' action=' . $action . ' ' . json_encode($interesting, JSON_UNESCAPED_UNICODE) . PHP_EOL;
		@file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/.tmp_order_make_loc.log', $line, FILE_APPEND);
	}
}
catch (\Throwable $e)
{
	// ignore
}

global $APPLICATION;

$APPLICATION->IncludeComponent(
	'bitrix:sale.order.ajax',
	'bootstrap_v4',
	$params
);
