<?php
/**
 * CLI importer: motor-force.ru/articles (Nethouse) -> Bitrix iblock "Блог"
 *
 * Usage:
 *   php mf_import_blog.php --dry-run
 *   php mf_import_blog.php --apply
 *
 * Notes:
 * - Creates iblock (CODE=blog_motor_force) + properties on first run.
 * - Upserts by element CODE (slug from source URL).
 */

if (php_sapi_name() !== 'cli')
{
	header('Content-Type: text/plain; charset=UTF-8');
	echo "Run from CLI.\n";
	exit(1);
}

$args = $_SERVER['argv'] ?? [];
$isApply = in_array('--apply', $args, true);
$isReset = in_array('--reset', $args, true);
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
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/mf_import_blog.php';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/mf_import_blog.php';
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

$SOURCE_BASE = 'https://motor-force.ru';
$START_URL = $SOURCE_BASE . '/articles';

function out(string $s): void
{
	echo $s . PHP_EOL;
	if (function_exists('flush')) flush();
}

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
	return (string)$out;
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

function mf_abs_url(string $base, string $href): string
{
	$href = trim($href);
	if ($href === '') return '';
	if (str_starts_with($href, '//')) return 'https:' . $href;
	if (str_starts_with($href, '/')) return $base . $href;
	if (preg_match('~^https?://~i', $href)) return $href;
	return $base . '/' . ltrim($href, '/');
}

function mf_slug_from_url(string $url): string
{
	$u = parse_url($url);
	$path = (string)($u['path'] ?? '');
	$path = trim($path, '/');
	$parts = explode('/', $path);
	$slug = (string)end($parts);
	$slug = preg_replace('~[^a-z0-9\\-\\_]+~i', '-', $slug);
	$slug = preg_replace('~-+~', '-', (string)$slug);
	$slug = trim((string)$slug, '-');
	return $slug !== '' ? $slug : 'article';
}

function mf_parse_ru_datetime(string $s, string $fallbackYmd = ''): string
{
	$s = trim($s);
	// Expected: "03.07.2025, 04:57"
	if (preg_match('~^(\\d{2})\\.(\\d{2})\\.(\\d{4})(?:\\s*,\\s*(\\d{2}):(\\d{2}))?~u', $s, $m))
	{
		$dd = $m[1];
		$mm = $m[2];
		$yy = $m[3];
		$hh = $m[4] ?? '00';
		$ii = $m[5] ?? '00';
		return "{$dd}.{$mm}.{$yy} {$hh}:{$ii}:00";
	}
	if ($fallbackYmd !== '' && preg_match('~^\\d{4}-\\d{2}-\\d{2}$~', $fallbackYmd))
	{
		$dt = DateTime::createFromFormat('Y-m-d', $fallbackYmd);
		return $dt ? $dt->format('d.m.Y 00:00:00') : '';
	}
	return '';
}

function mf_sanitize_html(string $html): string
{
	// protocol-relative URLs
	$html = preg_replace('~\\b(src|href)=\"//~i', '$1=\"https://', $html);

	// remove backslash-escaped quotes inside tags (common after previous imports)
	$html = preg_replace_callback('~<[^>]+>~s', static function (array $m): string {
		$tag = (string)$m[0];
		$tag = str_replace('\\"', '"', $tag);
		$tag = str_replace('\\&quot;', '&quot;', $tag);
		return $tag;
	}, (string)$html);

	// unwrap src="&quot;...&quot;"
	$html = preg_replace('~\\b(src|href)\\s*=\\s*\"&quot;([^\\\"]+?)&quot;\"~i', '$1="$2"', (string)$html);
	$html = preg_replace('~\\b(src|href)\\s*=\\s*\\\'&quot;([^\\\']+?)&quot;\\\'~i', '$1="$2"', (string)$html);

	// Rewrite source article links to local blog paths (keep them relative).
	$rewrite = static function (string $path): string {
		$path = preg_replace('~^/articles/~', '', $path);
		$path = ltrim((string)$path, '/');
		if ($path === '' || $path === 'articles') return '/blog/';
		if ($path === 'rss' || str_starts_with($path, 'rss/')) return '/blog/rss/';
		if ($path === 'tags' || str_starts_with($path, 'tags/')) return '/blog/';
		if (preg_match('~^page/(\\d+)~', $path, $m)) return '/blog/page/' . $m[1];
		if (preg_match('~^tag/(.+)~', $path, $m)) return '/blog/tag/' . $m[1];
		$slug = preg_replace('~[^a-z0-9\\-_]+~i', '-', $path);
		$slug = preg_replace('~-+~', '-', (string)$slug);
		$slug = trim((string)$slug, '-');
		return $slug !== '' ? ('/blog/' . $slug . '/') : '/blog/';
	};

	$html = preg_replace_callback('~\\bhref=\"https?://(?:www\\.)?motor-force\\.ru(/articles(?:/[^\"#?]*)?)\"~i', static function (array $m) use ($rewrite): string {
		return 'href="' . $rewrite((string)$m[1]) . '"';
	}, (string)$html);
	$html = preg_replace_callback('~\\bhref=\"(/articles(?:/[^\"#?]*)?)\"~i', static function (array $m) use ($rewrite): string {
		return 'href="' . $rewrite((string)$m[1]) . '"';
	}, (string)$html);

	return (string)$html;
}

