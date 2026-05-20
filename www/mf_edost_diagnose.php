<?php
declare(strict_types=1);

/**
 * Диагностика тарифов eDost: какие тарифы возвращает API, в т.ч. Почта России.
 *
 * php /var/www/html/mf_edost_diagnose.php --city="Москва" --zip=101000 --weight=1
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
require_once $_SERVER['DOCUMENT_ROOT'] . '/mf_delivery_tariffed.php';

$city = 'Москва';
$zip = '101000';
$weight = 1.0;
foreach ($argv ?? [] as $arg)
{
	if (str_starts_with($arg, '--city='))
	{
		$city = substr($arg, 7);
	}
	elseif (str_starts_with($arg, '--zip='))
	{
		$zip = preg_replace('~\D+~', '', substr($arg, 6));
	}
	elseif (str_starts_with($arg, '--weight='))
	{
		$weight = max(0.001, (float)substr($arg, 9));
	}
}

if (!\MF\Delivery\Edost::isConfigured())
{
	fwrite(STDERR, "eDost не настроен (MF_EDOST_ID / MF_EDOST_PASS)\n");
	exit(1);
}

$dimensionsM = \MF\Delivery\Edost::defaultParcelDimensionsM();
$insurance = 1000.0;

echo "eDost shop id: " . \MF\Delivery\Edost::shopId() . "\n";
echo "Destination: {$city}, zip={$zip}, weight={$weight} kg, strah={$insurance}\n";
if (is_array($dimensionsM))
{
	echo "Dimensions (m): ln={$dimensionsM['ln']}, wd={$dimensionsM['wd']}, hg={$dimensionsM['hg']}\n";
}
echo str_repeat('-', 72) . "\n";

$resp = \MF\Delivery\Edost::calculate($city, $weight, $insurance, $zip, $dimensionsM);
if (!($resp['ok'] ?? false))
{
	echo "API error: " . ($resp['error'] ?? 'unknown') . " stat=" . ($resp['stat'] ?? '') . "\n";
	exit(2);
}

$labels = \MF\Delivery\Edost::russianPostTariffLabels();
$postIds = \MF\Delivery\Edost::RUSSIAN_POST_TARIFF_IDS;
$foundPost = [];

foreach (($resp['offers'] ?? []) as $offer)
{
	if (!is_array($offer))
	{
		continue;
	}
	$id = (string)($offer['id'] ?? '');
	$company = (string)($offer['company'] ?? '');
	$name = (string)($offer['name'] ?? '');
	$price = (string)($offer['price'] ?? '');
	$days = (string)($offer['days_from'] ?? '') . '-' . (string)($offer['days_to'] ?? '');
	echo sprintf("id=%-3s | %-22s | %-40s | %8s | %s\n", $id, $company, $name, $price, $days);
	if (in_array($id, $postIds, true) || stripos($company, 'почта') !== false)
	{
		$foundPost[$id] = true;
	}
}

echo str_repeat('-', 72) . "\n";
echo "Всего тарифов: " . count($resp['offers'] ?? []) . "\n";

$expected = ['1', '2', '3'];
$missing = array_values(array_filter($expected, static fn ($id) => !isset($foundPost[$id])));
if ($missing !== [])
{
	echo "\nНе найдены тарифы Почты России: " . implode(', ', $missing) . "\n";
	foreach ($missing as $id)
	{
		echo "  - id={$id}: " . ($labels[$id] ?? '?') . "\n";
	}
	echo "\nЭто настраивается в личном кабинете eDost, а не в коде сайта:\n";
	echo "  1. https://edost.ru/shop_edit.php — включить «Почта России: отправление 1-го класса» и «наземная посылка»\n";
	echo "  2. Для наземной посылки (id=2) обязателен индекс получателя (zip в запросе)\n";
	echo "  3. Документация: http://edost.ru/kln/help.html#DeliveryCode\n";
}
else
{
	echo "\nБазовые тарифы Почты России (1, 2, 3) присутствуют в ответе API.\n";
}
