<?php
/**
 * Интерактивный фикс брендов в каталоге.
 *
 * Цель: чтобы в MF_BRAND / MF_BRAND_NORM попадал канонический бренд (Yamaha/Can-Am/...)
 * и никогда не сохранялись "описания" типа "Yamaha Brake Disc 1HP-...".
 *
 * Запуск (внутри контейнера bitrix_php):
 *   php /var/www/html/mf_fix_catalog_brands.php --dry-run
 *   php /var/www/html/mf_fix_catalog_brands.php --apply --interactive=Y
 *
 * Опции:
 *   --iblock-id=4
 *   --limit=200
 *   --id=1023635,1023636 (ограничить конкретными ID элементов)
 *   --brand-norm-prefix=BRP,YAMAHA (ограничить выборку по префиксу(ам) MF_BRAND_NORM)
 *   --name-contains=Lynx,Rotax (ограничить выборку по подстроке(ам) в NAME)
 *   --include-redirects=Y|N (по умолчанию N)
 *   --include-inactive=Y|N (по умолчанию N)
 *   --interactive=Y|N (по умолчанию Y)
 *   --interactive-mismatch=Y|N (по умолчанию N) (спрашивать при MISMATCH, когда предлагается заменить бренд)
 *   --verbose=Y|N (по умолчанию N)
 *   --apply / --dry-run
 */
$_SERVER["DOCUMENT_ROOT"] = __DIR__;
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

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
		if (strpos($a, $name.'=') === 0) return substr($a, strlen($name) + 1);
	}
	return null;
}
function flag(string $name): bool { return in_array($name, $_SERVER['argv'], true); }
function out(string $s): void { echo $s . PHP_EOL; if (function_exists('flush')) flush(); }

function mf_csv_parse_report_ids(string $path, array $statuses): array
{
	$path = trim($path);
	if ($path === '' || !is_file($path))
	{
		throw new RuntimeException("Report file not found: $path");
	}
	$want = [];
	foreach ($statuses as $s)
	{
		$s = strtoupper(trim((string)$s));
		if ($s !== '') $want[$s] = true;
	}
	if (empty($want))
	{
		throw new RuntimeException("No statuses provided for --only-status");
	}

	$fp = fopen($path, 'rb');
	if (!$fp)
	{
		throw new RuntimeException("Cannot open report file: $path");
	}

	$header = fgetcsv($fp, 0, ';', '"');
	if (!is_array($header) || empty($header))
	{
		fclose($fp);
		throw new RuntimeException("Report CSV has no header: $path");
	}
	$idx = [];
	foreach ($header as $i => $name)
	{
		$idx[(string)$name] = (int)$i;
	}
	if (!isset($idx['ID']) || !isset($idx['STATUS']))
	{
		fclose($fp);
		throw new RuntimeException("Report CSV header must contain ID and STATUS columns");
	}

	$ids = [];
	$counts = [];
	while (($row = fgetcsv($fp, 0, ';', '"')) !== false)
	{
		if (!is_array($row) || empty($row)) continue;
		$st = strtoupper(trim((string)($row[$idx['STATUS']] ?? '')));
		if ($st === '' || !isset($want[$st])) continue;

		$id = (int)trim((string)($row[$idx['ID']] ?? '0'));
		if ($id <= 0) continue;
		$ids[$id] = true;
		$counts[$st] = ($counts[$st] ?? 0) + 1;
	}
	fclose($fp);

	ksort($counts);
	out("REPORT STATUS COUNTS: " . json_encode($counts, JSON_UNESCAPED_UNICODE));
	return array_map('intval', array_keys($ids));
}

