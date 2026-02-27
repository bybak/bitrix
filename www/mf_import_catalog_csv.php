<?php
/**
 * Импорт каталога из большого CSV с дедупликацией по связке (артикул + бренд) и редирект-элементами.
 *
 * Запуск (внутри контейнера bitrix_php):
 *   php /var/www/html/mf_import_catalog_csv.php --dry-run --csv=/var/www/html/Каталог\\ 22-10-2025_17-25-15.csv
 *   php /var/www/html/mf_import_catalog_csv.php --apply   --csv=/var/www/html/Каталог\\ 22-10-2025_17-25-15.csv
 *
 * Опции:
 *   --iblock-id=4
 *   --csv=/path/file.csv
 *   --encoding=cp1251
 *   --limit=1000
 *   --apply
 *   --dry-run
 */

$_SERVER["DOCUMENT_ROOT"] = __DIR__;
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Iblock\SectionTable;

Loader::includeModule("iblock");
Loader::includeModule("catalog");

// Ensure progress output is visible in CLI (no buffering).
while (ob_get_level() > 0)
{
	@ob_end_flush();
}
@ob_implicit_flush(true);

function arg(string $name): ?string
{
	foreach ($_SERVER['argv'] as $a)
	{
		if (strpos($a, $name.'=') === 0)
		{
			return substr($a, strlen($name) + 1);
		}
	}
	return null;
}
function flag(string $name): bool { return in_array($name, $_SERVER['argv'], true); }
function out(string $s): void
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

function mf_progress(string $label, float $pct, string $extra = ''): void
{
	static $lastLen = 0;
	$line = sprintf("%s: %6.2f%%", $label, $pct);
	if ($extra !== '') $line .= "  " . $extra;

	$pad = max(0, $lastLen - strlen($line));
	echo "\r" . $line . str_repeat(' ', $pad);
	$lastLen = strlen($line);
	if (function_exists('flush')) flush();
}

function mf_progressDone(): void
{
	echo PHP_EOL;
	if (function_exists('flush')) flush();
}

function mf_toUtf8($v, string $fromEncoding): string
{
	$s = (string)($v ?? '');
	if ($s === '') return '';

	$from = strtoupper(trim($fromEncoding));
	if ($from === '' || $from === 'UTF8') $from = 'UTF-8';

	// If it already looks like valid UTF-8, keep it (helps with mixed-encoding CSVs).
	if (mb_check_encoding($s, 'UTF-8'))
	{
		$det = mb_detect_encoding($s, ['UTF-8', $from], true);
		if ($det === 'UTF-8')
		{
			return $s;
		}
	}

	// Prefer iconv with //IGNORE to avoid fatal conversion errors on broken bytes.
	if (function_exists('iconv'))
	{
		$converted = @iconv($from, 'UTF-8//IGNORE', $s);
		if (is_string($converted) && $converted !== '')
		{
			return $converted;
		}
		// If conversion produced empty but original wasn't empty, fall back to mb_convert_encoding.
	}

	$converted = mb_convert_encoding($s, 'UTF-8', $from);
	return is_string($converted) ? $converted : $s;
}

function normalizeArticle(string $s): string
{
	$s = mb_strtoupper(trim($s));
	$s = preg_replace('~[^A-Z0-9]+~', '', $s) ?? '';
	return $s;
}

function normalizeBrand(string $s): string
{
	$s = mb_strtoupper(trim($s));
	$s = str_replace('Ё', 'Е', $s);
	// Keep latin/cyrillic letters + digits only (no separators)
	$s = preg_replace('~[^A-ZА-Я0-9]+~u', '', $s) ?? '';
	return $s;
}

