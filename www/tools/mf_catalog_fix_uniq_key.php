<?php
/**
 * Массовая перезапись свойства MF_UNIQ_KEY по формуле как в mf_update_supplier_stock.php:
 *   normalizeArticle(MF_ARTICLE_NORM или CML2_ARTICLE) + '_' + normalizeBrand(источник бренда)
 * Словарь брендов (mf_brand_find) не используется — только свойства карточки.
 *
 * По умолчанию источник бренда — только MF_BRAND_NORM (как просили). Если норм пуст —
 * суффикс UNKNOWNBRAND (как в makeUniqKey). Опционально --fallback-brand-if-norm-empty=Y подставляет MF_BRAND.
 *
 * Перед --apply проверяется уникальность вычисленного ключа среди обработанных элементов
 * (два разных ID с одним и тем же новым ключом → остановка, если не --skip-duplicate-check=Y).
 *
 * CLI:
 *   php /var/www/html/tools/mf_catalog_fix_uniq_key.php --dry-run --iblock-id=4
 *   php /var/www/html/tools/mf_catalog_fix_uniq_key.php --apply --iblock-id=4
 *   php /var/www/html/tools/mf_catalog_fix_uniq_key.php --apply --collisions-export=/tmp/uniq_collisions.csv --iblock-id=4
 *
 * Опции:
 *   --iblock-id=N (по умолчанию 4)
 *   --apply | --dry-run (по умолчанию dry-run, если нет --apply)
 *   --include-redirect=Y|N (по умолчанию N)
 *   --active-only=Y|N (по умолчанию Y)
 *   --batch=N (50–2000, по умолчанию 500)
 *   --limit=N (0 = без лимита)
 *   --fallback-brand-if-norm-empty=Y|N (по умолчанию N — только MF_BRAND_NORM)
 *   --skip-duplicate-check=Y|N (по умолчанию N — при коллизии ключей не выполнять --apply)
 *   --collisions-export=/path.csv — куда сохранить все коллизии (если не указан при их наличии — файл в sys_get_temp_dir с меткой времени)
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

function mffuk_flag(string $name): bool
{
	return in_array($name, $_SERVER['argv'], true);
}

function mffuk_arg(string $name): ?string
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

function mffuk_bool(string $raw, bool $default): bool
{
	$raw = strtoupper(trim($raw));
	if ($raw === '' || $raw === '*')
	{
		return $default;
	}

	return in_array($raw, ['Y', '1', 'YES', 'TRUE', 'ON'], true);
}

/** @see mf_update_supplier_stock.php */
function mffuk_normalize_article(string $s): string
{
	$s = mb_strtoupper(trim($s));
	$s = preg_replace('~[^A-Z0-9]+~', '', $s) ?? '';

	return $s;
}

/** @see mf_update_supplier_stock.php */
function mffuk_normalize_brand(string $s): string
{
	$s = mb_strtoupper(trim($s));
	$s = str_replace('Ё', 'Е', $s);
	$s = preg_replace('~[^A-ZА-Я0-9]+~u', '', $s) ?? '';

	return $s;
}

/** @see mf_update_supplier_stock.php */
function mffuk_make_uniq_key(string $articleNorm, string $brandNorm): string
{
	$articleNorm = trim($articleNorm);
	$brandNorm = trim($brandNorm);
	if ($brandNorm === '')
	{
		$brandNorm = 'UNKNOWNBRAND';
	}

	return $articleNorm . '_' . $brandNorm;
}

/**
 * @param array<string, array<string, mixed>> $props
 */
function mffuk_prop_scalar(array $props, string $code): string
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

function mffuk_flatten_field_value($v): string
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

function mffuk_iblock_prop_id(int $iblockId, string $code): int
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

/**
 * Bitrix кладёт значения в GetFields() как PROPERTY_{ID}_VALUE; GetProperties() при «быстром» пути
 * иногда читает PROPERTY_{ID} без _VALUE и отдаёт пусто — читаем из fields в первую очередь.
 */