function mf_csv_parse_report_map(string $path, array $statuses): array
{
	$path = trim($path);
	if ($path === '' || !is_file($path))
	{
		throw new RuntimeException("Report file not found: $path");
	}
	$want = [];
	foreach ($statuses as $s)
	{
		$s = strtoupper(trim((string)$s));
		if ($s !== '') $want[$s] = true;
	}
	if (empty($want))
	{
		throw new RuntimeException("No statuses provided for --only-status");
	}

	$fp = fopen($path, 'rb');
	if (!$fp)
	{
		throw new RuntimeException("Cannot open report file: $path");
	}
	$header = fgetcsv($fp, 0, ';', '"');
	if (!is_array($header) || empty($header))
	{
		fclose($fp);
		throw new RuntimeException("Report CSV has no header: $path");
	}
	$idx = [];
	foreach ($header as $i => $name)
	{
		$idx[(string)$name] = (int)$i;
	}
	if (!isset($idx['ID']) || !isset($idx['STATUS']))
	{
		fclose($fp);
		throw new RuntimeException("Report CSV header must contain ID and STATUS columns");
	}

	$map = [];
	while (($row = fgetcsv($fp, 0, ';', '"')) !== false)
	{
		if (!is_array($row) || empty($row)) continue;
		$st = strtoupper(trim((string)($row[$idx['STATUS']] ?? '')));
		if ($st === '' || !isset($want[$st])) continue;

		$id = (int)trim((string)($row[$idx['ID']] ?? '0'));
		if ($id <= 0) continue;

		$map[$id] = [
			'STATUS' => $st,
			'DETAIL' => (string)($idx['DETAIL'] ?? null) !== '' ? (string)($row[$idx['DETAIL']] ?? '') : '',
			'EXPECTED_TEXT' => (isset($idx['BRAND_FROM_TEXT']) ? (string)($row[$idx['BRAND_FROM_TEXT']] ?? '') : ''),
			'EXPECTED_CODE' => (isset($idx['BRAND_FROM_CODE']) ? (string)($row[$idx['BRAND_FROM_CODE']] ?? '') : ''),
			'EXPECTED_CODE_SRC' => (isset($idx['BRAND_FROM_CODE_SRC']) ? (string)($row[$idx['BRAND_FROM_CODE_SRC']] ?? '') : ''),
		];
	}
	fclose($fp);
	return $map;
}

function mf_brand_lookup(string $text): string
{
	$create = (bool)($GLOBALS['MF_BRAND_DICT_CREATE'] ?? false);
	return mf_brand_find($text, $create);
}

