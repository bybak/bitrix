<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Блог");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");

use Bitrix\Main\Loader;

Loader::includeModule("iblock");

function mf_blog_iblock_id(): int
{
	static $id = null;
	if ($id !== null)
	{
		return (int)$id;
	}
	// CIBlock::GetList is aggressively cached; use ORM with ttl=0.
	$rs = \Bitrix\Iblock\IblockTable::getList([
		'filter' => ['=CODE' => 'blog_motor_force', '=ACTIVE' => 'Y'],
		'select' => ['ID'],
		'order' => ['ID' => 'DESC'],
		'limit' => 1,
		'cache' => ['ttl' => 0],
	]);
	$row = $rs->fetch();
	$id = $row ? (int)$row['ID'] : 0;
	return (int)$id;
}

function mf_blog_get_tags(int $iblockId, int $elementId): array
{
	$rs = CIBlockElement::GetProperty($iblockId, $elementId, ["sort" => "asc", "id" => "asc"], ["CODE" => "MF_TAGS"]);
	$tags = [];
	while ($p = $rs->Fetch())
	{
		$v = trim((string)($p["VALUE"] ?? ""));
		if ($v === "")
		{
			continue;
		}
		$tags[$v] = true;
	}
	return array_keys($tags);
}

function mf_blog_fix_protocol_relative_urls(string $html): string
{
	// 1) Nethouse often uses "//host/path". Make it explicit.
	$html = preg_replace('~\\b(src|href)=\"//~i', '$1=\"https://', $html);

	// 2) Fix broken attributes like src=\"https://... (stored with backslashes in DB).
	//    Only touch inside HTML tags to avoid breaking text/code.
	$html = preg_replace_callback('~<[^>]+>~s', static function (array $m): string {
		$tag = (string)$m[0];
		$tag = str_replace('\\"', '"', $tag);
		$tag = str_replace('\\&quot;', '&quot;', $tag);
		return $tag;
	}, (string)$html);

	// 3) Fix wrappers like src="&quot;https://...&quot;"
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

function mf_blog_detail_html(array $row): string
{
	$text = (string)($row["~DETAIL_TEXT"] ?? $row["DETAIL_TEXT"] ?? "");
	$html = mf_blog_fix_protocol_relative_urls($text);

	$hasEscapedTags = (strpos($html, "&lt;") !== false || strpos($html, "&#60;") !== false);
	$hasRealTags = (bool)preg_match('/<\\s*(p|div|h[1-6]|ul|ol|li|br|img|span|table|thead|tbody|tr|td|th|a)\\b/i', $html);
	if ($hasEscapedTags && !$hasRealTags)
	{
		$charset = defined("SITE_CHARSET") ? (string)SITE_CHARSET : "UTF-8";
		for ($i = 0; $i < 2; $i++)
		{
			$decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, $charset);
			if ($decoded === $html)
			{
				break;
			}
			$html = $decoded;
		}
	}
	return $html;
}

function mf_blog_svg_arrow_left(): string
{
	return '<svg version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="768" height="768" viewBox="0 0 768 768"><path d="M493.5 514.5l-45 45-192-192 192-192 45 45-147 147z"></path></svg>';
}

function mf_blog_svg_arrow_right(): string
{
	return '<svg version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="768" height="768" viewBox="0 0 768 768"><path d="M274.5 523.5l147-147-147-147 45-45 192 192-192 192z"></path></svg>';
}

function mf_blog_svg_tag(): string
{
	return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 16 17"><defs/><path d="M5 6.6a1 1 0 100-2 1 1 0 000 2z"/><path fill-rule="evenodd" d="M0 1.4C0 .94.36.6.8.6h6.4a.8.8 0 01.57.23l8 8a.8.8 0 010 1.13l-6.4 6.4a.8.8 0 01-1.14 0l-8-8A.8.8 0 010 7.8V1.4zm2 5.9V2.6h4.7l6.8 6.8-4.7 4.7L2 7.3z" clip-rule="evenodd"/></svg>';
}

function mf_blog_page_url(int $page, string $tag = ""): string
{
	if ($page <= 1)
	{
		return $tag !== "" ? ("/blog/tag/" . rawurlencode($tag) . "/") : "/blog/";
	}
	if ($tag !== "")
	{
		return "/blog/tag/" . rawurlencode($tag) . "/page/" . $page;
	}
	return "/blog/page/" . $page;
}