function extractBrandFromPreview(string $previewHtml): string
{
	$previewHtml = (string)$previewHtml;
	if ($previewHtml === '') return '';

	// Make HTML more parseable as text
	$s = preg_replace('~<br\\s*/?>~i', "\n", $previewHtml) ?? $previewHtml;
	$s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$s = strip_tags($s);
	$s = str_replace(["\xC2\xA0", "\t"], ' ', $s);
	$s = preg_replace('~\\s+~u', ' ', $s) ?? $s;
	$s = trim($s);
	if ($s === '') return '';

	$labels = [
		'производитель',
		'изготовитель',
		'бренд',
		'марка',
		'фирма',
		'manufacturer',
		'brand',
		'make',
		'vendor',
		'company',
	];

	$labelRe = implode('|', array_map(static fn($x) => preg_quote($x, '~'), $labels));
	if (!preg_match('~(?:^|[;,.\\(\\)\\[\\]\\s])(' . $labelRe . ')\\s*[:\\-—=]?\\s*([^;,.\\n\\r]{1,80})~iu', $s, $m))
	{
		return '';
	}

	$brand = trim((string)($m[2] ?? ''));
	// Cut at common trailing separators
	$brand = preg_split('~\\s{2,}|\\s\\|\\s|\\s/\\s~u', $brand)[0] ?? $brand;
	$brand = trim($brand);
	// Remove surrounding quotes
	$brand = trim($brand, " \t\n\r\0\x0B\"'`");

	// If someone wrote "Производитель: BRP (something)" – keep first token-ish chunk
	$brand = preg_replace('~\\s*\\(.*$~u', '', $brand) ?? $brand;
	$brand = trim($brand);
	return $brand;
}

function getBrandFromRow(array $row): array
{
	// Prefer explicit CSV columns if present
	$brand = trim((string)($row['Бренд'] ?? ''));
	$brandNorm = trim((string)($row['Бренд (норм)'] ?? ''));

	if ($brand === '' && $brandNorm === '')
	{
		$brand = extractBrandFromPreview((string)($row['Краткий текст'] ?? ''));
		$brandNorm = normalizeBrand($brand);
	}
	if ($brandNorm !== '')
	{
		$brandNorm = normalizeBrand($brandNorm);
	}
	if ($brand === '' && $brandNorm === '')
	{
		$brand = 'Unknown brand';
		$brandNorm = normalizeBrand($brand); // UNKNOWNBRAND
	}

	return [$brand, $brandNorm];
}

function makeUniqKey(string $articleNorm, string $brandNorm): string
{
	$articleNorm = trim($articleNorm);
	$brandNorm = trim($brandNorm);
	if ($brandNorm === '') $brandNorm = 'UNKNOWNBRAND';
	return $articleNorm . '_' . $brandNorm;
}

function formatArticleHuman(string $s): string
{
	$s = mb_strtoupper(trim($s));
	$s = preg_replace('~[^A-Z0-9]+~', '-', $s) ?? '';
	$s = preg_replace('~-+~', '-', $s) ?? '';
	$s = trim($s, '-');
	return $s;
}

function slugify(string $s): string
{
	$s = trim($s);
	if ($s === '') return '';
	$slug = CUtil::translit($s, 'ru', [
		'replace_space' => '-',
		'replace_other' => '-',
		'change_case' => 'L',
		'delete_repeat_replace' => true,
	]);
	$slug = preg_replace('~[^a-z0-9\\-]+~', '', $slug) ?? '';
	$slug = preg_replace('~-+~', '-', $slug) ?? '';
	return trim($slug, '-');
}

function safeAssoc(array $headers, array $row, string $delim = ';'): array
{
	$hc = count($headers);
	$rc = count($row);
	if ($hc === 0) return [];

	if ($rc < $hc)
	{
		$row = array_pad($row, $hc, '');
	}
	else if ($rc > $hc)
	{
		$last = $hc - 1;
		$tail = array_slice($row, $last);
		$row = array_slice($row, 0, $hc);
		$row[$last] = implode($delim, $tail);
	}

	$assoc = array_combine($headers, $row);
	return is_array($assoc) ? $assoc : [];
}

