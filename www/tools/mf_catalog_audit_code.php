<?php
/**
 * Аудит ЧПУ (CODE) элементов каталога.
 * Ожидание: mb_strtolower(MF_BRAND_NORM . MF_ARTICLE_NORM), как в импорте внешних прайсов (codeBase до транслита).
 *
 * CLI (в контейнере PHP / на сервере с Bitrix):
 *   php /var/www/html/tools/mf_catalog_audit_code.php --iblock-id=4
 *   php /var/www/html/tools/mf_catalog_audit_code.php --iblock-id=4 --export=/tmp/code_mismatch.csv
 *   php /var/www/html/tools/mf_catalog_audit_code.php --include-redirect=Y
 *   php /var/www/html/tools/mf_catalog_audit_code.php --limit=1000
 *
 * Опции:
 *   --iblock-id=N (по умолчанию 4)
 *   --include-redirect=Y|N (по умолчанию N — только не-редиректы)
 *   --active-only=Y|N (по умолчанию Y)
 *   --batch=N размер пачки GetList (по умолчанию 500)
 *   --limit=N остановиться после N проверенных элементов (отладка)
 *   --export=/path.csv выгрузить расхождения в CSV (; разделитель)
 *   --progress-every=N печатать прогресс каждые N элементов (0 = только после пачки)
 *   --allow-suffix=Y|N (по умолчанию Y) — CODE вида expected-2 не считать ошибкой (коллизия уникальности)
 *   --allow-translit=Y|N (по умолчанию Y) — relaxed: совпадение с транслитом codeBase (как mf_ep_generate_unique_element_code)
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

Loader::includeModule('iblock');

while (ob_get_level() > 0)
{
	@ob_end_flush();
}
@ob_implicit_flush(true);

function mfcc_arg(string $name): ?string
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

function mfcc_bool(string $raw, bool $default): bool
{
	$raw = strtoupper(trim($raw));
	if ($raw === '' || $raw === '*')
	{
		return $default;
	}

	return in_array($raw, ['Y', '1', 'YES', 'TRUE', 'ON'], true);
}

function mfcc_flatten_field_value($v): string
{
	if ($v === null || $v === false || $v === '')
	{
		return '';
	}
	if (is_array($v))
	{
		if (isset($v['TEXT']))
		{
			return trim((string)$v['TEXT']);
		}
		$first = reset($v);
		if ($first === false)
		{
			return '';
		}

		return is_scalar($first) ? trim((string)$first) : '';
	}

	return trim((string)$v);
}

function mfcc_prop_scalar(array $props, string $code): string
{
	if (!isset($props[$code]) || !is_array($props[$code]))
	{
		return '';
	}
	$v = $props[$code]['VALUE'] ?? '';
	if (is_array($v))
	{
		return '';
	}

	return trim((string)$v);
}

function mfcc_iblock_prop_id(int $iblockId, string $code): int
{
	static $cache = [];
	$key = $iblockId . "\0" . $code;
	if (array_key_exists($key, $cache))
	{
		return $cache[$key];
	}
	$r = CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code])->Fetch();
	$cache[$key] = $r ? (int)$r['ID'] : 0;

	return $cache[$key];
}

function mfcc_prop_value(int $iblockId, array $fields, array $props, string $code): string
{
	$pid = mfcc_iblock_prop_id($iblockId, $code);
	if ($pid > 0)
	{
		foreach (
			[
				'PROPERTY_' . $pid . '_VALUE',
				'~PROPERTY_' . $pid . '_VALUE',
				'PROPERTY_' . $pid,
				'~PROPERTY_' . $pid,
			] as $k
		)
		{
			if (!array_key_exists($k, $fields))
			{
				continue;
			}
			$s = mfcc_flatten_field_value($fields[$k]);
			if ($s !== '')
			{
				return $s;
			}
		}
	}
	foreach (['PROPERTY_' . $code . '_VALUE', '~PROPERTY_' . $code . '_VALUE'] as $k)
	{
		if (!array_key_exists($k, $fields))
		{
			continue;
		}
		$s = mfcc_flatten_field_value($fields[$k]);
		if ($s !== '')
		{
			return $s;
		}
	}

	return mfcc_prop_scalar($props, $code);
}

/**
 * Строгое ожидание ЧПУ: lowercase(MF_BRAND_NORM . MF_ARTICLE_NORM), обрезка до 96 символов как при создании карточки.
 */
