<?php

// PHP 7.x: ниже используются str_starts_with / str_ends_with (встроены с PHP 8.0).
if (!function_exists('str_starts_with'))
{
	function str_starts_with($haystack, $needle)
	{
		$haystack = (string)$haystack;
		$needle = (string)$needle;

		return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
	}
}
if (!function_exists('str_ends_with'))
{
	function str_ends_with($haystack, $needle)
	{
		$haystack = (string)$haystack;
		$needle = (string)$needle;
		if ($needle === '')
		{
			return true;
		}
		$len = strlen($needle);

		return $len <= strlen($haystack) && substr($haystack, -$len) === $needle;
	}
}

// Global config for external Motor-Force image host.
// We intentionally generate URLs deterministically (no downloads into Bitrix).
if (!defined('MF_MOTOR_FORCE_IMG_HOST'))
{
	// Default:
	// - local dev: proxy images through this site (avoids browser HSTS / TLS issues)
	// - other envs: use the real image host
	$env = trim((string)getenv('MF_MOTOR_FORCE_IMG_HOST'));
	if ($env !== '')
	{
		define('MF_MOTOR_FORCE_IMG_HOST', $env);
	}
	else
	{
		$httpHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
		$isLocal =
			$httpHost === 'localhost'
			|| str_starts_with($httpHost, 'localhost:')
			|| $httpHost === '127.0.0.1'
			|| str_starts_with($httpHost, '127.0.0.1:')
			|| str_ends_with($httpHost, '.local')
			|| str_ends_with($httpHost, '.test');

		define('MF_MOTOR_FORCE_IMG_HOST', $isLocal ? '/mf-img' : 'img-motor-force.ru');
	}
}

