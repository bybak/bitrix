<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Поиск");
$APPLICATION->AddChainItem("Поиск", SITE_DIR."search/");
if (trim((string)($_GET['q'] ?? '')) !== '')
{
	$APPLICATION->AddHeadString('<script>document.documentElement.classList.add("mf-search-page-pending");</script>', true);
}
?>

<?$APPLICATION->IncludeComponent("bitrix:search.page", "mf_search", array(
	// critical for short queries (BRP/UNV) and to avoid empty-stem issues
	"RESTART" => "Y",
	"CHECK_DATES" => "N",
	"USE_TITLE_RANK" => "N",
	"DEFAULT_SORT" => "rank",
	// don't auto-convert keyboard layout (BRP -> ИКЗ)
	"USE_LANGUAGE_GUESS" => "N",
	"arrFILTER" => array(
		0 => "iblock_catalog",
	),
	"arrFILTER_iblock_catalog" => array(
		0 => "4",
	),
	"SHOW_WHERE" => "N",
	"SHOW_WHEN" => "N",
	"PAGE_RESULT_COUNT" => "25",
	"AJAX_MODE" => "N",
	"AJAX_OPTION_SHADOW" => "Y",
	"AJAX_OPTION_JUMP" => "N",
	"AJAX_OPTION_STYLE" => "Y",
	"AJAX_OPTION_HISTORY" => "N",
	"CACHE_TYPE" => "A",
	"CACHE_TIME" => "1800",
	"DISPLAY_TOP_PAGER" => "N",
	"DISPLAY_BOTTOM_PAGER" => "Y",
	"PAGER_TITLE" => "Результаты поиска",
	"PAGER_SHOW_ALWAYS" => "N",
	"PAGER_TEMPLATE" => "arrows",
	"USE_SUGGEST" => "N",
	"SHOW_ITEM_TAGS" => "N",
	"SHOW_ITEM_DATE_CHANGE" => "N",
	"SHOW_ORDER_BY" => "N",
	"SHOW_TAGS_CLOUD" => "N",
	"AJAX_OPTION_ADDITIONAL" => ""
	),
	false
);?>

<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>