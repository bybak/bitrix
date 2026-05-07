<?php
declare(strict_types=1);

// Геоподсказки для оформления заказа. Публичный nominatim.openstreetmap.org для autocomplete
// по политике OSMF недоступен (403). Используем Photon (komoot, данные OSM) — см. комментарий в коде.

const STOP_STATISTICS = true;
const NO_KEEP_STATISTIC = 'Y';
const NO_AGENT_STATISTIC = 'Y';
const DisableEventsCheck = true;
const BX_SECURITY_SHOW_MESSAGE = true;
const NOT_CHECK_PERMISSIONS = true;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Application;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

header('Content-Type: application/json; charset=UTF-8');

/**
 * Photon (https://photon.komoot.io) — GeoJSON Feature → формат, ожидаемый фронтом (как у Nominatim).
 */
function mf_photon_feature_to_item(array $feature): array
{
	$props = isset($feature['properties']) && is_array($feature['properties']) ? $feature['properties'] : [];
	$geom = isset($feature['geometry']) && is_array($feature['geometry']) ? $feature['geometry'] : [];
	$coords = isset($geom['coordinates']) && is_array($geom['coordinates']) ? $geom['coordinates'] : [0.0, 0.0];
	$lon = isset($coords[0]) ? (string)$coords[0] : '';
	$lat = isset($coords[1]) ? (string)$coords[1] : '';

	$city = $props['city'] ?? $props['town'] ?? $props['village'] ?? $props['locality'] ?? '';
	if ($city === '' && !empty($props['name']))
	{
		$city = (string)$props['name'];
	}
	$address = [
		'city' => $city,
		'name' => isset($props['name']) ? (string)$props['name'] : '',
		'town' => $props['town'] ?? '',
		'village' => $props['village'] ?? '',
		'locality' => isset($props['locality']) ? (string)$props['locality'] : '',
		'road' => isset($props['street']) ? (string)$props['street'] : '',
		'house_number' => isset($props['housenumber']) ? (string)$props['housenumber'] : '',
		'postcode' => isset($props['postcode']) ? (string)$props['postcode'] : '',
		'state' => isset($props['state']) ? (string)$props['state'] : '',
		'country' => isset($props['country']) ? (string)$props['country'] : '',
		'county' => isset($props['county']) ? (string)$props['county'] : '',
	];

	$line = [];
	if (!empty($props['name']))
	{
		$line[] = (string)$props['name'];
	}
	$streetPart = trim(((string)($props['street'] ?? '')) . ' ' . ((string)($props['housenumber'] ?? '')));
	if ($streetPart !== '')
	{
		$line[] = $streetPart;
	}
	if ($city !== '')
	{
		$line[] = $city;
	}
	elseif (!empty($props['locality']))
	{
		$line[] = (string)$props['locality'];
	}
	if (!empty($props['state']))
	{
		$line[] = (string)$props['state'];
	}
	if (!empty($props['country']))
	{
		$line[] = (string)$props['country'];
	}
	$display = implode(', ', array_filter($line, static function ($s) {
		return $s !== '';
	}));
	if ($display === '')
	{
		$display = $lat !== '' && $lon !== '' ? ($lat . ', ' . $lon) : '—';
	}

	return [
		'lat' => $lat,
		'lon' => $lon,
		'display_name' => $display,
		'address' => $address,
		'osm_id' => $props['osm_id'] ?? null,
		'osm_type' => $props['osm_type'] ?? null,
		'place_id' => null,
	];
}

/**
 * Кириллический запрос к Photon с lang=default часто не находит зарубежные города
 * (тот же «Майами» даёт улицы в РФ/УА вместо Miami, US — в OSM у города имя на латинице).
 * Для известных топонимов делаем второй поиск по латинской подсказке и мержим результаты (сначала она).
 */
