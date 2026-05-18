<?php
/**
 * Прогрев Redis-кэша списка брендов для админки выгрузки каталога (mf_ce_load_brand_choices).
 *
 * Запуск (в контейнере / на сервере):
 *   php /var/www/html/mf_refresh_ce_brand_choices_cache.php
 *   php ... --dry-run
 *   php ... --iblock-id=4
 *
 * Переменные среды (как у Bitrix .settings.php):
 *   BITRIX_REDIS_HOST (по умолчанию redis), BITRIX_REDIS_PORT (6379)
 *   MF_CE_BRAND_CHOICES_CACHE_TTL — TTL ключа в секундах (по умолчанию 604800)
 */

$_SERVER['DOCUMENT_ROOT'] = __DIR__;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

Loader::includeModule('iblock');

$inc = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_ce_brand_choices_inc.php';
if (!is_file($inc))
{
	fwrite(STDERR, "Missing: {$inc}\n");
	exit(1);
}
require_once $inc;

function mfRcxArg(string $name): ?string
{
	foreach ($_SERVER['argv'] as $a)
	{
		if (str_starts_with($a, $name . '='))
		{
			return substr($a, strlen($name) + 1);
		}
	}

	return null;
}

$dry = in_array('--dry-run', $_SERVER['argv'], true);
$iblockId = (int)(mfRcxArg('--iblock-id') ?: '4');
if ($iblockId <= 0)
{
	fwrite(STDERR, "Invalid --iblock-id\n");
	exit(1);
}

$presets = [
	['label' => 'default (MF_BRAND+MF_BRAND_NORM, active exportable)', 'onlyActive' => true, 'codes' => null],
	['label' => "mf_brand_map_admin (only MF_BRAND)", 'onlyActive' => true, 'codes' => ['MF_BRAND']],
];

echo 'iblock=' . $iblockId . '; dry-run=' . ($dry ? 'Y' : 'N') . PHP_EOL;
echo 'redis host=' . (getenv('BITRIX_REDIS_HOST') ?: '(default redis)') . PHP_EOL;

$exit = 0;
foreach ($presets as $preset)
{
	$key = mf_ce_brand_choices_cache_redis_key($iblockId, $preset['onlyActive'], $preset['codes']);
	echo '--- ' . $preset['label'] . PHP_EOL;
	echo 'key=' . $key . PHP_EOL;

	if ($dry)
	{
		$n = count(mf_ce_load_brand_choices_from_db($iblockId, $preset['onlyActive'], $preset['codes']));
		echo 'db_count=' . $n . ' (not written)' . PHP_EOL;
		continue;
	}

	$t0 = microtime(true);
	$ok = mf_ce_refresh_brand_choices_cache($iblockId, $preset['onlyActive'], $preset['codes']);
	$dtDb = microtime(true) - $t0;
	if (!$ok)
	{
		echo 'ERROR: Redis SET failed (extension? host? auth?)' . PHP_EOL;
		$exit = 2;
		continue;
	}
	echo 'ok brands=' . count(mf_ce_brand_choices_cache_get($iblockId, $preset['onlyActive'], $preset['codes']) ?: []) . ' wall_seconds=' . round($dtDb, 3) . ' ttl=' . mf_ce_brand_choices_cache_ttl_seconds() . PHP_EOL;
}

exit($exit);
