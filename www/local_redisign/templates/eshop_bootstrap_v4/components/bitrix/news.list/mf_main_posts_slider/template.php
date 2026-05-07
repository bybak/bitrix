<?if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();
/** @var array $arParams */
/** @var array $arResult */
/** @global CMain $APPLICATION */

if (empty($arResult["ITEMS"])) { return; }

$count = count($arResult["ITEMS"]);

function mf_format_news_date(array $item): array
{
	// Prefer active-from date (Bitrix "Дата активности (с)"), fallback to create date.
	$display = (string)($item["DISPLAY_ACTIVE_FROM"] ?? "");

	$raw = (string)($item["ACTIVE_FROM"] ?? "");
	if ($raw === "")
	{
		$raw = (string)($item["DATE_ACTIVE_FROM"] ?? "");
	}
	if ($raw === "")
	{
		$raw = (string)($item["DATE_CREATE"] ?? "");
	}

	$dt = "";
	if ($raw !== "")
	{
		$ts = MakeTimeStamp($raw);
		if ($ts) $dt = date("Y-m-d", $ts);
	}

	if ($display === "" && $raw !== "")
	{
		$ts = MakeTimeStamp($raw);
		if ($ts) $display = FormatDate("d.m.Y", $ts);
	}

	return [$display, $dt];
}

function mf_posts_slider_brief_html(array $item): string
{
	$previewType = (string)($item["PREVIEW_TEXT_TYPE"] ?? "text");
	$previewHtml = (string)($item["~PREVIEW_TEXT"] ?? $item["PREVIEW_TEXT"] ?? "");

	if ($previewType === "html" && trim(strip_tags($previewHtml)) !== "")
	{
		return $previewHtml;
	}

	$detailType = (string)($item["DETAIL_TEXT_TYPE"] ?? "text");
	$detailHtml = (string)($item["~DETAIL_TEXT"] ?? $item["DETAIL_TEXT"] ?? "");

	if ($detailType === "html" && trim(strip_tags($detailHtml)) !== "")
	{
		// Remove potentially dangerous blocks and keep the first paragraph as a brief.
		$detailHtml = preg_replace('~<(script|style)\\b[^>]*>[\\s\\S]*?</\\1>~i', '', $detailHtml) ?? $detailHtml;
		if (preg_match('~<p\\b[^>]*>[\\s\\S]*?</p>~i', $detailHtml, $m))
		{
			return (string)$m[0];
		}
		$text = trim(strip_tags($detailHtml));
		if ($text !== "")
		{
			$text = mb_substr($text, 0, 240) . (mb_strlen($text) > 240 ? "…" : "");
			return "<p>" . htmlspecialcharsbx($text) . "</p>";
		}
	}

	// Fallback: render preview as plain text.
	$text = trim((string)($item["~PREVIEW_TEXT"] ?? $item["PREVIEW_TEXT"] ?? ""));
	if ($text === "")
	{
		return "";
	}
	$text = mb_substr($text, 0, 240) . (mb_strlen($text) > 240 ? "…" : "");
	return "<p>" . htmlspecialcharsbx($text) . "</p>";
}
?>

<div class="posts-slider posts-slider_length_<?=$count?>">
	<div class="posts-slider__content js-slider slick-slider slick-initialized" data-mf="posts-slider">
		<div class="slick-list">
			<div class="slick-track">
				<?foreach($arResult["ITEMS"] as $arItem):
					$title = $arItem["NAME"] ?? "";
					$url = $arItem["DETAIL_PAGE_URL"] ?? "";
					[$date, $datetime] = mf_format_news_date($arItem);
					$briefHtml = mf_posts_slider_brief_html($arItem);
					?>
					<div class="posts-slider__post row js-slider__item slick-slide">
						<div class="large-8 medium-centered column small-12">
							<article itemscope itemtype="https://schema.org/NewsArticle" class="post-item post-slider-item posts__item">
								<header class="post-item__header text-center">
									<h4 class="post-item__title post-slider-item__title">
										<a itemprop="url" href="<?=$url?>"><?=htmlspecialcharsbx($title)?></a>
									</h4>
								</header>

								<div class="text-center">
									<time itemprop="dateCreated"
											<?if($datetime):?>datetime="<?=$datetime?>"<?endif?>
											class="post-item__time post-slider-item__time medium-centered"
									><?=$date?></time>
								</div>

								<div>
									<div itemprop="description" class="user-inner post-item__brief post-slider-item__brief">
										<?=$briefHtml?>
									</div>
								</div>
							</article>
						</div>
					</div>
				<?endforeach;?>
			</div>
		</div>
	</div>
</div>