function mf_photon_latin_hints_for_cyrillic_query(string $q): array
{
	$q = mb_strtolower(trim($q), 'UTF-8');
	if ($q === '' || !preg_match('/\p{Cyrillic}/u', $q))
	{
		return [];
	}
	$q = preg_replace('/[.,;]+$/u', '', $q);
	$q = trim($q);
	$q = preg_replace('/\s+/u', ' ', $q);

	static $aliases = [
		'майами' => 'Miami Florida United States',
		'нью-йорк' => 'New York United States',
		'нью йорк' => 'New York United States',
		'лос-анджелес' => 'Los Angeles United States',
		'лос анджелес' => 'Los Angeles United States',
		'сан-франциско' => 'San Francisco United States',
		'сан франциско' => 'San Francisco United States',
		'чикаго' => 'Chicago United States',
		'вашингтон' => 'Washington DC United States',
		'бостон' => 'Boston United States',
		'сиэтл' => 'Seattle United States',
		'филадельфия' => 'Philadelphia United States',
		'хьюстон' => 'Houston United States',
		'феникс' => 'Phoenix United States',
		'детройт' => 'Detroit United States',
		'атланта' => 'Atlanta United States',
		'денвер' => 'Denver United States',
		'лас-вегас' => 'Las Vegas United States',
		'лас вегас' => 'Las Vegas United States',
		'орландо' => 'Orlando United States',
		'лондон' => 'London United Kingdom',
		'берлин' => 'Berlin Germany',
		'париж' => 'Paris France',
		'рим' => 'Rome Italy',
		'мадрид' => 'Madrid Spain',
		'амстердам' => 'Amsterdam Netherlands',
		'вена' => 'Vienna Austria',
		'прага' => 'Prague Czech Republic',
		'варшава' => 'Warsaw Poland',
		'стокгольм' => 'Stockholm Sweden',
		'осло' => 'Oslo Norway',
		'копенгаген' => 'Copenhagen Denmark',
		'хельсинки' => 'Helsinki Finland',
		'дублин' => 'Dublin Ireland',
		'лиссабон' => 'Lisbon Portugal',
		'афины' => 'Athens Greece',
		'стамбул' => 'Istanbul Turkey',
		'тель-авив' => 'Tel Aviv Israel',
		'дубай' => 'Dubai United Arab Emirates',
		'токио' => 'Tokyo Japan',
		'сеул' => 'Seoul South Korea',
		'пекин' => 'Beijing China',
		'шанхай' => 'Shanghai China',
		'гонконг' => 'Hong Kong',
		'сингапур' => 'Singapore',
		'сидней' => 'Sydney Australia',
		'мельбурн' => 'Melbourne Australia',
	];

	$out = [];
	if (isset($aliases[$q]))
	{
		$out[] = $aliases[$q];
	}
	else
	{
		foreach ($aliases as $ru => $en)
		{
			if ($q === $ru || mb_strpos($q, $ru . ' ') === 0 || mb_strpos($q, $ru . ',') === 0)
			{
				$out[] = $en;
				break;
			}
		}
	}

	return array_values(array_unique($out));
}

/**
 * @return list<array<string, mixed>>
 */
function mf_photon_merge_feature_rows(array $first, array $second): array
{
	$seen = [];
	$out = [];
	foreach (array_merge($first, $second) as $row)
	{
		if (!is_array($row))
		{
			continue;
		}
		$p = isset($row['properties']) && is_array($row['properties']) ? $row['properties'] : [];
		$key = (string)($p['osm_type'] ?? '') . ':' . (string)($p['osm_id'] ?? '');
		if ($key === ':')
		{
			continue;
		}
		if (isset($seen[$key]))
		{
			continue;
		}
		$seen[$key] = true;
		$out[] = $row;
	}

	return $out;
}

