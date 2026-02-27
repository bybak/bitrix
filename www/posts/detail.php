<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");

$APPLICATION->SetPageProperty("MF_HIDE_TITLEBAR", "Y");
$APPLICATION->SetPageProperty("MF_HIDE_BREADCRUMBS", "Y");

use Bitrix\Main\Loader;

Loader::includeModule("iblock");

$iblockId = 1;
$code = isset($_GET["ELEMENT_CODE"]) ? (string)$_GET["ELEMENT_CODE"] : "";

if ($code === "")
{
	CHTTP::SetStatus("404 Not Found");
	@define("ERROR_404", "Y");
	$APPLICATION->SetTitle("Новости");
	echo '<div class="posts__empty">Новость не найдена.</div>';
	require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php");
	return;
}

$rs = CIBlockElement::GetList(
	[],
	["IBLOCK_ID" => $iblockId, "ACTIVE" => "Y", "=CODE" => $code, "CHECK_PERMISSIONS" => "Y"],
	false,
	false,
	["ID", "IBLOCK_ID", "CODE", "NAME", "DATE_ACTIVE_FROM", "DATE_CREATE", "DETAIL_TEXT", "DETAIL_TEXT_TYPE"]
);
$item = $rs->GetNext(false, false);

if (!$item)
{
	CHTTP::SetStatus("404 Not Found");
	@define("ERROR_404", "Y");
	$APPLICATION->SetTitle("Новости");
	echo '<div class="posts__empty">Новость не найдена.</div>';
	require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php");
	return;
}

$title = (string)$item["NAME"];
$APPLICATION->SetTitle($title);

$date = (string)($item["DATE_ACTIVE_FROM"] ?: $item["DATE_CREATE"]);
$ts = MakeTimeStamp($date);
$dateAttr = $ts ? date("Y-m-d", $ts) : "";
$dateText = $ts ? FormatDate("j F Y", $ts) : "";

$detailText = (string)($item["~DETAIL_TEXT"] ?? $item["DETAIL_TEXT"] ?? "");

// Some imported posts have HTML stored as escaped entities (&lt;p&gt;...).
// Decode only when it doesn't already look like real HTML.
$detailHtml = $detailText;
$hasEscapedTags = (strpos($detailHtml, "&lt;") !== false || strpos($detailHtml, "&#60;") !== false);
$hasRealTags = (bool)preg_match('/<\s*(p|div|h[1-6]|ul|ol|li|br|img|span|table|thead|tbody|tr|td|th|a)\b/i', $detailHtml);
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
?>

<div
		id="posts"
		itemscope
		itemtype="https://schema.org/NewsArticle"
		data-widget-content
		class="post widget widget-block"
>
	<header class="post__header text-center widget__header widget--indent-top-2 widget--indent-bottom-2">
		<div class="row">
			<div class="small-12 column">
				<h1 itemprop="headline" class="post__title"><?=htmlspecialcharsbx($title)?></h1>
				<time itemprop="dateCreated" datetime="<?=htmlspecialcharsbx($dateAttr)?>" class="infoDigits post__time">
					<?=htmlspecialcharsbx($dateText)?>
				</time>
			</div>
		</div>
	</header>

	<article class="widget__content widget__content_filled">
		<div class="row">
			<div class="small-12 column">
				<div class="post__bread-crumbs bread-crumbs">
					<div class="bread-crumbs__item">
						<a href="/posts/" title="Новости">
							<div class="bread-crumbs__item bread-crumbs__text">Новости</div>
						</a>
					</div>
					<div class="bread-crumbs__item bread-crumbs__delimiter"></div>
					<div class="bread-crumbs__item bread-crumbs__item_active"><?=htmlspecialcharsbx($title)?></div>
				</div>

				<div class="widget__inner">
					<div class="row">
						<div class="small-12 medium-12 text-left large-centered large-10 column">
							<div itemprop="articleBody" class="post-item__body user-inner">
								<?=$detailHtml?>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</article>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>