function mfcc_expected_code_strict(string $brandNorm, string $articleNorm): string
{
	$brandNorm = trim($brandNorm);
	$articleNorm = trim($articleNorm);
	if ($brandNorm === '' || $articleNorm === '')
	{
		return '';
	}
	$base = $brandNorm . $articleNorm;
	if (mb_strlen($base) > 96)
	{
		$base = mb_substr($base, 0, 96);
	}

	return mb_strtolower($base);
}

/**
 * Транслит codeBase — как mf_ep_generate_unique_element_code / mf_analogs_generate_unique_code (без суффикса коллизии).
 */
function mfcc_expected_code_translit(string $brandNorm, string $articleNorm): string
{
	$brandNorm = trim($brandNorm);
	$articleNorm = trim($articleNorm);
	if ($brandNorm === '' || $articleNorm === '')
	{
		return '';
	}
	$base = $brandNorm . $articleNorm;
	if (mb_strlen($base) > 96)
	{
		$base = mb_substr($base, 0, 96);
	}

	if (class_exists('CUtil'))
	{
		$code = (string)\CUtil::translit($base, 'ru', [
			'change_case' => 'L',
			'replace_space' => '-',
			'replace_other' => '-',
			'delete_repeat_replace' => true,
			'use_google' => false,
		]);
	}
	else
	{
		$code = strtolower(preg_replace('~[^a-z0-9]+~i', '-', $base) ?? $base);
	}

	$code = trim((string)(preg_replace('~[^a-z0-9\-]+~', '', $code) ?? ''), '-');

	return $code;
}

function mfcc_code_matches_expected(string $stored, string $expected, bool $allowSuffix): bool
{
	if ($expected === '')
	{
		return false;
	}
	$stored = mb_strtolower(trim($stored));
	if ($stored === $expected)
	{
		return true;
	}
	if (!$allowSuffix)
	{
		return false;
	}

	return (bool)preg_match('~^' . preg_quote($expected, '~') . '-\d+$~u', $stored);
}

function mfcc_out(string $s): void
{
	echo $s . PHP_EOL;
	if (function_exists('flush'))
	{
		flush();
	}
}

function mfcc_progress_line(int $analyzed, int $wrongStrict, int $wrongAny, int $skipped, float $elapsedSec): void
{
	$speed = ($elapsedSec > 0.001) ? ($analyzed / $elapsedSec) : 0.0;
	mfcc_out(sprintf(
		'[прогресс] проанализировано: %d | строго неверный ЧПУ: %d | неверный даже с relaxed: %d | пропуск (нет norm): %d | %.0f товар/с',
		$analyzed,
		$wrongStrict,
		$wrongAny,
		$skipped,
		$speed
	));
}

// --- main

$iblockId = (int)(mfcc_arg('--iblock-id') ?: 4);
$includeRedirect = mfcc_bool((string)(mfcc_arg('--include-redirect') ?: ''), false);
$activeOnly = mfcc_bool((string)(mfcc_arg('--active-only') ?: ''), true);
$allowSuffix = mfcc_bool((string)(mfcc_arg('--allow-suffix') ?: ''), true);
$allowTranslit = mfcc_bool((string)(mfcc_arg('--allow-translit') ?: ''), true);
$batch = (int)(mfcc_arg('--batch') ?: 500);
if ($batch < 50)
{
	$batch = 50;
}
if ($batch > 2000)
{
	$batch = 2000;
}
$limit = (int)(mfcc_arg('--limit') ?: 0);
$exportPath = mfcc_arg('--export');
$exportPath = ($exportPath !== null && $exportPath !== '') ? $exportPath : null;
$progressEvery = (int)(mfcc_arg('--progress-every') ?: 0);
if ($progressEvery < 0)
{
	$progressEvery = 0;
}

if ($iblockId <= 0)
{
	fwrite(STDERR, "Нужен валидный --iblock-id\n");
	exit(1);
}

