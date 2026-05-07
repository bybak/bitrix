<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;

Loader::includeModule("iblock");

function mf_blog_detail_iblock_id(): int
{
	// CIBlock::GetList is aggressively cached; use ORM with ttl=0.
	$rs = \Bitrix\Iblock\IblockTable::getList([
		'filter' => ['=CODE' => 'blog_motor_force', '=ACTIVE' => 'Y'],
		'select' => ['ID'],
		'order' => ['ID' => 'DESC'],
		'limit' => 1,
		'cache' => ['ttl' => 0],
	]);
	$row = $rs->fetch();
	return $row ? (int)$row['ID'] : 0;
}

function mf_blog_fix_protocol_relative_urls(string $html): string
{
	// 1) protocol-relative
	$html = preg_replace('~\\b(src|href)=\"//~i', '$1=\"https://', $html);

	// 2) remove backslash-escaped quotes inside tags: src=\"...\"
	$html = preg_replace_callback('~<[^>]+>~s', static function (array $m): string {
		$tag = (string)$m[0];
		$tag = str_replace('\\"', '"', $tag);
		$tag = str_replace('\\&quot;', '&quot;', $tag);
		return $tag;
	}, (string)$html);

	// 3) unwrap src="&quot;...&quot;"
	$html = preg_replace('~\\b(src|href)\\s*=\\s*\"&quot;([^\\\"]+?)&quot;\"~i', '$1="$2"', (string)$html);
	$html = preg_replace('~\\b(src|href)\\s*=\\s*\\\'&quot;([^\\\']+?)&quot;\\\'~i', '$1="$2"', (string)$html);

	// 4) Rewrite imported Nethouse article links to our local blog.
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

$iblockId = mf_blog_detail_iblock_id();
$code = isset($_GET["ELEMENT_CODE"]) ? (string)$_GET["ELEMENT_CODE"] : "";

// Preload item BEFORE template header is rendered (breadcrumbs depend on chain).
$item = null;
$title = "Блог";
$dateAttr = "";
$dateText = "";
$detailHtml = "";
$tags = [];

if ($iblockId > 0 && $code !== "")
{
	$rs = CIBlockElement::GetList(
		[],
		["IBLOCK_ID" => $iblockId, "ACTIVE" => "Y", "=CODE" => $code, "CHECK_PERMISSIONS" => "Y"],
		false,
		false,
		["ID", "IBLOCK_ID", "CODE", "NAME", "DATE_ACTIVE_FROM", "DATE_CREATE", "DETAIL_TEXT", "DETAIL_TEXT_TYPE", "PROPERTY_MF_TAGS"]
	);
	$item = $rs->GetNext(false, true);
}

if (!$item)
{
	CHTTP::SetStatus("404 Not Found");
	@define("ERROR_404", "Y");
	$APPLICATION->SetTitle("Блог");
	$APPLICATION->AddChainItem("Блог");
	require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
	echo '<div class="posts__empty">Статья не найдена.</div>';
	require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php");
	return;
}

$title = (string)$item["NAME"];
$APPLICATION->SetTitle($title);
$APPLICATION->AddChainItem("Блог", "/blog/");
$APPLICATION->AddChainItem($title);

$date = (string)($item["DATE_ACTIVE_FROM"] ?: $item["DATE_CREATE"]);
$ts = MakeTimeStamp($date);
$dateAttr = $ts ? date("Y-m-d", $ts) : "";
$dateText = $ts ? FormatDate("j F Y", $ts) : "";

$detailText = (string)($item["~DETAIL_TEXT"] ?? $item["DETAIL_TEXT"] ?? "");
$detailHtml = mf_blog_fix_protocol_relative_urls($detailText);

$hasEscapedTags = (strpos($detailHtml, "&lt;") !== false || strpos($detailHtml, "&#60;") !== false);
$hasRealTags = (bool)preg_match('/<\\s*(p|div|h[1-6]|ul|ol|li|br|img|span|table|thead|tbody|tr|td|th|a)\\b/i', $detailHtml);
if ($hasEscapedTags && !$hasRealTags)
{
	$charset = defined("SITE_CHARSET") ? (string)SITE_CHARSET : "UTF-8";
	for ($i = 0; $i < 2; $i++)
	{
		$decoded = html_entity_decode($detailHtml, ENT_QUOTES | ENT_HTML5, $charset);
		if ($decoded === $detailHtml)
		{
			break;
		}
		$detailHtml = $decoded;
	}
}

$tagsVal = $item["PROPERTY_MF_TAGS_VALUE"] ?? [];
$tags = is_array($tagsVal) ? $tagsVal : ($tagsVal !== "" ? [$tagsVal] : []);

// Now render with template header (breadcrumbs already have chain items).
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
?>

<div
	id="articles"
	itemscope
	itemtype="https://schema.org/BlogPosting"
	data-widget-content
	class="article widget widget-block"
>
	<header class="articles__header text-center widget__header widget--indent-top-2 widget--indent-bottom-2">
		<div class="row">
			<div class="small-12 column">
				<time itemprop="datePublished" datetime="<?=htmlspecialcharsbx($dateAttr)?>" class="article-item__time">
					<?=htmlspecialcharsbx($dateText)?>
				</time>
				<meta itemprop="headline" content="<?=htmlspecialcharsbx($title)?>">
			</div>
		</div>
	</header>

	<article class="widget__content widget__content_filled">
		<div class="row">
			<div class="small-12 column">
				<div class="widget__inner">
					<div class="row">
						<div class="small-12 medium-12 text-left large-centered large-10 column">
							<div itemprop="articleBody" class="article-item__content user-inner">
								<?=$detailHtml?>
							</div>

							<?php if (!empty($tags)): ?>
								<div class="article-tags -inline-group">
									<div class="article-tags__icon svg-icon">
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 16 17"><defs/><path d="M5 6.6a1 1 0 100-2 1 1 0 000 2z"/><path fill-rule="evenodd" d="M0 1.4C0 .94.36.6.8.6h6.4a.8.8 0 01.57.23l8 8a.8.8 0 010 1.13l-6.4 6.4a.8.8 0 01-1.14 0l-8-8A.8.8 0 010 7.8V1.4zm2 5.9V2.6h4.7l6.8 6.8-4.7 4.7L2 7.3z" clip-rule="evenodd"/></svg>
									</div>
									<div class="article-tags__list -inline-group">
										<?php foreach ($tags as $t): ?>
											<?php $t = trim((string)$t); if ($t === "") continue; ?>
											<div class="article-tags__item"><a href="/blog/tag/<?=urlencode($t)?>"><?=htmlspecialcharsbx($t)?></a></div>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
		</div>
	</article>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>