function mffuk_prop_value(int $iblockId, array $fields, array $props, string $code): string
{
	$pid = mffuk_iblock_prop_id($iblockId, $code);
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
			$s = mffuk_flatten_field_value($fields[$k]);
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
		$s = mffuk_flatten_field_value($fields[$k]);
		if ($s !== '')
		{
			return $s;
		}
	}

	return mffuk_prop_scalar($props, $code);
}

function mffuk_out(string $s): void
{
	echo $s . PHP_EOL;
	if (function_exists('flush'))
	{
		flush();
	}
}

/**
 * @param array<string, list<int>> $duplicateKeys
 * @param-out string|null $errorOut
 */
function mffuk_write_collisions_csv(string $path, array $duplicateKeys, ?string &$errorOut = null): bool
{
	$errorOut = null;
	$path = trim($path);
	if ($path === '')
	{
		$errorOut = 'пустой путь';

		return false;
	}
	$dir = dirname($path);
	if ($dir !== '' && $dir !== '.' && !is_dir($dir))
	{
		$errorOut = 'каталог не существует: ' . $dir;

		return false;
	}
	if (is_dir($path))
	{
		$errorOut = 'путь — это каталог, укажите путь к файлу .csv';

		return false;
	}
	if ($dir !== '' && $dir !== '.' && !is_writable($dir))
	{
		$errorOut = 'нет прав на запись в каталог (chmod/chown или другой путь, напр. /tmp/…): ' . $dir;

		return false;
	}

	$fp = @fopen($path, 'wb');
	if ($fp === false)
	{
		$last = error_get_last();
		$msg = is_array($last) ? trim((string)($last['message'] ?? '')) : '';
		$errorOut = $msg !== '' ? $msg : 'fopen(wb) не удался';

		return false;
	}

	fwrite($fp, "\xEF\xBB\xBF");
	fputcsv($fp, ['MF_UNIQ_KEY_NEW', 'ELEMENT_ID', 'GROUP_SIZE', 'ALL_ELEMENT_IDS'], ';');

	foreach ($duplicateKeys as $key => $ids)
	{
		$ids = array_values(array_unique(array_map('intval', $ids)));
		sort($ids, SORT_NUMERIC);
		$groupSize = count($ids);
		$allStr = implode(',', $ids);
		foreach ($ids as $id)
		{
			fputcsv($fp, [$key, $id, $groupSize, $allStr], ';');
		}
	}

	fclose($fp);

	return true;
}

$iblockId = (int)(mffuk_arg('--iblock-id') ?: 4);
$includeRedirect = mffuk_bool((string)(mffuk_arg('--include-redirect') ?: ''), false);
$activeOnly = mffuk_bool((string)(mffuk_arg('--active-only') ?: ''), true);
$apply = mffuk_flag('--apply');
$fallbackBrand = mffuk_bool((string)(mffuk_arg('--fallback-brand-if-norm-empty') ?: ''), false);
$skipDupCheck = mffuk_bool((string)(mffuk_arg('--skip-duplicate-check') ?: ''), false);
$collisionsExportArg = mffuk_arg('--collisions-export');
$collisionsExportArg = $collisionsExportArg !== null ? trim((string)$collisionsExportArg) : '';
$batch = (int)(mffuk_arg('--batch') ?: 500);
if ($batch < 50)
{
	$batch = 50;
}
if ($batch > 2000)
{
	$batch = 2000;
}
$limit = (int)(mffuk_arg('--limit') ?: 0);

if ($iblockId <= 0)
{
	fwrite(STDERR, "Нужен --iblock-id\n");
	exit(1);
}

$filter = ['IBLOCK_ID' => $iblockId];
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
	'PROPERTY_MF_UNIQ_KEY',
	'PROPERTY_MF_ARTICLE_NORM',
	'PROPERTY_CML2_ARTICLE',
	'PROPERTY_MF_BRAND_NORM',
	'PROPERTY_MF_BRAND',
];

