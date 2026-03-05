<?php
/**
 * Аудит/валидация брендов по всему каталогу.
 *
 * Задача: найти расхождения между брендом в свойствах (MF_BRAND/MF_BRAND_NORM)
 * и брендом, который можно вывести из CODE (slug) и/или из текста (PREVIEW/NAME).
 *
 * Запуск (внутри контейнера bitrix_php):
 *   php /var/www/html/mf_validate_catalog_brands.php --dry-run --report=/var/www/html/brand_audit.csv
 *
 * Опции:
 *   --iblock-id=4
 *   --limit=10000
 *   --include-redirects=Y|N (по умолчанию N)
 *   --include-inactive=Y|N (по умолчанию N)
 *   --report=/path/file.csv (CSV с разделителем ;)
 *   --dry-run (по умолчанию)
 */

$_SERVER["DOCUMENT_ROOT"] = __DIR__;
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;

Loader::includeModule("iblock");
Loader::includeModule("highloadblock");

require_once __DIR__ . '/mf_brand_dict.php';

while (ob_get_level() > 0) { @ob_end_flush(); }
@ob_implicit_flush(true);

function arg(string $name): ?string
{
	foreach ($_SERVER['argv'] as $a)
	{
		if (strpos($a, $name . '=') === 0) return substr($a, strlen($name) + 1);
	}
	return null;
}
function flag(string $name): bool { return in_array($name, $_SERVER['argv'], true); }
function out(string $s): void { echo $s . PHP_EOL; if (function_exists('flush')) flush(); }

function mf_brand_lookup(string $text): string
{
	return mf_brand_find($text, false);
}

function mf_brand_lookup_for_slug_prefix(string $prefix): string
{
	$dict = mf_brand_aliases_load(false);
	if (empty($dict['ROWS'])) return '';

	$norm = mf_brand_norm($prefix);
	if ($norm === '') return '';

	foreach ($dict['ROWS'] as $r)
	{
		$an = (string)($r['ALIAS_NORM'] ?? '');
		if ($an === '') continue;

		if ($norm === $an) return (string)($r['CANONICAL'] ?? '');

		$minLen = 4;
		if (strlen($an) >= $minLen && str_starts_with($norm, $an))
		{
			return (string)($r['CANONICAL'] ?? '');
		}
	}
	return '';
}

function mf_slug_prefix(string $code): string
{
	$code = trim((string)$code);
	if ($code === '') return '';
	if (!preg_match('~^([a-zа-я]{2,})~iu', $code, $m))
	{
		return '';
	}
	return mb_strtolower((string)$m[1]);
}

/**
 * Консервативное определение бренда по slug:
 *  - берём только буквенный префикс до цифр (bosh0092m60230 -> bosh)
 *  - считаем валидным только если словарь брендов умеет его мапить в канонический бренд
 */
function mf_brand_from_code(string $code): array
{
	$code = trim($code);
	if ($code === '') return ['', ''];

	$prefix = mf_slug_prefix($code);
	if ($prefix === '') return ['', ''];
	if (mb_strlen($prefix) < 4) return ['', ''];

	$byPrefix = mf_brand_lookup_for_slug_prefix($prefix);
	if ($byPrefix !== '')
	{
		return [$byPrefix, 'code_prefix'];
	}

	// Не нашли в словаре — возвращаем пусто, но префикс полезен для отчёта.
	return ['', ''];
}