function mf_blog_render_pagination(int $currentPage, int $totalPages, string $tag = ""): string
{
	if ($totalPages <= 1)
	{
		return "";
	}

	$arrowLeft = $currentPage > 1
		? '<a class="pagination__arrow_left" href="' . htmlspecialcharsbx(mf_blog_page_url($currentPage - 1, $tag)) . '">' . mf_blog_svg_arrow_left() . '</a>'
		: '<div class="pagination__arrow_left pagination__arrow_disabled">' . mf_blog_svg_arrow_left() . '</div>';

	$arrowRight = $currentPage < $totalPages
		? '<a class="pagination__arrow_right" href="' . htmlspecialcharsbx(mf_blog_page_url($currentPage + 1, $tag)) . '">' . mf_blog_svg_arrow_right() . '</a>'
		: '<div class="pagination__arrow_right pagination__arrow_disabled">' . mf_blog_svg_arrow_right() . '</div>';

	$items = [];

	$addPage = static function (int $page, bool $active = false, string $extraClass = "") use (&$items, $tag): void
	{
		$cls = trim("pagination__item" . ($active ? " pagination__item_active" : "") . ($extraClass ? " " . $extraClass : ""));
		if ($active)
		{
			$items[] = '<div class="' . $cls . '">' . (int)$page . '</div>';
			return;
		}
		$items[] = '<a class="' . $cls . '" href="' . htmlspecialcharsbx(mf_blog_page_url($page, $tag)) . '">' . (int)$page . '</a>';
	};

	$addDots = static function () use (&$items): void
	{
		$items[] = '<div class="pagination__item pagination__item_three-dots">...</div>';
	};

	if ($totalPages <= 7)
	{
		for ($p = 1; $p <= $totalPages; $p++)
		{
			$addPage($p, $p === $currentPage);
		}
	}
	elseif ($currentPage <= 4)
	{
		for ($p = 1; $p <= 5; $p++)
		{
			$addPage($p, $p === $currentPage);
		}
		$addDots();
		$addPage($totalPages, $totalPages === $currentPage);
	}
	elseif ($currentPage >= $totalPages - 3)
	{
		$addPage(1, $currentPage === 1);
		$addDots();
		for ($p = $totalPages - 4; $p <= $totalPages; $p++)
		{
			$addPage($p, $p === $currentPage);
		}
	}
	else
	{
		$addPage(1, $currentPage === 1);
		$addDots();
		$addPage($currentPage - 1);
		$addPage($currentPage, true);
		$addPage($currentPage + 1);
		$addDots();
		$addPage($totalPages, $currentPage === $totalPages);
	}

	return
		'<div class="pagination pagination_min-width">' .
		$arrowLeft .
		'<div class="pagination__pages">' . implode("", $items) . '</div>' .
		$arrowRight .
		'</div>';
}

$iblockId = mf_blog_iblock_id();
$pageSize = 10;
$page = isset($_GET["BLOG_PAGE"]) ? (int)$_GET["BLOG_PAGE"] : 1;
if ($page < 1) $page = 1;
$tag = isset($_GET["BLOG_TAG"]) ? urldecode((string)$_GET["BLOG_TAG"]) : "";
$tag = trim($tag);
if ($tag !== "")
{
	$APPLICATION->SetTitle("Блог: " . $tag);
	$APPLICATION->AddChainItem("Блог", "/blog/");
	$APPLICATION->AddChainItem($tag);
}
else
{
	$APPLICATION->AddChainItem("Блог");
}

$items = [];
$totalItems = 0;
$popularTags = [];

if ($iblockId > 0)
{
	$filter = ["IBLOCK_ID" => $iblockId, "ACTIVE" => "Y", "CHECK_PERMISSIONS" => "Y"];
	if ($tag !== "")
	{
		$filter["=PROPERTY_MF_TAGS"] = $tag;
	}
	$totalItems = (int)CIBlockElement::GetList([], $filter, [], false, ["ID"]);

	$totalPages = $totalItems > 0 ? (int)ceil($totalItems / $pageSize) : 1;
	if ($page > $totalPages) $page = $totalPages;
	if ($page < 1) $page = 1;

	$nav = ["nPageSize" => $pageSize, "iNumPage" => $page, "checkOutOfRange" => true];
	$rs = CIBlockElement::GetList(
		["ACTIVE_FROM" => "DESC", "DATE_CREATE" => "DESC", "ID" => "DESC"],
		$filter,
		false,
		$nav,
		["ID", "IBLOCK_ID", "CODE", "NAME", "DATE_ACTIVE_FROM", "DATE_CREATE", "DETAIL_TEXT", "DETAIL_TEXT_TYPE"]
	);
	while ($row = $rs->GetNext(false, true))
	{
		$items[] = $row;
	}

	// Sidebar: top tags (count across all elements)
	$rsAll = CIBlockElement::GetList([], ["IBLOCK_ID" => $iblockId, "ACTIVE" => "Y", "CHECK_PERMISSIONS" => "Y"], false, false, ["ID"]);
	while ($row = $rsAll->Fetch())
	{
		$id = (int)($row["ID"] ?? 0);
		if ($id <= 0) continue;
		foreach (mf_blog_get_tags($iblockId, $id) as $t)
		{
			$popularTags[$t] = ($popularTags[$t] ?? 0) + 1;
		}
	}
	arsort($popularTags, SORT_NUMERIC);
	$popularTags = array_slice($popularTags, 0, 20, true);
}
else
{
	$totalPages = 1;
}

