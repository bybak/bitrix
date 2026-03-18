<?php

declare(strict_types=1);

/**
 * Admin UI integration:
 * - Adds "Импорт аналогов" button to iblock element edit page for catalog iblock 4.
 * - The button opens /bitrix/admin/mf_import_analogs.php for the current element.
 */

if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
{
	return;
}

if (!class_exists(\Bitrix\Main\EventManager::class))
{
	return;
}

\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', 'OnAdminContextMenuShow', static function (&$items) {
	global $APPLICATION;
	if (!is_object($APPLICATION) || !method_exists($APPLICATION, 'GetCurPage'))
	{
		return;
	}

	$page = (string)$APPLICATION->GetCurPage();
	if ($page !== '/bitrix/admin/iblock_element_edit.php')
	{
		return;
	}

	$iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? 0);
	$elementId = (int)($_REQUEST['ID'] ?? 0);
	if ($iblockId !== 4 || $elementId <= 0)
	{
		return;
	}

	$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
	$listLink = 'mf_analogs_list.php?lang=' . urlencode($lang)
		. '&IBLOCK_ID=' . $iblockId
		. '&ID=' . $elementId
		. '&back_url=' . urlencode($page . '?' . http_build_query($_GET));

	$items[] = [
		'TEXT' => 'Список аналогов',
		'TITLE' => 'Посмотреть / удалить аналоги для товара',
		'LINK' => $listLink,
		'ICON' => 'btn_list',
		'SORT' => 1990,
	];
});

// Global menu item: bulk analog import.
\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', 'OnBuildGlobalMenu', static function (&$aGlobalMenu, &$aModuleMenu) {
	if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
	{
		return;
	}

	$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
	$aModuleMenu[] = [
		'parent_menu' => 'global_menu_store',
		'section' => 'mf_analogs',
		'sort' => 2059,
		'text' => 'Все аналоги',
		'title' => 'Все связи аналогов (mf_product_analogs)',
		'icon' => 'sale_menu_icon',
		'page_icon' => 'sale_menu_icon',
		'items_id' => 'menu_mf_analogs_all',
		'url' => 'mf_analogs_all.php?lang=' . urlencode($lang),
		'more_url' => [
			'mf_analogs_all.php?lang=' . urlencode($lang),
		],
	];
	$aModuleMenu[] = [
		'parent_menu' => 'global_menu_store',
		'section' => 'mf_analogs',
		'sort' => 2060,
		'text' => 'Импорт аналогов',
		'title' => 'Импорт аналогов для множества товаров (CSV)',
		'icon' => 'sale_menu_icon',
		'page_icon' => 'sale_menu_icon',
		'items_id' => 'menu_mf_analogs_import',
		'url' => 'mf_import_analogs_bulk.php?lang=' . urlencode($lang),
		'more_url' => [
			'mf_import_analogs_bulk.php?lang=' . urlencode($lang),
		],
	];
});

