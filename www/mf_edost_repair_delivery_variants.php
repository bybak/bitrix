<?php
declare(strict_types=1);

/**
 * Repair CONFIG for delivery variants created by mf_edost_seed_delivery_variants.php (SQL-mode).
 * Fixes missing EDOST_TARIFF_ID / EDOST_COMPANY / EDOST_ENABLED due to earlier bad insert binding.
 *
 * Usage:
 *   php /var/www/html/mf_edost_repair_delivery_variants.php --ids=15,16,17,18,19,20,21,22,23,24,25,26 --to-city="Всеволожск" --zip=188640
 */

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@error_reporting(E_ALL);

$docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
if ($docRoot === '')
{
	$docRoot = dirname(__FILE__);
}
$_SERVER['DOCUMENT_ROOT'] = $docRoot;

function argValue(string $key): ?string
{
	global $argv;
	$prefix = $key . '=';
	foreach ($argv as $a)
	{
		if (strpos($a, $prefix) === 0)
		{
			return substr($a, strlen($prefix));
		}
	}
	return null;
}

function norm(string $s): string
{
	$s = mb_strtolower(trim($s));
	$s = (string)preg_replace('~[^\\p{L}\\p{N}]+~u', '', $s);
	return $s;
}

/**
 * @return array{ok:bool,error?:string,offers?:array<int,array{id:string,price:float,company:string,name:string}>}
 */
function edostCalculate(string $shopId, string $shopPass, string $toCityUtf8, float $weightKg, float $insuranceRub, string $zipDigits): array
{
	$toCityUtf8 = trim($toCityUtf8);
	if ($toCityUtf8 === '')
	{
		return ['ok' => false, 'error' => 'empty to_city'];
	}

	$toCity1251 = @iconv('UTF-8', 'Windows-1251//IGNORE', $toCityUtf8);
	if (!is_string($toCity1251) || $toCity1251 === '')
	{
		return ['ok' => false, 'error' => 'cannot convert to_city to windows-1251'];
	}

	$zipDigits = preg_replace('~\\D+~', '', $zipDigits);
	$post = [
		'id' => $shopId,
		'p' => $shopPass,
		'to_city' => $toCity1251,
		'weight' => (string)round(max(0.001, $weightKg), 3),
		'strah' => (string)round(max(0.0, $insuranceRub), 2),
		'headerutf' => '1',
	];
	if ($zipDigits !== '')
	{
		$post['zip'] = $zipDigits;
	}
	$payload = http_build_query($post, '', '&');
	$ctx = stream_context_create([
		'http' => [
			'method' => 'POST',
			'timeout' => 10,
			'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
			'content' => $payload,
		],
	]);
	$body = @file_get_contents('http://api.edost.ru/api2.php', false, $ctx);
	if (!is_string($body) || $body === '')
	{
		$body = @file_get_contents('http://edost.net/api2.php', false, $ctx);
	}
	if (!is_string($body) || $body === '')
	{
		return ['ok' => false, 'error' => 'empty response'];
	}
	$xml = @simplexml_load_string($body);
	if ($xml === false)
	{
		return ['ok' => false, 'error' => 'invalid xml'];
	}
	$stat = (int)($xml->stat ?? 0);
	if ($stat !== 1)
	{
		return ['ok' => false, 'error' => 'eDost stat=' . (string)$stat];
	}
	$offers = [];
	foreach (($xml->tarif ?? []) as $tarif)
	{
		$id = trim((string)($tarif->id ?? ''));
		$price = (float)str_replace(',', '.', (string)($tarif->price ?? '0'));
		$company = trim((string)($tarif->company ?? ''));
		$name = trim((string)($tarif->name ?? ''));
		if ($id === '' || $price <= 0)
		{
			continue;
		}
		$offers[] = [
			'id' => $id,
			'price' => $price,
			'company' => $company,
			'name' => $name,
		];
	}
	return ['ok' => true, 'offers' => $offers];
}

$idsRaw = argValue('--ids') ?? '15,16,17,18,19,20,21,22,23,24,25,26';
$ids = array_values(array_filter(array_map('intval', preg_split('~\\s*,\\s*~', (string)$idsRaw))));
$toCityUtf8 = (string)(argValue('--to-city') ?? 'Всеволожск');
$zip = (string)(argValue('--zip') ?? '188640');
$weightKg = max(0.001, (float)(argValue('--weight') ?? '1'));
$insuranceRub = max(0.0, (float)(argValue('--insurance') ?? '0'));

$shopId = trim((string)(getenv('MF_EDOST_ID') ?: ''));
$shopPass = trim((string)(getenv('MF_EDOST_PASS') ?: ''));
if ($shopId === '' || $shopPass === '')
{
	fwrite(STDERR, "MF_EDOST_ID / MF_EDOST_PASS are empty\n");
	exit(1);
}

$resp = edostCalculate($shopId, $shopPass, $toCityUtf8, $weightKg, $insuranceRub, $zip);
if (!($resp['ok'] ?? false))
{
	fwrite(STDERR, "eDost error: " . (string)($resp['error'] ?? 'unknown') . "\n");
	exit(1);
}
$offersAll = is_array($resp['offers'] ?? null) ? $resp['offers'] : [];

