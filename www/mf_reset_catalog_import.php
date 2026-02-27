<?php
/**
 * Сброс результатов импорта каталога из CSV (удаление элементов и разделов).
 *
 * Запуск (внутри контейнера bitrix_php):
 *   php /var/www/html/mf_reset_catalog_import.php              # dry-run
 *   php /var/www/html/mf_reset_catalog_import.php --apply      # удалить
 *
 * Опции:
 *   --iblock-id=4              # явный ID инфоблока
 *   --code=catalog_motor_force # искать инфоблок по CODE (по умолчанию)
 *   --delete-iblock            # дополнительно удалить сам инфоблок (опасно)
 */

$_SERVER["DOCUMENT_ROOT"] = __DIR__;
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;

Loader::includeModule("iblock");
Loader::includeModule("catalog");

// Ensure progress output is visible in CLI (no buffering).
while (ob_get_level() > 0)
{
	@ob_end_flush();
}
@ob_implicit_flush(true);

function mf_arg(string $name): ?string
{
	foreach ($_SERVER['argv'] as $arg)
	{
		if (strpos($arg, $name.'=') === 0)
		{
			return substr($arg, strlen($name) + 1);
		}
	}
	return null;
}

function mf_flag(string $name): bool
{
	return in_array($name, $_SERVER['argv'], true);
}

function mf_out(string $s): void
{
	echo $s . PHP_EOL;
	if (function_exists('flush')) flush();
}

function mf_pct(float $done, float $total): float
{
	if ($total <= 0) return 0.0;
	$p = ($done / $total) * 100.0;
	if ($p < 0) $p = 0.0;
	if ($p > 100) $p = 100.0;
	return $p;
}

function mf_progressLine(string $label, int $processed, int $totalExpected, int $deleted, int $errors): void
{
	static $lastLen = 0;
	$pct = $totalExpected > 0 ? mf_pct((float)$processed, (float)$totalExpected) : 0.0;
	$line = $label . ': ' . number_format($pct, 2, '.', '') . "% ($processed/$totalExpected) deleted=$deleted errors=$errors";
	$pad = max(0, $lastLen - strlen($line));
	echo "\r" . $line . str_repeat(' ', $pad);
	$lastLen = strlen($line);
	if (function_exists('flush')) flush();
}

function mf_findIblockId(?int $forcedId, string $code): array
{
	$sectionNames = ['Запчасти для квадроциклов', 'Запчасти для снегоходов'];

	if ($forcedId)
	{
		return [$forcedId];
	}

	$byCode = IblockTable::getList([
		'filter' => ['=CODE' => $code],
		'select' => ['ID'],
	])->fetchAll();

	if (!empty($byCode))
	{
		return array_values(array_unique(array_map(static fn($r) => (int)$r['ID'], $byCode)));
	}

	// Фоллбек: ищем инфоблоки, где есть ОБА верхнеуровневых раздела с заданными названиями.
	$ibIds = [];
	foreach ($sectionNames as $name)
	{
		$rs = CIBlockSection::GetList(
			[],
			['NAME' => $name, 'SECTION_ID' => false],
			false,
			['ID', 'IBLOCK_ID']
		);
		$found = [];
		while ($row = $rs->Fetch())
		{
			$found[(int)$row['IBLOCK_ID']] = true;
		}
		if (empty($ibIds))
		{
			$ibIds = array_keys($found);
		}
		else
		{
			$ibIds = array_values(array_intersect($ibIds, array_keys($found)));
		}
	}

	return array_values(array_unique(array_map('intval', $ibIds)));
}

function mf_countElements(int $iblockId): int { return (int)ElementTable::getCount(['=IBLOCK_ID' => $iblockId]); }
function mf_countSections(int $iblockId): int { return (int)SectionTable::getCount(['=IBLOCK_ID' => $iblockId]); }

function mf_deleteAllElements(int $iblockId, int $batchSize, int $totalExpected): array
{
	$deleted = 0;
	$errors = 0;
	$processed = 0;
	$start = microtime(true);

	$iter = 0;
	while (true)
	{
		$iter++;
		$ids = [];
		$rs = CIBlockElement::GetList(
			['ID' => 'ASC'],
			['IBLOCK_ID' => $iblockId],
			false,
			['nTopCount' => $batchSize],
			['ID']
		);
		while ($row = $rs->Fetch())
		{
			$ids[] = (int)$row['ID'];
		}
		if (empty($ids))
		{
			break;
		}

		foreach ($ids as $id)
		{
			if ($id <= 0) continue;
			$processed++;
			if (CIBlockElement::Delete($id))
			{
				$deleted++;
			}
			else
			{
				$errors++;
			}

			// Live progress inside long iterations.
			if (($processed % 500) === 0)
			{
				mf_progressLine('ELEMENTS', $processed, $totalExpected, $deleted, $errors);
			}
		}
		// Ensure line ends before iteration summary
		echo PHP_EOL;

		$elapsed = max(microtime(true) - $start, 0.001);
		$speed = ($deleted + $errors) / $elapsed;
		$pct = $totalExpected > 0 ? mf_pct((float)$processed, (float)$totalExpected) : 0.0;
		mf_out("Итерация #$iter: удалено " . count($ids) . " (итого $deleted), ошибок: $errors, прогресс: " . number_format($pct, 2, '.', '') . "% ($processed/$totalExpected), скорость: " . round($speed, 1) . "/сек");
	}

	return [$deleted, $errors];
}

