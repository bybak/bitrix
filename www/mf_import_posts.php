<?php
/**
 * CLI importer: motor-force.ru posts -> Bitrix iblock news (ID=1)
 *
 * Usage:
 *   php mf_import_posts.php --dry-run
 *   php mf_import_posts.php --apply
 *
 * Notes:
 * - Deletes ALL existing elements from iblock before import (when --apply).
 */

if (php_sapi_name() !== 'cli')
{
	header('Content-Type: text/plain; charset=UTF-8');
	echo "Run from CLI.\n";
	exit(1);
}

$args = $_SERVER['argv'] ?? [];
$isApply = in_array('--apply', $args, true);
$isDryRun = !$isApply;

$docRoot = realpath(__DIR__);
if (!$docRoot)
{
	fwrite(STDERR, "Failed to resolve DOCUMENT_ROOT.\n");
	exit(1);
}

$_SERVER['DOCUMENT_ROOT'] = $docRoot;
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_SERVER['SERVER_PORT'] = $_SERVER['SERVER_PORT'] ?? 80;
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/mf_import_posts.php';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/mf_import_posts.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['SCRIPT_FILENAME'] ?? (__FILE__);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

define('BX_ROOT', '/bitrix');
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_PUBLIC_MODE', true);
define('BX_CRONTAB', true);

@set_time_limit(0);
@ini_set('memory_limit', '1024M');
@ini_set('display_errors', '1');
@error_reporting(E_ALL);

require $_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

Loader::includeModule('iblock');

$IBLOCK_ID = 1;
$SOURCE_BASE = 'https://motor-force.ru';
$START_URL = $SOURCE_BASE . '/posts/year/all/1';

function mf_fetch_url(string $url): string
{
	$ua = 'Mozilla/5.0 (compatible; MFBitrixImporter/1.0; +https://motor-force.ru)';

	if (function_exists('curl_init'))
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_CONNECTTIMEOUT => 20,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_USERAGENT => $ua,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		]);
		$body = curl_exec($ch);
		$err = curl_error($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		if ($body === false || $code < 200 || $code >= 300)
		{
			throw new RuntimeException("HTTP {$code} for {$url}. {$err}");
		}
		return (string)$body;
	}

	$ctx = stream_context_create([
		'http' => [
			'method' => 'GET',
			'timeout' => 60,
			'header' => "User-Agent: {$ua}\r\n",
		],
	]);
	$body = @file_get_contents($url, false, $ctx);
	if ($body === false)
	{
		throw new RuntimeException("Failed to fetch {$url}");
	}
	return (string)$body;
}

function mf_dom(string $html): DOMXPath
{
	libxml_use_internal_errors(true);
	$dom = new DOMDocument();
	$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
	libxml_clear_errors();
	return new DOMXPath($dom);
}

function mf_inner_html(DOMNode $node): string
{
	$out = '';
	foreach ($node->childNodes as $child)
	{
		$out .= $node->ownerDocument->saveHTML($child);
	}
	return $out;
}

function mf_text(DOMXPath $xp, string $query, ?DOMNode $ctx = null): string
{
	$n = $xp->query($query, $ctx)->item(0);
	return $n ? trim($n->textContent) : '';
}

function mf_attr(DOMXPath $xp, string $query, string $attr, ?DOMNode $ctx = null): string
{
	$n = $xp->query($query, $ctx)->item(0);
	if (!$n || !($n instanceof DOMElement))
	{
		return '';
	}
	return trim((string)$n->getAttribute($attr));
}

function mf_slugify(string $title): string
{
	$title = trim($title);
	if ($title === '')
	{
		return 'post';
	}
	$slug = CUtil::translit($title, 'ru', [
		'max_len' => 80,
		'change_case' => 'L',
		'replace_space' => '-',
		'replace_other' => '-',
		'delete_repeat_replace' => true,
	]);
	$slug = preg_replace('~-+~', '-', (string)$slug);
	$slug = trim((string)$slug, '-');
	return $slug !== '' ? $slug : 'post';
}

function mf_parse_list_page(string $url, string $base): array
{
	$html = mf_fetch_url($url);
	$xp = mf_dom($html);

	$items = [];
	$articles = $xp->query("//article[contains(concat(' ', normalize-space(@class), ' '), ' post-item ')]");
	foreach ($articles as $a)
	{
		if (!($a instanceof DOMElement))
		{
			continue;
		}

		$datetime = mf_attr($xp, ".//time[contains(@class,'post-item__time')]", "datetime", $a);
		$dateText = mf_text($xp, ".//time[contains(@class,'post-item__time')]", $a);

		$title = mf_text($xp, ".//h4[contains(@class,'post-item__title')]", $a);
		$href = mf_attr($xp, ".//h4[contains(@class,'post-item__title')]//a", "href", $a);
		if ($href !== '' && str_starts_with($href, '/'))
		{
			$href = $base . $href;
		}

		$briefNode = $xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' post-item__brief ')]", $a)->item(0);
		$briefHtml = ($briefNode instanceof DOMNode) ? mf_inner_html($briefNode) : '';

		if ($title === '')
		{
			continue;
		}

		$items[] = [
			'title' => $title,
			'datetime' => $datetime,
			'dateText' => $dateText,
			'href' => $href,
			'briefHtml' => $briefHtml,
		];
	}

	$nextHref = mf_attr($xp, "//*[contains(@class,'pagination__arrow_right') and self::a]", "href");
	if ($nextHref !== '' && str_starts_with($nextHref, '/'))
	{
		$nextHref = $base . $nextHref;
	}

	return [$items, $nextHref];
}

