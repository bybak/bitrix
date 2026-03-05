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
$isBlog = (strpos($curPage, SITE_DIR."blog/") === 0);
$isContacts = ($curPage === SITE_DIR."contacts/index.php");
$isPayment = ($curPage === SITE_DIR."oplata/index.php");
$isDelivery = ($curPage === SITE_DIR."delivery/index.php");
$isOffer = ($curPage === SITE_DIR."dogovor-oferti/index.php");
$isRepair = ($curPage === SITE_DIR."remont_motorov/index.php");
$isBuyout = ($curPage === SITE_DIR."vikup_mototehniki/index.php");
$isRent = ($curPage === SITE_DIR."prokat/index.php");
$isDocuments = ($curPage === SITE_DIR."documents/index.php");
$isCooperation = ($curPage === SITE_DIR."sotrudnichestvo/index.php");
$isFaq = ($curPage === SITE_DIR."faq/index.php");
$isProducts = (strpos($curPage, SITE_DIR."products/") === 0);
$isSearch = (strpos($curPage, SITE_DIR."search/") === 0);
$isCart = (strpos($curPage, SITE_DIR."personal/cart/") === 0);
$isOrderMake = (strpos($curPage, SITE_DIR."personal/order/make/") === 0);
$isPersonal = (strpos($curPage, SITE_DIR."personal/") === 0);
$isAuth = (strpos($curPage, SITE_DIR."auth/") === 0);
$isLogin = (strpos($curPage, SITE_DIR."login/") === 0);

$APPLICATION->SetAdditionalCSS(SITE_TEMPLATE_PATH."/mf-header.css");
$APPLICATION->SetAdditionalCSS(SITE_TEMPLATE_PATH."/mf-footer.css");
$APPLICATION->SetAdditionalCSS(SITE_TEMPLATE_PATH."/mf-text-page.css");
$APPLICATION->AddHeadScript(SITE_TEMPLATE_PATH."/mf-header.js");

// Cache-bust custom static assets (browser caches them aggressively).
$mfAssetVer = function (string $rel) {
	$abs = $_SERVER["DOCUMENT_ROOT"] . $rel;
	if (is_file($abs))
	{
		return $rel . '?v=' . filemtime($abs);
	}
	return $rel;
};

if ($isHome)
{
	$APPLICATION->SetAdditionalCSS($mfAssetVer(SITE_TEMPLATE_PATH."/mf-mainpage.css"));
	$APPLICATION->AddHeadScript($mfAssetVer(SITE_TEMPLATE_PATH."/mf-mainpage.js"));
}
if ($isPosts)
{
	$APPLICATION->SetAdditionalCSS($mfAssetVer(SITE_TEMPLATE_PATH."/mf-posts.css"));
}
if ($isBlog)
{
	$APPLICATION->SetAdditionalCSS($mfAssetVer(SITE_TEMPLATE_PATH."/mf-blog.css"));
}
if ($isContacts)
{
	$APPLICATION->SetAdditionalCSS($mfAssetVer(SITE_TEMPLATE_PATH."/mf-contacts.css"));
}
if ($isPersonal)
{
	// Force cache-busting for frequently edited personal styles.
	$APPLICATION->AddHeadString(
		'<link rel="stylesheet" href="' . htmlspecialcharsbx($mfAssetVer(SITE_TEMPLATE_PATH . '/mf-personal.css')) . '">',
		true
	);
}
if ($isAuth || $isLogin)
{
	// Force cache-busting for frequently edited auth styles.
	$APPLICATION->AddHeadString(
		'<link rel="stylesheet" href="' . htmlspecialcharsbx($mfAssetVer(SITE_TEMPLATE_PATH . '/mf-auth.css')) . '">',
		true
	);
}
if ($isProducts)
{
	// We will include mf-shop assets explicitly after ShowHead()
}
if ($isSearch)
{
	// We will include mf-search assets explicitly after ShowHead()
}