$filter = [
	'IBLOCK_ID' => $iblockId,
];
if ($activeOnly)
{
	$filter['ACTIVE'] = 'Y';
}
if (!$includeRedirect)
{
	$filter['!=PROPERTY_MF_IS_REDIRECT'] = 'Y';
}

$select = [
	'ID',
	'IBLOCK_ID',
	'CODE',
	'PROPERTY_MF_ARTICLE_NORM',
	'PROPERTY_MF_BRAND_NORM',
	'PROPERTY_MF_IS_REDIRECT',
];

$fp = null;
if ($exportPath !== null)
{
	$fp = fopen($exportPath, 'wb');
	if ($fp === false)
	{
		fwrite(STDERR, "Не удалось открыть export: {$exportPath}\n");
		exit(1);
	}
	fwrite($fp, "\xEF\xBB\xBF");
	fputcsv($fp, [
		'ID',
		'CODE_STORED',
		'CODE_EXPECTED_STRICT',
		'CODE_EXPECTED_TRANSLIT',
		'MATCH_STRICT',
		'MATCH_RELAXED',
		'MF_BRAND_NORM',
		'MF_ARTICLE_NORM',
		'IS_REDIRECT',
	], ';');
}

$lastId = 0;
$scanned = 0;
$strictOk = 0;
$relaxedOnlyOk = 0;
$wrongStrict = 0;
$wrongAny = 0;
$skippedNoNorm = 0;
$emptyCode = 0;
$sampleShown = 0;
$sampleMax = 15;

mfcc_out('=== MF catalog CODE (ЧПУ) audit ===');
mfcc_out('IBLOCK_ID: ' . $iblockId);
mfcc_out('INCLUDE_REDIRECT: ' . ($includeRedirect ? 'Y' : 'N'));
mfcc_out('ACTIVE_ONLY: ' . ($activeOnly ? 'Y' : 'N'));
mfcc_out('ALLOW_SUFFIX: ' . ($allowSuffix ? 'Y' : 'N'));
mfcc_out('ALLOW_TRANSLIT: ' . ($allowTranslit ? 'Y' : 'N'));
mfcc_out('Ожидание (strict): mb_strtolower(MF_BRAND_NORM . MF_ARTICLE_NORM)');
mfcc_out('BATCH: ' . $batch);
if ($limit > 0)
{
	mfcc_out('LIMIT: ' . $limit);
}
if ($exportPath !== null)
{
	mfcc_out('EXPORT: ' . $exportPath);
}
mfcc_out('');

$scriptStartTs = microtime(true);
$lastProgressPrintedScanned = 0;

