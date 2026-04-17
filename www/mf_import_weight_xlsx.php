<?php
/**
 * CLI: импорт веса товаров из .xlsx (колонка G — вес, H — артикул, J — бренд; первая строка — заголовок).
 *
 * Запуск (в контейнере / на сервере, из корня сайта):
 *   php /var/www/html/mf_import_weight_xlsx.php --xlsx=/path/to/vesa.xlsx
 *   php /var/www/html/mf_import_weight_xlsx.php --xlsx=/path/to/file.xlsx --grams
 *
 * Опции:
 *   --xlsx=/path/file.xlsx   обязательно
 *   --iblock-id=4            каталог (по умолчанию 4)
 *   --grams                  вес в файле уже в граммах (иначе считаются килограммы → умножение на 1000)
 *   --progress-every=400     интервал строк для строки прогресса в консоль (50–5000)
 */

$_SERVER['DOCUMENT_ROOT'] = __DIR__;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

Loader::includeModule('iblock');
Loader::includeModule('catalog');

$libEp = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_external_price_lib.php';
$libWxi = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_weight_xlsx_import_lib.php';
if (!is_file($libEp) || !is_file($libWxi))
{
	fwrite(STDERR, "Не найдены mf_external_price_lib.php или mf_weight_xlsx_import_lib.php\n");
	exit(1);
}
require_once $libEp;
require_once $libWxi;

$brandDict = $_SERVER['DOCUMENT_ROOT'] . '/mf_brand_dict.php';
if (is_file($brandDict))
{
	require_once $brandDict;
}

while (ob_get_level() > 0)
{
	@ob_end_flush();
}
@ob_implicit_flush(true);

function mf_wxi_cli_arg(string $name): ?string
{
	foreach ($_SERVER['argv'] as $a)
	{
		if (strpos($a, $name . '=') === 0)
		{
			return substr($a, strlen($name) + 1);
		}
	}

	return null;
}

function mf_wxi_cli_flag(string $name): bool
{
	return in_array($name, $_SERVER['argv'], true);
}

function mf_wxi_cli_out(string $s): void
{
	echo $s . PHP_EOL;
	if (function_exists('flush'))
	{
		flush();
	}
}

if (mf_wxi_cli_flag('--help') || mf_wxi_cli_flag('-h'))
{
	mf_wxi_cli_out('Использование: php mf_import_weight_xlsx.php --xlsx=/path/to/file.xlsx [--grams] [--iblock-id=4] [--progress-every=400]');
	exit(0);
}

$xlsx = mf_wxi_cli_arg('--xlsx');
if ($xlsx === null || $xlsx === '' || !is_readable($xlsx))
{
	fwrite(STDERR, "Укажите существующий файл: --xlsx=/полный/путь/к/файлу.xlsx\n");
	exit(1);
}

$iblockId = (int)(mf_wxi_cli_arg('--iblock-id') ?: '4');
if ($iblockId <= 0)
{
	$iblockId = 4;
}

$weightInKg = !mf_wxi_cli_flag('--grams');
$progressEvery = (int)(mf_wxi_cli_arg('--progress-every') ?: '400');
if ($progressEvery < 50)
{
	$progressEvery = 50;
}
if ($progressEvery > 5000)
{
	$progressEvery = 5000;
}

@set_time_limit(0);
$mem = (string)ini_get('memory_limit');
if ($mem !== '' && $mem !== '-1')
{
	@ini_set('memory_limit', '512M');
}

$onProgress = static function (array $snap): void {
	mf_wxi_cli_out(sprintf(
		'[%s] rows=%d excel~%d | ok=%d not_found=%d bad=%d weight_fail=%d',
		date('H:i:s'),
		(int)($snap['rows_seen'] ?? 0),
		(int)($snap['excel_row'] ?? 0),
		(int)($snap['ok'] ?? 0),
		(int)($snap['not_found'] ?? 0),
		(int)($snap['bad'] ?? 0),
		(int)($snap['weight_fail'] ?? 0)
	));
};

mf_wxi_cli_out('Файл: ' . $xlsx);
mf_wxi_cli_out('Инфоблок: ' . $iblockId . ' | вес в файле: ' . ($weightInKg ? 'кг → в каталог записывается в г' : 'уже в г'));

try
{
	$stats = mf_wxi_import_file($xlsx, $iblockId, $weightInKg, $onProgress, $progressEvery);
}
catch (Throwable $e)
{
	fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . PHP_EOL);
	exit(1);
}

mf_wxi_cli_out('--- Итог ---');
mf_wxi_cli_out('Обновлено позиций: ' . (int)($stats['ok'] ?? 0));
mf_wxi_cli_out('Строк данных (после заголовка): ' . (int)($stats['rows_seen'] ?? 0));
mf_wxi_cli_out('Не найден товар: ' . (int)($stats['not_found'] ?? 0));
mf_wxi_cli_out('Пропущено (брак строки): ' . (int)($stats['bad'] ?? 0));
mf_wxi_cli_out('Ошибок записи веса по кластеру: ' . (int)($stats['weight_fail'] ?? 0));

$ex = $stats['examples_not_found'] ?? [];
if (is_array($ex) && $ex !== [])
{
	mf_wxi_cli_out('Примеры «не найден» (до 20):');
	foreach ($ex as $line)
	{
		mf_wxi_cli_out('  ' . (string)$line);
	}
}

exit(0);