function scoreRow(array $row): array
{
	$score = 0;
	$detail = (string)($row['Текст полностью'] ?? '');
	$preview = (string)($row['Краткий текст'] ?? '');
	$title = (string)($row['Заголовок страницы (title)'] ?? '');
	$desc = (string)($row['Описание страницы (description)'] ?? '');
	$keys = (string)($row['Ключевые слова страницы (keywords)'] ?? '');
	$price = (float)str_replace(',', '.', (string)($row['Стоимость товара *'] ?? '0'));
	$active = (int)($row['Показывать на сайте *'] ?? 0) === 1;
	$avail = (int)($row['Товар в наличии'] ?? 0) === 1;
	$slug = (string)($row['ЧПУ страницы (slug)'] ?? '');
	$section = (string)($row['Раздел товара *'] ?? '');

	if (trim($detail) !== '') $score += 50;
	if (trim($preview) !== '') $score += 20;
	if (trim($title) !== '') $score += 10;
	if (trim($desc) !== '') $score += 10;
	if (trim($keys) !== '') $score += 5;
	if ($price > 0) $score += 10;
	if (trim($slug) !== '') $score += 5;
	if (trim($section) !== '') $score += 5;
	if ($active) $score += 2;
	if ($avail) $score += 2;

	$detailLen = mb_strlen(strip_tags($detail));
	return [$score, $detailLen];
}

function ensureProperty(int $iblockId, array $def): int
{
	$code = $def['CODE'];
	// В legacy API фильтр по '=CODE' не работает, нужен 'CODE'
	$existing = CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code])->Fetch();
	if ($existing)
	{
		return (int)$existing['ID'];
	}

	$ibp = new CIBlockProperty();
	$id = $ibp->Add($def + ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y']);
	if (!$id)
	{
		throw new RuntimeException("Не удалось создать свойство $code: " . $ibp->LAST_ERROR);
	}
	return (int)$id;
}

function ensureProperties(int $iblockId): void
{
	ensureProperty($iblockId, [
		'NAME' => 'Артикул',
		'CODE' => 'CML2_ARTICLE',
		'PROPERTY_TYPE' => 'S',
		'MULTIPLE' => 'N',
		'SEARCHABLE' => 'Y',
		'FILTRABLE' => 'Y',
		'SORT' => 100,
	]);
	ensureProperty($iblockId, [
		'NAME' => 'Нормализованный артикул',
		'CODE' => 'MF_ARTICLE_NORM',
		'PROPERTY_TYPE' => 'S',
		'MULTIPLE' => 'N',
		'SEARCHABLE' => 'Y',
		'FILTRABLE' => 'Y',
		'SORT' => 110,
	]);
	ensureProperty($iblockId, [
		'NAME' => 'Бренд (из CSV)',
		'CODE' => 'MF_BRAND',
		'PROPERTY_TYPE' => 'S',
		'MULTIPLE' => 'N',
		'SEARCHABLE' => 'Y',
		'FILTRABLE' => 'Y',
		'SORT' => 112,
	]);
	ensureProperty($iblockId, [
		'NAME' => 'Нормализованный бренд',
		'CODE' => 'MF_BRAND_NORM',
		'PROPERTY_TYPE' => 'S',
		'MULTIPLE' => 'N',
		'SEARCHABLE' => 'Y',
		'FILTRABLE' => 'Y',
		'SORT' => 114,
	]);
	ensureProperty($iblockId, [
		'NAME' => 'Уникальный ключ (артикул+бренд)',
		'CODE' => 'MF_UNIQ_KEY',
		'PROPERTY_TYPE' => 'S',
		'MULTIPLE' => 'N',
		'SEARCHABLE' => 'Y',
		'FILTRABLE' => 'Y',
		'SORT' => 116,
	]);
	ensureProperty($iblockId, [
		'NAME' => 'Это редирект-элемент',
		'CODE' => 'MF_IS_REDIRECT',
		'PROPERTY_TYPE' => 'S',
		'MULTIPLE' => 'N',
		'FILTRABLE' => 'Y',
		'SORT' => 120,
	]);
	ensureProperty($iblockId, [
		'NAME' => 'Канонический slug',
		'CODE' => 'MF_CANONICAL_CODE',
		'PROPERTY_TYPE' => 'S',
		'MULTIPLE' => 'N',
		'FILTRABLE' => 'Y',
		'SORT' => 130,
	]);
	ensureProperty($iblockId, [
		'NAME' => 'Схлопнутые id из CSV',
		'CODE' => 'MF_SOURCE_IDS',
		'PROPERTY_TYPE' => 'S',
		'MULTIPLE' => 'N',
		'SORT' => 140,
	]);
}

