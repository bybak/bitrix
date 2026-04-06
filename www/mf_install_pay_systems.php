<?php
/**
 * MF: установка/обновление способов оплаты под Motor-Force.
 *
 * Создаёт/обновляет оплаты:
 * - Перевод с карты на карту (физ.лица)
 * - Безнал: выставление счета (юр.лица) (на базе стандартного handler'а bill)
 * - PayKeeper (СБП QR + оплата по ссылке картой) (физ+юр)
 *
 * Запуск (dry-run):
 * docker compose exec -T php php /var/www/html/mf_install_pay_systems.php
 *
 * Применить:
 * docker compose exec -T php php /var/www/html/mf_install_pay_systems.php --apply
 *
 * Отключить прочие оплаты (кроме MF_*):
 * docker compose exec -T php php /var/www/html/mf_install_pay_systems.php --apply --disable-others=Y
 */

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Sale\PaySystem\Manager;
use Bitrix\Sale\Internals\ServiceRestrictionTable;
use Bitrix\Sale\Services\Base\RestrictionManager;

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

function mf_find_by_code(string $code): int
{
	$code = trim($code);
	if ($code === '')
	{
		return 0;
	}
	$row = Manager::getByCode($code);
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

	$items = [
		[
			'CODE' => 'MF_CARD2CARD_FIZ',
			'NAME' => 'Перевод с карты на карту',
			'PERSON_TYPE_ID' => 1,
			'SORT' => 100,
			'ACTION_FILE' => 'mf_card2card',
			'NEW_WINDOW' => 'N',
			'HAVE_RESULT_RECEIVE' => 'N',
		],
		[
			'CODE' => 'MF_BILL_JUR',
			'NAME' => 'Безнал: выставление счета (для юр. лиц)',
			'PERSON_TYPE_ID' => 2,
			'SORT' => 110,
			'ACTION_FILE' => 'bill',
			'NEW_WINDOW' => 'Y',
			'HAVE_RESULT_RECEIVE' => 'N',
			// Prefer to reuse existing bill (ID=9) if present.
			'PREFER_EXISTING_ID' => 9,
		],
		[
			'CODE' => 'MF_PAYKEEPER_FIZ',
			'NAME' => 'PayKeeper (СБП/карта)',
			'PERSON_TYPE_ID' => 1,
			'SORT' => 120,
			'ACTION_FILE' => 'mf_paykeeper',
			'NEW_WINDOW' => 'N',
			'HAVE_RESULT_RECEIVE' => 'Y',
		],
		[
			'CODE' => 'MF_PAYKEEPER_JUR',
			'NAME' => 'PayKeeper (СБП/карта)',
			'PERSON_TYPE_ID' => 2,
			'SORT' => 121,
			'ACTION_FILE' => 'mf_paykeeper',
			'NEW_WINDOW' => 'N',
			'HAVE_RESULT_RECEIVE' => 'Y',
		],
		[
			'CODE' => 'MF_CASH_OFFICE_FIZ',
			'NAME' => 'Наличными в офисе',
			'PERSON_TYPE_ID' => 1,
			'SORT' => 130,
			'ACTION_FILE' => 'cash',
			'NEW_WINDOW' => 'N',
			'HAVE_RESULT_RECEIVE' => 'N',
		],
	];

	$keepCodes = array_column($items, 'CODE');
	$created = [];
	$updated = [];
	$idsByCode = [];

	foreach ($items as $it)
	{
		$code = (string)$it['CODE'];
		$id = mf_find_by_code($code);
		if ($id <= 0 && !empty($it['PREFER_EXISTING_ID']))
		{
			$maybe = Manager::getById((int)$it['PREFER_EXISTING_ID']);
			if ($maybe && ((string)$maybe['ACTION_FILE'] === (string)$it['ACTION_FILE']))
			{
				$id = (int)$maybe['ID'];
			}
		}

		$fields = [
			'CODE' => $code,
			'NAME' => (string)$it['NAME'],
			'PSA_NAME' => (string)$it['NAME'],
			'ACTIVE' => 'Y',
			'SORT' => (int)$it['SORT'],
			'ACTION_FILE' => (string)$it['ACTION_FILE'],
			'NEW_WINDOW' => (string)$it['NEW_WINDOW'],
			'PERSON_TYPE_ID' => (int)$it['PERSON_TYPE_ID'],
			'ENTITY_REGISTRY_TYPE' => 'ORDER',
			'ALLOW_EDIT_PAYMENT' => 'Y',
			'HAVE_PAYMENT' => 'Y',
			'HAVE_RESULT_RECEIVE' => (string)$it['HAVE_RESULT_RECEIVE'],
		];

		if (!$apply)
		{
			echo ($id > 0 ? "UPDATE " : "ADD ") . $code . " person=" . $fields['PERSON_TYPE_ID'] . " action=" . $fields['ACTION_FILE'] . " name=" . $fields['NAME'] . "\n";
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
			$idsByCode[$code] = $id;
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
			$idsByCode[$code] = $newId;
		}
	}

	// Ограничения по типу плательщика (иначе MODE_CLIENT покажет всё всем).
	if ($apply)
	{
		$personRestrictions = [
			'MF_CARD2CARD_FIZ' => [1],
			'MF_BILL_JUR' => [2],
			'MF_PAYKEEPER_FIZ' => [1],
			'MF_PAYKEEPER_JUR' => [2],
			'MF_CASH_OFFICE_FIZ' => [1],
		];
		$class = '\\Bitrix\\Sale\\Services\\PaySystem\\Restrictions\\PersonType';

		foreach ($personRestrictions as $code => $pts)
		{
			$serviceId = (int)($idsByCode[$code] ?? 0);
			if ($serviceId <= 0)
			{
				continue;
			}

			// Delete existing PersonType restrictions for this service
			$rs = ServiceRestrictionTable::getList([
				'filter' => [
					'=SERVICE_ID' => $serviceId,
					'=SERVICE_TYPE' => RestrictionManager::SERVICE_TYPE_PAYMENT,
					'=CLASS_NAME' => $class,
				],
				'select' => ['ID'],
			]);
			while ($row = $rs->fetch())
			{
				ServiceRestrictionTable::delete((int)$row['ID']);
			}

			$add = ServiceRestrictionTable::add([
				'SERVICE_ID' => $serviceId,
				'SERVICE_TYPE' => RestrictionManager::SERVICE_TYPE_PAYMENT,
				'SORT' => 100,
				'CLASS_NAME' => $class,
				'PARAMS' => ['PERSON_TYPE_ID' => array_values($pts)],
			]);
			if (!$add->isSuccess())
			{
				fwrite(STDERR, "WARN: не удалось проставить restriction PersonType для {$code}: " . implode('; ', $add->getErrorMessages()) . "\n");
			}
		}
	}

	if ($apply && $disableOthers)
	{
		$rs = Manager::getList([
			'filter' => ['=ACTIVE' => 'Y'],
			'select' => ['ID', 'CODE', 'NAME', 'ACTION_FILE'],
		]);
		while ($row = $rs->fetch())
		{
			$id = (int)$row['ID'];
			$code = (string)($row['CODE'] ?? '');
			if ($id <= 0)
			{
				continue;
			}
			if (in_array($code, $keepCodes, true))
			{
				continue;
			}
			// Отключаем всё, что не MF_*
			$r = Manager::update($id, ['ACTIVE' => 'N']);
			if (!$r->isSuccess())
			{
				fwrite(STDERR, "WARN: не удалось отключить ID={$id} CODE={$code}: " . implode('; ', $r->getErrorMessages()) . "\n");
			}
			else
			{
				echo "DISABLED ID={$id} CODE=" . ($code !== '' ? $code : '(no-code)') . " NAME=" . (string)$row['NAME'] . "\n";
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

