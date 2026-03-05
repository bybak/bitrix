<?php
/**
 * MF: установка/обновление служб доставки под Motor-Force.
 *
 * Создаёт 7 служб доставки:
 * - СДЭК
 * - Почта России
 * - EMS
 * - Деловые Линии
 * - ПЭК
 * - Кит
 * - Энергия
 *
 * Запуск (dry-run):
 * docker compose exec -T php php /var/www/html/mf_install_delivery_services.php
 *
 * Применить:
 * docker compose exec -T php php /var/www/html/mf_install_delivery_services.php --apply
 *
 * Отключить прочие доставки (кроме MF_*):
 * docker compose exec -T php php /var/www/html/mf_install_delivery_services.php --apply --disable-others=Y
 */

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Sale\Delivery\Services\Manager;

function mf_arg(string $name, ?string $default = null): ?string
{
	global $argv;
	if (!is_array($argv))
	{
		return $default;
	}
	foreach ($argv as $a)
	{
		if (str_starts_with((string)$a, $name . '='))
		{
			return (string)substr((string)$a, strlen($name) + 1);
		}
	}
	return $default;
}

function mf_has(string $flag): bool
{
	global $argv;
	return is_array($argv) && in_array($flag, $argv, true);
}

function mf_bootstrap(): void
{
	$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?: __DIR__;
	require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
}

function mf_services_seed(): array
{
	// Тарифы — стартовые. Их можно/нужно подправить в админке для каждой службы доставки.
	return [
		[
			'CODE' => 'MF_CDEK',
			'NAME' => 'СДЭК',
			'SORT' => 100,
			'CONFIG' => ['CARRIER' => 'CDEK', 'BASE_PRICE' => 350, 'PRICE_PER_KG' => 40, 'MIN_PRICE' => 350, 'FREE_FROM_SUM' => 0, 'ROUND' => 'Y', 'PERIOD_FROM_DAYS' => 2, 'PERIOD_TO_DAYS' => 6],
		],
		[
			'CODE' => 'MF_RUSPOST',
			'NAME' => 'Почта России',
			'SORT' => 110,
			'CONFIG' => ['CARRIER' => 'RUSPOST', 'BASE_PRICE' => 300, 'PRICE_PER_KG' => 50, 'MIN_PRICE' => 300, 'FREE_FROM_SUM' => 0, 'ROUND' => 'Y', 'PERIOD_FROM_DAYS' => 3, 'PERIOD_TO_DAYS' => 10],
		],
		[
			'CODE' => 'MF_EMS',
			'NAME' => 'EMS',
			'SORT' => 120,
			'CONFIG' => ['CARRIER' => 'EMS', 'BASE_PRICE' => 600, 'PRICE_PER_KG' => 70, 'MIN_PRICE' => 600, 'FREE_FROM_SUM' => 0, 'ROUND' => 'Y', 'PERIOD_FROM_DAYS' => 2, 'PERIOD_TO_DAYS' => 7],
		],
		[
			'CODE' => 'MF_DL',
			'NAME' => 'Деловые Линии',
			'SORT' => 130,
			'CONFIG' => ['CARRIER' => 'DL', 'BASE_PRICE' => 900, 'PRICE_PER_KG' => 35, 'MIN_PRICE' => 900, 'FREE_FROM_SUM' => 0, 'ROUND' => 'Y', 'PERIOD_FROM_DAYS' => 3, 'PERIOD_TO_DAYS' => 10],
		],
		[
			'CODE' => 'MF_PEK',
			'NAME' => 'ПЭК',
			'SORT' => 140,
			'CONFIG' => ['CARRIER' => 'PEK', 'BASE_PRICE' => 800, 'PRICE_PER_KG' => 30, 'MIN_PRICE' => 800, 'FREE_FROM_SUM' => 0, 'ROUND' => 'Y', 'PERIOD_FROM_DAYS' => 3, 'PERIOD_TO_DAYS' => 10],
		],
		[
			'CODE' => 'MF_KIT',
			'NAME' => 'Кит',
			'SORT' => 150,
			'CONFIG' => ['CARRIER' => 'KIT', 'BASE_PRICE' => 750, 'PRICE_PER_KG' => 30, 'MIN_PRICE' => 750, 'FREE_FROM_SUM' => 0, 'ROUND' => 'Y', 'PERIOD_FROM_DAYS' => 3, 'PERIOD_TO_DAYS' => 12],
		],
		[
			'CODE' => 'MF_ENERGY',
			'NAME' => 'Энергия',
			'SORT' => 160,
			'CONFIG' => ['CARRIER' => 'ENERGY', 'BASE_PRICE' => 700, 'PRICE_PER_KG' => 30, 'MIN_PRICE' => 700, 'FREE_FROM_SUM' => 0, 'ROUND' => 'Y', 'PERIOD_FROM_DAYS' => 3, 'PERIOD_TO_DAYS' => 12],
		],
	];
}