function getOrCreateSectionPath(int $iblockId, string $path): int
{
	static $cache = [];
	$path = trim($path);
	if ($path === '') return 0;
	// Иногда в CSV в поле "Раздел товара *" попадает "1"/"0" вместо пути.
	// Такие значения не являются реальным путём раздела и должны игнорироваться.
	if (preg_match('~^\\d+$~', $path)) return 0;
	if (isset($cache[$path])) return $cache[$path];

	$partsRaw = array_map('trim', explode(' => ', $path));
	$parts = [];
	foreach ($partsRaw as $p)
	{
		if ($p === '') continue;
		// Игнорируем чисто числовые "части" пути (артефакты данных)
		if (preg_match('~^\\d+$~', $p)) continue;
		$parts[] = $p;
	}
	if (empty($parts)) return 0;
	$parentId = 0;

	foreach ($parts as $name)
	{
		if ($name === '') continue;
		$existing = SectionTable::getList([
			'filter' => [
				'=IBLOCK_ID' => $iblockId,
				'=NAME' => $name,
				'=IBLOCK_SECTION_ID' => $parentId ?: null,
			],
			'select' => ['ID', 'CODE'],
			'limit' => 1,
		])->fetch();

		if ($existing)
		{
			$parentId = (int)$existing['ID'];
			continue;
		}

		$codeBase = slugify($name);
		if ($codeBase === '') $codeBase = 'section';

		$code = $codeBase;
		$suffix = 2;
		while (SectionTable::getCount([
			'=IBLOCK_ID' => $iblockId,
			'=CODE' => $code,
			'=IBLOCK_SECTION_ID' => $parentId ?: null,
		]) > 0)
		{
			$code = $codeBase . '-' . $suffix;
			$suffix++;
		}

		$bs = new CIBlockSection();
		$newId = $bs->Add([
			'IBLOCK_ID' => $iblockId,
			'IBLOCK_SECTION_ID' => $parentId ?: false,
			'NAME' => $name,
			'CODE' => $code,
			'ACTIVE' => 'Y',
		]);
		if (!$newId)
		{
			throw new RuntimeException("Не удалось создать раздел '$name': " . $bs->LAST_ERROR);
		}
		$parentId = (int)$newId;
	}

	$cache[$path] = $parentId;
	return $parentId;
}

function findElementIdByUniqKey(int $iblockId, string $uniqKey): ?int
{
	$r = CIBlockElement::GetList(
		[],
		['IBLOCK_ID' => $iblockId, '=PROPERTY_MF_UNIQ_KEY' => $uniqKey],
		false,
		false,
		['ID']
	)->Fetch();
	return $r ? (int)$r['ID'] : null;
}

function findElementIdByCode(int $iblockId, string $code): ?int
{
	$r = CIBlockElement::GetList(
		[],
		['IBLOCK_ID' => $iblockId, '=CODE' => $code],
		false,
		false,
		['ID']
	)->Fetch();
	return $r ? (int)$r['ID'] : null;
}

function setBasePrice(int $productId, float $price): void
{
	if ($price <= 0) return;
	CPrice::SetBasePrice($productId, $price, 'RUB');
}

function upsertCatalogProduct(int $productId, bool $available): void
{
	$cur = CCatalogProduct::GetByID($productId);
	$fields = [
		'ID' => $productId,
		'AVAILABLE' => $available ? 'Y' : 'N',
		'QUANTITY_TRACE' => 'N',
		'CAN_BUY_ZERO' => 'Y',
	];
	if ($cur)
	{
		CCatalogProduct::Update($productId, $fields);
	}
	else
	{
		CCatalogProduct::Add($fields);
	}
}