try
{
	if (!check_bitrix_sessid())
	{
		echo Json::encode(['ok' => false, 'error' => 'bad sessid']);
		exit;
	}

	$req = Application::getInstance()->getContext()->getRequest();
	$q = trim((string)$req->get('q'));
	if ($q === '' && $req->getPost('q') !== null)
	{
		$q = trim((string)$req->getPost('q'));
	}
	if (mb_strlen($q) < 2)
	{
		echo Json::encode(['ok' => false, 'error' => 'query too short']);
		exit;
	}

	$contact = getenv('MF_NOMINATIM_CONTACT_EMAIL');
	$contact = ($contact !== false && trim((string)$contact) !== '') ? trim((string)$contact) : 'noreply@example.com';
	$userAgent = 'MotorForceCheckout/1.0 (' . $contact . ')';

	$base = getenv('MF_PHOTON_API_URL');
	$base = ($base !== false && trim((string)$base) !== '') ? rtrim(trim((string)$base), '/') : 'https://photon.komoot.io';

	// Явный язык ответа: без него Photon может отдать name/state в en (по Accept-Language у HttpClient),
	// из‑за чего в подсказках дубли «Vsevolozhsk, …» вместо «Всеволожск, …» и eDost не находит город.
	// lang=default — локальное имя из OSM (для РФ обычно кириллица); ru на публичном photon.komoot.io не поддержан.
	$photonLang = getenv('MF_PHOTON_LANG');
	$photonLang = ($photonLang !== false && trim((string)$photonLang) !== '') ? trim((string)$photonLang) : 'default';

	$resultLimit = 10;
	$hintChunkLimit = 8;

	$http = new HttpClient([
		'socketTimeout' => 15,
		'streamTimeout' => 15,
	]);
	$http->setHeader('User-Agent', $userAgent);
	$http->setHeader('Accept', 'application/json');

	$latinHints = mf_photon_latin_hints_for_cyrillic_query($q);
	$hintFeatures = [];
	foreach ($latinHints as $hintQuery)
	{
		$urlHint = $base . '/api/?' . http_build_query([
			'q' => $hintQuery,
			'limit' => (string)$hintChunkLimit,
			'lang' => $photonLang,
		], '', '&', PHP_QUERY_RFC3986);

		$bodyHint = (string)$http->get($urlHint);
		if ($bodyHint === '' || (int)$http->getStatus() >= 400)
		{
			continue;
		}
		$decodedHint = json_decode($bodyHint, true);
		if (!is_array($decodedHint))
		{
			continue;
		}
		$fh = isset($decodedHint['features']) && is_array($decodedHint['features']) ? $decodedHint['features'] : [];
		foreach ($fh as $f)
		{
			if (is_array($f))
			{
				$hintFeatures[] = $f;
			}
			if (count($hintFeatures) >= $hintChunkLimit)
			{
				break 2;
			}
		}
	}

	$url = $base . '/api/?' . http_build_query([
		'q' => $q,
		'limit' => (string)$resultLimit,
		'lang' => $photonLang,
	], '', '&', PHP_QUERY_RFC3986);

	$body = (string)$http->get($url);
	$status = (int)$http->getStatus();
	if ($body === '' || $status >= 400)
	{
		if ($hintFeatures === [])
		{
			echo Json::encode([
				'ok' => false,
				'error' => 'geocoder http error',
				'status' => $status,
				'hint' => 'Проверьте MF_PHOTON_API_URL или доступ сервера к ' . $base,
			]);
			exit;
		}
		$features = $hintFeatures;
	}
	else
	{
		$decoded = json_decode($body, true);
		if (!is_array($decoded))
		{
			echo Json::encode(['ok' => false, 'error' => 'invalid json']);
			exit;
		}

		$features = isset($decoded['features']) && is_array($decoded['features']) ? $decoded['features'] : [];
		$features = mf_photon_merge_feature_rows($hintFeatures, $features);
		$features = array_slice($features, 0, $resultLimit);
	}

	$items = [];
	foreach ($features as $row)
	{
		if (!is_array($row))
		{
			continue;
		}
		$items[] = mf_photon_feature_to_item($row);
	}

	echo Json::encode(['ok' => true, 'items' => $items]);
}
catch (\Throwable $e)
{
	echo Json::encode(['ok' => false, 'error' => 'exception', 'message' => $e->getMessage()]);
}