mffuk_out('=== MF_UNIQ_KEY mass fix ===');
mffuk_out('IBLOCK_ID: ' . $iblockId);
mffuk_out('MODE: ' . ($apply ? 'APPLY' : 'DRY-RUN'));
mffuk_out('INCLUDE_REDIRECT: ' . ($includeRedirect ? 'Y' : 'N'));
mffuk_out('ACTIVE_ONLY: ' . ($activeOnly ? 'Y' : 'N'));
mffuk_out('BRAND: ' . ($fallbackBrand ? 'MF_BRAND_NORM, иначе MF_BRAND' : 'только MF_BRAND_NORM (пусто → UNKNOWNBRAND)'));
mffuk_out('SKIP_DUPLICATE_CHECK: ' . ($skipDupCheck ? 'Y' : 'N'));
mffuk_out('BATCH: ' . $batch);
if ($limit > 0)
{
	mffuk_out('LIMIT: ' . $limit);
}
mffuk_out('');
mffuk_out('Фаза 1: обход каталога (GetList + свойства по каждому элементу).');
mffuk_out('На ~100k+ позиций это часто 5–30 минут; ниже будет строка [фаза 1] после каждой пачки.');
mffuk_out('');

$scriptStart = microtime(true);
$lastId = 0;
$scanned = 0;
$skippedNoArticle = 0;
$elementsFetched = 0;

/** @var array<int, string> id => new key */
$idToNewKey = [];
/** @var array<int, string> id => old key */
$idToOldKey = [];

while (true)
{
	$chunkFilter = $filter;
	$chunkFilter['>ID'] = $lastId;

	$top = $batch;
	if ($limit > 0)
	{
		$top = min($top, $limit - $elementsFetched);
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
		if ($limit > 0 && $elementsFetched >= $limit)
		{
			break 2;
		}
		$elementsFetched++;
		$fields = $row->GetFields();
		$props = $row->GetProperties();
		$eid = (int)($fields['ID'] ?? 0);
		if ($eid <= 0)
		{
			continue;
		}
		$lastId = $eid;

		$rawArtNorm = mffuk_prop_value($iblockId, $fields, $props, 'MF_ARTICLE_NORM');
		$rawCml2 = mffuk_prop_value($iblockId, $fields, $props, 'CML2_ARTICLE');
		$artSrc = $rawArtNorm !== '' ? $rawArtNorm : $rawCml2;
		if ($artSrc === '')
		{
			$skippedNoArticle++;
			continue;
		}

		$rawBrandNorm = mffuk_prop_value($iblockId, $fields, $props, 'MF_BRAND_NORM');
		$rawBrand = mffuk_prop_value($iblockId, $fields, $props, 'MF_BRAND');
		$brandSrc = $rawBrandNorm;
		if ($brandSrc === '' && $fallbackBrand)
		{
			$brandSrc = $rawBrand;
		}

		$newKey = mffuk_make_uniq_key(
			mffuk_normalize_article($artSrc),
			mffuk_normalize_brand($brandSrc)
		);
		$oldKey = mffuk_prop_value($iblockId, $fields, $props, 'MF_UNIQ_KEY');

		$idToNewKey[$eid] = $newKey;
		$idToOldKey[$eid] = $oldKey;
		$scanned++;
	}

	if ($got > 0)
	{
		$elap = microtime(true) - $scriptStart;
		$rate = $elementsFetched / max(0.001, $elap);
		mffuk_out(sprintf(
			'[фаза 1] прочитано: %d | с ключом (есть артикул): %d | без артикула: %d | последний ID: %d | %.0f эл/с',
			$elementsFetched,
			count($idToNewKey),
			$skippedNoArticle,
			$lastId,
			$rate
		));
	}

	if ($got === 0)
	{
		break;
	}
}

mffuk_out(sprintf('Фаза 1 завершена за %.1f с.', microtime(true) - $scriptStart));
mffuk_out('');

$keyOwners = [];
foreach ($idToNewKey as $id => $key)
{
	if (!isset($keyOwners[$key]))
	{
		$keyOwners[$key] = [];
	}
	$keyOwners[$key][] = (int)$id;
}

$duplicateKeys = [];
foreach ($keyOwners as $key => $ids)
{
	if (count($ids) > 1)
	{
		$duplicateKeys[$key] = $ids;
	}
}

$wouldChangeCount = 0;
foreach ($idToNewKey as $id => $newKey)
{
	$oldKey = $idToOldKey[$id] ?? '';
	if ($oldKey !== $newKey)
	{
		$wouldChangeCount++;
	}
}

