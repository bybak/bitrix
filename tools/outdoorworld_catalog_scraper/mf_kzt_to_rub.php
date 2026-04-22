<?php
/**
 * CLI: сумма в KZT → рубли по курсам модуля «Валюты» (явно KZT → RUB).
 * Используется парсером outdoorworld_catalog_scraper для выгрузки stock_and_price CSV.
 *
 * Предпочтительно скрипт в DOCUMENT_ROOT (работает в Docker):
 *   docker compose exec php php /var/www/html/bitrix/php_interface/mf_kzt_to_rub_cli.php 1000000
 *
 * Либо с хоста (нужен доступ к БД как в .settings.php, не из Docker-сети):
 *   php tools/outdoorworld_catalog_scraper/mf_kzt_to_rub.php 1000000
 *
 * stdout: JSON {"rub":float,"kzt":float,"rub_per_kzt":float} или {"error":"..."}
 */

declare(strict_types=1);

$kzt = isset($argv[1]) ? (float)$argv[1] : 1_000_000.0;
if ($kzt <= 0 || !is_finite($kzt)) {
	fwrite(STDERR, "Invalid KZT amount\n");
	exit(1);
}

$docRoot = dirname(__DIR__, 2) . '/www';
if (!is_dir($docRoot . '/bitrix')) {
	echo json_encode(['error' => 'bitrix_not_found', 'docroot' => $docRoot], JSON_UNESCAPED_UNICODE) . "\n";
	exit(2);
}

$_SERVER['DOCUMENT_ROOT'] = $docRoot;
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

require $docRoot . '/bitrix/modules/main/include/prolog_before.php';

$rub = null;

if (\Bitrix\Main\Loader::includeModule('currency') && class_exists(\CCurrencyRates::class)) {
	$r = \CCurrencyRates::ConvertCurrency($kzt, 'KZT', 'RUB');
	if (is_finite($r) && (float)$r > 0) {
		$rub = round((float)$r, 2);
	}
}

if ($rub === null && function_exists('mf_ep_bitrix_convert_to_rub')) {
	$rub = mf_ep_bitrix_convert_to_rub($kzt, 'KZT');
}

if ($rub === null || $rub <= 0) {
	echo json_encode(['error' => 'convert_null'], JSON_UNESCAPED_UNICODE) . "\n";
	exit(4);
}

$rubPerKzt = $rub / $kzt;
echo json_encode(
	[
		'rub' => $rub,
		'kzt' => $kzt,
		'rub_per_kzt' => round($rubPerKzt, 10),
	],
	JSON_UNESCAPED_UNICODE
) . "\n";