function importCanonical(int $iblockId, array $row, string $uniqKey, string $norm, string $brand, string $brandNorm, string $articleHuman, array $sourceIds): array
{
	$el = new CIBlockElement();
	$name = (string)($row['Название товара *'] ?? '');
	$slug = trim((string)($row['ЧПУ страницы (slug)'] ?? ''));
	if ($slug === '') $slug = slugify($name);
	if ($slug === '') $slug = 'item';

	$existingId = findElementIdByUniqKey($iblockId, $uniqKey);
	if ($existingId)
	{
		// Ensure slug uniqueness if it changed
		$conflict = findElementIdByCode($iblockId, $slug);
		if ($conflict && $conflict !== $existingId)
		{
			$slug = $slug . '-' . $existingId;
		}
	}
	else
	{
		$conflict = findElementIdByCode($iblockId, $slug);
		if ($conflict)
		{
			$slug = $slug . '-' . (string)($row['id'] ?? time());
		}
	}

	$sectionPath = (string)($row['Раздел товара *'] ?? '');
	$sectionId = $sectionPath !== '' ? getOrCreateSectionPath($iblockId, $sectionPath) : 0;

	$active = ((int)($row['Показывать на сайте *'] ?? 0) === 1) ? 'Y' : 'N';
	$available = ((int)($row['Товар в наличии'] ?? 0) === 1);

	$fields = [
		'IBLOCK_ID' => $iblockId,
		'NAME' => $name,
		'CODE' => $slug,
		'XML_ID' => (string)($row['id'] ?? ''),
		'ACTIVE' => $active,
		'IBLOCK_SECTION_ID' => $sectionId ?: false,
		'PREVIEW_TEXT' => (string)($row['Краткий текст'] ?? ''),
		'PREVIEW_TEXT_TYPE' => 'html',
		'DETAIL_TEXT' => (string)($row['Текст полностью'] ?? ''),
		'DETAIL_TEXT_TYPE' => 'html',
		'PROPERTY_VALUES' => [
			'CML2_ARTICLE' => $articleHuman,
			'MF_ARTICLE_NORM' => $norm,
			'MF_BRAND' => $brand,
			'MF_BRAND_NORM' => $brandNorm,
			'MF_UNIQ_KEY' => $uniqKey,
			'MF_IS_REDIRECT' => 'N',
			'MF_CANONICAL_CODE' => '',
			'MF_SOURCE_IDS' => implode(',', $sourceIds),
		],
	];

	$title = (string)($row['Заголовок страницы (title)'] ?? '');
	$desc = (string)($row['Описание страницы (description)'] ?? '');
	$keys = (string)($row['Ключевые слова страницы (keywords)'] ?? '');
	if ($title !== '') $fields['IPROPERTY_TEMPLATES']['ELEMENT_META_TITLE'] = $title;
	if ($desc !== '') $fields['IPROPERTY_TEMPLATES']['ELEMENT_META_DESCRIPTION'] = $desc;
	if ($keys !== '') $fields['IPROPERTY_TEMPLATES']['ELEMENT_META_KEYWORDS'] = $keys;

	if ($existingId)
	{
		// Disable search index update during bulk import (avoids stemming OOM).
		if (!$el->Update($existingId, $fields, false, false))
		{
			throw new RuntimeException("Update failed for canonical ID=$existingId: " . $el->LAST_ERROR);
		}
		$productId = $existingId;
	}
	else
	{
		// Disable search index update during bulk import (avoids stemming OOM).
		$productId = $el->Add($fields, false, false);
		if (!$productId)
		{
			throw new RuntimeException("Add failed for canonical slug=$slug: " . $el->LAST_ERROR);
		}
	}

	$price = (float)str_replace(',', '.', (string)($row['Стоимость товара *'] ?? '0'));
	setBasePrice($productId, $price);
	upsertCatalogProduct($productId, $available);

	return [$productId, $slug];
}