mffuk_out('Элементов с артикулом (учтены в ключе): ' . count($idToNewKey));
mffuk_out('Пропущено (нет MF_ARTICLE_NORM и CML2_ARTICLE): ' . $skippedNoArticle);
mffuk_out('Нужно сменить MF_UNIQ_KEY: ' . $wouldChangeCount);
mffuk_out('Ключей с коллизией (одинаковый новый ключ у разных ID): ' . count($duplicateKeys));

if (!empty($duplicateKeys))
{
	$collPath = $collisionsExportArg;
	if ($collPath === '')
	{
		$collPath = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'mf_uniq_key_collisions_iblock' . $iblockId . '_' . date('Ymd_His') . '.csv';
	}
	$collErr = null;
	if (mffuk_write_collisions_csv($collPath, $duplicateKeys, $collErr))
	{
		$rows = 0;
		foreach ($duplicateKeys as $ids)
		{
			$rows += count($ids);
		}
		mffuk_out('Все коллизии выгружены в CSV: ' . $collPath . ' (строк: ' . $rows . ', уникальных ключей: ' . count($duplicateKeys) . ')');
	}
	else
	{
		mffuk_out('Не удалось записать CSV коллизий: ' . $collPath . ($collErr !== null && $collErr !== '' ? ' — ' . $collErr : ''));
	}
}

if (!empty($duplicateKeys) && !$skipDupCheck)
{
	mffuk_out('');
	mffuk_out('ОШИБКА: коллизии новых MF_UNIQ_KEY — --apply не выполнялся. Примеры:');
	$n = 0;
	foreach ($duplicateKeys as $key => $ids)
	{
		mffuk_out(sprintf('  key=%s ids=%s', $key, implode(',', array_slice($ids, 0, 20)) . (count($ids) > 20 ? '...' : '')));
		if (++$n >= 15)
		{
			break;
		}
	}
	mffuk_out('Исправьте данные вручную или временно --skip-duplicate-check=Y (не рекомендуется).');
	exit(1);
}

if (!empty($duplicateKeys) && $skipDupCheck)
{
	mffuk_out('');
	mffuk_out('ВНИМАНИЕ: коллизии есть, применяем из-за --skip-duplicate-check=Y');
}

if (!$apply)
{
	mffuk_out('');
	mffuk_out('DRY-RUN: для записи добавьте --apply');
	mffuk_out('Время: ' . sprintf('%.1f с', microtime(true) - $scriptStart));
	exit(0);
}

if ($wouldChangeCount === 0)
{
	mffuk_out('Нечего обновлять.');
	exit(0);
}

$tPhase2 = microtime(true);
mffuk_out('Фаза 2: запись MF_UNIQ_KEY для ' . $wouldChangeCount . ' элементов (остальные без изменений)…');
mffuk_out('');

$updated = 0;
$errors = 0;
foreach ($idToNewKey as $id => $newKey)
{
	$oldKey = $idToOldKey[$id] ?? '';
	if ($oldKey === $newKey)
	{
		continue;
	}
	// В ядре Bitrix SetPropertyValuesEx при успехе не возвращает true (void/null).
	// Проверка if (!SetPropertyValuesEx(...)) даёт ложные «ошибки» на каждом элементе.
	try
	{
		CIBlockElement::SetPropertyValuesEx((int)$id, $iblockId, ['MF_UNIQ_KEY' => $newKey]);
	}
	catch (Throwable $e)
	{
		$errors++;
		mffuk_out('Ошибка SetPropertyValuesEx ID=' . $id . ': ' . $e->getMessage());
		continue;
	}
	$updated++;
	if (($updated % 500) === 0)
	{
		mffuk_out('[прогресс] обновлено: ' . $updated . ' / ' . $wouldChangeCount);
	}
}

mffuk_out('');
mffuk_out('Готово. Обновлено элементов: ' . $updated . ', ошибок: ' . $errors);
mffuk_out('Фаза 2 заняла: ' . sprintf('%.1f с', microtime(true) - $tPhase2));
mffuk_out('Всего времени: ' . sprintf('%.1f с', microtime(true) - $scriptStart));