// Применяем SEO (title/description/keywords/canonical/OG) для статических страниц меню
// до генерации <head> (ShowTitle/ShowHead).
if (function_exists('mf_seo_apply_for_current_page'))
{
	mf_seo_apply_for_current_page();
}

?><!DOCTYPE html>
<html xml:lang="<?=LANGUAGE_ID?>" lang="<?=LANGUAGE_ID?>">
<head>
	<title><?$APPLICATION->ShowTitle()?></title>
	<meta http-equiv="X-UA-Compatible" content="IE=edge" />
	<meta name="viewport" content="user-scalable=no, initial-scale=1.0, maximum-scale=1.0, width=device-width">
	<link rel="shortcut icon" type="image/x-icon" href="<?=SITE_DIR?>favicon.ico" />
	<? $APPLICATION->ShowHead(); ?>
	<?php
	// Include our custom assets last (override Bitrix aggregated CSS/JS + bust browser cache).
	// Critical: bind MF image fallback early (before <img> in body is parsed),
	// so broken external/proxied images are immediately replaced with a placeholder.
	$imgFallbackJs = $mfAssetVer(SITE_TEMPLATE_PATH . "/mf-img-fallback.js");
	echo '<script src="' . htmlspecialcharsbx($imgFallbackJs) . '"></script>' . "\n";

	if ($isProducts)
	{
		// Ensure catalog.item JS is loaded (binds add-to-basket handlers for JCCatalogItem).
		// Our custom catalog.item templates don't ship their own script.js.
		$catalogItemJs = $mfAssetVer('/bitrix/components/bitrix/catalog.item/templates/.default/script.js');
		// Ensure catalog.element JS is loaded for detail pages (JCCatalogElement).
		$catalogElementJs = $mfAssetVer('/bitrix/components/bitrix/catalog.element/templates/.default/script.js');
		$css = $mfAssetVer(SITE_TEMPLATE_PATH."/mf-shop.css");
		$js = $mfAssetVer(SITE_TEMPLATE_PATH."/mf-shop.js");
		echo '<link rel="stylesheet" href="'.htmlspecialcharsbx($css).'" />'."\n";
		// Important: load synchronously (inline new JCCatalogItem(...) runs during parsing).
		echo '<script src="'.htmlspecialcharsbx($catalogItemJs).'"></script>'."\n";
		echo '<script src="'.htmlspecialcharsbx($catalogElementJs).'"></script>'."\n";
		echo '<script src="'.htmlspecialcharsbx($js).'" defer></script>'."\n";
	}
	if ($isSearch)
	{
		$css = $mfAssetVer(SITE_TEMPLATE_PATH."/mf-search.css");
		echo '<link rel="stylesheet" href="'.htmlspecialcharsbx($css).'" />'."\n";
	}
	if ($isCart)
	{
		$css = $mfAssetVer(SITE_TEMPLATE_PATH."/mf-cart.css");
		echo '<link rel="stylesheet" href="'.htmlspecialcharsbx($css).'" />'."\n";
		$js = $mfAssetVer(SITE_TEMPLATE_PATH."/mf-cart.js");
		echo '<script src="'.htmlspecialcharsbx($js).'" defer></script>'."\n";
	}
	if ($isOrderMake)
	{
		$css = $mfAssetVer(SITE_TEMPLATE_PATH."/mf-order.css");
		echo '<link rel="stylesheet" href="'.htmlspecialcharsbx($css).'" />'."\n";
	}
	if ($isPayment)
	{
		$css = $mfAssetVer(SITE_TEMPLATE_PATH."/mf-payment.css");
		echo '<link rel="stylesheet" href="'.htmlspecialcharsbx($css).'" />'."\n";
	}
	if ($isDelivery)
	{
		$css = $mfAssetVer(SITE_TEMPLATE_PATH."/mf-delivery.css");
		echo '<link rel="stylesheet" href="'.htmlspecialcharsbx($css).'" />'."\n";
	}
	if ($isOffer)
	{
		$css = $mfAssetVer(SITE_TEMPLATE_PATH."/mf-offer.css");
		echo '<link rel="stylesheet" href="'.htmlspecialcharsbx($css).'" />'."\n";
	}
	if ($isRepair)
	{
		$css = $mfAssetVer(SITE_TEMPLATE_PATH."/mf-repair.css");
		echo '<link rel="stylesheet" href="'.htmlspecialcharsbx($css).'" />'."\n";
	}
	if ($isBuyout)
	{
		$css = $mfAssetVer(SITE_TEMPLATE_PATH."/mf-buyout.css");
		echo '<link rel="stylesheet" href="'.htmlspecialcharsbx($css).'" />'."\n";
	}
	if ($isRent)
	{
		$css = $mfAssetVer(SITE_TEMPLATE_PATH."/mf-prokat.css");
		echo '<link rel="stylesheet" href="'.htmlspecialcharsbx($css).'" />'."\n";
	}
	if ($isDocuments)
	{
		$css = $mfAssetVer(SITE_TEMPLATE_PATH."/mf-documents.css");
		echo '<link rel="stylesheet" href="'.htmlspecialcharsbx($css).'" />'."\n";
	}
	if ($isCooperation)
	{
		$css = $mfAssetVer(SITE_TEMPLATE_PATH."/mf-cooperation.css");
		echo '<link rel="stylesheet" href="'.htmlspecialcharsbx($css).'" />'."\n";
	}
	if ($isFaq)
	{
		$css = $mfAssetVer(SITE_TEMPLATE_PATH."/mf-faq.css");
		echo '<link rel="stylesheet" href="'.htmlspecialcharsbx($css).'" />'."\n";
	}
	?>