function mf_ensure_blog_iblock(): int
{
	// CIBlock::GetList may return stale cached list; use ORM without cache and prefer newest.
	$rs = \Bitrix\Iblock\IblockTable::getList([
		'filter' => ['=CODE' => 'blog_motor_force'],
		'select' => ['ID'],
		'order' => ['ID' => 'DESC'],
		'limit' => 1,
		'cache' => ['ttl' => 0],
	]);
	if ($row = $rs->fetch())
	{
		$iblockId = (int)$row['ID'];
		// Ensure public can read (group 2).
		CIBlock::SetPermission($iblockId, [1 => "X", 2 => "R"]);
		return $iblockId;
	}

	$ib = new CIBlock();
	$iblockId = (int)$ib->Add([
		"ACTIVE" => "Y",
		"NAME" => "Блог",
		"CODE" => "blog_motor_force",
		"IBLOCK_TYPE_ID" => "news",
		"SITE_ID" => ["s1"],
		"SORT" => 200,
		"LIST_PAGE_URL" => "/blog/",
		"DETAIL_PAGE_URL" => "/blog/#ELEMENT_CODE#/",
		"INDEX_ELEMENT" => "Y",
		"INDEX_SECTION" => "N",
	]);
	if ($iblockId <= 0)
	{
		throw new RuntimeException("Failed to create iblock blog_motor_force: " . (string)$ib->LAST_ERROR);
	}

	$props = [
		[
			"NAME" => "Источник (URL)",
			"CODE" => "MF_SOURCE_URL",
			"PROPERTY_TYPE" => "S",
			"MULTIPLE" => "N",
		],
		[
			"NAME" => "Внешний ID (Nethouse)",
			"CODE" => "MF_EXT_ID",
			"PROPERTY_TYPE" => "S",
			"MULTIPLE" => "N",
		],
		[
			"NAME" => "Теги",
			"CODE" => "MF_TAGS",
			"PROPERTY_TYPE" => "S",
			"MULTIPLE" => "Y",
		],
	];

	foreach ($props as $p)
	{
		$pr = new CIBlockProperty();
		$pid = (int)$pr->Add(array_merge($p, ["IBLOCK_ID" => $iblockId, "ACTIVE" => "Y"]));
		if ($pid <= 0)
		{
			throw new RuntimeException("Failed to create property {$p['CODE']}: " . (string)$pr->LAST_ERROR);
		}
	}

	// Public read.
	CIBlock::SetPermission($iblockId, [1 => "X", 2 => "R"]);

	return $iblockId;
}

function mf_parse_articles_page(string $url, string $base): array
{
	$html = mf_fetch_url($url);
	$xp = mf_dom($html);

	$items = [];

	$articles = $xp->query("//article[contains(concat(' ', normalize-space(@class), ' '), ' article-item ')]");
	foreach ($articles as $a)
	{
		if (!($a instanceof DOMElement))
		{
			continue;
		}

		$rawId = (string)$a->getAttribute('id'); // article_997018
		$extId = '';
		if (preg_match('~(\\d+)~', $rawId, $m))
		{
			$extId = (string)$m[1];
		}

		$timeAttr = mf_attr($xp, ".//time[contains(@class,'article-item__time')]", "datetime", $a);
		$timeText = mf_text($xp, ".//time[contains(@class,'article-item__time')]", $a);

		$title = mf_text($xp, ".//h4[contains(@class,'article-item__title')]", $a);
		$href = mf_attr($xp, ".//h4[contains(@class,'article-item__title')]//a", "href", $a);
		$abs = mf_abs_url($base, $href);

		$contentNode = $xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' article-item__content ')]", $a)->item(0);
		$contentHtml = ($contentNode instanceof DOMNode) ? mf_inner_html($contentNode) : '';
		$contentHtml = mf_sanitize_html((string)$contentHtml);

		$tagNodes = $xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' article-tags__item ')]//a", $a);
		$tags = [];
		foreach ($tagNodes as $tn)
		{
			$tt = trim((string)$tn->textContent);
			if ($tt === '') continue;
			$tags[$tt] = true;
		}
		$tags = array_keys($tags);

		if ($title === '' || $abs === '')
		{
			continue;
		}

		$code = mf_slug_from_url($abs);

		$activeFrom = mf_parse_ru_datetime($timeText, $timeAttr);
		if ($activeFrom === '')
		{
			$activeFrom = mf_parse_ru_datetime('', $timeAttr);
		}

		$items[] = [
			'extId' => $extId,
			'code' => $code,
			'title' => $title,
			'sourceUrl' => $abs,
			'activeFrom' => $activeFrom,
			'detailHtml' => (string)$contentHtml,
			'tags' => $tags,
		];
	}

	$nextHref = mf_attr($xp, "//*[contains(@class,'pagination__arrow_right') and self::a]", "href");
	$nextUrl = $nextHref !== '' ? mf_abs_url($base, $nextHref) : '';

	return [$items, $nextUrl];
}

