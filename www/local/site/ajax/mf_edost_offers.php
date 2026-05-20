<?php
declare(strict_types=1);

// Returns eDost offers for current basket + destination.
// Used by checkout UI to display "virtual" delivery tariffs without creating delivery services in DB.

const STOP_STATISTICS = true;
const NO_KEEP_STATISTIC = 'Y';
const NO_AGENT_STATISTIC = 'Y';
const DisableEventsCheck = true;
const BX_SECURITY_SHOW_MESSAGE = true;
const NOT_CHECK_PERMISSIONS = true;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Fuser;

header('Content-Type: application/json; charset=UTF-8');

try
{
	if (!Loader::includeModule('sale'))
	{
		echo Json::encode(['ok' => false, 'error' => 'sale module not available']);
		exit;
	}

	if (!check_bitrix_sessid())
	{
		echo Json::encode(['ok' => false, 'error' => 'bad sessid']);
		exit;
	}

	require_once($_SERVER['DOCUMENT_ROOT'] . '/mf_delivery_tariffed.php');

	$req = Application::getInstance()->getContext()->getRequest();
	$locationCode = trim((string)$req->getPost('location_code'));
	$zip = preg_replace('~\D+~', '', (string)$req->getPost('zip'));
	$mfEdostToCity = trim((string)$req->getPost('mf_edost_to_city'));
	$nomJsonRaw = trim((string)$req->getPost('mf_nominatim_json'));

	$dest = \MF\Delivery\Edost::resolveDeliveryDestination($nomJsonRaw, $mfEdostToCity, $locationCode, $zip);
	if ($dest['settlement'] === '' && $dest['region'] === '')
	{
		echo Json::encode(['ok' => false, 'error' => 'cannot resolve to_city']);
		exit;
	}

	$siteId = (string)Application::getInstance()->getContext()->getSite();
	if ($siteId === '')
	{
		$siteId = 's1';
	}

	$fuserId = Fuser::getId();
	$basket = Basket::loadItemsForFUser($fuserId, $siteId);

	$weightGrams = 0.0;
	$sum = 0.0;
	foreach ($basket as $item)
	{
		/** @var \Bitrix\Sale\BasketItem $item */
		$q = (float)$item->getQuantity();
		$w = (float)$item->getWeight(); // grams per item
		$weightGrams += max(0.0, $w) * max(0.0, $q);
		$sum += (float)$item->getFinalPrice();
	}

	$weightKg = $weightGrams > 0 ? ($weightGrams / 1000.0) : 0.001;
	$insurance = \MF\Delivery\Edost::insuranceRubForBasketSum($sum);
	$dimensionsM = \MF\Delivery\Edost::parcelDimensionsMFromBasketItems($basket);

	$resp = \MF\Delivery\Edost::calculateForDestination(
		$dest['settlement'],
		$dest['region'],
		$weightKg,
		$insurance,
		$dest['zip'],
		$dimensionsM
	);
	if (!is_array($resp) || !($resp['ok'] ?? false) || !isset($resp['offers']) || !is_array($resp['offers']))
	{
		echo Json::encode([
			'ok' => false,
			'error' => 'edost error',
			'resp' => $resp,
			'destination' => $dest,
		]);
		exit;
	}

	$offers = array_values(array_filter($resp['offers'], static function ($o) {
		return is_array($o) && trim((string)($o['id'] ?? '')) !== '';
	}));

	usort($offers, static function ($a, $b) {
		$ca = mb_strtolower(trim((string)($a['company'] ?? '')));
		$cb = mb_strtolower(trim((string)($b['company'] ?? '')));
		if ($ca !== $cb)
		{
			return $ca <=> $cb;
		}
		$pa = (float)($a['price'] ?? 0);
		$pb = (float)($b['price'] ?? 0);
		if ($pa !== $pb)
		{
			return $pa <=> $pb;
		}
		$na = mb_strtolower(trim((string)($a['name'] ?? '')));
		$nb = mb_strtolower(trim((string)($b['name'] ?? '')));
		return $na <=> $nb;
	});

	echo Json::encode([
		'ok' => true,
		'to_city' => (string)($resp['to_city_used'] ?? $dest['settlement']),
		'settlement' => $dest['settlement'],
		'region' => $dest['region'],
		'destination_mode' => (string)($resp['destination_mode'] ?? ''),
		'zip' => $dest['zip'],
		'weight_kg' => round($weightKg, 3),
		'insurance_rub' => round($insurance, 2),
		'dimensions_m' => $dimensionsM,
		'basket_sum' => round($sum, 2),
		'offers' => $offers,
	]);
}
catch (\Throwable $e)
{
	echo Json::encode(['ok' => false, 'error' => 'exception', 'message' => $e->getMessage()]);
}
