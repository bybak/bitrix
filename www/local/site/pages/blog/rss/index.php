<?php
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define("BX_PUBLIC_MODE", true);

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Main\Context;

Loader::includeModule("iblock");

$rs = \Bitrix\Iblock\IblockTable::getList([
	'filter' => ['=CODE' => 'blog_motor_force', '=ACTIVE' => 'Y'],
	'select' => ['ID'],
	'order' => ['ID' => 'DESC'],
	'limit' => 1,
	'cache' => ['ttl' => 0],
]);
$row = $rs->fetch();
$iblockId = $row ? (int)$row['ID'] : 0;

$itemsCount = 20;

$request = Context::getCurrent()->getRequest();
$isHttps = $request->isHttps();
$host = (string)$request->getHttpHost();
$site = ($host !== "" ? (($isHttps ? "https://" : "http://") . $host) : "");

header("Content-Type: application/rss+xml; charset=UTF-8");

$channelTitle = "Блог";
$channelLink = $site . "/blog/";
$channelDesc = "Блог";

$rss = [];
$rss[] = '<?xml version="1.0" encoding="UTF-8"?>';
$rss[] = '<rss version="2.0">';
$rss[] = '<channel>';
$rss[] = '<title>' . htmlspecialcharsbx($channelTitle) . '</title>';
$rss[] = '<link>' . htmlspecialcharsbx($channelLink) . '</link>';
$rss[] = '<description>' . htmlspecialcharsbx($channelDesc) . '</description>';
$rss[] = '<language>ru</language>';

if ($iblockId > 0)
{
	$rsItems = CIBlockElement::GetList(
		["ACTIVE_FROM" => "DESC", "DATE_CREATE" => "DESC", "ID" => "DESC"],
		["IBLOCK_ID" => $iblockId, "ACTIVE" => "Y", "CHECK_PERMISSIONS" => "Y"],
		false,
		["nTopCount" => $itemsCount],
		["ID", "IBLOCK_ID", "CODE", "NAME", "DATE_ACTIVE_FROM", "DATE_CREATE", "PREVIEW_TEXT", "DETAIL_TEXT"]
	);

	while ($item = $rsItems->GetNext(false, true))
	{
		$title = (string)($item["~NAME"] ?? $item["NAME"] ?? "");
		$code = (string)($item["CODE"] ?? "");
		$link = $site . ($code !== "" ? ("/blog/" . rawurlencode($code) . "/") : "/blog/");

		$date = (string)($item["DATE_ACTIVE_FROM"] ?: $item["DATE_CREATE"]);
		$ts = MakeTimeStamp($date);
		$pubDate = $ts ? date(DATE_RSS, $ts) : date(DATE_RSS);

		$descHtml = (string)($item["~PREVIEW_TEXT"] ?: $item["~DETAIL_TEXT"]);
		$descText = trim(strip_tags($descHtml));

		$rss[] = '<item>';
		$rss[] = '<title>' . htmlspecialcharsbx($title) . '</title>';
		$rss[] = '<link>' . htmlspecialcharsbx($link) . '</link>';
		$rss[] = '<guid isPermaLink="true">' . htmlspecialcharsbx($link) . '</guid>';
		$rss[] = '<pubDate>' . htmlspecialcharsbx($pubDate) . '</pubDate>';
		$rss[] = '<description><![CDATA[' . $descText . ']]></description>';
		$rss[] = '</item>';
	}
}

$rss[] = '</channel>';
$rss[] = '</rss>';

echo implode("\n", $rss);
die();