function importRedirect(int $iblockId, array $row, string $uniqKey, string $norm, string $brand, string $brandNorm, string $articleHuman, string $canonicalSlug): void
{
	$el = new CIBlockElement();
	$name = (string)($row['Название товара *'] ?? '');
	$slug = trim((string)($row['ЧПУ страницы (slug)'] ?? ''));
	if ($slug === '') $slug = slugify($name);
	if ($slug === '' || $slug === $canonicalSlug) return;

	$existingByCode = findElementIdByCode($iblockId, $slug);
	if ($existingByCode)
	{
		// If it's already canonical for the same norm, do nothing.
		return;
	}

	$fields = [
		'IBLOCK_ID' => $iblockId,
		'NAME' => $name !== '' ? $name : $canonicalSlug,
		'CODE' => $slug,
		'XML_ID' => (string)($row['id'] ?? ''),
		'ACTIVE' => 'Y',
		'DETAIL_TEXT' => '',
		'DETAIL_TEXT_TYPE' => 'html',
		'PREVIEW_TEXT' => '',
		'PREVIEW_TEXT_TYPE' => 'html',
		'PROPERTY_VALUES' => [
			'CML2_ARTICLE' => $articleHuman,
			'MF_ARTICLE_NORM' => $norm,
			'MF_BRAND' => $brand,
			'MF_BRAND_NORM' => $brandNorm,
			'MF_UNIQ_KEY' => $uniqKey,
			'MF_IS_REDIRECT' => 'Y',
			'MF_CANONICAL_CODE' => $canonicalSlug,
		],
	];

	// Disable search index update during bulk import (avoids stemming OOM).
	$id = $el->Add($fields, false, false);
	if (!$id)
	{
		// Не падаем на одном конфликте, но пишем в stderr.
		fwrite(STDERR, "Redirect add failed for slug=$slug: {$el->LAST_ERROR}\n");
	}
}

