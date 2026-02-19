<?if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();
IncludeTemplateLangFile($_SERVER["DOCUMENT_ROOT"]."/bitrix/templates/".SITE_TEMPLATE_ID."/header.php");
CJSCore::Init(array("fx"));

\Bitrix\Main\UI\Extension::load(["ui.bootstrap4", "ui.fonts.opensans"]);

if (isset($_GET["theme"]) && in_array($_GET["theme"], array("blue", "green", "yellow", "red")))
{
	COption::SetOptionString("main", "wizard_eshop_bootstrap_theme_id", $_GET["theme"], false, SITE_ID);
}
$theme = COption::GetOptionString("main", "wizard_eshop_bootstrap_theme_id", "green", SITE_ID);

$curPage = $APPLICATION->GetCurPage(true);
$isHome = ($curPage === SITE_DIR."index.php");
$isPosts = (strpos($curPage, SITE_DIR."posts/") === 0);
$isContacts = ($curPage === SITE_DIR."contacts/index.php");

$APPLICATION->SetAdditionalCSS(SITE_TEMPLATE_PATH."/mf-header.css");
$APPLICATION->SetAdditionalCSS(SITE_TEMPLATE_PATH."/mf-footer.css");
$APPLICATION->SetAdditionalCSS(SITE_TEMPLATE_PATH."/mf-text-page.css");
$APPLICATION->AddHeadScript(SITE_TEMPLATE_PATH."/mf-header.js");

if ($isHome)
{
	$APPLICATION->SetAdditionalCSS(SITE_TEMPLATE_PATH."/mf-mainpage.css");
	$APPLICATION->AddHeadScript(SITE_TEMPLATE_PATH."/mf-mainpage.js");
}
if ($isPosts)
{
	$APPLICATION->SetAdditionalCSS(SITE_TEMPLATE_PATH."/mf-posts.css");
}
if ($isContacts)
{
	$APPLICATION->SetAdditionalCSS(SITE_TEMPLATE_PATH."/mf-contacts.css");
}

?><!DOCTYPE html>
<html xml:lang="<?=LANGUAGE_ID?>" lang="<?=LANGUAGE_ID?>">
<head>
	<title><?$APPLICATION->ShowTitle()?></title>
	<meta http-equiv="X-UA-Compatible" content="IE=edge" />
	<meta name="viewport" content="user-scalable=no, initial-scale=1.0, maximum-scale=1.0, width=device-width">
	<link rel="shortcut icon" type="image/x-icon" href="<?=SITE_DIR?>favicon.ico" />
	<? $APPLICATION->ShowHead(); ?>