function mf_reset_iblock_elements(int $iblockId): void
{
	out("Reset: deleting all elements in iblock {$iblockId}...");
	$rs = CIBlockElement::GetList(["ID" => "DESC"], ["IBLOCK_ID" => $iblockId], false, false, ["ID"]);
	$cnt = 0;
	while ($row = $rs->Fetch())
	{
		$id = (int)($row["ID"] ?? 0);
		if ($id <= 0) continue;
		if (!CIBlockElement::Delete($id))
		{
			throw new RuntimeException("Failed to delete element ID={$id}");
		}
		$cnt++;
	}
	out("Reset: deleted {$cnt} elements.");
}

function mf_upsert_blog_item(int $iblockId, array $it, bool $apply): array
{
	$code = (string)$it['code'];
	$rs = CIBlockElement::GetList([], ["IBLOCK_ID" => $iblockId, "=CODE" => $code], false, false, ["ID", "CODE"]);
	$existing = $rs->Fetch();
	$el = new CIBlockElement();

	$fields = [
		"IBLOCK_ID" => $iblockId,
		"ACTIVE" => "Y",
		"CODE" => $code,
		"NAME" => (string)$it['title'],
		"DATE_ACTIVE_FROM" => (string)$it['activeFrom'],
		"DETAIL_TEXT" => (string)$it['detailHtml'],
		"DETAIL_TEXT_TYPE" => "html",
	];

	$props = [
		"MF_SOURCE_URL" => (string)$it['sourceUrl'],
		"MF_EXT_ID" => (string)$it['extId'],
		"MF_TAGS" => (array)$it['tags'],
	];

	if ($existing)
	{
		$id = (int)$existing["ID"];
		if ($apply)
		{
			$ok = $el->Update($id, $fields);
			if (!$ok)
			{
				throw new RuntimeException("Update failed for {$code}: " . (string)$el->LAST_ERROR);
			}
			CIBlockElement::SetPropertyValuesEx($id, $iblockId, $props);
		}
		return ['action' => 'update', 'id' => $id];
	}

	if ($apply)
	{
		$id = (int)$el->Add($fields);
		if ($id <= 0)
		{
			throw new RuntimeException("Add failed for {$code}: " . (string)$el->LAST_ERROR);
		}
		CIBlockElement::SetPropertyValuesEx($id, $iblockId, $props);
		return ['action' => 'add', 'id' => $id];
	}

	return ['action' => 'add', 'id' => 0];
}

try
{
	out(($isApply ? "APPLY" : "DRY-RUN") . ": import motor-force.ru/articles -> Bitrix");
	if ($isReset && !$isApply)
	{
		throw new RuntimeException("--reset is only allowed with --apply");
	}
	$iblockId = mf_ensure_blog_iblock();
	out("Target iblock ID={$iblockId} (CODE=blog_motor_force)");
	if ($isReset)
	{
		mf_reset_iblock_elements($iblockId);
	}

	$seenPages = [];
	$url = $START_URL;
	$pageN = 0;
	$total = 0;
	$added = 0;
	$updated = 0;

	while ($url !== '' && !isset($seenPages[$url]) && $pageN < 50)
	{
		$pageN++;
		$seenPages[$url] = true;
		out("Fetch page {$pageN}: {$url}");

		[$items, $nextUrl] = mf_parse_articles_page($url, $SOURCE_BASE);
		out("  items: " . count($items) . ($nextUrl ? " | next: {$nextUrl}" : ""));

		foreach ($items as $it)
		{
			$total++;
			$res = mf_upsert_blog_item($iblockId, $it, $isApply);
			if ($res['action'] === 'add') $added++;
			if ($res['action'] === 'update') $updated++;
		}

		$url = $nextUrl;
	}

	out("Done. items={$total}, added={$added}, updated={$updated}");
	if ($isDryRun)
	{
		out("Tip: run with --apply to write to DB.");
	}
}
catch (Throwable $e)
{
	fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);
	exit(1);
}