try
{
	$iblockId = (int)(arg('--iblock-id') ?: 4);
	$csv = arg('--csv') ?: '';
	$encoding = arg('--encoding') ?: 'cp1251';
	$limit = arg('--limit');
	$limit = $limit !== null ? (int)$limit : null;
	$apply = flag('--apply');
	$dry = flag('--dry-run') || !$apply;

	if ($csv === '')
	{
		throw new RuntimeException("Укажи --csv=/path/file.csv");
	}
	if (!file_exists($csv))
	{
		throw new RuntimeException("CSV не найден: $csv");
	}

	out("=== MF IMPORT CATALOG CSV ===");
	out("IBLOCK_ID: $iblockId");
	out("CSV: $csv");
	out("ENCODING: $encoding");
	out("MODE: " . ($apply ? 'APPLY' : 'DRY-RUN'));
	if ($limit !== null) out("LIMIT: $limit");

	if ($apply)
	{
		ensureProperties($iblockId);
		out("Свойства проверены/созданы.");
	}

	$csvSize = (int)@filesize($csv);
	$progressEvery = 20000;

	$h = fopen($csv, 'r');
	if (!$h) throw new RuntimeException("Не удалось открыть CSV");

	$headers = fgetcsv($h, 0, ';');
	if (!$headers) throw new RuntimeException("Не удалось прочитать заголовок CSV");
	$headers = array_map(static fn($v) => mf_toUtf8($v, $encoding), $headers);

	$need = ['id','Артикул *','Название товара *','Стоимость товара *','Раздел товара *','Товар в наличии','Показывать на сайте *','Краткий текст','Текст полностью','ЧПУ страницы (slug)'];
	foreach ($need as $n)
	{
		if (!in_array($n, $headers, true))
		{
			throw new RuntimeException("В CSV нет обязательной колонки: $n");
		}
	}

	// Pass1: scan + dedup choice by composite key (article_norm + brand_norm)
	$best = []; // uniqKey => [score, detailLen, pos, csv_id, article_norm, brand_norm]
	$canonicalIds = []; // uniqKey => csv_id
	$canonicalPos = []; // uniqKey => pos
	$sourceIds = []; // uniqKey => [csv_id...]
	$tmpRows = tempnam(sys_get_temp_dir(), 'mf_csv_rows_');
	$tmp = fopen($tmpRows, 'w');
	if (!$tmp) throw new RuntimeException("Не удалось создать temp файл");

	$total = 0;
	$noArticle = 0;
	$tmpTotal = 0;
	while (!feof($h))
	{
		$pos = ftell($h);
		$row = fgetcsv($h, 0, ';');
		if ($row === false) break;
		$total++;
		if ($limit !== null && $total > $limit) break;

		$row = array_map(static fn($v) => mf_toUtf8($v, $encoding), $row);
		$assoc = safeAssoc($headers, $row);

		if (($total % $progressEvery) === 0)
		{
			$pct = 0.0;
			if ($limit !== null && $limit > 0)
			{
				$pct = mf_pct((float)$total, (float)$limit);
			}
			else if ($csvSize > 0)
			{
				$pct = mf_pct((float)ftell($h), (float)$csvSize);
			}
			mf_progress('PASS1', $pct, "rows=$total");
		}

		$articleRaw = (string)($assoc['Артикул *'] ?? '');
		$norm = normalizeArticle($articleRaw);
		if ($norm === '')
		{
			$noArticle++;
			continue;
		}
		[$brand, $brandNorm] = getBrandFromRow($assoc);
		$uniqKey = makeUniqKey($norm, $brandNorm);
		$csvId = (string)($assoc['id'] ?? '');
		$slug = trim((string)($assoc['ЧПУ страницы (slug)'] ?? ''));
		if ($slug === '') $slug = slugify((string)($assoc['Название товара *'] ?? ''));

		[$score, $detailLen] = scoreRow($assoc);

		if (!isset($best[$uniqKey]))
		{
			$best[$uniqKey] = [$score, $detailLen, $pos, $csvId, $norm, $brandNorm];
			$canonicalIds[$uniqKey] = $csvId;
			$canonicalPos[$uniqKey] = $pos;
			$sourceIds[$uniqKey] = [$csvId];
		}
		else
		{
			$sourceIds[$uniqKey][] = $csvId;
			[$bs, $bd, $bpos, $bid] = $best[$uniqKey];
			$isBetter = ($score > $bs) || ($score === $bs && $detailLen > $bd);
			if ($isBetter)
			{
				$best[$uniqKey] = [$score, $detailLen, $pos, $csvId, $norm, $brandNorm];
				$canonicalIds[$uniqKey] = $csvId;
				$canonicalPos[$uniqKey] = $pos;
			}
		}

		// store minimal row record for pass3 (redirects)
		fwrite($tmp, $uniqKey . "\t" . $pos . "\t" . str_replace(["\t","\n","\r"], ' ', $csvId) . "\t" . str_replace(["\t","\n","\r"], ' ', $slug) . "\t" . str_replace(["\t","\n","\r"], ' ', $articleRaw) . "\n");
		$tmpTotal++;
	}
	mf_progressDone();
	fclose($h);
	fclose($tmp);

	$unique = count($best);
	$dupes = 0;
	foreach ($sourceIds as $norm => $ids)
	{
		if (count($ids) > 1) $dupes += (count($ids) - 1);
	}

	out("PASS1 total_rows: $total");
	out("PASS1 no_article: $noArticle");
	out("PASS1 unique_keys: $unique");
	out("PASS1 duplicates_rows: $dupes");

	if ($dry)
	{
		out("DRY-RUN завершён. Для импорта запусти с --apply");
		exit(0);
	}

	// Pass2: import canonicals
	$h = fopen($csv, 'r');
	if (!$h) throw new RuntimeException("Не удалось открыть CSV (pass2)");
	$headers2 = fgetcsv($h, 0, ';');
	$headers2 = array_map(static fn($v) => mf_toUtf8($v, $encoding), $headers2);

	$canonMap = []; // uniqKey => canonicalSlug
	$canonIdMap = []; // uniqKey => elementId

	$processedCanon = 0;
	foreach ($best as $uniqKey => [$s, $d, $pos, $cid, $norm, $brandNorm])
	{
		fseek($h, $pos);
		$row = fgetcsv($h, 0, ';');
		if ($row === false) continue;
		$row = array_map(static fn($v) => mf_toUtf8($v, $encoding), $row);
		$assoc = safeAssoc($headers2, $row);

		[$brand, $brandNorm2] = getBrandFromRow($assoc);
		$uniqKey2 = makeUniqKey($norm, $brandNorm2);
		// Keep uniqKey from pass1 as source of truth, but align brandNorm if it changed
		if ($uniqKey2 !== $uniqKey)
		{
			$uniqKey2 = $uniqKey;
			$brandNorm2 = $brandNorm;
		}

		$articleHuman = formatArticleHuman((string)($assoc['Артикул *'] ?? ''));
		[$elId, $slug] = importCanonical(
			$iblockId,
			$assoc,
			$uniqKey2,
			$norm,
			$brand,
			$brandNorm2 !== '' ? $brandNorm2 : ($brandNorm !== '' ? $brandNorm : 'UNK'),
			$articleHuman,
			array_values(array_unique($sourceIds[$uniqKey] ?? [$cid]))
		);
		$canonMap[$uniqKey] = $slug;
		$canonIdMap[$uniqKey] = $elId;
		$processedCanon++;
		if (($processedCanon % 500) === 0)
		{
			mf_progress('PASS2', mf_pct((float)$processedCanon, (float)$unique), "canonicals=$processedCanon/$unique");
		}
	}
	mf_progressDone();
	fclose($h);
	out("PASS2 canonicals imported total: $processedCanon");

	// Pass3: create redirects (stream temp file)
	$tmp = fopen($tmpRows, 'r');
	if (!$tmp) throw new RuntimeException("Не удалось открыть temp файл");

	$h = fopen($csv, 'r');
	if (!$h) throw new RuntimeException("Не удалось открыть CSV (pass3)");
	$headers3 = fgetcsv($h, 0, ';');
	$headers3 = array_map(static fn($v) => mf_toUtf8($v, $encoding), $headers3);

	$createdRedirects = 0;
	$seenRedirectSlug = [];
	$tmpProcessed = 0;
	while (($line = fgets($tmp)) !== false)
	{
		$line = trim($line);
		if ($line === '') continue;
		$tmpProcessed++;
		if (($tmpProcessed % $progressEvery) === 0)
		{
			mf_progress('PASS3', mf_pct((float)$tmpProcessed, (float)$tmpTotal), "rows=$tmpProcessed/$tmpTotal redirects=$createdRedirects");
		}
		[$uniqKey, $posStr, $csvId, $slug, $articleRaw] = array_pad(explode("\t", $line), 5, '');
		if ($uniqKey === '' || !isset($canonMap[$uniqKey])) continue;

		$pos = (int)$posStr;
		// Skip canonical row by position
		if (isset($canonicalPos[$uniqKey]) && (int)$canonicalPos[$uniqKey] === $pos) continue;

		$canonicalSlug = $canonMap[$uniqKey];
		$slug = trim($slug);
		if ($slug === '' || $slug === $canonicalSlug) continue;
		if (isset($seenRedirectSlug[$slug])) continue;
		$seenRedirectSlug[$slug] = true;

		fseek($h, $pos);
		$row = fgetcsv($h, 0, ';');
		if ($row === false) continue;
		$row = array_map(static fn($v) => mf_toUtf8($v, $encoding), $row);
		$assoc = safeAssoc($headers3, $row);

		$norm = normalizeArticle((string)($assoc['Артикул *'] ?? $articleRaw));
		[$brand, $brandNorm] = getBrandFromRow($assoc);
		if ($brandNorm === '') $brandNorm = 'UNK';

		$articleHuman = formatArticleHuman((string)($assoc['Артикул *'] ?? $articleRaw));
		importRedirect($iblockId, $assoc, $uniqKey, $norm, $brand, $brandNorm, $articleHuman, $canonicalSlug);
		$createdRedirects++;
	}
	mf_progressDone();
	fclose($h);
	fclose($tmp);
	@unlink($tmpRows);

	out("PASS3 redirects created total: $createdRedirects");
	out("DONE");
}
catch (Throwable $e)
{
	out("ОШИБКА: " . $e->getMessage());
	fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
	exit(1);
}