if (!function_exists('mf_mf_img_host'))
{
	function mf_mf_img_host(): string
	{
		$h = trim((string)MF_MOTOR_FORCE_IMG_HOST);
		if ($h === '')
		{
			return '';
		}

		// Relative path (proxy through current host), e.g. /mf-img
		if (str_starts_with($h, '/'))
		{
			return rtrim($h, '/');
		}

		// If user already configured a full URL (https://...) or protocol-relative (//...),
		// keep it as-is.
		$hl = strtolower($h);
		if (str_starts_with($hl, 'http://') || str_starts_with($hl, 'https://') || str_starts_with($h, '//'))
		{
			return rtrim($h, '/');
		}

		$https =
			(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
			|| (!empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https')
			|| (!empty($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443');
		$scheme = $https ? 'https' : 'http';

		return $scheme . '://' . rtrim($h, '/');
	}
}

if (!function_exists('mf_mf_placeholder_img_url'))
{
	function mf_mf_placeholder_img_url(): string
	{
		// Local placeholder in current site template.
		$rel = '/bitrix/templates/eshop_bootstrap_v4/images/mf-no-photo.svg';
		$docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
		if ($docRoot === '')
		{
			// Fallback (CLI / unusual env): /bitrix/php_interface -> go up to site root.
			$docRoot = dirname(__DIR__, 2);
		}
		$abs = $docRoot . $rel;
		if ($docRoot !== '' && is_file($abs))
		{
			return $rel . '?v=' . (int)filemtime($abs);
		}
		return $rel;
	}
}

// --- Local dev: hide Bitrix "site checker" admin notify --------------------
// Some Bitrix installations show a persistent admin popup:
// "Обнаружены ошибки в работе сайта..." (TAG=SITE_CHECKER).
// For local development we suppress it to avoid interrupting admin workflows.
if (!function_exists('mf_is_local_dev_host'))
{
	function mf_is_local_dev_host(): bool
	{
		$httpHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
		return
			$httpHost === 'localhost'
			|| str_starts_with($httpHost, 'localhost:')
			|| $httpHost === '127.0.0.1'
			|| str_starts_with($httpHost, '127.0.0.1:')
			|| str_ends_with($httpHost, '.local')
			|| str_ends_with($httpHost, '.test');
	}
}

if (!function_exists('mf_admin_suppress_site_checker_notify'))
{
	function mf_admin_suppress_site_checker_notify(): void
	{
		if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
		{
			return;
		}
		if (!mf_is_local_dev_host())
		{
			return;
		}

		// 1) Remove the notify record (if any).
		if (class_exists('CAdminNotify') && method_exists('CAdminNotify', 'DeleteByTag'))
		{
			\CAdminNotify::DeleteByTag('SITE_CHECKER');
		}

		// 2) Keep the option in "success" state so it doesn't get re-added just because it was "N".
		if (class_exists(\Bitrix\Main\Config\Option::class))
		{
			try
			{
				\Bitrix\Main\Config\Option::set('main', 'site_checker_success', 'Y');
			}
			catch (\Throwable $e)
			{
				// ignore in local dev
			}
		}
	}
}

// Remove early (before page output) and also at the end of the request,
// so even if some agent recreates it during the hit, it won't persist.
if (defined('ADMIN_SECTION') && ADMIN_SECTION === true && mf_is_local_dev_host())
{
	mf_admin_suppress_site_checker_notify();
	if (class_exists(\Bitrix\Main\EventManager::class))
	{
		\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', 'OnEpilog', 'mf_admin_suppress_site_checker_notify');
	}
}

if (!function_exists('mf_mf_section_img_url'))
{
	function mf_mf_section_img_url(int $sectionId): string
	{
		$sectionId = (int)$sectionId;
		if ($sectionId <= 0)
		{
			return '';
		}
		return mf_mf_img_host() . '/sections/' . $sectionId . '.jpg';
	}
}

if (!function_exists('mf_mf_product_img_url'))
{
	function mf_mf_product_img_url(string $code, int $num = 1): string
	{
		$code = trim($code);
		if ($code === '')
		{
			return '';
		}
		$num = (int)$num;
		if ($num <= 0)
		{
			$num = 1;
		}
		$fname = str_pad((string)$num, 4, '0', STR_PAD_LEFT) . '.jpg';
		return mf_mf_img_host() . '/products/' . rawurlencode($code) . '/' . $fname;
	}
}

// Ensure catalog IBlock URL templates are set (needed for search URLs).
if (!function_exists('mf_ensure_catalog_iblock_url_templates'))
{
	function mf_ensure_catalog_iblock_url_templates(): void
	{
		$optKey = 'mf_iblock4_url_templates_fixed';

		if (!class_exists(\Bitrix\Main\Config\Option::class) || !class_exists(\Bitrix\Main\Loader::class))
		{
			return;
		}
		if (!\Bitrix\Main\Loader::includeModule('iblock'))
		{
			return;
		}

		try
		{
			if (\Bitrix\Main\Config\Option::get('main', $optKey, 'N') === 'Y')
			{
				return;
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}

		$iblockId = 4;
		$detail = (string)\CIBlock::GetArrayByID($iblockId, 'DETAIL_PAGE_URL');
		$section = (string)\CIBlock::GetArrayByID($iblockId, 'SECTION_PAGE_URL');
		$list = (string)\CIBlock::GetArrayByID($iblockId, 'LIST_PAGE_URL');

		$fields = [];
		if (trim($detail) === '')
		{
			$fields['DETAIL_PAGE_URL'] = '/products/#ELEMENT_CODE#/';
		}
		if (trim($section) === '')
		{
			$fields['SECTION_PAGE_URL'] = '/products/category/#SECTION_CODE#/';
		}
		if (trim($list) === '')
		{
			$fields['LIST_PAGE_URL'] = '/products/';
		}

		if (!empty($fields))
		{
			$ib = new \CIBlock();
			$ib->Update($iblockId, $fields);
		}

		try
		{
			\Bitrix\Main\Config\Option::set('main', $optKey, 'Y');
		}
		catch (\Throwable $e)
		{
			// ignore
		}
	}
}

mf_ensure_catalog_iblock_url_templates();

// Admin menu: "Магазин" -> "Ненайденные товары (импорт складов)" (HL mf_stock_import_missing)
if (!function_exists('mf_admin_menu_missing_stock_items'))
{
	function mf_admin_menu_missing_stock_items(array &$aGlobalMenu, array &$aModuleMenu): void
	{
		if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
		{
			return;
		}

		if (!class_exists(\Bitrix\Main\Loader::class))
		{
			return;
		}

		if (!\Bitrix\Main\Loader::includeModule('highloadblock'))
		{
			return;
		}

		$hl = \Bitrix\Highloadblock\HighloadBlockTable::getList([
			'filter' => ['=TABLE_NAME' => 'mf_stock_import_missing'],
			'select' => ['ID', 'NAME', 'TABLE_NAME'],
			'limit' => 1,
		])->fetch();
		if (!$hl)
		{
			return;
		}

		$hlId = (int)$hl['ID'];
		if ($hlId <= 0)
		{
			return;
		}

		// Custom page with convenient filters (warehouse + brand).
		$url = 'mf_stock_import_missing.php?lang=ru';

		$parentMenu =
			(isset($aGlobalMenu['global_menu_store']) ? 'global_menu_store' :
				(isset($aGlobalMenu['global_menu_sale']) ? 'global_menu_sale' : 'global_menu_content'));

		$aModuleMenu[] = [
			'parent_menu' => $parentMenu,
			'section' => 'mf_stock_import',
			'sort' => 2050,
			'text' => 'Ненайденные товары (импорт складов)',
			'title' => 'Товары, которые не сматчились по бренд+артикул при импорте складов',
			'icon' => 'sale_menu_icon',
			'page_icon' => 'sale_menu_icon',
			'items_id' => 'menu_mf_stock_import_missing',
			'url' => $url,
			'more_url' => [
				'mf_stock_import_missing.php',
				'highloadblock_rows_list.php?lang=ru&ENTITY_ID=' . $hlId,
				'highloadblock_row_edit.php?lang=ru&ENTITY_ID=' . $hlId,
			],
		];
	}
}

if (class_exists(\Bitrix\Main\EventManager::class))
{
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', 'OnBuildGlobalMenu', 'mf_admin_menu_missing_stock_items');
}

// Admin menu: "Магазин" -> "Логи импорта остатков" (table mf_supplier_stock_run_log)
if (!function_exists('mf_admin_menu_stock_import_logs'))
{
	function mf_admin_menu_stock_import_logs(array &$aGlobalMenu, array &$aModuleMenu): void
	{
		if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
		{
			return;
		}

		$url = 'mf_stock_import_log.php?lang=ru';
		$parentMenu =
			(isset($aGlobalMenu['global_menu_store']) ? 'global_menu_store' :
				(isset($aGlobalMenu['global_menu_sale']) ? 'global_menu_sale' : 'global_menu_content'));

		$aModuleMenu[] = [
			'parent_menu' => $parentMenu,
			'section' => 'mf_stock_import',
			'sort' => 2051,
			'text' => 'Логи импорта остатков',
			'title' => 'История запусков mf_update_supplier_stock.php',
			'icon' => 'sale_menu_icon',
			'page_icon' => 'sale_menu_icon',
			'items_id' => 'menu_mf_stock_import_log',
			'url' => $url,
			'more_url' => [
				'mf_stock_import_log.php',
			],
		];
	}
}

if (class_exists(\Bitrix\Main\EventManager::class))
{
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', 'OnBuildGlobalMenu', 'mf_admin_menu_stock_import_logs');
}

// Admin menu: "Магазин" -> купон «скидка на корзину» (правило + промокод)
if (!function_exists('mf_admin_menu_order_coupon'))
{
	function mf_admin_menu_order_coupon(array &$aGlobalMenu, array &$aModuleMenu): void
	{
		if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
		{
			return;
		}

		$url = 'mf_sale_order_coupon.php?lang=' . (defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru');
		$parentMenu =
			(isset($aGlobalMenu['global_menu_store']) ? 'global_menu_store' :
				(isset($aGlobalMenu['global_menu_sale']) ? 'global_menu_sale' : 'global_menu_content'));

		$aModuleMenu[] = [
			'parent_menu' => $parentMenu,
			'section' => 'mf_sale',
			'sort' => 2045,
			'text' => 'Купон: скидка на заказ',
			'title' => 'Создать правило корзины с промокодом (скидка на весь заказ)',
			'icon' => 'sale_menu_icon',
			'page_icon' => 'sale_menu_icon',
			'items_id' => 'menu_mf_sale_order_coupon',
			'url' => $url,
			'more_url' => [
				'mf_sale_order_coupon.php',
			],
		];
	}
}

// Admin menu: "Магазин" -> внешние прайсы (CSV), карта склад↔тип цены, история
if (!function_exists('mf_admin_menu_external_price_upload'))
{
	function mf_admin_menu_external_price_upload(array &$aGlobalMenu, array &$aModuleMenu): void
	{
		if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
		{
			return;
		}

		$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
		$parentMenu =
			(isset($aGlobalMenu['global_menu_store']) ? 'global_menu_store' :
				(isset($aGlobalMenu['global_menu_sale']) ? 'global_menu_sale' : 'global_menu_content'));

		$aModuleMenu[] = [
			'parent_menu' => $parentMenu,
			'section' => 'mf_stock_import',
			'sort' => 2052,
			'text' => 'Загрузка внешних прайсов',
			'title' => 'Импорт цен из CSV по складу (Производитель;Артикул;Наименование;Цена)',
			'icon' => 'sale_menu_icon',
			'page_icon' => 'sale_menu_icon',
			'items_id' => 'menu_mf_external_price_upload',
			'url' => 'mf_external_price_upload.php?lang=' . urlencode($lang),
			'more_url' => [
				'mf_external_price_upload.php',
				'mf_external_price_history.php',
				'mf_store_price_map.php',
			],
		];
		$aModuleMenu[] = [
			'parent_menu' => $parentMenu,
			'section' => 'mf_stock_import',
			'sort' => 20525,
			'text' => 'Склады ↔ типы цен',
			'title' => 'Проверка совпадения XML_ID склада и NAME типа цены',
			'icon' => 'sale_menu_icon',
			'page_icon' => 'sale_menu_icon',
			'items_id' => 'menu_mf_store_price_map',
			'url' => 'mf_store_price_map.php?lang=' . urlencode($lang),
			'more_url' => [
				'mf_store_price_map.php',
			],
		];
		$aModuleMenu[] = [
			'parent_menu' => $parentMenu,
			'section' => 'mf_stock_import',
			'sort' => 2053,
			'text' => 'История внешних прайсов',
			'title' => 'Журнал импортов CSV (настройки и результат)',
			'icon' => 'sale_menu_icon',
			'page_icon' => 'sale_menu_icon',
			'items_id' => 'menu_mf_external_price_history',
			'url' => 'mf_external_price_history.php?lang=' . urlencode($lang),
			'more_url' => [
				'mf_external_price_history.php',
			],
		];
	}
}

if (class_exists(\Bitrix\Main\EventManager::class))
{
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', 'OnBuildGlobalMenu', 'mf_admin_menu_order_coupon');
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', 'OnBuildGlobalMenu', 'mf_admin_menu_external_price_upload');
}

// === SEO sync (static pages from top menu, excluding /products/*) ===
if (!function_exists('mf_seo_escape_attr'))
{
	function mf_seo_escape_attr(string $s): string
	{
		if (function_exists('htmlspecialcharsbx'))
		{
			return (string)htmlspecialcharsbx($s);
		}
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}

if (!function_exists('mf_seo_base_url'))
{
	function mf_seo_base_url(): string
	{
		$https =
			(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
			|| (!empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https')
			|| (!empty($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443');

		$scheme = $https ? 'https' : 'http';
		$host = (string)($_SERVER['HTTP_HOST'] ?? '');
		if ($host === '')
		{
			// Fallback for unusual env (CLI): canonical будет относительным.
			return '';
		}
		return $scheme . '://' . $host;
	}
}

if (!function_exists('mf_seo_normalize_path'))
{
	function mf_seo_normalize_path(string $path): string
	{
		$path = trim($path);
		if ($path === '')
		{
			return '/';
		}
		if ($path[0] !== '/')
		{
			$path = '/' . $path;
		}
		// Нормализуем на “директории” для статических страниц.
		if ($path !== '/' && !str_ends_with($path, '/'))
		{
			$path .= '/';
		}
		return $path;
	}
}

if (!function_exists('mf_seo_map'))
{
	function mf_seo_map(): array
	{
		static $map = null;
		if (is_array($map))
		{
			return $map;
		}

		$docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
		if ($docRoot === '')
		{
			$docRoot = dirname(__DIR__, 2);
		}

		$file = $docRoot . '/mf_seo_map.php';
		if (is_file($file))
		{
			$tmp = include $file;
			if (is_array($tmp))
			{
				$map = $tmp;
				return $map;
			}
		}

		$map = [];
		return $map;
	}
}

if (!function_exists('mf_seo_apply_for_path'))
{
	function mf_seo_apply_for_path(string $path): void
	{
		global $APPLICATION;
		if (!is_object($APPLICATION))
		{
			return;
		}

		$path = mf_seo_normalize_path($path);
		// Магазин/каталог исключаем полностью.
		if (str_starts_with($path, '/products/'))
		{
			return;
		}

		$map = mf_seo_map();
		if (!isset($map[$path]) || !is_array($map[$path]))
		{
			return;
		}
		$seo = $map[$path];

		$APPLICATION->SetPageProperty('title', (string)($seo['title'] ?? ''));
		$APPLICATION->SetPageProperty('description', (string)($seo['description'] ?? ''));
		$APPLICATION->SetPageProperty('keywords', (string)($seo['keywords'] ?? ''));

		$base = mf_seo_base_url();
		$canon = $base !== '' ? ($base . $path) : $path;
		$APPLICATION->AddHeadString('<link rel="canonical" href="' . mf_seo_escape_attr($canon) . '" />', true);

		// OG: копируем 1-в-1, но og:url всегда делаем на наш домен + путь.
		if (isset($seo['og']) && is_array($seo['og']))
		{
			foreach ($seo['og'] as $prop => $val)
			{
				$prop = (string)$prop;
				if ($prop === '' || !str_starts_with($prop, 'og:'))
				{
					continue;
				}
				$val = (string)$val;
				if ($prop === 'og:url')
				{
					$val = $canon;
				}
				$APPLICATION->AddHeadString(
					'<meta property="' . mf_seo_escape_attr($prop) . '" content="' . mf_seo_escape_attr($val) . '" />',
					true
				);
			}
		}
	}
}

if (!function_exists('mf_seo_apply_for_current_page'))
{
	function mf_seo_apply_for_current_page(): void
	{
		global $APPLICATION;
		if (!is_object($APPLICATION))
		{
			return;
		}
		$path = method_exists($APPLICATION, 'GetCurPage') ? (string)$APPLICATION->GetCurPage(false) : (string)($_SERVER['REQUEST_URI'] ?? '/');
		$path = preg_replace('~\\?.*$~', '', $path);
		mf_seo_apply_for_path((string)$path);
	}
}

// === Delivery services (custom calculators) ===
// sale.order.ajax will use these classes to calculate delivery price.
$mfDeliveryFile = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/mf_delivery_tariffed.php';
if (
	is_file($mfDeliveryFile)
	&& class_exists(\Bitrix\Main\Loader::class)
	&& \Bitrix\Main\Loader::includeModule('sale')
	&& class_exists(\Bitrix\Sale\Delivery\Services\Base::class)
)
{
	require_once $mfDeliveryFile;
}

// === Users: customer type (retail / wholesale) ===
if (!function_exists('mf_ensure_user_customer_type_field'))
{
	function mf_ensure_user_customer_type_field(): void
	{
		// Run once: guard with an option (but still verify existence if option wasn't set).
		$optKey = 'mf_user_field_customer_type_installed';
		if (class_exists(\Bitrix\Main\Config\Option::class))
		{
			try
			{
				if (\Bitrix\Main\Config\Option::get('main', $optKey, 'N') === 'Y')
				{
					return;
				}
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}

		if (!class_exists(\CUserTypeEntity::class) || !class_exists(\CUserFieldEnum::class))
		{
			return;
		}

		$fieldName = 'UF_MF_CUSTOMER_TYPE';
		$entityId = 'USER';

		$existing = \CUserTypeEntity::GetList([], [
			'ENTITY_ID' => $entityId,
			'FIELD_NAME' => $fieldName,
		])->Fetch();

		$fieldId = (int)($existing['ID'] ?? 0);
		if ($fieldId <= 0)
		{
			$ute = new \CUserTypeEntity();
			$fieldId = (int)$ute->Add([
				'ENTITY_ID' => $entityId,
				'FIELD_NAME' => $fieldName,
				'USER_TYPE_ID' => 'enumeration',
				'SORT' => 500,
				'MULTIPLE' => 'N',
				'MANDATORY' => 'N',
				'SHOW_FILTER' => 'I',
				'SHOW_IN_LIST' => 'Y',
				'EDIT_IN_LIST' => 'Y',
				'IS_SEARCHABLE' => 'N',
				'SETTINGS' => [
					'DISPLAY' => 'LIST',
					'LIST_HEIGHT' => 1,
				],
				'EDIT_FORM_LABEL' => [
					'ru' => 'Тип покупателя',
					'en' => 'Customer type',
				],
				'LIST_COLUMN_LABEL' => [
					'ru' => 'Тип покупателя',
					'en' => 'Customer type',
				],
				'LIST_FILTER_LABEL' => [
					'ru' => 'Тип покупателя',
					'en' => 'Customer type',
				],
			]);
		}

		if ($fieldId <= 0)
		{
			return;
		}

		// Ensure enum values exist.
		$need = [
			'retail' => ['VALUE' => 'Розничный', 'SORT' => 100, 'DEF' => 'Y', 'XML_ID' => 'retail'],
			'wholesale' => ['VALUE' => 'Оптовый', 'SORT' => 200, 'DEF' => 'N', 'XML_ID' => 'wholesale'],
		];

		$existingEnumsByXml = [];
		$existingEnums = [];
		$rs = \CUserFieldEnum::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['USER_FIELD_ID' => $fieldId]);
		while ($r = $rs->Fetch())
		{
			$existingEnums[] = $r;
			$xml = (string)($r['XML_ID'] ?? '');
			if ($xml !== '')
			{
				$existingEnumsByXml[$xml] = $r;
			}
		}

		$values = [];
		foreach ($existingEnums as $r)
		{
			$id = (int)($r['ID'] ?? 0);
			if ($id <= 0)
			{
				continue;
			}
			$values[$id] = [
				'ID' => $id,
				'VALUE' => (string)($r['VALUE'] ?? ''),
				'SORT' => (int)($r['SORT'] ?? 100),
				'DEF' => (string)($r['DEF'] ?? 'N'),
				'XML_ID' => (string)($r['XML_ID'] ?? ''),
			];
		}

		$changed = false;
		foreach ($need as $xml => $v)
		{
			if (isset($existingEnumsByXml[$xml]))
			{
				$id = (int)$existingEnumsByXml[$xml]['ID'];
				if ($id > 0)
				{
					// Normalize required values.
					$cur = $values[$id] ?? [];
					$norm = [
						'ID' => $id,
						'VALUE' => $v['VALUE'],
						'SORT' => (int)$v['SORT'],
						'DEF' => (string)$v['DEF'],
						'XML_ID' => (string)$v['XML_ID'],
					];
					if ($cur !== $norm)
					{
						$values[$id] = $norm;
						$changed = true;
					}
				}
			}
			else
			{
				$values['n' . $xml] = $v;
				$changed = true;
			}
		}

		if ($changed)
		{
			$enum = new \CUserFieldEnum();
			$enum->SetEnumValues($fieldId, $values);
		}

		if (class_exists(\Bitrix\Main\Config\Option::class))
		{
			try
			{
				\Bitrix\Main\Config\Option::set('main', $optKey, 'Y');
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}
	}
}

// Create the field once (safe/idempotent).
mf_ensure_user_customer_type_field();

// === Stores: markup percent per store (warehouse) ===
if (!function_exists('mf_ensure_store_markup_field'))
{
	function mf_ensure_store_markup_field(): void
	{
		$optKey = 'mf_store_field_markup_pct_installed';

		if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('catalog'))
		{
			return;
		}

		if (class_exists(\Bitrix\Main\Config\Option::class))
		{
			try
			{
				if (\Bitrix\Main\Config\Option::get('main', $optKey, 'N') === 'Y')
				{
					return;
				}
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}

		if (!class_exists(\CUserTypeEntity::class))
		{
			return;
		}

		$entityId = \Bitrix\Catalog\StoreTable::getUfId(); // CAT_STORE
		$fieldName = 'UF_MF_MARKUP_PCT';

		$existing = \CUserTypeEntity::GetList([], [
			'ENTITY_ID' => $entityId,
			'FIELD_NAME' => $fieldName,
		])->Fetch();

		if ($existing && (int)($existing['ID'] ?? 0) > 0)
		{
			// already exists
		}
		else
		{
			$ute = new \CUserTypeEntity();
			$id = (int)$ute->Add([
				'ENTITY_ID' => $entityId,
				'FIELD_NAME' => $fieldName,
				'USER_TYPE_ID' => 'double',
				'SORT' => 300,
				'MULTIPLE' => 'N',
				'MANDATORY' => 'N',
				'SHOW_FILTER' => 'I',
				'SHOW_IN_LIST' => 'Y',
				'EDIT_IN_LIST' => 'Y',
				'IS_SEARCHABLE' => 'N',
				'SETTINGS' => [
					'DEFAULT_VALUE' => 0,
					'PRECISION' => 2,
					'SIZE' => 6,
				],
				'EDIT_FORM_LABEL' => [
					'ru' => 'Наценка, %',
					'en' => 'Markup, %',
				],
				'LIST_COLUMN_LABEL' => [
					'ru' => 'Наценка, %',
					'en' => 'Markup, %',
				],
				'LIST_FILTER_LABEL' => [
					'ru' => 'Наценка, %',
					'en' => 'Markup, %',
				],
			]);
			if ($id <= 0)
			{
				return;
			}
		}

		if (class_exists(\Bitrix\Main\Config\Option::class))
		{
			try
			{
				\Bitrix\Main\Config\Option::set('main', $optKey, 'Y');
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}
	}
}

mf_ensure_store_markup_field();

// Stores: min/max delivery days (shown on product as "N–M дней" when set).
if (!function_exists('mf_ensure_store_delivery_days_fields'))
{
	function mf_ensure_store_delivery_days_fields(): void
	{
		$optKey = 'mf_store_field_delivery_days_installed';

		if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('catalog'))
		{
			return;
		}

		if (class_exists(\Bitrix\Main\Config\Option::class))
		{
			try
			{
				if (\Bitrix\Main\Config\Option::get('main', $optKey, 'N') === 'Y')
				{
					return;
				}
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}

		if (!class_exists(\CUserTypeEntity::class))
		{
			return;
		}

		$entityId = \Bitrix\Catalog\StoreTable::getUfId();

		$ensureInt = static function (string $fieldName, int $sort, array $labels) use ($entityId): void {
			$ex = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId, 'FIELD_NAME' => $fieldName])->Fetch();
			if ($ex && (int)($ex['ID'] ?? 0) > 0)
			{
				return;
			}
			$ute = new \CUserTypeEntity();
			$ute->Add([
				'ENTITY_ID' => $entityId,
				'FIELD_NAME' => $fieldName,
				'USER_TYPE_ID' => 'integer',
				'SORT' => $sort,
				'MULTIPLE' => 'N',
				'MANDATORY' => 'N',
				'SHOW_FILTER' => 'I',
				'SHOW_IN_LIST' => 'Y',
				'EDIT_IN_LIST' => 'Y',
				'IS_SEARCHABLE' => 'N',
				'SETTINGS' => [
					'DEFAULT_VALUE' => '',
				],
				'EDIT_FORM_LABEL' => $labels,
				'LIST_COLUMN_LABEL' => $labels,
				'LIST_FILTER_LABEL' => $labels,
			]);
		};

		$ensureInt('UF_MF_DELIVERY_DAYS_MIN', 305, [
			'ru' => 'Доставка: мин. дней',
			'en' => 'Delivery: min days',
		]);
		$ensureInt('UF_MF_DELIVERY_DAYS_MAX', 306, [
			'ru' => 'Доставка: макс. дней',
			'en' => 'Delivery: max days',
		]);

		if (class_exists(\Bitrix\Main\Config\Option::class))
		{
			try
			{
				\Bitrix\Main\Config\Option::set('main', $optKey, 'Y');
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}
	}
}

mf_ensure_store_delivery_days_fields();

if (!function_exists('mf_store_decl_days_ru'))
{
	function mf_store_decl_days_ru(int $n): string
	{
		$n = abs($n) % 100;
		$n1 = abs($n) % 10;
		if ($n >= 11 && $n <= 14)
		{
			return 'дней';
		}
		if ($n1 === 1)
		{
			return 'день';
		}
		if ($n1 >= 2 && $n1 <= 4)
		{
			return 'дня';
		}

		return 'дней';
	}
}

// Backfill store markup to 0 for existing stores (so admin UI isn't empty).
if (!function_exists('mf_backfill_store_markup_defaults'))
{
	function mf_backfill_store_markup_defaults(): void
	{
		$optKey = 'mf_store_markup_pct_backfilled';

		if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('catalog'))
		{
			return;
		}

		if (!class_exists(\Bitrix\Main\Config\Option::class))
		{
			return;
		}

		try
		{
			if (\Bitrix\Main\Config\Option::get('main', $optKey, 'N') === 'Y')
			{
				return;
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}

		global $USER_FIELD_MANAGER;
		if (!is_object($USER_FIELD_MANAGER))
		{
			return;
		}

		$entityId = \Bitrix\Catalog\StoreTable::getUfId(); // CAT_STORE
		$rs = \CCatalogStore::GetList(['ID' => 'ASC'], [], false, false, ['ID']);
		while ($s = $rs->Fetch())
		{
			$storeId = (int)($s['ID'] ?? 0);
			if ($storeId <= 0) continue;

			$ufs = $USER_FIELD_MANAGER->GetUserFields($entityId, $storeId);
			$val = $ufs['UF_MF_MARKUP_PCT']['VALUE'] ?? null;
			if (is_array($val)) $val = reset($val);

			// Treat empty/false/null as "not set" and set to 0.00.
			if ($val === null || $val === false || trim((string)$val) === '')
			{
				$USER_FIELD_MANAGER->Update($entityId, $storeId, ['UF_MF_MARKUP_PCT' => 0]);
			}
		}

		try
		{
			\Bitrix\Main\Config\Option::set('main', $optKey, 'Y');
		}
		catch (\Throwable $e)
		{
			// ignore
		}
	}
}

mf_backfill_store_markup_defaults();

// === Dynamic store-based pricing (RAW per store + markup; product price=min of available stores) ===
if (!function_exists('mf_store_markup_pct'))
{
	function mf_store_markup_pct(int $storeId): float
	{
		static $cache = [];
		$storeId = (int)$storeId;
		if ($storeId <= 0) return 0.0;
		if (array_key_exists($storeId, $cache)) return (float)$cache[$storeId];

		$pct = 0.0;
		global $USER_FIELD_MANAGER;
		if (is_object($USER_FIELD_MANAGER) && class_exists(\Bitrix\Catalog\StoreTable::class))
		{
			$ufs = $USER_FIELD_MANAGER->GetUserFields(\Bitrix\Catalog\StoreTable::getUfId(), $storeId);
			$v = $ufs['UF_MF_MARKUP_PCT']['VALUE'] ?? 0;
			if (is_array($v)) $v = reset($v);
			$v = str_replace(',', '.', (string)$v);
			$pct = (float)$v;
			if (!is_finite($pct)) $pct = 0.0;
		}

		$cache[$storeId] = $pct;
		return $pct;
	}
}

if (!function_exists('mf_apply_markup'))
{
	function mf_apply_markup(float $rawPrice, float $pct): float
	{
		if ($rawPrice <= 0) return 0.0;
		if ($pct == 0.0) return round($rawPrice, 2);
		return round($rawPrice * (1.0 + ($pct / 100.0)), 2);
	}
}

if (!function_exists('mf_supplier_store_to_price_group_reset'))
{
	function mf_supplier_store_to_price_group_reset(): void
	{
		unset($GLOBALS['MF_SUPPLIER_STORE_TO_PRICE_GROUP']);
	}
}

if (!function_exists('mf_catalog_xml_price_group_name_candidates'))
{
	/**
	 * Варианты CCatalogGroup.NAME для одного XML_ID склада (часто расходятся SUPPLIER_XXX и XXX).
	 *
	 * @return string[]
	 */
	function mf_catalog_xml_price_group_name_candidates(string $xmlRaw): array
	{
		$u = mb_strtoupper(trim($xmlRaw));
		if ($u === '')
		{
			return [];
		}
		$out = [$u];
		$p = 'SUPPLIER_';
		$lenP = mb_strlen($p);
		if (mb_substr($u, 0, $lenP) === $p)
		{
			$rest = mb_substr($u, $lenP);
			if ($rest !== '')
			{
				$out[] = $rest;
			}
		}
		else
		{
			$out[] = $p . $u;
		}

		return array_values(array_unique($out));
	}
}

if (!function_exists('mf_catalog_group_id_by_store_xml_candidates'))
{
	/**
	 * Подобрать ID типа цены по XML_ID склада, перебирая допустимые варианты имени.
	 */
	function mf_catalog_group_id_by_store_xml_candidates(string $xmlRaw): int
	{
		if (!class_exists(\CCatalogGroup::class))
		{
			return 0;
		}
		foreach (mf_catalog_xml_price_group_name_candidates($xmlRaw) as $name)
		{
			$name = mb_strtoupper(trim((string)$name));
			if ($name === '')
			{
				continue;
			}
			$r = \CCatalogGroup::GetList([], ['=NAME' => $name], false, false, ['ID'])->Fetch();
			$gid = (int)($r['ID'] ?? 0);
			if ($gid > 0)
			{
				return $gid;
			}
		}

		return 0;
	}
}

if (!function_exists('mf_supplier_store_to_price_group'))
{
	/**
	 * Map storeId => catalog group id: group NAME совпадает с XML_ID склада (или альтернативой SUPPLIER_*).
	 * Учитываются все склады, у которых есть соответствующий тип цены (в т.ч. внешние прайсы).
	 */
	function mf_supplier_store_to_price_group(): array
	{
		$key = 'MF_SUPPLIER_STORE_TO_PRICE_GROUP';
		if (isset($GLOBALS[$key]) && is_array($GLOBALS[$key]))
		{
			return $GLOBALS[$key];
		}
		$cache = [];

		if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('catalog'))
		{
			$GLOBALS[$key] = $cache;

			return $cache;
		}

		$rs = \CCatalogStore::GetList(['ID' => 'ASC'], [], false, false, ['ID', 'XML_ID']);
		while ($s = $rs->Fetch())
		{
			$storeId = (int)($s['ID'] ?? 0);
			$xmlRaw = trim((string)($s['XML_ID'] ?? ''));
			if ($storeId <= 0 || $xmlRaw === '')
			{
				continue;
			}
			$gid = function_exists('mf_catalog_group_id_by_store_xml_candidates')
				? mf_catalog_group_id_by_store_xml_candidates($xmlRaw)
				: 0;
			if ($gid > 0)
			{
				$cache[$storeId] = $gid;
			}
		}
		$GLOBALS[$key] = $cache;

		return $cache;
	}
}

if (!function_exists('mf_catalog_product_cluster_ids'))
{
	/**
	 * ID элемента каталога для агрегации: родитель SKU + его офферы, или оффер + родитель.
	 * Нужно, чтобы остатки и цены совпадали с тем, как импорты пишут на родителя или на SKU.
	 */
	function mf_catalog_product_cluster_ids(int $elementId): array
	{
		static $cache = [];
		$elementId = (int)$elementId;
		if ($elementId <= 0)
		{
			return [];
		}
		if (isset($cache[$elementId]))
		{
			return $cache[$elementId];
		}

		$ids = [$elementId];
		if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('catalog'))
		{
			$cache[$elementId] = $ids;

			return $ids;
		}

		if (!class_exists(\Bitrix\Catalog\ProductTable::class))
		{
			$cache[$elementId] = $ids;

			return $ids;
		}

		$row = \Bitrix\Catalog\ProductTable::getRow([
			'filter' => ['=ID' => $elementId],
			'select' => ['ID', 'TYPE'],
		]);
		if (!$row)
		{
			$cache[$elementId] = $ids;

			return $ids;
		}

		$type = (int)($row['TYPE'] ?? 0);
		if ($type === \Bitrix\Catalog\ProductTable::TYPE_SKU && class_exists(\CCatalogSKU::class))
		{
			$iblockId = (int)\CIBlockElement::GetIBlockByID($elementId);
			if ($iblockId > 0)
			{
				$list = \CCatalogSKU::getOffersList($elementId, $iblockId, [], [], [], [], ['ID' => 'ASC']);
				if (!empty($list[$elementId]) && is_array($list[$elementId]))
				{
					foreach ($list[$elementId] as $offerRow)
					{
						$oid = (int)($offerRow['ID'] ?? 0);
						if ($oid > 0)
						{
							$ids[] = $oid;
						}
					}
				}
			}
		}
		elseif ($type === \Bitrix\Catalog\ProductTable::TYPE_OFFER && class_exists(\CCatalogSKU::class))
		{
			$info = \CCatalogSKU::GetProductInfo($elementId);
			if (is_array($info) && !empty($info['ID']))
			{
				$pid = (int)$info['ID'];
				if ($pid > 0 && $pid !== $elementId)
				{
					$ids[] = $pid;
				}
			}
		}

		$ids = array_values(array_unique(array_filter($ids, static fn($v) => (int)$v > 0)));
		$cache[$elementId] = $ids;

		return $ids;
	}
}

if (!function_exists('mf_product_distinct_store_ids_clustered'))
{
	/**
	 * Уникальные STORE_ID по всем строкам b_catalog_store_product для кластера товара (родитель + SKU).
	 */
	function mf_product_distinct_store_ids_clustered(int $productId): array
	{
		$productId = (int)$productId;
		if ($productId <= 0 || !class_exists(\CCatalogStoreProduct::class))
		{
			return [];
		}
		if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('catalog'))
		{
			return [];
		}

		$clusterIds = function_exists('mf_catalog_product_cluster_ids')
			? mf_catalog_product_cluster_ids($productId)
			: [$productId];
		$seen = [];
		foreach ($clusterIds as $cid)
		{
			$cid = (int)$cid;
			if ($cid <= 0)
			{
				continue;
			}
			$rs = \CCatalogStoreProduct::GetList([], ['PRODUCT_ID' => $cid], false, false, ['STORE_ID']);
			while ($r = $rs->Fetch())
			{
				$sid = (int)($r['STORE_ID'] ?? 0);
				if ($sid > 0)
				{
					$seen[$sid] = true;
				}
			}
		}

		return array_keys($seen);
	}
}

if (!function_exists('mf_product_single_external_store_only'))
{
	/**
	 * Ровно один склад в остатках и у него UF «Внешний склад» (mf_ep_store_is_external_warehouse).
	 */
	function mf_product_single_external_store_only(int $productId): bool
	{
		$ids = mf_product_distinct_store_ids_clustered($productId);
		if (count($ids) !== 1)
		{
			return false;
		}
		$sid = (int)$ids[0];

		return function_exists('mf_ep_store_is_external_warehouse') && mf_ep_store_is_external_warehouse($sid);
	}
}

if (!function_exists('mf_product_prices_by_group'))
{
	function mf_product_prices_by_group(int $productId): array
	{
		static $cache = [];
		$productId = (int)$productId;
		if ($productId <= 0) return [];
		if (isset($cache[$productId])) return $cache[$productId];

		$map = [];
		if (class_exists(\Bitrix\Catalog\PriceTable::class))
		{
			$rs = \Bitrix\Catalog\PriceTable::getList([
				'filter' => ['=PRODUCT_ID' => $productId],
				'select' => ['CATALOG_GROUP_ID', 'PRICE'],
			]);
			while ($p = $rs->fetch())
			{
				$gid = (int)$p['CATALOG_GROUP_ID'];
				$map[$gid] = (float)$p['PRICE'];
			}
		}
		elseif (class_exists(\CPrice::class))
		{
			$rs = \CPrice::GetList([], ['PRODUCT_ID' => $productId], false, false, ['CATALOG_GROUP_ID', 'PRICE']);
			while ($p = $rs->Fetch())
			{
				$gid = (int)$p['CATALOG_GROUP_ID'];
				$map[$gid] = (float)$p['PRICE'];
			}
		}
		$cache[$productId] = $map;
		return $map;
	}
}

if (!function_exists('mf_store_row'))
{
	function mf_store_row(int $storeId): ?array
	{
		static $cache = [];
		$storeId = (int)$storeId;
		if ($storeId <= 0) return null;
		if (array_key_exists($storeId, $cache)) return $cache[$storeId];

		$row = null;
		if (class_exists(\CCatalogStore::class))
		{
			$row = \CCatalogStore::GetList([], ['ID' => $storeId], false, false, ['ID', 'TITLE', 'CODE', 'XML_ID'])->Fetch();
		}
		$cache[$storeId] = $row ?: null;
		return $cache[$storeId];
	}
}

if (!function_exists('mf_calc_store_price'))
{
	/**
	 * Computed price for конкретного склада: RAW(store price type) + markup(store UF).
	 * Returns null if no raw price.
	 */
	function mf_calc_store_price(int $productId, int $storeId): ?float
	{
		$productId = (int)$productId;
		$storeId = (int)$storeId;
		if ($productId <= 0 || $storeId <= 0) return null;

		$storeToGroup = mf_supplier_store_to_price_group();
		$gid = (int)($storeToGroup[$storeId] ?? 0);
		if ($gid <= 0) return null;

		$raw = 0.0;
		foreach (mf_catalog_product_cluster_ids($productId) as $cid)
		{
			$prices = mf_product_prices_by_group((int)$cid);
			$raw = (float)($prices[$gid] ?? 0);
			if ($raw > 0)
			{
				break;
			}
		}
		if ($raw <= 0) return null;

		$pct = mf_store_markup_pct($storeId);
		$computed = mf_apply_markup($raw, $pct);
		return ($computed > 0 ? $computed : null);
	}
}

if (!function_exists('mf_min_price_from_available_stores'))
{
	/**
	 * Returns [minPrice, storeId] among stores with AMOUNT>0.
	 */
	function mf_min_price_from_available_stores(int $productId): array
	{
		static $cache = [];
		$productId = (int)$productId;
		if ($productId <= 0) return [null, 0];
		if (isset($cache[$productId])) return $cache[$productId];

		if (!class_exists(\CCatalogStoreProduct::class)) return [null, 0];

		$storeToGroup = mf_supplier_store_to_price_group();
		if (empty($storeToGroup)) return [null, 0];

		$min = null;
		$minStoreId = 0;

		$byStore = [];
		foreach (mf_catalog_product_cluster_ids($productId) as $cid)
		{
			$rs = \CCatalogStoreProduct::GetList([], ['PRODUCT_ID' => (int)$cid, '>AMOUNT' => 0], false, false, ['STORE_ID', 'AMOUNT']);
			while ($r = $rs->Fetch())
			{
				$sid = (int)($r['STORE_ID'] ?? 0);
				if ($sid <= 0)
				{
					continue;
				}
				$byStore[$sid] = ($byStore[$sid] ?? 0.0) + (float)($r['AMOUNT'] ?? 0);
			}
		}

		foreach ($byStore as $storeId => $amt)
		{
			if ($storeId <= 0 || $amt <= 0)
			{
				continue;
			}
			if (!isset($storeToGroup[$storeId]))
			{
				continue;
			}

			$computed = mf_calc_store_price($productId, $storeId);
			if ($computed === null) continue;

			if ($min === null || $computed < $min)
			{
				$min = $computed;
				$minStoreId = $storeId;
			}
		}

		$cache[$productId] = [$min, $minStoreId];
		return $cache[$productId];
	}
}

if (!function_exists('mf_store_delivery_term'))
{
	function mf_store_delivery_term(int $storeId): string
	{
		$storeId = (int)$storeId;
		if ($storeId <= 0)
		{
			return 'Срок уточнит менеджер';
		}

		global $USER_FIELD_MANAGER;
		if (is_object($USER_FIELD_MANAGER) && class_exists(\Bitrix\Catalog\StoreTable::class))
		{
			try
			{
				$ufs = $USER_FIELD_MANAGER->GetUserFields(\Bitrix\Catalog\StoreTable::getUfId(), $storeId);
				$min = $ufs['UF_MF_DELIVERY_DAYS_MIN']['VALUE'] ?? null;
				$max = $ufs['UF_MF_DELIVERY_DAYS_MAX']['VALUE'] ?? null;
				if (is_array($min))
				{
					$min = reset($min);
				}
				if (is_array($max))
				{
					$max = reset($max);
				}
				$min = (int)$min;
				$max = (int)$max;

				if ($min > 0 || $max > 0)
				{
					if ($min > 0 && $max > 0)
					{
						$lo = min($min, $max);
						$hi = max($min, $max);
						if ($lo === $hi)
						{
							return (string)$lo . ' ' . mf_store_decl_days_ru($lo);
						}

						return (string)$lo . '–' . (string)$hi . ' ' . mf_store_decl_days_ru($hi);
					}
					if ($min > 0)
					{
						return 'от ' . (string)$min . ' ' . mf_store_decl_days_ru($min);
					}

					return 'до ' . (string)$max . ' ' . mf_store_decl_days_ru($max);
				}
			}
			catch (\Throwable $e)
			{
				// fall through to legacy
			}
		}

		$s = function_exists('mf_store_row') ? mf_store_row($storeId) : null;
		if (!is_array($s))
		{
			return 'Срок уточнит менеджер';
		}

		$code = mb_strtoupper(trim((string)($s['CODE'] ?? '')));
		$xml = mb_strtoupper(trim((string)($s['XML_ID'] ?? '')));

		if ($code === 'MOTOR_FORCE_INTERNAL' || mb_strpos($xml, 'MOTOR_FORCE_INTERNAL') !== false)
		{
			return '1-2 дня';
		}
		if ($xml !== '' && mb_strpos($xml, 'SUPPLIER_') === 0)
		{
			return '1-4 дня';
		}

		return 'Срок уточнит менеджер';
	}
}

if (!function_exists('mf_product_available_stores_for_qty'))
{
	/**
	 * @return array<int, array{store_id:int,title:string,code:string,xml_id:string,amount:float,price:float,price_fmt:string,delivery_term:string,delivery_spb_ok:bool,delivery_spb_title:string}>
	 */
	function mf_product_available_stores_for_qty(int $productId, float $qty = 1.0): array
	{
		$out = [];
		$productId = (int)$productId;
		$qty = (float)$qty;
		if ($productId <= 0)
		{
			return $out;
		}
		if ($qty <= 0)
		{
			$qty = 1.0;
		}
		if (!class_exists(\CCatalogStoreProduct::class))
		{
			return $out;
		}

		$byStore = [];
		foreach (mf_catalog_product_cluster_ids($productId) as $cid)
		{
			$rs = \CCatalogStoreProduct::GetList([], ['PRODUCT_ID' => (int)$cid, '>AMOUNT' => 0], false, false, ['STORE_ID', 'AMOUNT']);
			while ($r = $rs->Fetch())
			{
				$sid = (int)($r['STORE_ID'] ?? 0);
				if ($sid <= 0)
				{
					continue;
				}
				$byStore[$sid] = ($byStore[$sid] ?? 0.0) + (float)($r['AMOUNT'] ?? 0);
			}
		}

		foreach ($byStore as $storeId => $amount)
		{
			if ($storeId <= 0 || $amount + 1e-9 < $qty)
			{
				continue;
			}

			$price = function_exists('mf_calc_store_price') ? mf_calc_store_price($productId, $storeId) : null;
			if ($price === null || $price <= 0)
			{
				continue;
			}
			if (function_exists('mf_user_is_wholesale') && mf_user_is_wholesale())
			{
				$price = round((float)$price * 0.9, 2);
			}

			$s = function_exists('mf_store_row') ? mf_store_row($storeId) : null;
			$title = trim((string)($s['TITLE'] ?? ''));
			if ($title === '')
			{
				$title = 'Склад #' . $storeId;
			}

			$spb = function_exists('mf_store_delivery_spb_ui') ? mf_store_delivery_spb_ui($storeId) : ['ok' => true, 'title' => 'Доставка до склада СПб включена'];

			$out[] = [
				'store_id' => $storeId,
				'title' => $title,
				'code' => (string)($s['CODE'] ?? ''),
				'xml_id' => (string)($s['XML_ID'] ?? ''),
				'amount' => $amount,
				'price' => (float)$price,
				'price_fmt' => number_format((float)$price, 2, '.', ' ') . ' ₽',
				'delivery_term' => function_exists('mf_store_delivery_term') ? mf_store_delivery_term($storeId) : 'Срок уточнит менеджер',
				'delivery_spb_ok' => !empty($spb['ok']),
				'delivery_spb_title' => (string)($spb['title'] ?? ''),
			];
		}

		usort($out, static function (array $a, array $b): int {
			$pa = (float)($a['price'] ?? 0);
			$pb = (float)($b['price'] ?? 0);
			if ($pa > 0 && $pb > 0 && abs($pa - $pb) > 1e-9)
			{
				return $pa <=> $pb;
			}
			return (int)($a['store_id'] ?? 0) <=> (int)($b['store_id'] ?? 0);
		});

		return $out;
	}
}

// === Wholesale: show and charge -10% vs retail ===
if (!function_exists('mf_user_is_wholesale'))
{
	function mf_user_is_wholesale(): bool
	{
		static $cache = null;
		if ($cache !== null)
		{
			return (bool)$cache;
		}

		global $USER;
		if (!is_object($USER) || !$USER->IsAuthorized())
		{
			$cache = false;
			return false;
		}

		$userId = (int)$USER->GetID();
		if ($userId <= 0)
		{
			$cache = false;
			return false;
		}

		if (!class_exists(\CUser::class) || !class_exists(\CUserFieldEnum::class) || !class_exists(\CUserTypeEntity::class))
		{
			$cache = false;
			return false;
		}

		// Load the enum ID from user field.
		$by = 'ID';
		$order = 'ASC';
		$rs = \CUser::GetList($by, $order, ['ID' => $userId], ['SELECT' => ['UF_MF_CUSTOMER_TYPE']]);
		$u = $rs ? $rs->Fetch() : null;
		$enumId = (int)($u['UF_MF_CUSTOMER_TYPE'] ?? 0);
		if ($enumId <= 0)
		{
			$cache = false;
			return false;
		}

		$enum = \CUserFieldEnum::GetList([], ['ID' => $enumId])->Fetch();
		$xml = (string)($enum['XML_ID'] ?? '');
		$cache = ($xml === 'wholesale');
		return (bool)$cache;
	}
}

if (!function_exists('mf_wholesale_optimal_price_result'))
{
	function mf_wholesale_optimal_price_result(array &$arResult)
	{
		if (empty($arResult['RESULT_PRICE']) || !is_array($arResult['RESULT_PRICE']))
		{
			return true;
		}

		$productId = (int)($arResult['PRODUCT_ID'] ?? 0);
		if ($productId > 0)
		{
			[$min, $minStoreId] = mf_min_price_from_available_stores($productId);
			if ($min !== null && $min > 0)
			{
				// Retail dynamic base price = min computed among available stores.
				$arResult['RESULT_PRICE']['BASE_PRICE'] = (float)$min;
				$arResult['RESULT_PRICE']['DISCOUNT_PRICE'] = (float)$min;
				$arResult['DISCOUNT_PRICE'] = (float)$min;
				$arResult['RESULT_PRICE']['DISCOUNT'] = 0.0;
				$arResult['RESULT_PRICE']['PERCENT'] = 0;

				// stash store for debug/UI if needed
				$arResult['MF_MIN_STORE_ID'] = (int)$minStoreId;
			}
		}

		// Wholesale overlay: -10% from retail dynamic price.
		if (mf_user_is_wholesale())
		{
			$rp = &$arResult['RESULT_PRICE'];
			$base = (float)($rp['BASE_PRICE'] ?? 0);
			$cur = (float)($rp['DISCOUNT_PRICE'] ?? 0);
			if ($cur > 0)
			{
				$new = round($cur * 0.9, 2);
				if ($new > 0 && $new < $cur)
				{
					$rp['DISCOUNT_PRICE'] = $new;
					$arResult['DISCOUNT_PRICE'] = $new;
					$discount = ($base > 0 ? max(0.0, $base - $new) : 0.0);
					$rp['DISCOUNT'] = $discount;
					$rp['PERCENT'] = ($base > 0 ? (int)round((100 * $discount) / $base, 0) : 0);
				}
			}
		}

		return true;
	}
}

if (class_exists(\Bitrix\Main\EventManager::class))
{
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('catalog', 'OnGetOptimalPriceResult', 'mf_wholesale_optimal_price_result');
}

// --- Checkout flow: guest/registration choice, payer type, requisites -------
$mfCheckoutInclude = __DIR__ . '/include/mf_checkout.php';
if (is_file($mfCheckoutInclude))
{
	require_once $mfCheckoutInclude;
	if (function_exists('mf_checkout_bootstrap'))
	{
		mf_checkout_bootstrap();
	}
}

// Частичное оформление заказа (отложить невыбранные позиции в корзине).
$mfCartPartialInclude = __DIR__ . '/include/mf_cart_partial.php';
if (is_file($mfCartPartialInclude))
{
	require_once $mfCartPartialInclude;
	if (class_exists(\Bitrix\Main\EventManager::class))
	{
		\Bitrix\Main\EventManager::getInstance()->addEventHandler('sale', 'OnSaleOrderSaved', 'mf_cart_partial_on_order_saved');
	}
	if (function_exists('mf_cart_partial_try_restore_from_request'))
	{
		mf_cart_partial_try_restore_from_request();
	}
}

// === Basket/order: attach chosen store + set computed price dynamically ===
if (!function_exists('mf_basket_get_prop'))
{
	function mf_basket_get_prop(\Bitrix\Sale\BasketItemBase $item, string $code): ?string
	{
		$code = (string)$code;
		$pc = $item->getPropertyCollection();
		foreach ($pc as $p)
		{
			if ((string)$p->getField('CODE') === $code)
			{
				$v = $p->getField('VALUE');
				if (is_array($v)) $v = reset($v);
				$v = trim((string)$v);
				return $v !== '' ? $v : null;
			}
		}
		return null;
	}
}

if (!function_exists('mf_basket_set_props'))
{
	function mf_basket_set_props(\Bitrix\Sale\BasketItemBase $item, array $props): void
	{
		$pc = $item->getPropertyCollection();

		// index existing by CODE
		$byCode = [];
		foreach ($pc as $p)
		{
			$byCode[(string)$p->getField('CODE')] = $p;
		}

		foreach ($props as $code => $val)
		{
			$code = (string)$code;
			$val = (string)$val;
			if ($code === '') continue;

			if (isset($byCode[$code]))
			{
				$byCode[$code]->setField('VALUE', $val);
				continue;
			}

			$pi = $pc->createItem();
			$pi->setFields([
				'NAME' => $code,
				'CODE' => $code,
				'VALUE' => $val,
				'SORT' => 1000,
			]);
		}
	}
}

if (!function_exists('mf_catalog_brand_article_by_product_id'))
{
	/**
	 * Бренд и артикул из карточки товара (инфоблок), если в позиции корзины/заказа они не продублированы в b_sale_basket_props.
	 * Учитывает связку торговое предложение → товар (SKU).
	 *
	 * @return array{brand: string, article: string}
	 */
	function mf_catalog_brand_article_by_product_id(int $productId): array
	{
		static $cache = [];
		if ($productId <= 0)
		{
			return ['brand' => '', 'article' => ''];
		}
		if (array_key_exists($productId, $cache))
		{
			return $cache[$productId];
		}

		$empty = ['brand' => '', 'article' => ''];
		if (!class_exists(\CIBlockElement::class) || !\Bitrix\Main\Loader::includeModule('iblock'))
		{
			$cache[$productId] = $empty;

			return $empty;
		}

		$select = [
			'ID',
			'IBLOCK_ID',
			'PROPERTY_CML2_ARTICLE',
			'PROPERTY_MF_BRAND',
			'PROPERTY_MF_BRAND_NORM',
			'PROPERTY_MF_ARTICLE_NORM',
			'PROPERTY_ARTNUMBER',
		];

		$pick = static function (array $row): array {
			$article = trim((string)($row['PROPERTY_CML2_ARTICLE_VALUE'] ?? ''));
			if ($article === '')
			{
				$article = trim((string)($row['PROPERTY_MF_ARTICLE_NORM_VALUE'] ?? ''));
			}
			if ($article === '')
			{
				$article = trim((string)($row['PROPERTY_ARTNUMBER_VALUE'] ?? ''));
			}
			$brand = trim((string)($row['PROPERTY_MF_BRAND_VALUE'] ?? ''));
			if ($brand === '')
			{
				$brand = trim((string)($row['PROPERTY_MF_BRAND_NORM_VALUE'] ?? ''));
			}

			return ['brand' => $brand, 'article' => $article];
		};

		$load = static function (int $id) use ($select): ?array {
			if ($id <= 0)
			{
				return null;
			}
			$rs = \CIBlockElement::GetList([], ['ID' => $id], false, false, $select);
			$row = $rs ? $rs->GetNext() : false;

			return is_array($row) ? $row : null;
		};

		$ids = [$productId];
		if (\Bitrix\Main\Loader::includeModule('catalog'))
		{
			$info = \CCatalogSKU::GetProductInfo($productId);
			if (is_array($info) && !empty($info['ID']))
			{
				$parentId = (int)$info['ID'];
				if ($parentId > 0 && $parentId !== $productId)
				{
					$ids[] = $parentId;
				}
			}
		}

		$brand = '';
		$article = '';
		foreach ($ids as $id)
		{
			$row = $load($id);
			if ($row === null)
			{
				continue;
			}
			$p = $pick($row);
			if ($article === '' && $p['article'] !== '')
			{
				$article = $p['article'];
			}
			if ($brand === '' && $p['brand'] !== '')
			{
				$brand = $p['brand'];
			}
		}

		$out = ['brand' => $brand, 'article' => $article];
		$cache[$productId] = $out;

		return $out;
	}
}

if (!function_exists('mf_assign_store_and_price_to_basket_item'))
{
	function mf_assign_store_and_price_to_basket_item(\Bitrix\Sale\BasketItemBase $item): void
	{
		if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return;
		}
		if (!\Bitrix\Main\Loader::includeModule('catalog'))
		{
			return;
		}

		$productId = (int)$item->getProductId();
		if ($productId <= 0) return;

		// store selection: explicit MF_STORE_ID on item, else pick cheapest available.
		$storeId = (int)(mf_basket_get_prop($item, 'MF_STORE_ID') ?? 0);
		if ($storeId <= 0)
		{
			[$min, $minStoreId] = mf_min_price_from_available_stores($productId);
			$storeId = (int)$minStoreId;
		}

		if ($storeId <= 0) return;

		$computed = mf_calc_store_price($productId, $storeId);
		if ($computed === null || $computed <= 0) return;
		if (mf_user_is_wholesale())
		{
			$computed = round($computed * 0.9, 2);
		}

		if (function_exists('mf_ep_weight_surcharge_rub'))
		{
			$qty = (float)$item->getQuantity();
			if ($qty <= 0)
			{
				$qty = 1.0;
			}
			$sur = mf_ep_weight_surcharge_rub($productId, $storeId, $qty);
			if ($sur > 0)
			{
				$computed = round($computed + $sur, 2);
			}
		}

		// Ensure store props are set for order visibility.
		$s = mf_store_row($storeId);
		$props = [
			'MF_STORE_ID' => (string)$storeId,
		];
		if ($s)
		{
			$props['MF_STORE_TITLE'] = (string)($s['TITLE'] ?? '');
			$props['MF_STORE_CODE'] = (string)($s['CODE'] ?? '');
		}
		mf_basket_set_props($item, $props);

		// Не выставляем CUSTOM_PRICE=Y: в sale позиции с «ручной» ценой не участвуют в правилах корзины
		// и купонах (Discount\Actions::filterBasketForAction). Цена со склада задаётся через PRICE/BASE_PRICE.
		$item->setField('CUSTOM_PRICE', 'N');
		$item->setField('PRICE', $computed);
		$item->setField('BASE_PRICE', $computed);
		$item->setField('CURRENCY', 'RUB');
	}
}

if (!function_exists('mf_on_basket_item_before_saved'))
{
	function mf_on_basket_item_before_saved(\Bitrix\Main\Event $event)
	{
		if (defined('ADMIN_SECTION') && ADMIN_SECTION === true)
		{
			return;
		}
		$basketItem = $event->getParameter('ENTITY');
		if ($basketItem instanceof \Bitrix\Sale\BasketItemBase)
		{
			mf_assign_store_and_price_to_basket_item($basketItem);
		}
	}
}

if (!function_exists('mf_sale_order_prop_value_by_code'))
{
	/**
	 * @param \Bitrix\Sale\PropertyValueCollectionBase|null $propertyCollection
	 */
	function mf_sale_order_prop_value_by_code($propertyCollection, string $code)
	{
		if (!$propertyCollection)
		{
			return null;
		}
		if (method_exists($propertyCollection, 'getItemByOrderPropertyCode'))
		{
			return $propertyCollection->getItemByOrderPropertyCode($code);
		}
		try
		{
			foreach ($propertyCollection as $propertyValue)
			{
				if (
					$propertyValue
					&& method_exists($propertyValue, 'getField')
					&& (string)$propertyValue->getField('CODE') === $code
				)
				{
					return $propertyValue;
				}
			}
		}
		catch (\Throwable $e)
		{
		}

		return null;
	}
}

if (!function_exists('mf_on_order_before_saved'))
{
	function mf_on_order_before_saved(\Bitrix\Main\Event $event)
	{
		if (defined('ADMIN_SECTION') && ADMIN_SECTION === true)
		{
			return;
		}
		/** @var \Bitrix\Sale\Order $order */
		$order = $event->getParameter('ENTITY');
		if (!$order instanceof \Bitrix\Sale\Order)
		{
			return;
		}
		$basket = $order->getBasket();
		if (!$basket)
		{
			return;
		}
		foreach ($basket as $item)
		{
			if ($item instanceof \Bitrix\Sale\BasketItemBase)
			{
				mf_assign_store_and_price_to_basket_item($item);
			}
		}

		// Motor-Force customization:
		// Save selected eDost delivery tariff into manager comment (hidden from customer).
		// Служебные свойства: полный ответ Nominatim + город для eDost (не показываются покупателю, UTIL=Y).
		try
		{
			if (class_exists(\Bitrix\Main\Application::class))
			{
				$req = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
				$propCol = $order->getPropertyCollection();
				if ($propCol)
				{
					if ($req->getPost('MF_NOMINATIM_JSON') !== null)
					{
						$p = mf_sale_order_prop_value_by_code($propCol, 'MF_NOMINATIM_JSON');
						if ($p && method_exists($p, 'setValue'))
						{
							$p->setValue((string)$req->getPost('MF_NOMINATIM_JSON'));
						}
					}
					if ($req->getPost('MF_EDOST_TO_CITY') !== null)
					{
						$p2 = mf_sale_order_prop_value_by_code($propCol, 'MF_EDOST_TO_CITY');
						if ($p2 && method_exists($p2, 'setValue'))
						{
							$p2->setValue(trim((string)$req->getPost('MF_EDOST_TO_CITY')));
						}
					}
				}

				$tid = trim((string)$req->getPost('MF_EDOST_TARIF_ID'));
				if ($tid !== '')
				{
					$company = trim((string)$req->getPost('MF_EDOST_TARIF_COMPANY'));
					$name = trim((string)$req->getPost('MF_EDOST_TARIF_NAME'));
					$price = trim((string)$req->getPost('MF_EDOST_TARIF_PRICE'));

					$line = 'Доставка (eDost, справочно, не входит в Итого): '
						. ($company !== '' ? ($company . ' — ') : '')
						. ($name !== '' ? $name : ('тариф ' . $tid))
						. ' — ' . ($price !== '' ? ($price . ' ₽') : 'оплата при получении')
						. ' (tarif_id=' . $tid . ')';

					$comments = (string)$order->getField('COMMENTS');
					// Replace previous line if it exists (avoid duplicates on multiple saves).
					$comments = preg_replace('~^Доставка \\(eDost, справочно, не входит в Итого\\):.*$~mu', '', $comments);
					$comments = trim((string)$comments);
					$comments = trim($comments . "\n" . $line);

					$order->setField('COMMENTS', $comments);
				}
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}
	}
}

if (class_exists(\Bitrix\Main\EventManager::class))
{
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('sale', 'OnSaleBasketItemBeforeSaved', 'mf_on_basket_item_before_saved');
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('sale', 'OnSaleOrderBeforeSaved', 'mf_on_order_before_saved');
}

// --- UNF integration (orders -> 1C:UNF via HTTP API) ------------------------
// Kept in a separate file to avoid bloating init.php.
$mfUnfInclude = __DIR__ . '/include/mf_unf.php';
if (is_file($mfUnfInclude))
{
  require_once $mfUnfInclude;
  if (class_exists('\\Mf\\Unf\\Bootstrap'))
  {
    \Mf\Unf\Bootstrap::init();
  }
}

// --- Admin: "Import analogs" button on product edit -------------------------
$mfAdminAnalogsInclude = __DIR__ . '/include/mf_admin_analogs.php';
if (is_file($mfAdminAnalogsInclude))
{
	require_once $mfAdminAnalogsInclude;
}

// --- Product analogs storage (HL mf_product_analogs) ------------------------
$mfAnalogsInclude = __DIR__ . '/include/mf_analogs.php';
if (is_file($mfAnalogsInclude))
{
	require_once $mfAnalogsInclude;
}

// --- Catalog visibility flag (MF_SHOW_IN_CATALOG) ---------------------------
$mfCatalogVisibilityInclude = __DIR__ . '/include/mf_catalog_visibility.php';
if (is_file($mfCatalogVisibilityInclude))
{
	require_once $mfCatalogVisibilityInclude;
	if (function_exists('mf_ensure_iblock4_show_in_catalog_property'))
	{
		mf_ensure_iblock4_show_in_catalog_property();
	}
	if (function_exists('mf_ensure_iblock4_ext_images_property'))
	{
		mf_ensure_iblock4_ext_images_property();
	}
}

// --- External price CSV (admin + weight surcharge helpers) -----------------
$mfExtPriceLib = __DIR__ . '/include/mf_external_price_lib.php';
if (is_file($mfExtPriceLib))
{
	require_once $mfExtPriceLib;
	if (function_exists('mf_ep_ensure_store_weight_ufs'))
	{
		mf_ep_ensure_store_weight_ufs();
	}
}

// --- Admin: store edit — disable external-price weight UFs unless «Внешний склад» ---
$mfCatStoreEditUfUi = __DIR__ . '/include/mf_cat_store_edit_uf_ui.php';
if (is_file($mfCatStoreEditUfUi))
{
	require_once $mfCatStoreEditUfUi;
}

// --- Stock: deduct store amounts on checkout --------------------------------
$mfStockInclude = __DIR__ . '/include/mf_stock.php';
if (is_file($mfStockInclude))
{
    require_once $mfStockInclude;
    if (class_exists('\\Mf\\Stock\\Bootstrap'))
    {
        \Mf\Stock\Bootstrap::init();
    }
}

// --- Payment: card-to-card email instructions --------------------------------
$mfC2CInclude = __DIR__ . '/include/mf_card2card.php';
if (is_file($mfC2CInclude))
{
    require_once $mfC2CInclude;
    if (class_exists('\\Mf\\Card2Card\\Bootstrap'))
    {
        \Mf\Card2Card\Bootstrap::init();
    }
}
