<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

// ID вашего каталога
$IBLOCK_ID = 4; // Каталог из 1С

// Breadcrumbs: Home (link) -> Shop -> Sections...
$APPLICATION->AddChainItem("Магазин", SITE_DIR."products/");

// Получаем код раздела из URL
$sectionCode = $_REQUEST["SECTION_CODE"];
// Force correct SEF basepath for controls (sorting/view links)
$GLOBALS['MF_SHOP_BASEPATH'] = SITE_DIR . "products/category/" . $sectionCode . "/";

// Получаем информацию о разделе
CModule::IncludeModule("iblock");

$arFilter = array(
    "IBLOCK_ID" => $IBLOCK_ID,
    "CODE" => $sectionCode,
    "GLOBAL_ACTIVE" => "Y"
);

$rsSection = CIBlockSection::GetList(
    array(),
    $arFilter,
    false,
    array("ID", "NAME", "DESCRIPTION")
);

if ($arSection = $rsSection->Fetch()) {
    $APPLICATION->SetTitle($arSection["NAME"]);
    $SECTION_ID = $arSection["ID"];
} else {
    // Раздел не найден - 404
    CHTTP::SetStatus("404 Not Found");
    @define("ERROR_404", "Y");
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
    exit();
}

// If category has child sections -> keep "categories" view (cards + tree).
$hasChildSections = (bool)CIBlockSection::GetList(
	[],
	[
		'IBLOCK_ID' => $IBLOCK_ID,
		'SECTION_ID' => $SECTION_ID,
		'GLOBAL_ACTIVE' => 'Y',
	],
	false,
	['nTopCount' => 1],
	['ID']
)->Fetch();

