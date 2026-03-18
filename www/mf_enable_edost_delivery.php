<?php
declare(strict_types=1);

/**
 * Массово включает eDost-расчёт для существующих служб доставки MF\Delivery\Tariffed.
 *
 * Запуск (dry-run):
 *   php /var/www/html/mf_enable_edost_delivery.php
 *
 * Применить:
 *   php /var/www/html/mf_enable_edost_delivery.php --apply=Y
 */

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_BUFFER_USED', true);

$docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
if ($docRoot === '')
{
	$docRoot = dirname(__FILE__);
}
$_SERVER['DOCUMENT_ROOT'] = $docRoot;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
{
	fwrite(STDERR, "sale module is not available\n");
	exit(1);
}

$apply = false;
foreach ($argv as $arg)
{
	if ($arg === '--apply=Y' || $arg === '--apply=1' || $arg === '--apply=true')
	{
		$apply = true;
	}
}

// Маппинг названий текущих “типов доставки” -> company в ответе eDost.
$companyMap = [
	'СДЭК' => 'СДЭК',
	'Почта России' => 'Почта России',
	'EMS' => 'EMS Почта России',
	'Деловые Линии' => 'Деловые линии',
	'ПЭК' => 'ПЭК',
	'Кит' => 'GTD',
	'Энергия' => 'Энергия',
];

$ids = [8, 9, 10, 11, 12, 13, 14];

foreach ($ids as $id)
{
	try
	{
		$srv = \Bitrix\Sale\Delivery\Services\Manager::getById($id);
	}
	catch (\Throwable $e)
	{
		fwrite(STDERR, "ID={$id}: cannot load: " . $e->getMessage() . "\n");
		continue;
	}

	$name = (string)($srv['NAME'] ?? '');
	$class = (string)($srv['CLASS_NAME'] ?? '');
	$config = is_array($srv['CONFIG'] ?? null) ? $srv['CONFIG'] : [];

	if ($class !== '\\MF\\Delivery\\Tariffed')
	{
		fwrite(STDERR, "ID={$id}: skip (class={$class})\n");
		continue;
	}

	$company = $companyMap[$name] ?? $name;

	$config['EDOST_ENABLED'] = 'Y';
	$config['EDOST_COMPANY'] = $company;
	$config['EDOST_PICK'] = $config['EDOST_PICK'] ?? 'CHEAPEST';
	$config['EDOST_INSURANCE'] = $config['EDOST_INSURANCE'] ?? 'N';
	$config['EDOST_CACHE_TTL'] = isset($config['EDOST_CACHE_TTL']) ? (int)$config['EDOST_CACHE_TTL'] : 15;

	if (!$apply)
	{
		fwrite(STDOUT, "DRY ID={$id} name={$name}: EDOST_ENABLED=Y, EDOST_COMPANY=\"{$company}\"\n");
		continue;
	}

	$res = \Bitrix\Sale\Delivery\Services\Manager::update($id, [
		'CONFIG' => $config,
	]);
	if (method_exists($res, 'isSuccess') && $res->isSuccess())
	{
		fwrite(STDOUT, "OK  ID={$id} name={$name}: enabled eDost, company=\"{$company}\"\n");
	}
	else
	{
		$errs = method_exists($res, 'getErrorMessages') ? $res->getErrorMessages() : ['unknown error'];
		fwrite(STDERR, "ERR ID={$id} name={$name}: " . implode('; ', (array)$errs) . "\n");
	}
}