function mf_brand_lookup_for_slug_prefix(string $prefix): string
{
	$create = (bool)($GLOBALS['MF_BRAND_DICT_CREATE'] ?? false);
	$dict = mf_brand_aliases_load($create);
	if (empty($dict['ROWS'])) return '';

	$norm = mf_brand_norm($prefix);
	if ($norm === '') return '';

	foreach ($dict['ROWS'] as $r)
	{
		$an = (string)($r['ALIAS_NORM'] ?? '');
		if ($an === '') continue;

		// Exact match is always allowed.
		if ($norm === $an) return (string)($r['CANONICAL'] ?? '');

		// Prefix match only for sufficiently specific aliases (avoid accidental short matches).
		// Keep this conservative: short umbrella aliases (e.g. BRP) should NOT match prefixes like "brp123...".
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

function mf_brand_from_code(string $code): array
{
	$code = trim((string)$code);
	if ($code === '') return ['', ''];

	$prefix = mf_slug_prefix($code);
	if ($prefix === '') return ['', ''];
	// Too short prefixes (e.g. "brp") are ambiguous umbrella brands; ignore and fall back to description.
	if (mb_strlen($prefix) < 4) return ['', ''];

	$byPrefix = mf_brand_lookup_for_slug_prefix($prefix);
	if ($byPrefix !== '')
	{
		return [$byPrefix, 'code_prefix'];
	}
	return ['', ''];
}

function mf_canonicalize_brand_candidate(string $candidate, string $fullText = ''): string
{
	$candidate = trim($candidate);
	$candidate = preg_replace('~\\s*\\(.*$~u', '', $candidate) ?? $candidate;
	$candidate = trim($candidate, " \t\n\r\0\x0B\"'`");
	$candidate = trim($candidate);
	if ($candidate === '') return '';

	$known = mf_brand_lookup($candidate);
	// If current brand is umbrella (BRP), but the full text reveals a more specific sub-brand,
	// prefer the more specific one.
	if ($known === 'BRP' && $fullText !== '')
	{
		$spec = mf_brand_lookup($fullText);
		if ($spec !== '' && $spec !== 'BRP')
		{
			return $spec;
		}
	}
	if ($known !== '') return $known;

	$parts = preg_split('~\\s{2,}|\\s\\|\\s|\\s/\\s|,|;~u', $candidate) ?: [$candidate];
	foreach ($parts as $p)
	{
		$p = trim((string)$p);
		if ($p === '') continue;
		$k = mf_brand_lookup($p);
		if ($k !== '') return $k;
	}

	if ($fullText !== '')
	{
		$k = mf_brand_lookup($fullText);
		if ($k !== '') return $k;
	}
	return '';
}

function mf_is_unreliable_brand(string $brand): bool
{
	$bn = mf_brand_norm($brand);
	if ($bn === '') return true;
	// Vendor / non-brand placeholders observed in the catalog.
	$bad = [
		'WE' => true,          // W.E
		'RK' => true,
		'AS' => true,
		'UNKNOWNBRAND' => true,
	];
	return isset($bad[$bn]);
}

function extractBrandFromPreviewEx(string $previewHtml): array
{
	$previewHtml = (string)$previewHtml;
	if ($previewHtml === '') return ['', 'none'];

	// PREVIEW_TEXT из GetNext() часто приходит как HTML-entity escaped (&lt;p&gt;...),
	// поэтому декодируем сначала, а потом уже обрабатываем как HTML.
	$s = html_entity_decode($previewHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');

	// Preserve word boundaries between block elements before strip_tags(),
	// otherwise "...AGM</p><p>Производитель..." becomes "...AGMПроизводитель...".
	$s = preg_replace('~<br\\s*/?>~i', "\n", $s) ?? $s;
	$s = preg_replace('~</?(p|div|tr|td|th|li|ul|ol|table|tbody|thead|h[1-6])\\b[^>]*>~i', "\n", $s) ?? $s;
	$s = strip_tags($s);
	$s = str_replace(["\xC2\xA0", "\t"], ' ', $s);
	$s = preg_replace('~\\s+~u', ' ', $s) ?? $s;
	$s = trim($s);
	if ($s === '') return ['', 'none'];

	$labels = [
		'производитель','изготовитель','бренд','марка','фирма',
		'manufacturer','brand','make','vendor','company',
	];
	$labelRe = implode('|', array_map(static fn($x) => preg_quote($x, '~'), $labels));
	if (!preg_match('~(?:^|[;,.\\(\\)\\[\\]\\s])(' . $labelRe . ')\\s*[:\\-—=]?\\s*([^;,.\\n\\r]{1,80})~iu', $s, $m))
	{
		$scan = mf_brand_lookup($s);
		return [$scan, ($scan !== '' ? 'scan' : 'none')];
	}

	$brand = trim((string)($m[2] ?? ''));
	$brand = preg_split('~\\s{2,}|\\s\\|\\s|\\s/\\s~u', $brand)[0] ?? $brand;
	$brand = trim($brand, " \t\n\r\0\x0B\"'`");
	$brand = preg_replace('~\\s*\\(.*$~u', '', $brand) ?? $brand;
	$brand = trim($brand);
	if ($brand !== '')
	{
		// Heuristic: keep brand-like leading tokens only (avoid "Номер по каталогу..." etc).
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
			// Strip punctuation safely for UTF-8 (avoid trim() charmask multibyte issues).
			$tl = preg_replace('~[^\\p{L}\\p{N}]+~u', '', $tl) ?? $tl;
			if ($tl === '') continue;
			if (preg_match('~\\d~', $t)) break;
			if (in_array($tl, $stop, true)) break;
			$keep[] = $t;
			if (count($keep) >= 3) break; // brands rarely exceed 2-3 tokens
		}
		if (!empty($keep))
		{
			$brand = trim(implode(' ', $keep));
		}
	}

	// IMPORTANT:
	// For explicit label cases (Производитель/Бренд/Manufacturer: ...), do NOT scan the whole text
	// for other brands (OEM lists). Only match dictionary against the extracted value itself.
	$known = mf_brand_lookup($brand);
	if ($known !== '')
	{
		// If label says BRP, but text contains a more specific BRP-family sub-brand,
		// prefer the more specific one (avoid collapsing Lynx/Rotax/Can-Am/etc into BRP).
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
					return [$spec, 'label_refined'];
				}
			}
		}
		return [$known, 'label'];
	}
	return [$brand, 'label_unmapped'];
}

function prompt(string $q): string
{
	echo $q;
	if (function_exists('flush')) flush();
	$line = fgets(STDIN);
	return $line === false ? '' : trim($line);
}