function extractBrandFromPreview(string $previewHtml): string
{
	$previewHtml = (string)$previewHtml;
	if ($previewHtml === '') return '';

	$s = html_entity_decode($previewHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$s = preg_replace('~<br\\s*/?>~i', "\n", $s) ?? $s;
	$s = preg_replace('~</?(p|div|tr|td|th|li|ul|ol|table|tbody|thead|h[1-6])\\b[^>]*>~i', "\n", $s) ?? $s;
	$s = strip_tags($s);
	$s = str_replace(["\xC2\xA0", "\t"], ' ', $s);
	$s = preg_replace('~\\s+~u', ' ', $s) ?? $s;
	$s = trim($s);
	if ($s === '') return '';

	$labels = [
		'производитель','изготовитель','бренд','марка','фирма',
		'manufacturer','brand','make','vendor','company',
	];
	$labelRe = implode('|', array_map(static fn($x) => preg_quote($x, '~'), $labels));
	if (!preg_match('~(?:^|[;,.\\(\\)\\[\\]\\s])(' . $labelRe . ')\\s*[:\\-—=]?\\s*([^;,.\\n\\r]{1,80})~iu', $s, $m))
	{
		return mf_brand_lookup($s);
	}

	$brand = trim((string)($m[2] ?? ''));
	$brand = preg_split('~\\s{2,}|\\s\\|\\s|\\s/\\s|,|;~u', $brand)[0] ?? $brand;
	$brand = trim($brand, " \t\n\r\0\x0B\"'`");
	$brand = preg_replace('~\\s*\\(.*$~u', '', $brand) ?? $brand;
	$brand = trim($brand);
	if ($brand !== '')
	{
		$stop = [
			'номер','номера','каталог','каталогу','каталожный','по','производителя','производитель',
			'oem','оригинал','оригинальные','соответствует','характеристики','аналог','аналоги',
		];
		$tokens = preg_split('~\\s+~u', $brand) ?: [$brand];
		$keep = [];
		foreach ($tokens as $t)
		{
			$t = trim((string)$t);
			if ($t === '') continue;
			$tl = mb_strtolower($t);
			$tl = preg_replace('~[^\\p{L}\\p{N}]+~u', '', $tl) ?? $tl;
			if ($tl === '') continue;
			if (preg_match('~\\d~', $t)) break;
			if (in_array($tl, $stop, true)) break;
			$keep[] = $t;
			if (count($keep) >= 3) break;
		}
		if (!empty($keep))
		{
			$brand = trim(implode(' ', $keep));
		}
	}

	$known = mf_brand_lookup($brand);
	if ($known !== '')
	{
		if ($known === 'BRP')
		{
			$spec = mf_brand_lookup($s);
			if ($spec !== '' && $spec !== 'BRP')
			{
				$allow = [
					'Can-Am' => true,
					'Sea-Doo' => true,
					'Ski-Doo' => true,
					'brp_lynx' => true,
					'brp_rotax' => true,
				];
				if (isset($allow[$spec]))
				{
					return $spec;
				}
			}
		}
		return $known;
	}
	// Не фоллбэчимся на весь текст (там могут быть OEM-бренды).
	return $brand;
}

function mf_csv_row(array $cols): string
{
	// ; delimiter + quotes where needed
	$esc = static function ($v): string {
		$s = (string)$v;
		$s = str_replace('"', '""', $s);
		return '"' . $s . '"';
	};
	return implode(';', array_map($esc, $cols)) . "\n";
}

try
{
	$iblockId = (int)(arg('--iblock-id') ?: 4);
	$limit = arg('--limit');
	$limit = $limit !== null ? (int)$limit : 0;
	$includeRedirects = (arg('--include-redirects') ?: 'N') === 'Y';
	$includeInactive = (arg('--include-inactive') ?: 'N') === 'Y';
	$report = trim((string)(arg('--report') ?? ''));
	$dry = flag('--dry-run') || !flag('--apply');

	out("=== MF VALIDATE CATALOG BRANDS ===");
	out("IBLOCK_ID: $iblockId");
	if ($limit > 0) out("LIMIT: $limit");
	out("INCLUDE_REDIRECTS: " . ($includeRedirects ? 'Y' : 'N'));
	out("INCLUDE_INACTIVE: " . ($includeInactive ? 'Y' : 'N'));
	out("MODE: " . ($dry ? 'DRY-RUN' : 'APPLY (not implemented)'));
	if ($report !== '') out("REPORT: $report");

	$sel = [
		'ID','NAME','CODE','ACTIVE',
		'PREVIEW_TEXT',
		'PROPERTY_CML2_ARTICLE',
		'PROPERTY_MF_BRAND',
		'PROPERTY_MF_BRAND_NORM',
		'PROPERTY_MF_IS_REDIRECT',
	];
	$filter = ['IBLOCK_ID' => $iblockId];
	if (!$includeInactive)
	{
		$filter['ACTIVE'] = 'Y';
	}
	if (!$includeRedirects)
	{
		$filter['!PROPERTY_MF_IS_REDIRECT'] = 'Y';
	}

	$rs = CIBlockElement::GetList(['ID' => 'ASC'], $filter, false, false, $sel);

	$fp = null;
	if ($report !== '')
	{
		$fp = @fopen($report, 'wb');
		if (!$fp) throw new RuntimeException("Не удалось открыть report для записи: $report");
		fwrite($fp, mf_csv_row([
			'ID','ACTIVE','CODE','NAME','ARTICLE',
			'MF_BRAND','MF_BRAND_NORM',
			'SLUG_PREFIX','BRAND_FROM_CODE','BRAND_FROM_CODE_SRC',
			'BRAND_FROM_TEXT',
			'STATUS','DETAIL',
		]));
	}

	$total = 0;
	$ok = 0;
	$mismatch = 0;
	$unknown = 0;
	$weird = 0;

	while ($row = $rs->GetNext(false, false))
	{
		$total++;
		if ($limit > 0 && $total > $limit) break;

		$id = (int)$row['ID'];
		$active = (string)($row['ACTIVE'] ?? '');
		$name = (string)($row['NAME'] ?? '');
		$code = (string)($row['CODE'] ?? '');
		$article = trim((string)($row['PROPERTY_CML2_ARTICLE_VALUE'] ?? ''));
		$brandProp = trim((string)($row['PROPERTY_MF_BRAND_VALUE'] ?? ''));
		$brandNormProp = trim((string)($row['PROPERTY_MF_BRAND_NORM_VALUE'] ?? ''));
		$preview = (string)($row['PREVIEW_TEXT'] ?? '');

		$slugPrefix = mf_slug_prefix($code);
		[$brandByCode, $brandByCodeSrc] = mf_brand_from_code($code);
		$brandByText = extractBrandFromPreview($preview);
		if ($brandByText === '') $brandByText = mf_brand_lookup($name);

		$status = 'UNKNOWN';
		$detail = '';

		$propNormComputed = mf_brand_norm($brandProp);
		$propNormStored = mf_brand_norm($brandNormProp);
		if ($brandProp !== '' && $brandNormProp !== '' && $propNormComputed !== $propNormStored)
		{
			$weird++;
			$status = 'WEIRD';
			$detail = 'MF_BRAND_NORM не соответствует MF_BRAND';
		}

		$expected = '';
		$expectedSrc = '';
		if ($brandByCode !== '')
		{
			$expected = $brandByCode;
			$expectedSrc = $brandByCodeSrc;
		}
		elseif ($brandByText !== '')
		{
			$expected = $brandByText;
			$expectedSrc = 'text';
		}

		// If text source returned a raw (unmapped) brand, we still can compare by normalization.
		if ($expected !== '')
		{
			if ($brandProp === '')
			{
				$status = 'MISSING';
				$detail = "ожидается '$expected' ($expectedSrc), но MF_BRAND пуст";
				$mismatch++;
			}
			elseif (mf_brand_norm($expected) !== mf_brand_norm($brandProp))
			{
				$status = 'MISMATCH';
				$detail = "ожидается '$expected' ($expectedSrc), но MF_BRAND='$brandProp'";
				$mismatch++;
			}
			else
			{
				$status = 'OK';
				$ok++;
			}
		}
		else
		{
			$status = $status === 'WEIRD' ? 'WEIRD' : 'UNKNOWN';
			if ($status === 'UNKNOWN') $unknown++;
		}

		if ($fp)
		{
			fwrite($fp, mf_csv_row([
				$id, $active, $code, $name, $article,
				$brandProp, $brandNormProp,
				$slugPrefix, $brandByCode, $brandByCodeSrc,
				$brandByText,
				$status, $detail,
			]));
		}

		// компактный прогресс в stdout
		if (($total % 2000) === 0)
		{
			out("progress rows=$total ok=$ok mismatch=$mismatch unknown=$unknown weird=$weird");
		}
	}

	if ($fp) fclose($fp);
	out("DONE total=$total ok=$ok mismatch=$mismatch unknown=$unknown weird=$weird");

	out("");
	out("Подсказка:");
	out("- Если BRAND_FROM_CODE пуст, но SLUG_PREFIX заполнен (например, 'bosh'), добавь алиас в словарь брендов (HL mf_brand_alias),");
	out("  тогда mf_fix_catalog_brands.php сможет автоматически отдавать правильный бренд по slug.");
}
catch (Throwable $e)
{
	out("ОШИБКА: " . $e->getMessage());
	fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
	exit(1);
}