</head>
<body class="bx-background-image bx-theme-<?=$theme?><?if($isHome):?> mf-home<?endif?><?if($isPosts):?> mf-posts-page<?endif?><?if($isBlog):?> mf-blog-page<?endif?>" <?$APPLICATION->ShowProperty("backgroundImage");?>>
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
					<button class="mf-menu-btn" type="button" data-mf="menu-open" aria-expanded="false" aria-controls="mfMenuPanel" aria-label="Меню">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M0 3C0 2.44772 0.447715 2 1 2H15C15.5523 2 16 2.44772 16 3C16 3.55228 15.5523 4 15 4H1C0.447715 4 0 3.55228 0 3Z" fill="currentColor"/><path d="M1 12C0.447715 12 0 12.4477 0 13C0 13.5523 0.447715 14 1 14H15C15.5523 14 16 13.5523 16 13C16 12.4477 15.5523 12 15 12H1Z" fill="currentColor"/><path d="M0 8C0 7.44772 0.447715 7 1 7H15C15.5523 7 16 7.44772 16 8C16 8.55228 15.5523 9 15 9H1C0.447715 9 0 8.55228 0 8Z" fill="currentColor"/></svg>
					</button>
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

					<div class="mf-right">
						<?global $USER, $APPLICATION;
						$mfAccountHref = SITE_DIR."personal/";
						if (!$USER->IsAuthorized())
						{
							$arParamsToDelete = array(
								"login",
								"login_form",
								"logout",
								"register",
								"forgot_password",
								"change_password",
								"confirm_registration",
								"confirm_code",
								"confirm_user_id",
								"logout_butt",
								"auth_service_id",
								"clear_cache",
								"backurl",
							);
							$currentUrl = urlencode($APPLICATION->GetCurPageParam("", $arParamsToDelete));
							$mfAccountHref = SITE_DIR."login/?login=yes&backurl=".$currentUrl;
						}
						?>
						<a class="mf-account-link" href="<?=$mfAccountHref?>" aria-label="Личный кабинет">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 9 11" width="20" height="20" fill="currentColor" aria-hidden="true">
								<path d="M6.89169804,5.19519043 L6.89146912,5.00009512 C8.15766355,5.82658081 9,7.28931252 9,8.95718601 C9,11.2348833 6.98532404,10.805542 4.5,10.805542 C2.01467596,10.805542 0,11.0408389 0,8.95718601 C0,7.28920683 0.842405128,6.02212001 2.10853088,5.19560262 C2.6978711,5.80041681 3.55136713,6.18188963 4.50011446,6.18188963 C5.44850697,6.18188963 6.30242649,5.80042738 6.89169804,5.19519043 Z M4.47604916,0.263504276 C5.85444302,0.263504276 6.97604916,1.38505674 6.97604916,2.76395942 C6.97604916,3.45137668 6.6968529,4.07517561 6.24558076,4.52776361 C5.79257797,4.98211598 5.16655531,5.26350428 4.47604916,5.26350428 C3.785543,5.26350428 3.15954282,4.98211598 2.70651755,4.52776361 C2.25524542,4.07520933 1.97604916,3.45139915 1.97604916,2.7635099 C1.97604916,1.38511293 3.0975991,0.263504276 4.47604916,0.263504276 Z"/>
							</svg>
						</a>
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
					<div class="top-contacts__inner">
						<div class="content-block" id="topcontacts-show">
							<div class="top-contacts -inline-group js-top-contacts">
								<div class="-inline-group top-contacts__phones">
									<div class="top-contacts__phones-list show-for-medium js-top-contacts__phones">
										<div class="desktop -inline-group">
											<div class="top-contacts__icon top-contacts__icon_phone js-top-contacts__phone top-contacts--hover">
												<svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" d="M2.41582 0.558309L0.736602 2.03462L0.682656 2.0886C-0.0898041 2.86163 -0.0470871 4.0003 0.0562941 4.71029C0.171698 5.50287 0.441124 6.26295 0.595156 6.6686L0.598237 6.67671L0.601456 6.68477C1.33972 8.5318 2.56957 10.372 4.08933 11.8929C6.1375 13.9426 8.20881 14.9592 9.30011 15.386C9.82149 15.5915 10.9545 16 12.0145 16C12.6061 16 13.3649 15.8728 13.9196 15.3177L13.9736 15.2637L15.4491 13.5829C16.2557 12.7019 16.1438 11.3196 15.3352 10.5104L15.2487 10.4239L13.1557 8.75452C12.3107 8.01682 11.0071 8.00851 10.1716 8.84468L10.1689 8.84736C10.1553 8.86092 10.118 8.89807 10.0813 8.94009C10.0522 8.97341 10.0016 9.03386 9.95129 9.11697L9.91066 9.17796C9.53177 8.90726 8.99184 8.45886 8.27296 7.73945C7.55322 7.01918 7.10491 6.4784 6.83448 6.09912L6.89636 6.05783C6.97953 6.00742 7.04 5.95671 7.07328 5.92757C7.11522 5.89084 7.15234 5.85357 7.16583 5.84003L7.1685 5.83735C7.9864 5.01884 8.0288 3.71189 7.25374 2.84692L5.59053 0.758516L5.50407 0.671981C5.10958 0.277202 4.60859 0.0623617 4.08738 0.0102026L4.07841 0.00930485C3.47607 -0.045494 2.86882 0.142266 2.41582 0.558309ZM3.8932 2.00078C3.99119 2.0115 4.04894 2.04525 4.08933 2.08567L4.09616 2.09251L5.73432 4.14947L5.76094 4.17801C5.82043 4.24179 5.82688 4.33292 5.7707 4.40458L4.40992 5.31246L4.54922 5.97463C4.62286 6.32469 4.84247 6.74341 5.17446 7.21564C5.52516 7.71448 6.05957 8.35389 6.85822 9.15314C7.65687 9.95238 8.29583 10.4872 8.79436 10.8382C9.26623 11.1704 9.68483 11.3903 10.035 11.4641L10.6978 11.6037L11.6045 10.2427C11.6654 10.1978 11.7624 10.1909 11.843 10.2635L11.8653 10.2835L13.9135 11.9172L13.9204 11.9241C13.9649 11.9686 13.9968 12.0378 14.0002 12.1154C14.0036 12.1944 13.977 12.2291 13.9734 12.2329L13.9636 12.2435L12.5132 13.8956L12.5049 13.904C12.5049 13.904 12.4984 13.9104 12.4825 13.9194C12.4661 13.9287 12.4389 13.9413 12.3975 13.954C12.3115 13.9802 12.1849 14 12.0145 14C11.3642 14 10.5382 13.7243 10.0323 13.5248L10.0297 13.5238C9.1295 13.1719 7.3128 12.2893 5.50407 10.4792C4.16388 9.13805 3.09285 7.52565 2.46181 5.95047C2.32041 5.5773 2.11775 4.98753 2.03542 4.42212C1.94047 3.76999 2.06096 3.53875 2.09739 3.50229L2.10561 3.49406L3.75717 2.04207L3.76817 2.0318C3.7803 2.02047 3.81939 1.99508 3.8932 2.00078Z\" /></svg>
												<a class="top-contacts__phones-item phone-number js-phone-number__8812986-42-76" href="tel:8812986-42-76">8 812 986-42-76</a>
											</div>
											<div class="top-contacts__icon top-contacts__icon_phone js-top-contacts__phone top-contacts--hover">
												<a class="top-contacts__phones-item phone-number js-phone-number__8921883-73-40" href="tel:8921883-73-40">8 921 883-73-40</a>
											</div>
										</div>
									</div>
								</div>

								<div class="top-contacts__forms">
									<div class="row -inline-group text-right">
										<div class="mobile top-contacts__phones-item phone-number notranslate">
											<a class="js-phone-number__8812986-42-76" href="tel:8812986-42-76">
												<div class="top-contacts__icon top-contacts__icon_phone js-top-contacts__phone">
													<svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" d="M2.41582 0.558309L0.736602 2.03462L0.682656 2.0886C-0.0898041 2.86163 -0.0470871 4.0003 0.0562941 4.71029C0.171698 5.50287 0.441124 6.26295 0.595156 6.6686L0.598237 6.67671L0.601456 6.68477C1.33972 8.5318 2.56957 10.372 4.08933 11.8929C6.1375 13.9426 8.20881 14.9592 9.30011 15.386C9.82149 15.5915 10.9545 16 12.0145 16C12.6061 16 13.3649 15.8728 13.9196 15.3177L13.9736 15.2637L15.4491 13.5829C16.2557 12.7019 16.1438 11.3196 15.3352 10.5104L15.2487 10.4239L13.1557 8.75452C12.3107 8.01682 11.0071 8.00851 10.1716 8.84468L10.1689 8.84736C10.1553 8.86092 10.118 8.89807 10.0813 8.94009C10.0522 8.97341 10.0016 9.03386 9.95129 9.11697L9.91066 9.17796C9.53177 8.90726 8.99184 8.45886 8.27296 7.73945C7.55322 7.01918 7.10491 6.4784 6.83448 6.09912L6.89636 6.05783C6.97953 6.00742 7.04 5.95671 7.07328 5.92757C7.11522 5.89084 7.15234 5.85357 7.16583 5.84003L7.1685 5.83735C7.9864 5.01884 8.0288 3.71189 7.25374 2.84692L5.59053 0.758516L5.50407 0.671981C5.10958 0.277202 4.60859 0.0623617 4.08738 0.0102026L4.07841 0.00930485C3.47607 -0.045494 2.86882 0.142266 2.41582 0.558309ZM3.8932 2.00078C3.99119 2.0115 4.04894 2.04525 4.08933 2.08567L4.09616 2.09251L5.73432 4.14947L5.76094 4.17801C5.82043 4.24179 5.82688 4.33292 5.7707 4.40458L4.40992 5.31246L4.54922 5.97463C4.62286 6.32469 4.84247 6.74341 5.17446 7.21564C5.52516 7.71448 6.05957 8.35389 6.85822 9.15314C7.65687 9.95238 8.29583 10.4872 8.79436 10.8382C9.26623 11.1704 9.68483 11.3903 10.035 11.4641L10.6978 11.6037L11.6045 10.2427C11.6654 10.1978 11.7624 10.1909 11.843 10.2635L11.8653 10.2835L13.9135 11.9172L13.9204 11.9241C13.9649 11.9686 13.9968 12.0378 14.0002 12.1154C14.0036 12.1944 13.977 12.2291 13.9734 12.2329L13.9636 12.2435L12.5132 13.8956L12.5049 13.904C12.5049 13.904 12.4984 13.9104 12.4825 13.9194C12.4661 13.9287 12.4389 13.9413 12.3975 13.954C12.3115 13.9802 12.1849 14 12.0145 14C11.3642 14 10.5382 13.7243 10.0323 13.5248L10.0297 13.5238C9.1295 13.1719 7.3128 12.2893 5.50407 10.4792C4.16388 9.13805 3.09285 7.52565 2.46181 5.95047C2.32041 5.5773 2.11775 4.98753 2.03542 4.42212C1.94047 3.76999 2.06096 3.53875 2.09739 3.50229L2.10561 3.49406L3.75717 2.04207L3.76817 2.0318C3.7803 2.02047 3.81939 1.99508 3.8932 2.00078Z\" /></svg>
												</div>
											</a>
										</div>

										<div class="feedback inline-column top-contacts__form">
											<a href="/contacts/" class="top-contacts__link feedback-btn -inline-group" data-type="0">
												<div class="top-contacts__icon top-contacts__icon_form">
													<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 16 16"><defs/><path fill="currentColor" fill-rule="evenodd" d="M2 2h12a2 2 0 012 2v8a2 2 0 01-2 2H2a2 2 0 01-2-2V4c0-1.1.9-2 2-2zm0 5v5h12V6.9L8 10 2 7zm0-2l6 3 6-3V4H2v1z" clip-rule="evenodd"/></svg>
												</div>
												<span class="show-for-medium top-contacts__form-text">Напишите нам</span>
											</a>
										</div>

										<div class="feedback inline-column top-contacts__form">
											<a href="/contacts/" class="top-contacts__link feedback-btn -inline-group" data-type="1">
												<div class="top-contacts__icon top-contacts__icon_form">
													<svg id="callback_icon" width="50" height="50" data-name="callback icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 18 15"><title>callback_icon</title><path fill="currentColor" d="M17.88,1.5a10.32,10.32,0,0,0-1-1A1.45,1.45,0,0,0,16,0a2,2,0,0,0-1,.48,7.38,7.38,0,0,0-1.22,1.14A5.06,5.06,0,0,0,12.91,3,0.89,0.89,0,0,0,13,4a4.35,4.35,0,0,0,1.38,1A1,1,0,0,1,15,6a2.35,2.35,0,0,1-.62,1.5Q13.75,8.25,13,9t-1.5,1.38A2.35,2.35,0,0,1,10,11a1,1,0,0,1-1-.63A4.35,4.35,0,0,0,8,9a0.89,0.89,0,0,0-1-.09,5.06,5.06,0,0,0-1.33.84A7.37,7.37,0,0,0,4.48,11,2,2,0,0,0,4,12a1.45,1.45,0,0,0,.47.89,10.36,10.36,0,0,0,1,1Q6.06,14.34,7,15a6,6,0,0,0,1.82-.35,16.1,16.1,0,0,0,2.2-.92,19.94,19.94,0,0,0,2.19-1.28A12,12,0,0,0,15,11a12.05,12.05,0,0,0,1.45-1.79A20,12,0,0,0,17.73,7a16,16,0,0,0,.92-2.2A6,6,0,0,0,19,3Q18.34,2.06,17.88,1.5ZM6,6L9,3,6,0V2H1V4H6V6Z\" transform=\"translate(-1)\"/></svg>
												</div>
												<span class="show-for-medium top-contacts__form-text">Обратный звонок</span>
											</a>
										</div>

										<div class="feedback inline-column top-contacts__form top-contacts_scroll-up">
											<div class="top-contacts__icon js-scroll-up hide-for-medium" role="button" tabindex="0" aria-label="Наверх">
												<svg version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="768" height="768" viewBox="0 0 768 768"><path d="M384 256.5l192 192-45 45-147-147-147 147-45-45z"></path></svg>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
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