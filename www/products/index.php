<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Магазин");
$APPLICATION->AddChainItem("Магазин", SITE_DIR."products/");

// ID вашего каталога
$IBLOCK_ID = 4; // Каталог запчастей Motor Force

?>
<div class="mf-shop">
	<div class="mf-shop-layout">
		<section class="mf-shop-main" aria-label="Категории (карточки)">
			<?include($_SERVER["DOCUMENT_ROOT"]."/include/mf_catalog_search.php");?>
			<?$APPLICATION->IncludeComponent(
				"bitrix:catalog.section.list",
				"mf_shop_cards",
				array(
					"IBLOCK_TYPE" => "catalog",
					"IBLOCK_ID" => $IBLOCK_ID,

					"SECTION_ID" => 0,
					"SECTION_CODE" => "",

					"COUNT_ELEMENTS" => "N",
					"SECTION_URL" => "/products/category/#SECTION_CODE#/",

					// Need pictures for mf_shop_cards
					"SECTION_FIELDS" => array("PICTURE", "DETAIL_PICTURE"),

					"TOP_DEPTH" => "1",
					"VIEW_MODE" => "LIST",
					"SHOW_PARENT_NAME" => "N",
					"ADD_SECTIONS_CHAIN" => "N",

					"CACHE_TYPE" => "A",
					"CACHE_TIME" => "36000000",
					"CACHE_GROUPS" => "Y",
				),
				false
			);?>
		</section>

		<aside class="mf-shop-sidebar" aria-label="Категории (дерево)">
			<?$APPLICATION->IncludeComponent(
				"mf:catalog.section.tree.fast",
				".default",
				array(
					"IBLOCK_ID" => $IBLOCK_ID,
					"MF_CURRENT_SECTION_ID" => 0,
					"SECTION_URL" => "/products/category/#SECTION_CODE#/",
					"TOP_DEPTH" => "6",
					"CACHE_TYPE" => "A",
					"CACHE_TIME" => "36000000",
					"CACHE_GROUPS" => "Y",
				),
				false
			);?>
		</aside>
	</div>
</div>
<?php

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");