function mf_fetch_detail_body_html(string $url): string
{
	$html = mf_fetch_url($url);
	$xp = mf_dom($html);
	$body = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' post-item__body ')]")->item(0);
	if ($body instanceof DOMNode)
	{
		return mf_inner_html($body);
	}
	return '';
}

function mf_parse_date_to_bitrix(string $datetimeAttr, string $fallbackText): string
{
	// Prefer ISO Y-m-d from datetime attr.
	$dt = trim($datetimeAttr);
	if ($dt !== '')
	{
		// "2025-12-29"
		if (preg_match('~^(\\d{4})-(\\d{2})-(\\d{2})~', $dt, $m))
		{
			return "{$m[3]}.{$m[2]}.{$m[1]}";
		}
	}

	// Fallback: dd.mm.yyyy in text.
	$t = trim($fallbackText);
	if (preg_match('~(\\d{2})\\.(\\d{2})\\.(\\d{4})~', $t, $m))
	{
		return "{$m[1]}.{$m[2]}.{$m[3]}";
	}

	return date('d.m.Y');
}

// 1) Crawl all pages
$all = [];
$seen = [];
$url = $START_URL;

while ($url !== '' && !isset($seen[$url]))
{
	$seen[$url] = true;
	fwrite(STDOUT, "Fetch: {$url}\n");
	[$items, $next] = mf_parse_list_page($url, $SOURCE_BASE);
	foreach ($items as $it)
	{
		$all[] = $it;
	}
	$url = $next;
}

fwrite(STDOUT, "Parsed items: " . count($all) . "\n");

// 2) Normalize + enrich with detail (when link exists)
$normalized = [];
foreach ($all as $it)
{
	$title = trim((string)$it['title']);
	$date = mf_parse_date_to_bitrix((string)$it['datetime'], (string)$it['dateText']);
	$href = (string)$it['href'];
	$detailHtml = '';

	if ($href !== '')
	{
		try {
			$detailHtml = mf_fetch_detail_body_html($href);
		} catch (Throwable $e) {
			$detailHtml = '';
		}
	}

	$textHtml = $detailHtml !== '' ? $detailHtml : (string)$it['briefHtml'];
	if (trim(strip_tags($textHtml)) === '')
	{
		$textHtml = (string)$it['briefHtml'];
	}

	$code = '';
	if ($href !== '')
	{
		$path = parse_url($href, PHP_URL_PATH);
		if (is_string($path) && preg_match('~^/posts/([^/]+)/?~', $path, $m))
		{
			$code = $m[1];
		}
	}
	if ($code === '')
	{
		$code = mf_slugify($title);
	}

	$key = $date . '|' . $title;
	if (isset($normalized[$key]))
	{
		continue;
	}
	$normalized[$key] = [
		'NAME' => $title,
		'CODE' => $code,
		'ACTIVE_FROM' => $date,
		'DETAIL_HTML' => $textHtml,
		'PREVIEW_TEXT' => trim(strip_tags((string)$it['briefHtml'])),
	];
}

$normalized = array_values($normalized);
fwrite(STDOUT, "Normalized unique: " . count($normalized) . "\n");

// 3) Delete existing + import
if ($isDryRun)
{
	fwrite(STDOUT, "DRY RUN: no deletion/import performed. Use --apply\n");
	exit(0);
}

fwrite(STDOUT, "Deleting existing iblock elements (IBLOCK_ID={$IBLOCK_ID})...\n");
$rsDel = CIBlockElement::GetList([], ['IBLOCK_ID' => $IBLOCK_ID], false, false, ['ID']);
while ($row = $rsDel->Fetch())
{
	$id = (int)$row['ID'];
	if ($id > 0)
	{
		CIBlockElement::Delete($id);
	}
}

fwrite(STDOUT, "Importing...\n");
$el = new CIBlockElement();

$usedCodes = [];
foreach ($normalized as $idx => $n)
{
	$code = (string)$n['CODE'];
	if ($code === '')
	{
		$code = 'post';
	}
	$base = $code;
	$suffix = 1;
	while (isset($usedCodes[$code]))
	{
		$suffix++;
		$code = $base . '-' . $suffix;
	}
	$usedCodes[$code] = true;

	$fields = [
		'IBLOCK_ID' => $IBLOCK_ID,
		'ACTIVE' => 'Y',
		'NAME' => $n['NAME'],
		'CODE' => $code,
		'ACTIVE_FROM' => $n['ACTIVE_FROM'],
		'DETAIL_TEXT' => $n['DETAIL_HTML'],
		'DETAIL_TEXT_TYPE' => 'html',
		'PREVIEW_TEXT' => $n['PREVIEW_TEXT'],
		'PREVIEW_TEXT_TYPE' => 'text',
	];

	$newId = $el->Add($fields, false, true, true);
	if (!$newId)
	{
		fwrite(STDERR, "Add failed (#{$idx}): " . $el->LAST_ERROR . "\n");
	}
	else
	{
		fwrite(STDOUT, "Added ID={$newId} {$fields['ACTIVE_FROM']} {$fields['NAME']}\n");
	}
}

fwrite(STDOUT, "Done.\n");