$settingsPath = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/.settings.php';
$settings = require $settingsPath;
$db = $settings['connections']['value']['default'];
[$hostName, $hostPort] = array_pad(explode(':', (string)$db['host'], 2), 2, '3306');
$mysqli = @new mysqli($hostName, (string)$db['login'], (string)$db['password'], (string)$db['database'], (int)$hostPort);
if ($mysqli->connect_errno)
{
	fwrite(STDERR, "db connect error: {$mysqli->connect_error}\n");
	exit(1);
}
$mysqli->set_charset('utf8mb4');

// Load base services by name.
$baseMap = [];
$r = $mysqli->query("SELECT ID,NAME,CONFIG FROM b_sale_delivery_srv WHERE CLASS_NAME='\\\\MF\\\\Delivery\\\\Tariffed' AND ID IN (8,9,11,12,13,14)");
while ($row = $r->fetch_assoc())
{
	$baseMap[(string)$row['NAME']] = $row;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $mysqli->prepare("SELECT ID,NAME FROM b_sale_delivery_srv WHERE ID IN ({$placeholders})");
$types = str_repeat('i', count($ids));
$stmt->bind_param($types, ...$ids);
$stmt->execute();
$res = $stmt->get_result();
$targets = [];
while ($row = $res->fetch_assoc())
{
	$targets[] = $row;
}
$stmt->close();

foreach ($targets as $t)
{
	$id = (int)$t['ID'];
	$name = (string)$t['NAME'];
	$parts = explode(' — ', $name, 2);
	$baseName = trim((string)($parts[0] ?? ''));
	$suffix = trim((string)($parts[1] ?? ''));
	if ($baseName === '' || $suffix === '')
	{
		fwrite(STDERR, "SKIP ID={$id}: cannot parse base/suffix from name\n");
		continue;
	}

	$base = $baseMap[$baseName] ?? null;
	if (!$base)
	{
		fwrite(STDERR, "SKIP ID={$id}: base not found for \"{$baseName}\"\n");
		continue;
	}

	$baseCfgRaw = (string)($base['CONFIG'] ?? '');
	$baseCfg = @unserialize($baseCfgRaw, ['allowed_classes' => false]);
	$baseCfg = is_array($baseCfg) ? $baseCfg : [];

	$companyFilter = trim((string)($baseCfg['EDOST_COMPANY'] ?? ''));
	if ($companyFilter === '' && isset($baseCfg['MAIN']) && is_array($baseCfg['MAIN']))
	{
		$companyFilter = trim((string)($baseCfg['MAIN']['EDOST_COMPANY'] ?? ''));
	}
	if ($companyFilter === '')
	{
		$companyFilter = $baseName;
	}

	$companyNeedle = norm($companyFilter);
	$list = array_values(array_filter($offersAll, static function ($o) use ($companyNeedle) {
		$c = norm((string)($o['company'] ?? ''));
		return $c !== '' && $companyNeedle !== '' && (mb_strpos($c, $companyNeedle) !== false || mb_strpos($companyNeedle, $c) !== false);
	}));
	if (!$list)
	{
		fwrite(STDERR, "SKIP ID={$id}: no offers for company={$companyFilter}\n");
		continue;
	}

	$tarifId = '';
	if (preg_match('~\\bтариф\\s*(\\d+)\\b~ui', $suffix, $m))
	{
		$tarifId = (string)$m[1];
	}
	else
	{
		$sNeedle = norm($suffix);
		foreach ($list as $o)
		{
			$n = norm((string)($o['name'] ?? ''));
			if ($n !== '' && ($n === $sNeedle))
			{
				$tarifId = (string)($o['id'] ?? '');
				break;
			}
		}
	}
	if ($tarifId === '')
	{
		fwrite(STDERR, "SKIP ID={$id}: cannot match eDost tarif for suffix=\"{$suffix}\" (company={$companyFilter})\n");
		continue;
	}

	$newCfg = $baseCfg;
	$newCfg['EDOST_ENABLED'] = 'Y';
	$newCfg['EDOST_COMPANY'] = $companyFilter;
	$newCfg['EDOST_TEMPLATE_ONLY'] = 'N';
	$newCfg['EDOST_TARIFF_ID'] = $tarifId;
	$newCfg['EDOST_TARIFF_NAME'] = '';
	if (isset($newCfg['MAIN']) && is_array($newCfg['MAIN']))
	{
		$newCfg['MAIN']['EDOST_ENABLED'] = 'Y';
		$newCfg['MAIN']['EDOST_COMPANY'] = $companyFilter;
		$newCfg['MAIN']['EDOST_TEMPLATE_ONLY'] = 'N';
		$newCfg['MAIN']['EDOST_TARIFF_ID'] = $tarifId;
		$newCfg['MAIN']['EDOST_TARIFF_NAME'] = '';
	}

	$newCfgRaw = serialize($newCfg);
	$u = $mysqli->prepare("UPDATE b_sale_delivery_srv SET CONFIG=? WHERE ID=?");
	$u->bind_param('si', $newCfgRaw, $id);
	if ($u->execute())
	{
		fwrite(STDOUT, "OK  repaired ID={$id}: {$name} (tarif {$tarifId})\n");
	}
	else
	{
		fwrite(STDERR, "ERR repair ID={$id}: {$u->error}\n");
	}
	$u->close();
}

$mysqli->close();

