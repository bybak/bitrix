<?php
declare(strict_types=1);

/**
 * Создаёт “варианты” доставок под каждый тариф eDost без загрузки ядра Битрикса
 * (ядро падает в CLI из-за модулей вроде statistic/pull в этом окружении).
 *
 * Каждая карточка доставки = отдельный tarif eDost:
 * - CONFIG базовой службы копируется
 * - добавляются поля EDOST_TARIFF_ID / EDOST_TARIFF_NAME
 * - ограничения (b_sale_service_rstr) копируются с базовой службы
 *
 * Запуск (dry-run):
 *   php /var/www/html/mf_edost_seed_delivery_variants.php --to-city="Всеволожск" --zip=188640
 *
 * Применить:
 *   php /var/www/html/mf_edost_seed_delivery_variants.php --apply=Y --to-city="Всеволожск" --zip=188640
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

$apply = false;
foreach ($argv as $a)
{
	if ($a === '--apply=Y' || $a === '--apply=1' || $a === '--apply=true')
	{
		$apply = true;
	}
}

$idsRaw = argValue('--ids') ?? '8,9,11,12,13,14';
$ids = array_values(array_filter(array_map('intval', preg_split('~\\s*,\\s*~', (string)$idsRaw))));

$toCityUtf8 = (string)(argValue('--to-city') ?? 'Всеволожск');
$zip = (string)(argValue('--zip') ?? '');
$weightKg = max(0.001, (float)(argValue('--weight') ?? '1'));
$insuranceRub = max(0.0, (float)(argValue('--insurance') ?? '0'));

$shopId = trim((string)(getenv('MF_EDOST_ID') ?: ''));
$shopPass = trim((string)(getenv('MF_EDOST_PASS') ?: ''));
if ($shopId === '' || $shopPass === '')
{
	fwrite(STDERR, "MF_EDOST_ID / MF_EDOST_PASS are empty\n");
	exit(1);
}

function norm(string $s): string
{
	$s = mb_strtolower(trim($s));
	$s = (string)preg_replace('~[^\\p{L}\\p{N}]+~u', '', $s);
	return $s;
}

/**
 * @return array{ok:bool,error?:string,offers?:array<int,array{id:string,price:float,company:string,name:string,day:string,strah:int}>}
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
		$day = trim((string)($tarif->day ?? ''));
		$strah = (int)($tarif->strah ?? 0);
		if ($id === '' || $price <= 0)
		{
			continue;
		}
		$offers[] = [
			'id' => $id,
			'price' => $price,
			'company' => $company,
			'name' => $name,
			'day' => $day,
			'strah' => $strah,
		];
	}

	return ['ok' => true, 'offers' => $offers];
}

$resp = edostCalculate($shopId, $shopPass, $toCityUtf8, $weightKg, $insuranceRub, $zip);
if (!($resp['ok'] ?? false))
{
	fwrite(STDERR, "eDost error: " . (string)($resp['error'] ?? 'unknown') . "\n");
	exit(1);
}
$offersAll = is_array($resp['offers'] ?? null) ? $resp['offers'] : [];

// DB config from Bitrix settings.
$settingsPath = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/.settings.php';
if (!file_exists($settingsPath))
{
	fwrite(STDERR, ".settings.php not found\n");
	exit(1);
}
$settings = require $settingsPath;
$db = $settings['connections']['value']['default'] ?? null;
if (!is_array($db))
{
	fwrite(STDERR, "db settings not found in .settings.php\n");
	exit(1);
}
$host = (string)($db['host'] ?? 'mysql:3306');
$dbName = (string)($db['database'] ?? '');
$login = (string)($db['login'] ?? '');
$pass = (string)($db['password'] ?? '');
if ($dbName === '' || $login === '')
{
	fwrite(STDERR, "db credentials are empty\n");
	exit(1);
}
[$hostName, $hostPort] = array_pad(explode(':', $host, 2), 2, '3306');
$port = (int)$hostPort;
$mysqli = @new mysqli($hostName, $login, $pass, $dbName, $port);
if ($mysqli->connect_errno)
{
	fwrite(STDERR, "db connect error: {$mysqli->connect_error}\n");
	exit(1);
}
$mysqli->set_charset('utf8mb4');

// Fetch base services.
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $mysqli->prepare("SELECT * FROM b_sale_delivery_srv WHERE ID IN ({$placeholders})");
$types = str_repeat('i', count($ids));
$stmt->bind_param($types, ...$ids);
$stmt->execute();
$resSrv = $stmt->get_result();
$bases = [];
while ($row = $resSrv->fetch_assoc())
{
	$bases[(int)$row['ID']] = $row;
}
$stmt->close();

foreach ($ids as $baseId)
{
	$base = $bases[$baseId] ?? null;
	if (!$base)
	{
		fwrite(STDERR, "ID={$baseId}: not found\n");
		continue;
	}

	$baseName = (string)$base['NAME'];
	$baseClass = (string)$base['CLASS_NAME'];
	if ($baseClass !== '\\MF\\Delivery\\Tariffed' || (string)$base['ACTIVE'] !== 'Y')
	{
		fwrite(STDERR, "ID={$baseId} {$baseName}: skip (class={$baseClass}, active={$base['ACTIVE']})\n");
		continue;
	}

	$cfgRaw = (string)($base['CONFIG'] ?? '');
	$baseCfg = [];
	if ($cfgRaw !== '')
	{
		$tmp = @unserialize($cfgRaw, ['allowed_classes' => false]);
		if (is_array($tmp))
		{
			$baseCfg = $tmp;
		}
	}

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
	$offers = array_values(array_filter($offersAll, static function ($o) use ($companyNeedle) {
		if (!is_array($o))
		{
			return false;
		}
		$c = norm((string)($o['company'] ?? ''));
		if ($c === '' || $companyNeedle === '')
		{
			return false;
		}
		return (mb_strpos($c, $companyNeedle) !== false) || (mb_strpos($companyNeedle, $c) !== false);
	}));
	if (!$offers)
	{
		fwrite(STDOUT, "ID={$baseId} {$baseName}: no eDost offers for company=\"{$companyFilter}\" (to_city={$toCityUtf8})\n");
		continue;
	}

	usort($offers, static function ($a, $b) {
		$na = (string)($a['name'] ?? '');
		$nb = (string)($b['name'] ?? '');
		$cmp = strcmp($na, $nb);
		if ($cmp !== 0) return $cmp;
		return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
	});

	$idx = 0;
	foreach ($offers as $o)
	{
		$idx++;
		$tarifId = trim((string)($o['id'] ?? ''));
		$tarifName = trim((string)($o['name'] ?? ''));
		$suffix = $tarifName !== '' ? $tarifName : ('тариф ' . $tarifId);
		$newName = $baseName . ' — ' . $suffix;

		// Exists?
		$check = $mysqli->prepare("SELECT ID FROM b_sale_delivery_srv WHERE NAME=? AND CLASS_NAME='\\\\MF\\\\Delivery\\\\Tariffed' LIMIT 1");
		$check->bind_param('s', $newName);
		$check->execute();
		$check->bind_result($existingId);
		$found = $check->fetch();
		$check->close();
		if ($found && (int)$existingId > 0)
		{
			fwrite(STDOUT, "SKIP exists ID={$existingId}: {$newName}\n");
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

		if (!$apply)
		{
			fwrite(STDOUT, "DRY add: base={$baseId} \"{$baseName}\" -> \"{$newName}\" (tarif {$tarifId})\n");
			continue;
		}

		$newSort = (int)$base['SORT'] + ($idx * 2);
		$logotip = $base['LOGOTIP'] !== null ? (int)$base['LOGOTIP'] : null;
		$currency = (string)$base['CURRENCY'];
		$desc = (string)($base['DESCRIPTION'] ?? '');
		$tracking = $base['TRACKING_PARAMS'] ?? null;
		$vatId = $base['VAT_ID'] !== null ? (int)$base['VAT_ID'] : null;
		$allowEdit = (string)($base['ALLOW_EDIT_SHIPMENT'] ?? 'Y');

		$ins = $mysqli->prepare(
			"INSERT INTO b_sale_delivery_srv (CODE,PARENT_ID,NAME,ACTIVE,DESCRIPTION,SORT,LOGOTIP,CONFIG,CLASS_NAME,CURRENCY,TRACKING_PARAMS,ALLOW_EDIT_SHIPMENT,VAT_ID,XML_ID)
			 VALUES (NULL,NULL,?,'Y',?, ?, ?, ?, '\\\\MF\\\\Delivery\\\\Tariffed', ?, ?, ?, ?, NULL)"
		);
		$ins->bind_param(
			'ssiisssssi',
			$newName,
			$desc,
			$newSort,
			$logotip,
			$newCfgRaw,
			$currency,
			$tracking,
			$allowEdit,
			$vatId
		);
		$ok = $ins->execute();
		$newId = (int)$mysqli->insert_id;
		$err = $ins->error;
		$ins->close();

		if (!$ok || $newId <= 0)
		{
			fwrite(STDERR, "ERR add {$newName}: {$err}\n");
			continue;
		}

		fwrite(STDOUT, "OK  add ID={$newId}: {$newName}\n");

		// Copy restrictions from base.
		$mysqli->query(
			"INSERT INTO b_sale_service_rstr (SERVICE_ID,SERVICE_TYPE,SORT,CLASS_NAME,PARAMS)
			 SELECT {$newId}, SERVICE_TYPE, SORT, CLASS_NAME, PARAMS
			 FROM b_sale_service_rstr WHERE SERVICE_ID=" . (int)$baseId
		);

		// Copy delivery->location bindings if any.
		$mysqli->query(
			"INSERT IGNORE INTO b_sale_delivery2location (DELIVERY_ID, LOCATION_CODE, LOCATION_TYPE)
			 SELECT {$newId}, LOCATION_CODE, LOCATION_TYPE
			 FROM b_sale_delivery2location WHERE DELIVERY_ID=" . (int)$baseId
		);
	}
}

$mysqli->close();