function mf_find_service_id_by_code(string $code): int
{
	$code = trim($code);
	if ($code === '')
	{
		return 0;
	}

	$rs = Manager::getList([
		'filter' => ['=CODE' => $code],
		'select' => ['ID', 'CODE'],
	]);
	$row = $rs->fetch();
	return $row ? (int)$row['ID'] : 0;
}

function mf_main(): int
{
	$apply = mf_has('--apply');
	$disableOthers = (mf_arg('--disable-others', 'N') === 'Y');

	mf_bootstrap();

	if (!Loader::includeModule('sale'))
	{
		fwrite(STDERR, "ОШИБКА: модуль sale не подключился\n");
		return 2;
	}

	// Ensure class is available
	require_once(__DIR__ . '/mf_delivery_tariffed.php');

	$className = '\\MF\\Delivery\\Tariffed';
	$seed = mf_services_seed();

	$created = [];
	$updated = [];

	foreach ($seed as $s)
	{
		$code = (string)$s['CODE'];
		$id = mf_find_service_id_by_code($code);

		$fields = [
			'CODE' => $code,
			'NAME' => (string)$s['NAME'],
			'ACTIVE' => 'Y',
			'SORT' => (int)$s['SORT'],
			'CLASS_NAME' => $className,
			'PARENT_ID' => 0,
			'CONFIG' => (array)$s['CONFIG'],
		];

		if (!$apply)
		{
			echo ($id > 0 ? "UPDATE " : "ADD ") . $code . " " . $fields['NAME'] . "\n";
			continue;
		}

		if ($id > 0)
		{
			$r = Manager::update($id, $fields);
			if (!$r->isSuccess())
			{
				fwrite(STDERR, "ОШИБКА: update {$code}: " . implode('; ', $r->getErrorMessages()) . "\n");
				return 3;
			}
			$updated[] = ['ID' => $id, 'CODE' => $code, 'NAME' => $fields['NAME']];
		}
		else
		{
			$r = Manager::add($fields);
			if (!$r->isSuccess())
			{
				fwrite(STDERR, "ОШИБКА: add {$code}: " . implode('; ', $r->getErrorMessages()) . "\n");
				return 4;
			}
			$newId = (int)$r->getId();
			$created[] = ['ID' => $newId, 'CODE' => $code, 'NAME' => $fields['NAME']];
		}
	}

	if ($apply && $disableOthers)
	{
		$rs = Manager::getList([
			'filter' => ['=ACTIVE' => 'Y', '!CODE' => array_column($seed, 'CODE')],
			'select' => ['ID', 'CODE', 'NAME'],
		]);
		while ($row = $rs->fetch())
		{
			$id = (int)$row['ID'];
			$code = (string)$row['CODE'];
			// Не трогаем системные записи без кода.
			if ($id <= 0 || $code === '')
			{
				continue;
			}
			$r = Manager::update($id, ['ACTIVE' => 'N']);
			if (!$r->isSuccess())
			{
				fwrite(STDERR, "WARN: не удалось отключить {$code} (ID={$id}): " . implode('; ', $r->getErrorMessages()) . "\n");
			}
			else
			{
				echo "DISABLED {$code} (ID={$id})\n";
			}
		}
	}

	if (!$apply)
	{
		echo "DRY-RUN OK\n";
		return 0;
	}

	foreach ($created as $x)
	{
		echo "CREATED {$x['CODE']} ID={$x['ID']} NAME={$x['NAME']}\n";
	}
	foreach ($updated as $x)
	{
		echo "UPDATED {$x['CODE']} ID={$x['ID']} NAME={$x['NAME']}\n";
	}
	echo "OK\n";

	return 0;
}

try
{
	exit(mf_main());
}
catch (Throwable $e)
{
	fwrite(STDERR, "ОШИБКА: " . $e->getMessage() . "\n");
	exit(1);
}

