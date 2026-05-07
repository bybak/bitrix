<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");

$APPLICATION->SetTitle("Новости");

use Bitrix\Main\Loader;

Loader::includeModule("iblock");

$iblockId = 1;
$pageSize = 10;

function mf_posts_effective_date_ts(array $row): int
{
	$date = (string)($row["DATE_ACTIVE_FROM"] ?? "");
	if ($date === "")
	{
		$date = (string)($row["DATE_CREATE"] ?? "");
	}
	$ts = MakeTimeStamp($date);
	return $ts ? (int)$ts : 0;
}

function mf_posts_effective_year(array $row): int
{
	$ts = mf_posts_effective_date_ts($row);
	return $ts > 0 ? (int)date("Y", $ts) : 0;
}

function mf_posts_format_ddmmyyyy(int $ts): string
{
	return $ts > 0 ? date("d.m.Y", $ts) : "";
}

function mf_posts_format_ymd(int $ts): string
{
	return $ts > 0 ? date("Y-m-d", $ts) : "";
}

function mf_posts_render_pagination(string $baseUrl, int $currentPage, int $totalPages): string
{
	if ($totalPages <= 1)
	{
		return "";
	}

	$arrowLeft = $currentPage > 1
		? '<a class="pagination__arrow_left" href="' . htmlspecialcharsbx($baseUrl . ($currentPage - 1)) . '">' . mf_posts_svg_arrow_left() . '</a>'
		: '<div class="pagination__arrow_left pagination__arrow_disabled">' . mf_posts_svg_arrow_left() . '</div>';

	$arrowRight = $currentPage < $totalPages
		? '<a class="pagination__arrow_right" href="' . htmlspecialcharsbx($baseUrl . ($currentPage + 1)) . '">' . mf_posts_svg_arrow_right() . '</a>'
		: '<div class="pagination__arrow_right pagination__arrow_disabled">' . mf_posts_svg_arrow_right() . '</div>';

	$items = [];

	$addPage = static function (int $page, bool $active = false, string $extraClass = "") use (&$items, $baseUrl): void
	{
		$cls = trim("pagination__item" . ($active ? " pagination__item_active" : "") . ($extraClass ? " " . $extraClass : ""));
		if ($active)
		{
			$items[] = '<div class="' . $cls . '">' . (int)$page . '</div>';
			return;
		}
		$items[] = '<a class="' . $cls . '" href="' . htmlspecialcharsbx($baseUrl . $page) . '">' . (int)$page . '</a>';
	};

	$addDots = static function (string $extraClass = "") use (&$items): void
	{
		$cls = trim("pagination__item pagination__item_three-dots" . ($extraClass ? " " . $extraClass : ""));
		$items[] = '<div class="' . $cls . '">...</div>';
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
		$addPage(6, 6 === $currentPage, "hide-for-small-only");
		$addDots();
		$addPage($totalPages, $totalPages === $currentPage);
	}
	elseif ($currentPage >= $totalPages - 3)
	{
		$addPage(1, $currentPage === 1);
		$addDots();
		$addPage($totalPages - 5, $currentPage === ($totalPages - 5), "hide-for-small-only");
		for ($p = $totalPages - 4; $p <= $totalPages; $p++)
		{
			$addPage($p, $p === $currentPage);
		}
	}
	else
	{
		$addPage(1, $currentPage === 1);
		$addDots();
		$addPage($currentPage - 2, false, "hide-for-small-only");
		$addPage($currentPage - 1);
		$addPage($currentPage, true);
		$addPage($currentPage + 1);
		$addPage($currentPage + 2, false, "hide-for-small-only");
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

function mf_posts_svg_arrow_left(): string
{
	return '<svg version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="768" height="768" viewBox="0 0 768 768"><path d="M493.5 514.5l-45 45-192-192 192-192 45 45-147 147z"></path></svg>';
}

function mf_posts_svg_arrow_right(): string
{
	return '<svg version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="768" height="768" viewBox="0 0 768 768"><path d="M274.5 523.5l147-147-147-147 45-45 192 192-192 192z"></path></svg>';
}

function mf_posts_svg_calendar(): string
{
	return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 25 25"><defs/><path fill-rule="evenodd" d="M6.5 6.6h2v-1h8v1h2v-1h2v3h-16v-3h2v1zm-2 4v10h16v-10h-16zm4-7h8v-1h2v1h2a2 2 0 012 2v15a2 2 0 01-2 2h-16a2 2 0 01-2-2v-15c0-1.1.9-2 2-2h2v-1h2v1z" clip-rule="evenodd"/></svg>';
}

$requestedYear = isset($_GET["POSTS_YEAR"]) ? (string)$_GET["POSTS_YEAR"] : "";
$requestedPage = isset($_GET["POSTS_PAGE"]) ? (int)$_GET["POSTS_PAGE"] : 1;
if ($requestedPage < 1)
{
	$requestedPage = 1;
}

$allFilter = [
	"IBLOCK_ID" => $iblockId,
	"ACTIVE" => "Y",
	"CHECK_PERMISSIONS" => "Y",
];

$years = [];
$itemsByYear = [];
$itemsAll = [];

$rs = CIBlockElement::GetList(
	["ACTIVE_FROM" => "DESC", "DATE_CREATE" => "DESC", "ID" => "DESC"],
	$allFilter,
	false,
	false,
	["ID", "IBLOCK_ID", "CODE", "NAME", "DATE_ACTIVE_FROM", "DATE_CREATE"]
);

while ($row = $rs->Fetch())
{
	$ts = mf_posts_effective_date_ts($row);
	if ($ts <= 0)
	{
		continue;
	}
	$y = (int)date("Y", $ts);
	$years[$y] = true;
	$itemsByYear[$y][] = ["ID" => (int)$row["ID"], "TS" => $ts, "CODE" => (string)$row["CODE"], "NAME" => (string)$row["NAME"]];
	$itemsAll[] = ["ID" => (int)$row["ID"], "TS" => $ts, "CODE" => (string)$row["CODE"], "NAME" => (string)$row["NAME"], "YEAR" => $y];
}

$yearsList = array_keys($years);
rsort($yearsList, SORT_NUMERIC);

$currentYear = (int)date("Y");
$defaultYear = null;
if (in_array($currentYear, $yearsList, true))
{
	$defaultYear = $currentYear;
}
elseif (!empty($yearsList))
{
	$defaultYear = (int)$yearsList[0];
}

$isAll = ($requestedYear === "all");
if ($requestedYear === "" && $defaultYear !== null)
{
	$requestedYear = (string)$defaultYear;
}
if ($requestedYear !== "" && $requestedYear !== "all" && !preg_match('/^\d{4}$/', $requestedYear))
{
	$requestedYear = (string)$defaultYear;
}
if ($requestedYear === "" && $defaultYear === null)
{
	$requestedYear = "all";
	$isAll = true;
}

$selectedYear = (!$isAll && $requestedYear !== "") ? (int)$requestedYear : null;

$list = $isAll ? $itemsAll : ($selectedYear !== null ? ($itemsByYear[$selectedYear] ?? []) : []);

usort($list, static function (array $a, array $b): int
{
	if ($a["TS"] === $b["TS"])
	{
		return $b["ID"] <=> $a["ID"];
	}
	return $b["TS"] <=> $a["TS"];
});

$totalItems = count($list);
$totalPages = $totalItems > 0 ? (int)ceil($totalItems / $pageSize) : 1;
if ($requestedPage > $totalPages)
{
	$requestedPage = $totalPages;
}
if ($requestedPage < 1)
{
	$requestedPage = 1;
}

$offset = ($requestedPage - 1) * $pageSize;
$pageSlice = array_slice($list, $offset, $pageSize);
$pageIds = array_map(static fn(array $it): int => (int)$it["ID"], $pageSlice);

$items = [];
if (!empty($pageIds))
{
	$rsItems = CIBlockElement::GetList(
		[],
		["ID" => $pageIds, "ACTIVE" => "Y", "CHECK_PERMISSIONS" => "Y"],
		false,
		false,
		["ID", "IBLOCK_ID", "CODE", "NAME", "DATE_ACTIVE_FROM", "DATE_CREATE", "PREVIEW_TEXT", "PREVIEW_TEXT_TYPE", "DETAIL_TEXT", "DETAIL_TEXT_TYPE"]
	);
	while ($row = $rsItems->GetNext(false, true))
	{
		$items[(int)$row["ID"]] = $row;
	}
}

$baseUrl = "/posts/year/" . ($isAll ? "all" : (string)$selectedYear) . "/";
$paginationHtml = mf_posts_render_pagination($baseUrl, $requestedPage, $totalPages);
?>

<section data-region="1" id="posts" class="widget-block block-7 nh-editor-panel posts widget on-view -relative">
	<div class="content-block posts__content widget__content widget__content_filled" id="posts-show">
		<div class="widget__inner row">
			<div class="small-12 column">
				<div class="row mf-posts-grid">
					<div class="small-12 large-9 column posts__list text-left">
						<?php if (empty($pageSlice)): ?>
							<div class="posts__empty">Пока нет новостей.</div>
						<?php else: ?>
							<?php foreach ($pageSlice as $meta):
								$id = (int)$meta["ID"];
								$item = $items[$id] ?? null;
								if (!$item)
								{
									continue;
								}
								$ts = mf_posts_effective_date_ts($item);
								$dateAttr = mf_posts_format_ymd($ts);
								$dateText = mf_posts_format_ddmmyyyy($ts);
								$title = (string)($item["~NAME"] ?? $item["NAME"] ?? "");
								$titleEsc = htmlspecialcharsbx($title);
								$code = (string)$item["CODE"];
								$detailUrl = $code !== "" ? ("/posts/" . rawurlencode($code) . "/") : ("/posts/" . $id . "/");
								$text = (string)($item["~DETAIL_TEXT"] ?: $item["~PREVIEW_TEXT"]);
								?>
								<article itemscope itemtype="https://schema.org/NewsArticle" class="post-item posts__item row">
									<div class="small-12 column">
										<time itemprop="dateCreated" datetime="<?=htmlspecialcharsbx($dateAttr)?>" class="post-item__time"><?=$dateText?></time>
										<header class="post-item__header">
											<h4 class="post-item__title"><a itemprop="url" href="<?=htmlspecialcharsbx($detailUrl)?>"><?=$titleEsc?></a></h4>
										</header>
										<div itemprop="description" class="user-inner post-item__brief ">
											<?=$text?>
										</div>
									</div>
								</article>
							<?php endforeach; ?>
							<div class="text-center">
								<?=$paginationHtml?>
							</div>
						<?php endif; ?>
					</div>

					<div class="large-3 small-12 column">
						<div class="posts__calendar">
							<div class="calendar posts-calendar">
								<span class="posts-calendar__icon"><?=mf_posts_svg_calendar()?></span>
								<div class="posts-calendar__item">
									<div class="posts-calendar__years">
										<?php if (!empty($yearsList)): ?>
											<?php for ($i = 0, $n = count($yearsList); $i < $n; $i++):
												$y = (int)$yearsList[$i];
												$isActive = (!$isAll && $selectedYear === $y);
												if ($isActive): ?>
													<div class="posts-calendar__year posts-calendar__year_active" href=""> <?= $y ?> </div>
												<?php else: ?>
													<a class="posts-calendar__year" href="/posts/year/<?= $y ?>/1"><?= $y ?></a>
												<?php endif; ?>
												<?php if ($i !== $n - 1): ?>
													<span class="posts-calendar__devider">/</span>
												<?php endif; ?>
											<?php endfor; ?>
										<?php endif; ?>
									</div>
									<div class="year posts-calendar__all-time"><a href="/posts/year/all/1">За все время</a></div>
								</div>
							</div>
						</div>
						<div class="posts__abdanner"></div>
					</div>
				</div>
			</div>
		</div>
	</div>
</section>

<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>