</head>
<body class="bx-background-image bx-theme-<?=$theme?><?if($isHome):?> mf-home<?endif?><?if($isPosts):?> mf-posts-page<?endif?>" <?$APPLICATION->ShowProperty("backgroundImage");?>>
<div id="panel"><? $APPLICATION->ShowPanel(); ?></div>
<div class="bx-wrapper" id="bx_eshop_wrap">
	<header class="mf-header">
		<div class="mf-top">
			<div class="container">
				<div class="mf-top-inner">
					<div class="mf-phones">
						<a data-mf="tel" href="tel:+78129864276">8 812 986-42-76</a>
						<a data-mf="tel" href="tel:+79218837340">8 921 883-73-40</a>
					</div>
					<div class="mf-top-links">
						<a class="mf-top-link" href="/contacts/">Напишите нам</a>
						<a class="mf-top-link" href="/contacts/">Обратный звонок</a>
						<a class="mf-login" href="<?=SITE_DIR?>login/">Войти</a>
					</div>
				</div>
			</div>
		</div>

		<div class="mf-main">
			<div class="container">
				<div class="mf-main-inner">
					<div class="mf-logo">
						<a href="<?=SITE_DIR?>">
							<?$APPLICATION->IncludeComponent(
								"bitrix:main.include",
								"",
								array(
									"AREA_FILE_SHOW" => "file",
									"PATH" => SITE_DIR."include/company_logo.php"
								),
								false
							);?>
						</a>
					</div>

					<nav class="mf-desktop-menu" aria-label="Главное меню">
						<?$APPLICATION->IncludeComponent(
							"bitrix:menu",
							"bootstrap_v4",
							array(
								"ROOT_MENU_TYPE" => "top",
								"MENU_CACHE_TYPE" => "N",
								"MENU_CACHE_TIME" => "0",
								"MENU_CACHE_USE_GROUPS" => "Y",
								"MENU_THEME" => "site",
								"CACHE_SELECTED_ITEMS" => "N",
								"MENU_CACHE_GET_VARS" => array(),
								"MAX_LEVEL" => "1",
								"CHILD_MENU_TYPE" => "top",
								"USE_EXT" => "N",
								"DELAY" => "N",
								"ALLOW_MULTI_SELECT" => "N",
								"COMPONENT_TEMPLATE" => "bootstrap_v4"
							),
							false
						);?>
					</nav>

					<form class="mf-search mf-search--mobile" action="/search/" method="get">
						<input type="search" name="q" placeholder="Найти" aria-label="Найти">
						<button type="submit">Найти</button>
					</form>

					<div class="mf-right">
						<?$APPLICATION->IncludeComponent(
							"bitrix:sale.basket.basket.line",
							"bootstrap_v4",
							array(
								"PATH_TO_BASKET" => SITE_DIR."personal/cart/",
								"PATH_TO_PERSONAL" => SITE_DIR."personal/",
								"SHOW_PERSONAL_LINK" => "N",
								"SHOW_NUM_PRODUCTS" => "Y",
								"SHOW_TOTAL_PRICE" => "N",
								"SHOW_PRODUCTS" => "N",
								"POSITION_FIXED" =>"N",
								"SHOW_AUTHOR" => "N",
								"PATH_TO_REGISTER" => SITE_DIR."login/",
								"PATH_TO_PROFILE" => SITE_DIR."personal/"
							),
							false,
							array()
						);?>
					</div>
				</div>
			</div>
		</div>

		<div class="mf-nav">
			<div class="container">
				<div class="mf-nav-inner">
					<button class="mf-burger" type="button" data-mf="menu-open" aria-expanded="false" aria-controls="mfMenuPanel">
						<span class="mf-burger-icon" aria-hidden="true"><span></span><span></span><span></span></span>
						<span>Меню</span>
					</button>
				</div>
			</div>
		</div>

		<a href="#" class="mf-menu-overlay" data-mf="menu-overlay" aria-hidden="true"></a>
		<aside class="mf-menu-panel" id="mfMenuPanel" data-mf="menu-panel" aria-hidden="true">
			<div class="mf-menu-head">
				<span>Меню</span>
				<button class="mf-menu-close" type="button" data-mf="menu-close" aria-label="Закрыть">×</button>
			</div>
			<div class="mf-menu-body">
				<?$APPLICATION->IncludeComponent(
					"bitrix:menu",
					"bootstrap_v4",
					array(
						"ROOT_MENU_TYPE" => "top",
						"MENU_CACHE_TYPE" => "N",
						"MENU_CACHE_TIME" => "0",
						"MENU_CACHE_USE_GROUPS" => "Y",
						"MENU_THEME" => "site",
						"CACHE_SELECTED_ITEMS" => "N",
						"MENU_CACHE_GET_VARS" => array(),
						"MAX_LEVEL" => "1",
						"CHILD_MENU_TYPE" => "top",
						"USE_EXT" => "N",
						"DELAY" => "N",
						"ALLOW_MULTI_SELECT" => "N",
						"COMPONENT_TEMPLATE" => "bootstrap_v4"
					),
					false
				);?>
			</div>
		</aside>
	</header>

	<div class="workarea">
		<?$mfHideTitlebar =
			($APPLICATION->GetPageProperty("MF_HIDE_TITLEBAR") === "Y")
			|| (defined("MF_HIDE_TITLEBAR") && (MF_HIDE_TITLEBAR === true || MF_HIDE_TITLEBAR === "Y"));?>
		<?$mfHideBreadcrumbs =
			($APPLICATION->GetPageProperty("MF_HIDE_BREADCRUMBS") === "Y")
			|| (defined("MF_HIDE_BREADCRUMBS") && (MF_HIDE_BREADCRUMBS === true || MF_HIDE_BREADCRUMBS === "Y"));?>

		<?if ($curPage != SITE_DIR."index.php" && !$mfHideTitlebar):?>
			<div class="mf-titlebar" role="presentation">
				<div class="mf-titlebar-inner">
					<h1 id="pagetitle" class="mf-pagetitle"><?$APPLICATION->ShowTitle(false);?></h1>
				</div>
			</div>
		<?endif?>

		<div class="bx-content-section mf-content-section">
			<div class="container">
			<!--region breadcrumb-->
			<?if ($curPage != SITE_DIR."index.php" && !$mfHideBreadcrumbs):?>
				<div class="mf-breadcrumbs row mb-3">
					<div class="col" id="navigation">
						<?$APPLICATION->IncludeComponent(
							"bitrix:breadcrumb",
							"universal",
							array(
								"START_FROM" => "0",
								"PATH" => "",
								"SITE_ID" => "-"
							),
							false,
							Array('HIDE_ICONS' => 'Y')
						);?>
					</div>
				</div>
			<?endif?>
			<!--endregion-->
			<div class="row">
				<div class="bx-content col">