if ($hasChildSections)
{
	// Breadcrumbs for category pages when we don't render catalog.section (it would normally add chain).
	$nav = CIBlockSection::GetNavChain($IBLOCK_ID, $SECTION_ID, ['NAME', 'SECTION_PAGE_URL']);
	while ($n = $nav->Fetch())
	{
		$APPLICATION->AddChainItem($n['NAME'], $n['SECTION_PAGE_URL']);
	}
	?>
	<div class="mf-shop">
		<div class="mf-shop-layout">
			<section class="mf-shop-main" aria-label="Подкатегории (карточки)">
				<?include($_SERVER["DOCUMENT_ROOT"]."/include/mf_catalog_search.php");?>
				<?$APPLICATION->IncludeComponent(
					"bitrix:catalog.section.list",
					"mf_shop_cards",
					array(
						"IBLOCK_TYPE" => "catalog",
						"IBLOCK_ID" => $IBLOCK_ID,
						"SECTION_ID" => $SECTION_ID,
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

			<aside class="mf-shop-sidebar" aria-label="Подкатегории (дерево)">
				<?$APPLICATION->IncludeComponent(
					"mf:catalog.section.tree.fast",
					".default",
					array(
						"IBLOCK_ID" => $IBLOCK_ID,
						"MF_CURRENT_SECTION_ID" => $SECTION_ID,
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
	exit;
}

// Затем показываем товары текущей категории
$GLOBALS['mfCatalogFilter'] = [
	'!PROPERTY_MF_IS_REDIRECT' => 'Y',
	// Hide products explicitly marked "N". Empty (old) values remain visible.
	'!PROPERTY_MF_SHOW_IN_CATALOG' => 'N',
];

$mfView = (string)($_GET['mf_view'] ?? 'grid');
if (!in_array($mfView, ['grid', 'tiles', 'list'], true)) $mfView = 'grid';

$productRowVariants = '[{"VARIANT":3,"BIG_DATA":false}]'; // 4-cols cards
if ($mfView === 'tiles')
{
	$productRowVariants = '[{"VARIANT":1,"BIG_DATA":false}]'; // 2-cols cards
}
if ($mfView === 'list')
{
	$productRowVariants = '[{"VARIANT":9,"BIG_DATA":false}]'; // line view (different card template)
}

$mfSort = (string)($_GET['mf_sort'] ?? 'default');
$sortField = 'sort';
$sortOrder = 'asc';
$sortField2 = 'name';
$sortOrder2 = 'asc';
switch ($mfSort)
{
	case 'name_asc':
		$sortField = 'name';
		$sortOrder = 'asc';
		$sortField2 = 'sort';
		$sortOrder2 = 'asc';
		break;
	case 'name_desc':
		$sortField = 'name';
		$sortOrder = 'desc';
		$sortField2 = 'sort';
		$sortOrder2 = 'asc';
		break;
	case 'price_asc':
		// Bitrix supports sorting by catalog price using catalog_PRICE_<PRICE_TYPE_ID> in many configurations.
		$sortField = 'catalog_PRICE_1';
		$sortOrder = 'asc';
		$sortField2 = 'name';
		$sortOrder2 = 'asc';
		break;
	case 'price_desc':
		$sortField = 'catalog_PRICE_1';
		$sortOrder = 'desc';
		$sortField2 = 'name';
		$sortOrder2 = 'asc';
		break;
	case 'default':
	default:
		break;
}

$arParams = array(
    "IBLOCK_TYPE" => "catalog",
    "IBLOCK_ID" => $IBLOCK_ID,
    
    // ID текущего раздела
    "SECTION_ID" => $SECTION_ID,
    "SECTION_CODE" => $sectionCode,
    
    // URL шаблоны - категории с префиксом, товары без
    "SECTION_URL" => "/products/category/#SECTION_CODE#/",
    "DETAIL_URL" => "/products/#ELEMENT_CODE#/",
    
    // Показывать подразделы
    "SHOW_ALL_WO_SECTION" => "N",
    "INCLUDE_SUBSECTIONS" => "Y",
    
    // Количество элементов
    "PAGE_ELEMENT_COUNT" => 30,
    "LINE_ELEMENT_COUNT" => 3,

	// View (affects CARD vs LINE and columns)
	"PRODUCT_ROW_VARIANTS" => $productRowVariants,
    
    // Сортировка
    "ELEMENT_SORT_FIELD" => $sortField,
    "ELEMENT_SORT_ORDER" => $sortOrder,
    "ELEMENT_SORT_FIELD2" => $sortField2,
    "ELEMENT_SORT_ORDER2" => $sortOrder2,
    
    // Свойства (нужны для вывода бренд/артикул в карточках товара)
    "PROPERTY_CODE" => array(
		"CML2_ARTICLE",
		"MF_BRAND",
		"MF_ARTICLE_NORM",
		"MF_BRAND_NORM",
		"OEM",
		"CML2_MANUFACTURER",
		"BRAND",
		"MANUFACTURER",
		"ARTNUMBER",
		"ARTICLE",
	),
    
    // Цены
    "PRICE_CODE" => array("BASE"),
    "USE_PRICE_COUNT" => "Y",
    "SHOW_PRICE_COUNT" => 1,
    
    // Корзина
    "USE_PRODUCT_QUANTITY" => "Y",
    "ADD_SECTIONS_CHAIN" => "Y",

	// Бренд (используется частью логики catalog.item + может пригодиться для аналитики)
	"BRAND_PROPERTY" => "MF_BRAND",
    
    // Кэширование: авто (учёт глобальной настройки + тегов каталога). После импорта цен — сброс кеша в админке.
    "CACHE_TYPE" => "A",
    "CACHE_TIME" => "3600",
    "CACHE_FILTER" => "Y",
    "CACHE_GROUPS" => "Y",

	// Hide redirect-elements from lists
	"FILTER_NAME" => "mfCatalogFilter",

	// Режим сортировки для result_modifier (наличие / цена / имя)
	"MF_SORT_MODE" => $mfSort,
	"MF_CATALOG_SORT_VERSION" => "2",
    
    // SEO
    "SET_TITLE" => "Y",
    "SET_BROWSER_TITLE" => "Y",
    "SET_META_KEYWORDS" => "Y",
    "SET_META_DESCRIPTION" => "Y",
    "SET_LAST_MODIFIED" => "N",
    
    // Пагинация
    "PAGER_TEMPLATE" => ".default",
    "DISPLAY_TOP_PAGER" => "N",
    "DISPLAY_BOTTOM_PAGER" => "Y",
    "PAGER_TITLE" => "Товары",
    "PAGER_SHOW_ALWAYS" => "N",
    "PAGER_DESC_NUMBERING" => "N",
    
    // Шаблон
    "TEMPLATE_THEME" => "blue",
    
    // Разделы
    "SECTION_COUNT_ELEMENTS" => "Y",
    "SECTION_TOP_DEPTH" => "1",
    
    // Сообщения
    "MESS_BTN_BUY" => "Купить",
    "MESS_BTN_ADD_TO_BASKET" => "В корзину",
    "MESS_BTN_SUBSCRIBE" => "Подписаться",
    "MESS_BTN_DETAIL" => "Подробнее",
    "MESS_NOT_AVAILABLE" => "Нет в наличии",
);

?>
<div class="mf-shop mf-view-<?=$mfView?>">
	<div class="mf-shop-layout">
		<section class="mf-shop-main" aria-label="Товары">
			<?include($_SERVER["DOCUMENT_ROOT"]."/include/mf_catalog_search.php");?>
			<?include($_SERVER["DOCUMENT_ROOT"]."/include/mf_catalog_controls.php");?>
			<?$APPLICATION->IncludeComponent(
				"bitrix:catalog.section",
				"",
				$arParams,
				false
			);?>
		</section>

		<aside class="mf-shop-sidebar" aria-label="Категории (дерево)">
			<?$APPLICATION->IncludeComponent(
				"mf:catalog.section.tree.fast",
				".default",
				array(
					"IBLOCK_ID" => $IBLOCK_ID,
					"MF_CURRENT_SECTION_ID" => $SECTION_ID,
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