function mf_deleteAllSections(int $iblockId, int $batchSize, int $totalExpected): array
{
	$deleted = 0;
	$errors = 0;
	$processed = 0;
	$start = microtime(true);

	$iter = 0;
	while (true)
	{
		$iter++;
		$ids = [];
		$rs = CIBlockSection::GetList(
			['DEPTH_LEVEL' => 'DESC', 'ID' => 'DESC'],
			['IBLOCK_ID' => $iblockId],
			false,
			['ID'],
			['nTopCount' => $batchSize]
		);
		while ($row = $rs->Fetch())
		{
			$ids[] = (int)$row['ID'];
		}
		if (empty($ids))
		{
			break;
		}

		foreach ($ids as $id)
		{
			if ($id <= 0) continue;
			$processed++;
			if (CIBlockSection::Delete($id))
			{
				$deleted++;
			}
			else
			{
				$errors++;
			}

			// Live progress inside long iterations.
			if (($processed % 200) === 0)
			{
				mf_progressLine('SECTIONS', $processed, $totalExpected, $deleted, $errors);
			}
		}
		echo PHP_EOL;

		$elapsed = max(microtime(true) - $start, 0.001);
		$speed = ($deleted + $errors) / $elapsed;
		$pct = $totalExpected > 0 ? mf_pct((float)$processed, (float)$totalExpected) : 0.0;
		mf_out("Итерация #$iter: разделов удалено " . count($ids) . " (итого $deleted), ошибок: $errors, прогресс: " . number_format($pct, 2, '.', '') . "% ($processed/$totalExpected), скорость: " . round($speed, 1) . "/сек");
	}

	return [$deleted, $errors];
}

try
{
	$apply = mf_flag('--apply');
	$deleteIblock = mf_flag('--delete-iblock');
	$code = mf_arg('--code') ?: 'catalog_motor_force';
	$forcedId = mf_arg('--iblock-id');
	$forcedId = $forcedId !== null ? (int)$forcedId : null;
	$batch = (int)(mf_arg('--batch') ?: 20000);
	if ($batch < 1000) $batch = 1000;
	$batchSections = (int)(mf_arg('--batch-sections') ?: 5000);
	if ($batchSections < 500) $batchSections = 500;

	mf_out("=== СБРОС ИМПОРТА КАТАЛОГА (CSV) ===");
	mf_out("Режим: " . ($apply ? "APPLY (удаление)" : "DRY-RUN (без удаления)"));
	mf_out("Поиск инфоблока: " . ($forcedId ? "по ID=$forcedId" : "по CODE='$code' (+фоллбек по разделам)"));

	$iblockIds = mf_findIblockId($forcedId, $code);
	if (count($iblockIds) === 0)
	{
		throw new RuntimeException("Не удалось определить инфоблок для очистки (нет CODE '$code' и не найден фоллбек по разделам).");
	}
	if (count($iblockIds) > 1)
	{
		mf_out("Найдено несколько инфоблоков-кандидатов: " . implode(', ', $iblockIds));
		throw new RuntimeException("Неоднозначно. Запусти с --iblock-id=ID чтобы выбрать точно.");
	}

	$iblockId = (int)$iblockIds[0];

	$ib = IblockTable::getList([
		'filter' => ['=ID' => $iblockId],
		'select' => ['ID', 'NAME', 'CODE', 'IBLOCK_TYPE_ID'],
	])->fetch();

	mf_out("Инфоблок: ID={$iblockId}"
		. ($ib ? " NAME='{$ib['NAME']}' CODE='{$ib['CODE']}' TYPE='{$ib['IBLOCK_TYPE_ID']}'" : "")
	);
	mf_out("Batch элементов: $batch");
	mf_out("Batch разделов:  $batchSections");

	$elCnt = mf_countElements($iblockId);
	$secCnt = mf_countSections($iblockId);
	mf_out("Элементов: $elCnt");
	mf_out("Разделов:  $secCnt");

	if (!$apply)
	{
		mf_out("DRY-RUN завершён. Для удаления запусти с --apply");
		exit(0);
	}

	mf_out("Удаляем элементы...");
	[$delEl, $errEl] = mf_deleteAllElements($iblockId, $batch, $elCnt);
	mf_out("Элементы удалены: $delEl, ошибок: $errEl");

	mf_out("Удаляем разделы...");
	[$delSec, $errSec] = mf_deleteAllSections($iblockId, $batchSections, $secCnt);
	mf_out("Разделы удалены: $delSec, ошибок: $errSec");

	if ($deleteIblock)
	{
		mf_out("Удаляем инфоблок целиком...");
		$ibApi = new CIBlock;
		if (!$ibApi->Delete($iblockId))
		{
			mf_out("Ошибка удаления инфоблока ID=$iblockId");
		}
		else
		{
			mf_out("Инфоблок удалён.");
		}
	}

	mf_out("Готово.");
}
catch (Throwable $e)
{
	mf_out("ОШИБКА: " . $e->getMessage());
	exit(1);
}