$paginationHtml = mf_blog_render_pagination($page, $totalPages ?? 1, $tag);
?>

<section id="articles" data-region="1" class="widget-block articles widget on-view -relative">
	<div class="content-block articles__content widget__content widget__content_filled" id="articles-show">
		<div class="row">
			<div class="small-12 column">
				<div class="row widget__inner">
					<div class="small-12 column">
						<div class="row">
							<div class="small-12 medium-9 column articles__list" ng-non-bindable>
								<?php if ($iblockId <= 0): ?>
									<div class="posts__empty">Инфоблок “Блог” ещё не создан. Запустите импортёр `mf_import_blog.php`.</div>
								<?php elseif (empty($items)): ?>
									<div class="posts__empty">Пока нет статей.</div>
								<?php else: ?>
									<?php foreach ($items as $it): ?>
										<?php
										$title = (string)$it["NAME"];
										$code = (string)$it["CODE"];
										$date = (string)($it["DATE_ACTIVE_FROM"] ?: $it["DATE_CREATE"]);
										$ts = MakeTimeStamp($date);
										$dateAttr = $ts ? date("Y-m-d", $ts) : "";
										$dateText = $ts ? date("d.m.Y, H:i", $ts) : "";
										$detailHtml = mf_blog_detail_html($it);
										$tags = mf_blog_get_tags($iblockId, (int)$it["ID"]);
										?>
										<article id="article_<?= (int)$it["ID"] ?>" class="article-item articles__item row">
											<div class="small-12 medium-12 column">
												<div>
													<time class="article-item__time small-12" datetime="<?= htmlspecialcharsbx($dateAttr) ?>" pubdate>
														<?= htmlspecialcharsbx($dateText) ?>
													</time>
												</div>
												<header>
													<h4 class="article-item__title">
														<a href="/blog/<?= htmlspecialcharsbx($code) ?>/"><?= htmlspecialcharsbx($title) ?></a>
													</h4>
												</header>
												<div class="article-item__content user-inner">
													<?= $detailHtml ?>
												</div>
												<?php if (!empty($tags)): ?>
													<div class="row">
														<div class="small-12 column">
															<div class="article-tags -inline-group">
																<div class="article-tags__icon svg-icon"><?= mf_blog_svg_tag() ?></div>
																<div class="article-tags__list -inline-group">
																	<?php foreach ($tags as $t): ?>
																		<?php $t = trim((string)$t); if ($t === "") continue; ?>
																		<div class="article-tags__item"><a href="/blog/tag/<?= urlencode($t) ?>"><?= htmlspecialcharsbx($t) ?></a></div>
																	<?php endforeach; ?>
																</div>
															</div>
														</div>
													</div>
												<?php endif; ?>
											</div>
										</article>
									<?php endforeach; ?>

									<div class="text-center"><?= $paginationHtml ?></div>
								<?php endif; ?>
							</div>

							<div class="small-12 medium-3 column articles__sidebar">
								<div class="popular-tags">
									<p class="popular-tags__menu-title">Популярные теги</p>
									<div class="-inline-group">
										<?php if (empty($popularTags)): ?>
											<div class="popular-tags__item"><span class="text-muted">—</span></div>
										<?php else: ?>
											<?php foreach ($popularTags as $tag => $cnt): ?>
												<div class="popular-tags__item">
													<a href="/blog/tag/<?= urlencode($tag) ?>"><?= htmlspecialcharsbx($tag) ?></a>
												</div>
											<?php endforeach; ?>
										<?php endif; ?>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</section>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>

