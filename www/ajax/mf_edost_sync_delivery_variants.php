<?php
declare(strict_types=1);

// Create missing eDost delivery variants on-the-fly for chosen destination.
// Called from checkout when user changes LOCATION.

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
use Bitrix\Sale\Delivery\Services\Manager;
use Bitrix\Sale\Delivery\Services\Table as DeliveryTable;
use Bitrix\Sale\Internals\ServiceRestrictionTable;

header('Content-Type: application/json; charset=UTF-8');

try
{
	$host = (string)($_SERVER['HTTP_HOST'] ?? '');
	$remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
	$isLocal = ($host === 'localhost' || $host === '127.0.0.1' || $remote === '127.0.0.1');
	$logFile = $_SERVER['DOCUMENT_ROOT'] . '/.tmp_edost_sync.log';

	if (!Loader::includeModule('sale'))
	{
		echo Json::encode(['ok' => false, 'error' => 'sale module not available']);
		exit;
	}

	if (!check_bitrix_sessid())
	{
		try
		{
			if ($isLocal)
			{
				@file_put_contents($logFile, date('c') . " bad_sessid host={$host} remote={$remote}\n", FILE_APPEND);
			}
		}
		catch (\Throwable $e) {}
		echo Json::encode(['ok' => false, 'error' => 'bad sessid']);
		exit;
	}

	require_once($_SERVER['DOCUMENT_ROOT'] . '/mf_delivery_tariffed.php');

	$req = Application::getInstance()->getContext()->getRequest();
	$locationCode = trim((string)$req->getPost('location_code'));
	$zip = preg_replace('~\\D+~', '', (string)$req->getPost('zip'));

	if ($locationCode === '')
	{
		try
		{
			if ($isLocal)
			{
				@file_put_contents($logFile, date('c') . " empty_location zip={$zip}\n", FILE_APPEND);
			}
		}
		catch (\Throwable $e) {}
		echo Json::encode(['ok' => false, 'error' => 'empty location_code']);
		exit;
	}

	$toCity = \MF\Delivery\Edost::resolveCityNameRuByLocationCode($locationCode);
	if ($toCity === '')
	{
		try
		{
			if ($isLocal)
			{
				@file_put_contents($logFile, date('c') . " cannot_resolve_to_city location_code={$locationCode} zip={$zip}\n", FILE_APPEND);
			}
		}
		catch (\Throwable $e) {}
		echo Json::encode(['ok' => false, 'error' => 'cannot resolve to_city']);
		exit;
	}

	// Fetch offers once for destination; then split by company per base service.
	$resp = \MF\Delivery\Edost::calculate($toCity, 1.0, 0.0, (string)$zip);
	if (!is_array($resp) || !($resp['ok'] ?? false) || !isset($resp['offers']) || !is_array($resp['offers']))
	{
		try
		{
			if ($isLocal)
			{
				@file_put_contents($logFile, date('c') . " edost_error to_city={$toCity} location_code={$locationCode} zip={$zip} resp=" . json_encode($resp, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
			}
		}
		catch (\Throwable $e) {}
		echo Json::encode(['ok' => false, 'error' => 'edost error', 'resp' => $resp]);
		exit;
	}
	$offersAll = $resp['offers'];

	$norm = static function(string $s): string {
		$s = mb_strtolower(trim($s));
		return (string)preg_replace('~[^\\p{L}\\p{N}]+~u', '', $s);
	};

	// Template services: MF\Delivery\Tariffed with EDOST enabled, template-only, without fixed tariff.
	$baseIds = [];
	$rs = DeliveryTable::getList([
		'select' => ['ID'],
		'filter' => [
			'=CLASS_NAME' => '\\MF\\Delivery\\Tariffed',
			'=ACTIVE' => 'Y',
		],
	]);
	while ($row = $rs->fetch())
	{
		$baseIds[] = (int)$row['ID'];
	}

	$created = [];
	$skipped = 0;

	foreach ($baseIds as $baseId)
	{
		$base = Manager::getById($baseId);
		$baseName = (string)($base['NAME'] ?? '');
		$cfg = is_array($base['CONFIG'] ?? null) ? $base['CONFIG'] : [];

		$edostEnabled = (string)($cfg['EDOST_ENABLED'] ?? ($cfg['MAIN']['EDOST_ENABLED'] ?? 'N'));
		if ($edostEnabled !== 'Y')
		{
			continue;
		}

		// Use only base "template" services (hidden in checkout) as sources for variants.
		$templateOnly = (string)($cfg['EDOST_TEMPLATE_ONLY'] ?? ($cfg['MAIN']['EDOST_TEMPLATE_ONLY'] ?? 'N'));
		if ($templateOnly !== 'Y')
		{
			continue;
		}

		// Skip variants and other fixed-tariff services.
		$fixed = trim((string)($cfg['EDOST_TARIFF_ID'] ?? ($cfg['MAIN']['EDOST_TARIFF_ID'] ?? '')));
		if ($fixed !== '')
		{
			continue;
		}

		$companyFilter = trim((string)($cfg['EDOST_COMPANY'] ?? ($cfg['MAIN']['EDOST_COMPANY'] ?? '')));
		$companyNeedle = $norm($companyFilter);

		// If template has no company filter (e.g. "Стандартный"), take ALL offers.
		$offers = $offersAll;
		if ($companyNeedle !== '')
		{
			$offers = array_values(array_filter($offersAll, static function ($o) use ($companyNeedle, $norm) {
				if (!is_array($o))
				{
					return false;
				}
				$c = $norm((string)($o['company'] ?? ''));
				return $c !== '' && (mb_strpos($c, $companyNeedle) !== false || mb_strpos($companyNeedle, $c) !== false);
			}));
		}
		if (!$offers)
		{
			continue;
		}

		foreach ($offers as $o)
		{
			$tarifId = trim((string)($o['id'] ?? ''));
			if ($tarifId === '')
			{
				continue;
			}
			$tarifName = trim((string)($o['name'] ?? ''));
			$company = trim((string)($o['company'] ?? ''));
			$suffix = $tarifName !== '' ? $tarifName : ('тариф ' . $tarifId);
			// For generic template, prefix by company; otherwise prefix by base name.
			$prefix = ($companyFilter === '' || $baseName === 'Стандартный') ? ($company !== '' ? $company : $baseName) : $baseName;
			$newName = $prefix . ' — ' . $suffix;
			$xmlId = 'MF_EDOST_TARIF_' . $tarifId;

			$exists = DeliveryTable::getList([
				'select' => ['ID'],
				'filter' => [
					'=CLASS_NAME' => '\\MF\\Delivery\\Tariffed',
					'=XML_ID' => $xmlId,
				],
				'limit' => 1,
			])->fetch();
			if ($exists)
			{
				$skipped++;
				continue;
			}

			$newCfg = $cfg;
			$newCfg['EDOST_ENABLED'] = 'Y';
			$newCfg['EDOST_COMPANY'] = $company !== '' ? $company : $companyFilter;
			$newCfg['EDOST_TEMPLATE_ONLY'] = 'N';
			$newCfg['EDOST_TARIFF_ID'] = $tarifId;
			$newCfg['EDOST_TARIFF_NAME'] = '';
			if (isset($newCfg['MAIN']) && is_array($newCfg['MAIN']))
			{
				$newCfg['MAIN']['EDOST_ENABLED'] = 'Y';
				$newCfg['MAIN']['EDOST_COMPANY'] = $company !== '' ? $company : $companyFilter;
				$newCfg['MAIN']['EDOST_TEMPLATE_ONLY'] = 'N';
				$newCfg['MAIN']['EDOST_TARIFF_ID'] = $tarifId;
				$newCfg['MAIN']['EDOST_TARIFF_NAME'] = '';
			}

			$fields = [
				'NAME' => $newName,
				'ACTIVE' => 'Y',
				'SORT' => (int)($base['SORT'] ?? 100) + 2,
				'CLASS_NAME' => '\\MF\\Delivery\\Tariffed',
				'CURRENCY' => (string)($base['CURRENCY'] ?? 'RUB'),
				'CONFIG' => $newCfg,
				'DESCRIPTION' => (string)($base['DESCRIPTION'] ?? ''),
				'LOGOTIP' => (int)($base['LOGOTIP'] ?? 0),
				'XML_ID' => $xmlId,
			];

			$resAdd = Manager::add($fields);
			if (!$resAdd || !$resAdd->isSuccess())
			{
				continue;
			}
			$newId = (int)$resAdd->getId();

			// Copy restrictions from base.
			$rsR = ServiceRestrictionTable::getList(['filter' => ['=SERVICE_ID' => $baseId]]);
			while ($r = $rsR->fetch())
			{
				unset($r['ID']);
				$r['SERVICE_ID'] = $newId;
				ServiceRestrictionTable::add($r);
			}

			// Copy delivery->location bindings if any.
			try
			{
				$conn = Application::getConnection();
				$conn->queryExecute(
					"INSERT IGNORE INTO b_sale_delivery2location (DELIVERY_ID, LOCATION_CODE, LOCATION_TYPE)
					 SELECT {$newId}, LOCATION_CODE, LOCATION_TYPE
					 FROM b_sale_delivery2location WHERE DELIVERY_ID=" . (int)$baseId
				);
			}
			catch (\Throwable $e)
			{
				// ignore
			}

			$created[] = ['id' => $newId, 'name' => $newName, 'tarif' => $tarifId, 'company' => ($company !== '' ? $company : $companyFilter)];
		}
	}

	// Drop delivery services cache for this hit.
	try
	{
		$ref = new ReflectionClass(Manager::class);
		if ($ref->hasProperty('cachedFields'))
		{
			$p = $ref->getProperty('cachedFields');
			$p->setAccessible(true);
			$p->setValue(null, []);
		}
	}
	catch (\Throwable $e)
	{
		// ignore
	}

	echo Json::encode([
		'ok' => true,
		'to_city' => $toCity,
		'created' => $created,
		'created_count' => count($created),
		'skipped_existing' => $skipped,
	]);

	try
	{
		if ($isLocal)
		{
			@file_put_contents(
				$logFile,
				date('c') . " ok location_code={$locationCode} zip={$zip} to_city={$toCity} created=" . count($created) . " skipped={$skipped}\n",
				FILE_APPEND
			);
		}
	}
	catch (\Throwable $e) {}
}
catch (\Throwable $e)
{
	try
	{
		if (($isLocal ?? false) && isset($logFile))
		{
			@file_put_contents($logFile, date('c') . " exception " . $e->getMessage() . "\n", FILE_APPEND);
		}
	}
	catch (\Throwable $e2) {}
	echo Json::encode(['ok' => false, 'error' => 'exception', 'message' => $e->getMessage()]);
}