try
{
	$iblockId = (int)(arg('--iblock-id') ?: 4);
	$limit = arg('--limit');
	$limit = $limit !== null ? (int)$limit : 0;
	$idArg = trim((string)(arg('--id') ?? ''));
	$fromReport = trim((string)(arg('--from-report') ?? ''));
	$onlyStatusArg = trim((string)(arg('--only-status') ?? ''));
	$brandNormPrefix = trim((string)(arg('--brand-norm-prefix') ?? ''));
	$nameContains = trim((string)(arg('--name-contains') ?? ''));
	$includeRedirects = (arg('--include-redirects') ?: 'N') === 'Y';
	$includeInactive = (arg('--include-inactive') ?: 'N') === 'Y';
	$interactive = (arg('--interactive') ?: 'Y') === 'Y';
	$interactiveMismatch = (arg('--interactive-mismatch') ?: 'N') === 'Y';
	$verbose = (arg('--verbose') ?: 'N') === 'Y';
	$apply = flag('--apply');
	$dry = flag('--dry-run') || !$apply;
	$GLOBALS['MF_BRAND_DICT_CREATE'] = (bool)$apply;

	out("=== MF FIX CATALOG BRANDS ===");
	out("IBLOCK_ID: $iblockId");
	if ($limit > 0) out("LIMIT: $limit");
	if ($idArg !== '') out("ID: $idArg");
	if ($fromReport !== '') out("FROM_REPORT: $fromReport");
	if ($onlyStatusArg !== '') out("ONLY_STATUS: $onlyStatusArg");
	if ($brandNormPrefix !== '') out("BRAND_NORM_PREFIX: $brandNormPrefix");
	if ($nameContains !== '') out("NAME_CONTAINS: $nameContains");
	out("MODE: " . ($apply ? 'APPLY' : 'DRY-RUN'));
	out("INCLUDE_REDIRECTS: " . ($includeRedirects ? 'Y' : 'N'));
	out("INCLUDE_INACTIVE: " . ($includeInactive ? 'Y' : 'N'));
	out("INTERACTIVE: " . ($interactive ? 'Y' : 'N'));
	out("INTERACTIVE_MISMATCH: " . ($interactiveMismatch ? 'Y' : 'N'));
	out("VERBOSE: " . ($verbose ? 'Y' : 'N'));

	// If mismatch prompting is requested but STDIN isn't a TTY, disable it to avoid blocking.
	if ($interactiveMismatch && function_exists('posix_isatty') && !@posix_isatty(STDIN))
	{
		out("WARN: STDIN is not a TTY; disabling --interactive-mismatch to avoid blocking.");
		$interactiveMismatch = false;
	}

	$dict = mf_brand_aliases_load($apply);
	$hl = (isset($dict['HL']) && is_array($dict['HL'])) ? $dict['HL'] : null;

	$reportMap = [];
	$sel = [
		'ID','NAME','CODE',
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
	if ($idArg !== '')
	{
		$ids = array_values(array_filter(array_map('intval', preg_split('~[\\s,;]+~', $idArg) ?: []), static fn($v) => $v > 0));
		if (!empty($ids))
		{
			$filter['ID'] = $ids;
		}
	}
	// Allow running only on IDs from validation report.
	if ($fromReport !== '')
	{
		$statuses = $onlyStatusArg !== ''
			? array_values(array_filter(array_map('trim', explode(',', $onlyStatusArg)), static fn($v) => $v !== ''))
			: ['MISMATCH', 'UNKNOWN', 'WEIRD', 'MISSING'];
		$reportMap = mf_csv_parse_report_map($fromReport, $statuses);
		$idsFromReport = array_map('intval', array_keys($reportMap));
		out("REPORT IDS: " . count($idsFromReport));
		if (!empty($idsFromReport))
		{
			$filter['ID'] = $idsFromReport;
		}
	}
	if ($brandNormPrefix !== '')
	{
		$prefixes = array_values(array_filter(array_map('trim', explode(',', $brandNormPrefix)), static fn($v) => $v !== ''));
		if (!empty($prefixes))
		{
			$or = ['LOGIC' => 'OR'];
			foreach ($prefixes as $p)
			{
				$or[] = ['%PROPERTY_MF_BRAND_NORM' => $p];
			}
			$filter[] = $or;
		}
	}
	if ($nameContains !== '')
	{
		$subs = array_values(array_filter(array_map('trim', explode(',', $nameContains)), static fn($v) => $v !== ''));
		if (!empty($subs))
		{
			$or = ['LOGIC' => 'OR'];
			foreach ($subs as $s)
			{
				$or[] = ['%NAME' => $s];
			}
			$filter[] = $or;
		}
	}
	$rs = CIBlockElement::GetList(['ID' => 'ASC'], $filter, false, false, $sel);

	$total = 0;
	$changed = 0;
	$unknown = 0;
	$skipped = 0;
	$lastProgressAt = microtime(true);
	$progressEvery = 2000;

	$el = new CIBlockElement();

	while ($row = $rs->GetNext(false, false))
	{
		$total++;
		if ($limit > 0 && $total > $limit) break;

		$id = (int)$row['ID'];
		$name = (string)$row['NAME'];
		$code = (string)($row['CODE'] ?? '');
		$article = trim((string)($row['PROPERTY_CML2_ARTICLE_VALUE'] ?? ''));
		$brandOld = trim((string)($row['PROPERTY_MF_BRAND_VALUE'] ?? ''));
		$brandNormOld = trim((string)($row['PROPERTY_MF_BRAND_NORM_VALUE'] ?? ''));
		$preview = (string)($row['PREVIEW_TEXT'] ?? '');

		$source = trim($brandOld . "\n" . $preview . "\n" . $name);

		$brandNew = '';
		[$brandByCode, $brandByCodeSrc] = mf_brand_from_code($code);
		[$brandFromPreview, $brandFromPreviewSrc] = extractBrandFromPreviewEx($preview);
		if ($verbose)
		{
			out(sprintf(
				"CAND ID=%d code='%s' codeBrand='%s'(%s) previewBrand='%s'(%s) current='%s'",
				$id,
				$code,
				$brandByCode,
				$brandByCodeSrc,
				$brandFromPreview,
				$brandFromPreviewSrc,
				$brandOld
			));
		}

		// 1) Prefer brand derived from slug/code when it maps to a known canonical brand.
		if ($brandByCode !== '')
		{
			$brandNew = $brandByCode;
			if ($verbose)
			{
				out(sprintf("HINT ID=%d code='%s' -> brand='%s' (%s)", $id, $code, $brandByCode, $brandByCodeSrc));
			}
		}

		// 2) If preview has an explicit manufacturer/brand label, trust it even if MF_BRAND is set.
		if (
			$brandNew === ''
			&& $brandFromPreview !== ''
			&& (
				$brandFromPreviewSrc === 'label'
				|| $brandFromPreviewSrc === 'label_unmapped'
				|| $brandFromPreviewSrc === 'label_refined'
			)
		)
		{
			$brandNew = $brandFromPreview;
		}

		$unreliableOld = mf_is_unreliable_brand($brandOld);

		// 3) Otherwise, keep existing brand if it can be canonicalized AND isn't a vendor placeholder.
		if ($brandNew === '' && $brandOld !== '' && !$unreliableOld)
		{
			$brandNew = mf_canonicalize_brand_candidate($brandOld, $source);
		}

		// 4) If current brand is unreliable (vendor), prefer scan result from preview/name.
		if ($brandNew === '' && $brandFromPreview !== '' && $unreliableOld)
		{
			$brandNew = $brandFromPreview;
		}

		// 5) Fallback: try brand from preview scan / name / full text.
		if ($brandNew === '' && $brandFromPreview !== '')
		{
			$brandNew = $brandFromPreview;
		}
		if ($brandNew === '')
		{
			$brandNew = mf_brand_lookup($source);
		}

		if ($brandNew === '')
		{
			$unknown++;
			if ($interactive)
			{
				out("\n--- UNKNOWN BRAND ---");
				out("ID: $id");
				out("NAME: $name");
				if ($article !== '') out("ARTICLE: $article");
				if ($brandOld !== '' || $brandNormOld !== '') out("CURRENT: brand='$brandOld' norm='$brandNormOld'");
				$hint = mf_brand_lookup($source);
				if ($hint !== '') out("HINT: найдено в тексте '$hint'");
				$canon = [];
				if (!empty($dict['ROWS']))
				{
					foreach ($dict['ROWS'] as $r)
					{
						$c = (string)($r['CANONICAL'] ?? '');
						if ($c !== '') $canon[$c] = true;
					}
				}
				out("Варианты (словарь HL): " . (!empty($canon) ? implode(', ', array_keys($canon)) : '(пока пусто)'));

				$ans = prompt("Введи бренд (Enter=пропустить, 'u'=Unknown): ");
				if ($ans === '')
				{
					$skipped++;
					continue;
				}
				if (mb_strtolower($ans) === 'u')
				{
					$brandNew = 'Unknown brand';
				}
				else
				{
					$brandNew = trim($ans);
				}

				// Persist user decision into HL dictionary (so next items match automatically).
				if (!$dry && is_array($hl))
				{
					mf_brand_register_alias($hl, $brandNew, $brandNew, true, 200);
					if ($brandOld !== '')
					{
						mf_brand_register_alias($hl, $brandNew, $brandOld, true, 200);
					}
					// refresh dict snapshot for printing hints later
					$dict = mf_brand_aliases_load(true);
				}
			}
			else
			{
				$skipped++;
				continue;
			}
		}

		$brandNormNew = mf_brand_norm($brandNew);
		$need = ($brandNew !== $brandOld || $brandNormNew !== mf_brand_norm($brandNormOld));
		$reportRow = (isset($reportMap[$id]) && is_array($reportMap[$id])) ? $reportMap[$id] : null;
		$reportStatus = $reportRow ? (string)($reportRow['STATUS'] ?? '') : '';
		$expectedFromReport = $reportRow ? trim((string)($reportRow['EXPECTED_TEXT'] ?? '')) : '';

		// If running from report + interactive mismatch, prompt even if algorithm decided "no change".
		if (!$dry && $interactiveMismatch && $reportStatus === 'MISMATCH' && !$need)
		{
			out("\n--- MISMATCH (report) ---");
			out("ID: $id");
			out("CODE: $code");
			out("NAME: $name");
			if ($article !== '') out("ARTICLE: $article");
			out("CURRENT: brand='$brandOld' norm='$brandNormOld'");
			out("CANDIDATES: codeBrand='{$brandByCode}'({$brandByCodeSrc}) previewBrand='{$brandFromPreview}'({$brandFromPreviewSrc})");
			if ($expectedFromReport !== '') out("REPORT_EXPECT: '$expectedFromReport'");
			$ans = prompt("Выбери: Enter=применить REPORT_EXPECT, k=оставить, s=пропустить, или введи бренд вручную: ");
			$ansL = mb_strtolower(trim((string)$ans));
			if ($ansL === 'k' || $ansL === 's')
			{
				$skipped++;
				continue;
			}
			if ($ans === '')
			{
				if ($expectedFromReport === '')
				{
					$skipped++;
					continue;
				}
				$brandNew = $expectedFromReport;
			}
			else
			{
				$brandNew = trim((string)$ans);
			}
			$brandNormNew = mf_brand_norm($brandNew);
			$need = ($brandNew !== $brandOld || $brandNormNew !== mf_brand_norm($brandNormOld));
			if (!$need)
			{
				$skipped++;
				continue;
			}
		}

		if (!$need) continue;

		// Optional interactive resolution for mismatches.
		if (!$dry && $interactiveMismatch)
		{
			out("\n--- MISMATCH ---");
			out("ID: $id");
			out("CODE: $code");
			out("NAME: $name");
			if ($article !== '') out("ARTICLE: $article");
			out("CURRENT: brand='$brandOld' norm='$brandNormOld'");
			out("SUGGEST: brand='$brandNew' norm='$brandNormNew'");
			out("CANDIDATES: codeBrand='{$brandByCode}'({$brandByCodeSrc}) previewBrand='{$brandFromPreview}'({$brandFromPreviewSrc})");

			$ans = prompt("Выбери: Enter=применить, k=оставить, s=пропустить, или введи бренд вручную: ");
			$ansL = mb_strtolower(trim((string)$ans));
			if ($ansL === 'k')
			{
				$skipped++;
				continue;
			}
			if ($ansL === 's')
			{
				$skipped++;
				continue;
			}
			if ($ans !== '' && $ansL !== 'k' && $ansL !== 's')
			{
				$brandNew = trim((string)$ans);
				if ($brandNew === '')
				{
					$skipped++;
					continue;
				}
				$brandNormNew = mf_brand_norm($brandNew);
			}
		}

		if ($verbose)
		{
			out(sprintf("FIX ID=%d  '%s'  %s  brand: '%s' -> '%s'", $id, $name, ($article !== '' ? "[$article]" : ''), $brandOld, $brandNew));
		}

		if ($dry) { $changed++; continue; }

		$ok = $el->SetPropertyValuesEx($id, $iblockId, [
			'MF_BRAND' => $brandNew,
			'MF_BRAND_NORM' => $brandNormNew,
		]);
		if ($ok === false)
		{
			$skipped++;
			continue;
		}
		$changed++;

		$now = microtime(true);
		if (($total % $progressEvery) === 0 || ($now - $lastProgressAt) >= 1.0)
		{
			out("progress rows=$total changed=$changed unknown=$unknown skipped=$skipped");
			$lastProgressAt = $now;
		}
	}

	out("\nDONE total=$total changed=$changed unknown=$unknown skipped=$skipped");
}
catch (Throwable $e)
{
	out("ОШИБКА: " . $e->getMessage());
	fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
	exit(1);
}