while (true)
{
	if ($limit > 0 && $scanned >= $limit)
	{
		break;
	}

	$chunkFilter = $filter;
	$chunkFilter['>ID'] = $lastId;

	$top = $batch;
	if ($limit > 0)
	{
		$top = min($top, $limit - $scanned);
		if ($top <= 0)
		{
			break;
		}
	}

	$rs = CIBlockElement::GetList(
		['ID' => 'ASC'],
		$chunkFilter,
		false,
		['nTopCount' => $top],
		$select
	);

	$got = 0;
	while ($row = $rs->GetNextElement())
	{
		$got++;
		$fields = $row->GetFields();
		$props = $row->GetProperties();
		$eid = (int)($fields['ID'] ?? 0);
		if ($eid <= 0)
		{
			continue;
		}
		$lastId = $eid;

		$brandNorm = mfcc_prop_value($iblockId, $fields, $props, 'MF_BRAND_NORM');
		$articleNorm = mfcc_prop_value($iblockId, $fields, $props, 'MF_ARTICLE_NORM');
		if ($brandNorm === '' || $articleNorm === '')
		{
			$skippedNoNorm++;
			$scanned++;
			if ($progressEvery > 0 && $scanned - $lastProgressPrintedScanned >= $progressEvery)
			{
				mfcc_progress_line($scanned, $wrongStrict, $wrongAny, $skippedNoNorm, microtime(true) - $scriptStartTs);
				$lastProgressPrintedScanned = $scanned;
			}
			continue;
		}

		$storedCode = trim((string)($fields['CODE'] ?? ''));
		if ($storedCode === '')
		{
			$emptyCode++;
		}

		$expectedStrict = mfcc_expected_code_strict($brandNorm, $articleNorm);
		$expectedTranslit = $allowTranslit ? mfcc_expected_code_translit($brandNorm, $articleNorm) : '';

		$strictMatch = mfcc_code_matches_expected($storedCode, $expectedStrict, $allowSuffix);
		$translitMatch = $allowTranslit && $expectedTranslit !== ''
			&& mfcc_code_matches_expected($storedCode, $expectedTranslit, $allowSuffix);
		$relaxedMatch = $strictMatch || $translitMatch;

		if ($strictMatch)
		{
			$strictOk++;
		}
		elseif ($relaxedMatch)
		{
			$relaxedOnlyOk++;
			$wrongStrict++;
		}
		else
		{
			$wrongStrict++;
			$wrongAny++;
		}

		$scanned++;
		if (!$strictMatch)
		{
			if ($fp)
			{
				fputcsv($fp, [
					$eid,
					$storedCode,
					$expectedStrict,
					$expectedTranslit,
					$strictMatch ? 'Y' : 'N',
					$relaxedMatch ? 'Y' : 'N',
					$brandNorm,
					$articleNorm,
					mfcc_prop_value($iblockId, $fields, $props, 'MF_IS_REDIRECT'),
				], ';');
			}

			if ($sampleShown < $sampleMax)
			{
				mfcc_out(sprintf(
					'SAMPLE id=%d stored=%s strict_expected=%s translit_expected=%s relaxed_ok=%s',
					$eid,
					$storedCode !== '' ? $storedCode : '(пусто)',
					$expectedStrict,
					$expectedTranslit !== '' ? $expectedTranslit : '(n/a)',
					$relaxedMatch ? 'Y' : 'N'
				));
				$sampleShown++;
			}
		}

		if ($progressEvery > 0 && $scanned - $lastProgressPrintedScanned >= $progressEvery)
		{
			mfcc_progress_line($scanned, $wrongStrict, $wrongAny, $skippedNoNorm, microtime(true) - $scriptStartTs);
			$lastProgressPrintedScanned = $scanned;
		}

		if ($limit > 0 && $scanned >= $limit)
		{
			break 2;
		}
	}

	if ($got > 0 && $scanned > $lastProgressPrintedScanned)
	{
		mfcc_progress_line($scanned, $wrongStrict, $wrongAny, $skippedNoNorm, microtime(true) - $scriptStartTs);
		$lastProgressPrintedScanned = $scanned;
	}

	if ($got === 0)
	{
		break;
	}
}

if ($fp)
{
	fclose($fp);
}

$compared = $scanned - $skippedNoNorm;
$elapsedTotal = microtime(true) - $scriptStartTs;

mfcc_out('');
mfcc_out('=== ИТОГ ===');
mfcc_out('Всего проанализировано товаров: ' . $scanned);
mfcc_out('Пропущено (нет MF_BRAND_NORM или MF_ARTICLE_NORM): ' . $skippedNoNorm);
mfcc_out('Сравнили ЧПУ (оба norm заполнены): ' . $compared);
mfcc_out('');
mfcc_out('Строгое совпадение (lowercase brand+article): ' . $strictOk);
mfcc_out('Не strict, но совпало с транслитом codeBase' . ($allowSuffix ? ' (± суффикс -N)' : '') . ': ' . $relaxedOnlyOk);
mfcc_out('Неверный ЧПУ (strict): ' . $wrongStrict);
mfcc_out('Неверный даже с relaxed (транслит' . ($allowSuffix ? ', суффикс' : '') . '): ' . $wrongAny);
mfcc_out('Пустой CODE у сравниваемых: ' . $emptyCode);
if ($compared > 0)
{
	mfcc_out('Доля неверных (strict) от сравненных: ' . sprintf('%.2f%%', 100.0 * $wrongStrict / $compared));
	mfcc_out('Доля полностью неверных (без relaxed) от сравненных: ' . sprintf('%.2f%%', 100.0 * $wrongAny / $compared));
}
mfcc_out('Время: ' . sprintf('%.1f с', $elapsedTotal));
mfcc_out('=== готово ===');
