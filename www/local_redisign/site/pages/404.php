<?php
include_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/urlrewrite.php');

CHTTP::SetStatus("404 Not Found");
@define("ERROR_404","Y");
const HIDE_SIDEBAR = true;
const MF_HIDE_TITLEBAR = true;

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

/** @global CMain $APPLICATION */
$APPLICATION->SetTitle("Страница не найдена");
?>

<div class="mf-404">
	<header class="mf-404-hero">
		<div class="mf-404-hero__code" aria-hidden="true">404</div>
		<h1>Страница не найдена</h1>
		<p>Неправильно набран адрес или такой страницы на сайте больше не существует.</p>
		<div class="mf-404-hero__actions">
			<a class="mf-btn mf-btn--accent mf-btn--lg" href="<?= SITE_DIR ?>">
				<?= function_exists('mf_icon') ? mf_icon('home') : '' ?>
				На главную
			</a>
			<a class="mf-btn mf-btn--ghost" href="<?= SITE_DIR ?>products/">
				<?= function_exists('mf_icon') ? mf_icon('search') : '' ?>
				В каталог
			</a>
		</div>
	</header>

	<section class="mf-404-grid">
		<article class="mf-404-block">
			<header class="mf-404-block__head">
				<span class="mf-404-block__icon" aria-hidden="true">
					<?= function_exists('mf_icon') ? mf_icon('engine') : '' ?>
				</span>
				<h2>Каталог</h2>
			</header>
			<?php
			$APPLICATION->IncludeComponent(
				"bitrix:catalog.section.list",
				"tree",
				array(
					"COMPONENT_TEMPLATE" => "tree",
					"IBLOCK_TYPE" => "catalog",
					"IBLOCK_ID" => "2",
					"SECTION_ID" => $_REQUEST["SECTION_ID"],
					"SECTION_CODE" => "",
					"COUNT_ELEMENTS" => "Y",
					"TOP_DEPTH" => "2",
					"SECTION_FIELDS" => array(0 => "", 1 => ""),
					"SECTION_USER_FIELDS" => array(0 => "", 1 => ""),
					"SECTION_URL" => "",
					"CACHE_TYPE" => "A",
					"CACHE_TIME" => "36000000",
					"CACHE_GROUPS" => "Y",
					"ADD_SECTIONS_CHAIN" => "Y",
				),
				false
			);
			?>
		</article>

		<article class="mf-404-block">
			<header class="mf-404-block__head">
				<span class="mf-404-block__icon" aria-hidden="true">
					<?= function_exists('mf_icon') ? mf_icon('info') : '' ?>
				</span>
				<h2>О магазине</h2>
			</header>
			<?php
			$APPLICATION->IncludeComponent(
				"bitrix:main.map",
				".default",
				array(
					"CACHE_TYPE" => "A",
					"CACHE_TIME" => "36000000",
					"SET_TITLE" => "N",
					"LEVEL" => "3",
					"COL_NUM" => "2",
					"SHOW_DESCRIPTION" => "Y",
					"COMPONENT_TEMPLATE" => ".default",
				),
				false
			);
			?>
		</article>
	</section>
</div>

<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
