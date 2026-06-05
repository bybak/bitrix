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

$_mfMailTransport = __DIR__ . '/include/mf_mail_transport.php';
if (is_file($_mfMailTransport))
{
	require_once $_mfMailTransport;
}
unset($_mfMailTransport);

$_mfProductSearchCard = __DIR__ . '/include/mf_product_search_card.php';
if (is_file($_mfProductSearchCard))
{
	require_once $_mfProductSearchCard;
}
unset($_mfProductSearchCard);

// Global config for external Motor-Force image host.
// We intentionally generate URLs deterministically (no downloads into Bitrix).
if (!defined('MF_MOTOR_FORCE_IMG_HOST'))
{
	// Приоритет: .env / docker (getenv, $_SERVER, $_ENV).
	$env = trim((string)(getenv('MF_MOTOR_FORCE_IMG_HOST') ?: ($_SERVER['MF_MOTOR_FORCE_IMG_HOST'] ?? $_ENV['MF_MOTOR_FORCE_IMG_HOST'] ?? '')));
	if ($env !== '')
	{
		define('MF_MOTOR_FORCE_IMG_HOST', $env);
	}
	else
	{
		// По умолчанию — прокси /mf-img (валидный SSL основного домена).
		// Прямой img-motor-force.ru ломает HTTPS (самоподписанный сертификат).
		define('MF_MOTOR_FORCE_IMG_HOST', '/mf-img');
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

if (!function_exists('mf_mf_normalize_img_url'))
{
	/**
	 * Переписывает прямые URL img-motor-force.ru → локальный прокси /mf-img (HTTPS основного домена).
	 * Нужно для MF_EXT_IMAGES и meta аналогов, где в БД лежат полные http(s)://img-motor-force.ru/...
	 */
	function mf_mf_normalize_img_url(string $url): string
	{
		$url = trim($url);
		if ($url === '')
		{
			return '';
		}

		if (str_starts_with($url, '/mf-img/') || $url === '/mf-img')
		{
			return $url;
		}

		if (preg_match('#^(?:https?:)?//img-motor-force\.ru(/[^?#]*)#i', $url, $m))
		{
			$path = (string)($m[1] ?? '');
			if ($path === '' || $path === '/')
			{
				return '';
			}

			return mf_mf_img_host() . $path;
		}

		if (preg_match('#^(?:https?:)?//img\.motor-force\.ru(/[^?#]*)#i', $url, $m))
		{
			$path = (string)($m[1] ?? '');
			if ($path === '' || $path === '/')
			{
				return '';
			}

			return mf_mf_img_host() . $path;
		}

		return $url;
	}
}

if (!function_exists('mf_mf_placeholder_img_url'))
{
	function mf_mf_placeholder_img_url(): string
	{
		// Placeholder in site template (public copy under /local/templates).
		$docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
		if ($docRoot === '')
		{
			// Fallback (CLI): local/php_interface -> site root.
			$docRoot = dirname(__DIR__, 2);
		}
		$rel = '/local/templates/eshop_bootstrap_v4/images/mf-no-photo.svg';
		if ($docRoot !== '' && !is_file($docRoot . $rel))
		{
			$rel = '/bitrix/templates/eshop_bootstrap_v4/images/mf-no-photo.svg';
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
		return mf_mf_normalize_img_url(mf_mf_img_host() . '/sections/' . $sectionId . '.jpg');
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
		return mf_mf_normalize_img_url(mf_mf_img_host() . '/products/' . rawurlencode($code) . '/' . $fname);
	}
}

if (!function_exists('mf_mf_is_placeholder_img_url'))
{
	function mf_mf_is_placeholder_img_url(string $url): bool
	{
		$url = trim($url);
		if ($url === '')
		{
			return true;
		}

		return (bool)preg_match('#/mf-no-photo\.svg(?:\?|$)#i', $url)
			|| (bool)preg_match('#/no_photo\.png(?:\?|$)#i', $url);
	}
}

if (!function_exists('mf_mf_more_photo_slot_count_from_row'))
{
	function mf_mf_more_photo_slot_count_from_row(array $el): int
	{
		$v = $el['PROPERTY_MORE_PHOTO_VALUE'] ?? null;
		if ($v === null || $v === false || $v === '')
		{
			return 0;
		}
		if (is_array($v))
		{
			$n = 0;
			foreach ($v as $one)
			{
				if ((int)$one > 0)
				{
					$n++;
				}
				elseif (is_string($one) && trim($one) !== '')
				{
					$n++;
				}
			}

			return $n;
		}

		return (int)$v > 0 ? 1 : 0;
	}
}

if (!function_exists('mf_mf_product_card_gallery_slot_count'))
{
	/**
	 * Число слотов галереи, для которых на деталке подставляется mf_mf_product_img_url(CODE, n).
	 */
	function mf_mf_product_card_gallery_slot_count(int $productId, ?array $prefetchRow = null, int $iblockId = 4): int
	{
		$productId = (int)$productId;
		$iblockId = (int)$iblockId;
		$el = is_array($prefetchRow) ? $prefetchRow : [];
		$hasPictureFields = array_key_exists('PREVIEW_PICTURE', $el)
			|| array_key_exists('DETAIL_PICTURE', $el)
			|| array_key_exists('PROPERTY_MORE_PHOTO_VALUE', $el);

		if (!$hasPictureFields && $productId > 0 && $iblockId > 0 && class_exists('CIBlockElement'))
		{
			$rs = \CIBlockElement::GetList(
				[],
				['IBLOCK_ID' => $iblockId, 'ID' => $productId],
				false,
				['nTopCount' => 1],
				['ID', 'PREVIEW_PICTURE', 'DETAIL_PICTURE', 'PROPERTY_MORE_PHOTO']
			);
			if ($row = $rs->Fetch())
			{
				$el = array_merge($el, $row);
			}
		}

		$n = mf_mf_more_photo_slot_count_from_row($el);
		if ($n > 0)
		{
			return $n;
		}

		$prevId = (int)($el['PREVIEW_PICTURE'] ?? 0);
		$detailId = (int)($el['DETAIL_PICTURE'] ?? 0);
		if ($prevId <= 0 && $detailId <= 0)
		{
			return 0;
		}
		if ($prevId > 0 && $detailId <= 0)
		{
			return 1;
		}
		if ($detailId > 0 && $prevId <= 0)
		{
			return 1;
		}
		if ($prevId === $detailId)
		{
			return 1;
		}
		if (class_exists('CFile'))
		{
			$prevPath = (string)\CFile::GetPath($prevId);
			$detailPath = (string)\CFile::GetPath($detailId);
			if ($prevPath !== '' && $detailPath !== '' && $prevPath === $detailPath)
			{
				return 1;
			}
		}

		return 2;
	}
}

if (!function_exists('mf_mf_product_card_preview_src'))
{
	/**
	 * Первое изображение для карточки (поиск, списки): как catalog.element bootstrap_v4 —
	 * сначала MF_EXT_IMAGES, иначе mf_analogs_meta_images_for_product,
	 * иначе mf_mf_product_img_url(CODE,1) только если у товара есть слоты галереи.
	 *
	 * @param array<string,mixed>|null $prefetchRow строка CIBlockElement::GetList с PROPERTY_MF_EXT_IMAGES_VALUE, если уже подгружена
	 */
	function mf_mf_product_card_preview_src(int $productId, string $code, ?array $prefetchRow = null, int $iblockId = 4): string
	{
		$productId = (int)$productId;
		$code = trim($code);
		$iblockId = (int)$iblockId;
		$urls = [];

		if (is_array($prefetchRow))
		{
			$v = $prefetchRow['PROPERTY_MF_EXT_IMAGES_VALUE'] ?? null;
			if (is_array($v))
			{
				foreach ($v as $one)
				{
					$s = trim((string)$one);
					if ($s !== '')
					{
						$urls[] = $s;
					}
				}
			}
			elseif ($v !== null && $v !== '')
			{
				$s = trim((string)$v);
				if ($s !== '')
				{
					$urls[] = $s;
				}
			}
		}

		if (empty($urls) && $productId > 0 && $iblockId > 0 && class_exists('CIBlockElement'))
		{
			$rsP = \CIBlockElement::GetProperty($iblockId, $productId, ['sort' => 'asc'], ['CODE' => 'MF_EXT_IMAGES']);
			while ($p = $rsP->Fetch())
			{
				$u = trim((string)($p['VALUE'] ?? ''));
				if ($u !== '')
				{
					$urls[] = $u;
				}
			}
		}

		if (!empty($urls))
		{
			return mf_mf_normalize_img_url((string)$urls[0]);
		}

		if ($productId > 0 && function_exists('mf_analogs_meta_images_for_product'))
		{
			$meta = mf_analogs_meta_images_for_product($productId);
			if (is_array($meta))
			{
				foreach ($meta as $u)
				{
					$u = trim((string)$u);
					if ($u !== '')
					{
						return mf_mf_normalize_img_url($u);
					}
				}
			}
		}

		if ($code !== '')
		{
			$nSlots = mf_mf_product_card_gallery_slot_count($productId, $prefetchRow, $iblockId);
			if ($nSlots > 0)
			{
				return mf_mf_normalize_img_url((string)mf_mf_product_img_url($code, 1));
			}
		}

		return '';
	}
}

if (!function_exists('mf_is_legacy_bitrix_upload_image_url'))
{
	function mf_is_legacy_bitrix_upload_image_url(string $url): bool
	{
		$url = trim($url);
		if ($url === '')
		{
			return false;
		}

		return (bool)preg_match('#/upload/iblock/#i', $url);
	}
}

if (!function_exists('mf_catalog_basket_canonical_image_url'))
{
	/**
	 * Картинка товара в корзине/checkout: как на витрине (MF_EXT_IMAGES / mf-img), не /upload/iblock/.
	 */
	function mf_catalog_basket_canonical_image_url(int $productId, array $row = [], string $fallback = ''): string
	{
		$productId = (int)$productId;
		if ($productId <= 0)
		{
			$fallback = trim($fallback);

			return ($fallback !== '' && !mf_is_legacy_bitrix_upload_image_url($fallback)) ? $fallback : '';
		}

		$code = '';
		if (function_exists('mf_catalog_element_code_for_basket_row'))
		{
			$code = mf_catalog_element_code_for_basket_row($productId, $row);
		}
		elseif (!empty($row['DETAIL_PAGE_URL']) && preg_match('#/products/([^/]+)/?#', (string)$row['DETAIL_PAGE_URL'], $m))
		{
			$code = (string)$m[1];
		}

		$mfSrc = '';
		if (function_exists('mf_mf_product_card_preview_src'))
		{
			$mfSrc = trim((string)mf_mf_product_card_preview_src($productId, $code));
		}
		elseif ($code !== '' && function_exists('mf_mf_product_img_url'))
		{
			$mfSrc = trim((string)mf_mf_product_img_url($code, 1));
		}
		if ($mfSrc !== '')
		{
			return $mfSrc;
		}

		$fallback = trim($fallback);
		if ($fallback !== '' && !mf_is_legacy_bitrix_upload_image_url($fallback))
		{
			return $fallback;
		}

		if (function_exists('mf_mf_placeholder_img_url'))
		{
			return (string)mf_mf_placeholder_img_url();
		}

		return '';
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
	if (function_exists('mf_mail_register_transport_handlers'))
	{
		mf_mail_register_transport_handlers();
	}
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', 'OnBuildGlobalMenu', 'mf_admin_menu_missing_stock_items');
}

// Admin menu: "Магазин" -> "Сопоставление брендов" (mf_brand_map.php → HL mf_brand_alias)
if (!function_exists('mf_admin_menu_brand_map'))
{
	function mf_admin_menu_brand_map(array &$aGlobalMenu, array &$aModuleMenu): void
	{
		if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
		{
			return;
		}

		$url = 'mf_brand_map.php?lang=ru';
		$parentMenu =
			(isset($aGlobalMenu['global_menu_store']) ? 'global_menu_store' :
				(isset($aGlobalMenu['global_menu_sale']) ? 'global_menu_sale' : 'global_menu_content'));

		$aModuleMenu[] = [
			'parent_menu' => $parentMenu,
			'section' => 'mf_stock_import',
			'sort' => 2049,
			'text' => 'Сопоставление брендов',
			'title' => 'Справочник mf_brand_alias и mf_brand_import_skip (просмотр)',
			'icon' => 'sale_menu_icon',
			'page_icon' => 'sale_menu_icon',
			'items_id' => 'menu_mf_brand_map',
			'url' => $url,
			'more_url' => [
				'mf_brand_map.php',
			],
		];
	}
}

if (class_exists(\Bitrix\Main\EventManager::class))
{
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', 'OnBuildGlobalMenu', 'mf_admin_menu_brand_map');
}

// Admin menu: "Магазин" -> "Бренды каталога" (статистика MF_BRAND)
if (!function_exists('mf_admin_menu_brand_stats'))
{
	function mf_admin_menu_brand_stats(array &$aGlobalMenu, array &$aModuleMenu): void
	{
		if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
		{
			return;
		}

		$url = 'mf_brand_stats.php?lang=ru';
		$parentMenu =
			(isset($aGlobalMenu['global_menu_store']) ? 'global_menu_store' :
				(isset($aGlobalMenu['global_menu_sale']) ? 'global_menu_sale' : 'global_menu_content'));

		$aModuleMenu[] = [
			'parent_menu' => $parentMenu,
			'section' => 'mf_stock_import',
			'sort' => 2048,
			'text' => 'Бренды каталога (статистика)',
			'title' => 'Список брендов MF_BRAND и количество товаров',
			'icon' => 'sale_menu_icon',
			'page_icon' => 'sale_menu_icon',
			'items_id' => 'menu_mf_brand_stats',
			'url' => $url,
			'more_url' => [
				'mf_brand_stats.php',
			],
		];
	}
}

if (class_exists(\Bitrix\Main\EventManager::class))
{
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', 'OnBuildGlobalMenu', 'mf_admin_menu_brand_stats');
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

// Admin menu: "Магазин" -> заказы поставщику (UNF sync, mf_supplier_order*)
if (!function_exists('mf_admin_menu_supplier_orders'))
{
	function mf_admin_menu_supplier_orders(array &$aGlobalMenu, array &$aModuleMenu): void
	{
		if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
		{
			return;
		}

		$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
		$url = 'mf_supplier_orders.php?lang=' . urlencode($lang);
		$parentMenu =
			(isset($aGlobalMenu['global_menu_store']) ? 'global_menu_store' :
				(isset($aGlobalMenu['global_menu_sale']) ? 'global_menu_sale' : 'global_menu_content'));

		$aModuleMenu[] = [
			'parent_menu' => $parentMenu,
			'section' => 'mf_stock_import',
			'sort' => 20518,
			'text' => 'Заказы поставщику (UNF)',
			'title' => 'Заказы в работе из 1С (таблицы mf_supplier_order)',
			'icon' => 'sale_menu_icon',
			'page_icon' => 'sale_menu_icon',
			'items_id' => 'menu_mf_supplier_orders',
			'url' => $url,
			'more_url' => [
				'mf_supplier_orders.php',
			],
		];
	}
}

if (class_exists(\Bitrix\Main\EventManager::class))
{
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', 'OnBuildGlobalMenu', 'mf_admin_menu_supplier_orders');
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
			],
		];
		$aModuleMenu[] = [
			'parent_menu' => $parentMenu,
			'section' => 'mf_clear_external_warehouse',
			'sort' => 20525,
			'text' => 'Очистка внешних складов',
			'title' => 'Снятие остатков с внешнего склада (необратимо)',
			'icon' => 'sale_menu_icon',
			'page_icon' => 'sale_menu_icon',
			'items_id' => 'menu_mf_clear_external_warehouse',
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

if (!function_exists('mf_admin_menu_catalog_export'))
{
	function mf_admin_menu_catalog_export(array &$aGlobalMenu, array &$aModuleMenu): void
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
			'section' => 'mf_catalog_export',
			'sort' => 20545,
			'text' => 'Выгрузка/Изменение товаров',
			'title' => 'Экспорт и изменение каталога (CSV/XLSX): выгрузка, загрузка, выбор полей',
			'icon' => 'sale_menu_icon',
			'page_icon' => 'sale_menu_icon',
			'items_id' => 'menu_mf_catalog_export',
			'url' => 'mf_catalog_export.php?lang=' . urlencode($lang),
			'more_url' => [
				'mf_catalog_export.php',
				'mf_catalog_delete_by_csv.php',
				'mf_catalog_uniq_duplicates.php',
			],
		];
		$aModuleMenu[] = [
			'parent_menu' => $parentMenu,
			'section' => 'mf_catalog_delete_by_csv',
			'sort' => 20546,
			'text' => 'Удаление товаров (CSV)',
			'title' => 'Пакетное удаление по ID из CSV (первая колонка)',
			'icon' => 'sale_menu_icon',
			'page_icon' => 'sale_menu_icon',
			'items_id' => 'menu_mf_catalog_delete_by_csv',
			'url' => 'mf_catalog_delete_by_csv.php?lang=' . urlencode($lang),
			'more_url' => [
				'mf_catalog_delete_by_csv.php',
				'mf_catalog_delete_by_csv.php?mode=history',
			],
		];
		$aModuleMenu[] = [
			'parent_menu' => $parentMenu,
			'section' => 'mf_catalog_uniq_duplicates',
			'sort' => 20547,
			'text' => 'Дубликаты (артикул + бренд)',
			'title' => 'Одинаковый MF_UNIQ_KEY: отметка товаров на удаление',
			'icon' => 'sale_menu_icon',
			'page_icon' => 'sale_menu_icon',
			'items_id' => 'menu_mf_catalog_uniq_duplicates',
			'url' => 'mf_catalog_uniq_duplicates.php?lang=' . urlencode($lang),
			'more_url' => [
				'mf_catalog_uniq_duplicates.php',
			],
		];
		$aModuleMenu[] = [
			'parent_menu' => $parentMenu,
			'section' => 'mf_weights_io',
			'sort' => 20547,
			'text' => 'Выгрузка / загрузка весов',
			'title' => 'CSV: бренд, артикул, вес (г). Импорт обновляет вес в каталоге',
			'icon' => 'sale_menu_icon',
			'page_icon' => 'sale_menu_icon',
			'items_id' => 'menu_mf_weights_io',
			'url' => 'mf_weights_import_export.php?lang=' . urlencode($lang),
			'more_url' => [
				'mf_weights_import_export.php',
			],
		];
	}
}

if (class_exists(\Bitrix\Main\EventManager::class))
{
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', 'OnBuildGlobalMenu', 'mf_admin_menu_order_coupon');
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', 'OnBuildGlobalMenu', 'mf_admin_menu_external_price_upload');
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', 'OnBuildGlobalMenu', 'mf_admin_menu_catalog_export');
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

// === Users: customer type (retail / opt1–opt4) ===
if (!function_exists('mf_customer_type_discount_map'))
{
	/** XML_ID => скидка к розничной динамической цене, %. */
	function mf_customer_type_discount_map(): array
	{
		return [
			'retail' => 0,
			'opt1' => 3,
			'opt2' => 5,
			'opt3' => 7,
			'opt4' => 10,
			'wholesale' => 10, // legacy XML_ID (до переименования в «Опт 4»)
		];
	}
}

if (!function_exists('mf_customer_type_discount_percent_by_xml'))
{
	function mf_customer_type_discount_percent_by_xml(string $xmlId): int
	{
		$xmlId = trim($xmlId);
		$map = mf_customer_type_discount_map();

		return (int)($map[$xmlId] ?? 0);
	}
}

if (!function_exists('mf_ensure_user_customer_type_field'))
{
	function mf_ensure_user_customer_type_field(): void
	{
		$versionKey = 'mf_user_field_customer_type_version';
		$targetVersion = 2;
		$currentVersion = 0;
		if (class_exists(\Bitrix\Main\Config\Option::class))
		{
			try
			{
				$currentVersion = (int)\Bitrix\Main\Config\Option::get('main', $versionKey, '0');
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

		if ($currentVersion >= $targetVersion)
		{
			return;
		}

		$need = [
			'retail' => ['VALUE' => 'Розничный', 'SORT' => 100, 'DEF' => 'Y', 'XML_ID' => 'retail'],
			'opt1' => ['VALUE' => 'Опт 1', 'SORT' => 110, 'DEF' => 'N', 'XML_ID' => 'opt1'],
			'opt2' => ['VALUE' => 'Опт 2', 'SORT' => 120, 'DEF' => 'N', 'XML_ID' => 'opt2'],
			'opt3' => ['VALUE' => 'Опт 3', 'SORT' => 130, 'DEF' => 'N', 'XML_ID' => 'opt3'],
			'opt4' => ['VALUE' => 'Опт 4', 'SORT' => 140, 'DEF' => 'N', 'XML_ID' => 'opt4'],
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

		// Бывший «Оптовый» (wholesale) → «Опт 4» (opt4), enum ID у пользователей сохраняется.
		if (isset($existingEnumsByXml['wholesale']) && !isset($existingEnumsByXml['opt4']))
		{
			$id = (int)($existingEnumsByXml['wholesale']['ID'] ?? 0);
			if ($id > 0)
			{
				$values[$id] = [
					'ID' => $id,
					'VALUE' => 'Опт 4',
					'SORT' => 140,
					'DEF' => 'N',
					'XML_ID' => 'opt4',
				];
				$existingEnumsByXml['opt4'] = $existingEnumsByXml['wholesale'];
				unset($existingEnumsByXml['wholesale']);
				$changed = true;
			}
		}

		foreach ($need as $xml => $v)
		{
			if (isset($existingEnumsByXml[$xml]))
			{
				$id = (int)$existingEnumsByXml[$xml]['ID'];
				if ($id > 0)
				{
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
				\Bitrix\Main\Config\Option::set('main', $versionKey, (string)$targetVersion);
				\Bitrix\Main\Config\Option::set('main', 'mf_user_field_customer_type_installed', 'Y');
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

if (!function_exists('mf_round_price'))
{
	/**
	 * Округление денежных сумм до десятков рублей вверх (15094 → 15100, 18488 → 18490).
	 */
	function mf_round_price(float $amount): float
	{
		if (!is_finite($amount) || $amount <= 0)
		{
			return 0.0;
		}

		return (float)(ceil($amount / 10.0) * 10.0);
	}
}

if (!function_exists('mf_apply_markup'))
{
	function mf_apply_markup(float $rawPrice, float $pct): float
	{
		if ($rawPrice <= 0) return 0.0;
		if ($pct == 0.0) return mf_round_price($rawPrice);
		return mf_round_price($rawPrice * (1.0 + ($pct / 100.0)));
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

if (!function_exists('mf_catalog_product_store_amounts'))
{
	/**
	 * Остатки по складам для товара (сумма по кластеру SKU). Использует прогрев MF_CATALOG_WARM.
	 *
	 * @return array<int, float> storeId => amount
	 */
	function mf_catalog_product_store_amounts(int $productId): array
	{
		static $local = [];
		$productId = (int)$productId;
		if ($productId <= 0)
		{
			return [];
		}
		if (isset($local[$productId]))
		{
			return $local[$productId];
		}
		if (!empty($GLOBALS['MF_CATALOG_WARM']['store_amounts'][$productId])
			&& is_array($GLOBALS['MF_CATALOG_WARM']['store_amounts'][$productId]))
		{
			return $local[$productId] = $GLOBALS['MF_CATALOG_WARM']['store_amounts'][$productId];
		}

		$byStore = [];
		if (!class_exists(\CCatalogStoreProduct::class))
		{
			return $local[$productId] = $byStore;
		}
		try
		{
			\Bitrix\Main\Loader::includeModule('catalog');
		}
		catch (\Throwable $e)
		{
			return $local[$productId] = $byStore;
		}

		foreach (mf_catalog_product_cluster_ids($productId) as $cid)
		{
			$cid = (int)$cid;
			if ($cid <= 0)
			{
				continue;
			}
			$rs = \CCatalogStoreProduct::GetList(
				[],
				['PRODUCT_ID' => $cid],
				false,
				false,
				['STORE_ID', 'AMOUNT']
			);
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

		return $local[$productId] = $byStore;
	}
}

if (!function_exists('mf_catalog_warm_price_maps'))
{
	/**
	 * Пакетная загрузка цен каталога в MF_CATALOG_WARM['price_maps'].
	 *
	 * @param int[] $productIds
	 */
	function mf_catalog_warm_price_maps(array $productIds): void
	{
		$productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
		if ($productIds === [])
		{
			return;
		}

		$maps = [];
		if (class_exists(\Bitrix\Catalog\PriceTable::class))
		{
			$rs = \Bitrix\Catalog\PriceTable::getList([
				'filter' => ['@PRODUCT_ID' => $productIds],
				'select' => ['PRODUCT_ID', 'CATALOG_GROUP_ID', 'PRICE', 'CURRENCY'],
			]);
			while ($p = $rs->fetch())
			{
				$pid = (int)($p['PRODUCT_ID'] ?? 0);
				$gid = (int)($p['CATALOG_GROUP_ID'] ?? 0);
				if ($pid <= 0 || $gid <= 0)
				{
					continue;
				}
				if (!isset($maps[$pid]))
				{
					$maps[$pid] = ['prices' => [], 'currencies' => []];
				}
				$maps[$pid]['prices'][$gid] = (float)($p['PRICE'] ?? 0);
				$c = trim((string)($p['CURRENCY'] ?? ''));
				$maps[$pid]['currencies'][$gid] = $c !== '' ? $c : 'RUB';
			}
		}

		if ($maps === [])
		{
			return;
		}
		if (!isset($GLOBALS['MF_CATALOG_WARM']) || !is_array($GLOBALS['MF_CATALOG_WARM']))
		{
			$GLOBALS['MF_CATALOG_WARM'] = [];
		}
		$existing = $GLOBALS['MF_CATALOG_WARM']['price_maps'] ?? [];
		if (!is_array($existing))
		{
			$existing = [];
		}
		$GLOBALS['MF_CATALOG_WARM']['price_maps'] = array_replace($existing, $maps);
	}
}

if (!function_exists('mf_catalog_warm_products'))
{
	/**
	 * Пакетный прогрев остатков и цен для списка товаров (страница поиска, списки).
	 *
	 * @param int[] $productIds
	 */
	function mf_catalog_warm_products(array $productIds): void
	{
		$productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
		if ($productIds === [])
		{
			return;
		}

		$clusterToProducts = [];
		foreach ($productIds as $pid)
		{
			foreach (mf_catalog_product_cluster_ids($pid) as $cid)
			{
				$cid = (int)$cid;
				if ($cid <= 0)
				{
					continue;
				}
				$clusterToProducts[$cid][$pid] = true;
			}
		}

		$amountsByProduct = [];
		foreach ($productIds as $pid)
		{
			$amountsByProduct[$pid] = [];
		}

		$clusterIds = array_keys($clusterToProducts);
		if ($clusterIds !== [] && class_exists(\CCatalogStoreProduct::class))
		{
			try
			{
				\Bitrix\Main\Loader::includeModule('catalog');
			}
			catch (\Throwable $e)
			{
				$clusterIds = [];
			}
		}
		if ($clusterIds !== [])
		{
			$rs = \CCatalogStoreProduct::GetList(
				['STORE_ID' => 'ASC'],
				['PRODUCT_ID' => $clusterIds],
				false,
				false,
				['PRODUCT_ID', 'STORE_ID', 'AMOUNT']
			);
			while ($r = $rs->Fetch())
			{
				$cid = (int)($r['PRODUCT_ID'] ?? 0);
				$sid = (int)($r['STORE_ID'] ?? 0);
				if ($cid <= 0 || $sid <= 0)
				{
					continue;
				}
				$amt = (float)($r['AMOUNT'] ?? 0);
				foreach (array_keys($clusterToProducts[$cid] ?? []) as $pid)
				{
					$amountsByProduct[$pid][$sid] = ($amountsByProduct[$pid][$sid] ?? 0.0) + $amt;
				}
			}
		}

		if (!isset($GLOBALS['MF_CATALOG_WARM']) || !is_array($GLOBALS['MF_CATALOG_WARM']))
		{
			$GLOBALS['MF_CATALOG_WARM'] = [];
		}
		$existingAmounts = $GLOBALS['MF_CATALOG_WARM']['store_amounts'] ?? [];
		if (!is_array($existingAmounts))
		{
			$existingAmounts = [];
		}
		$GLOBALS['MF_CATALOG_WARM']['store_amounts'] = array_replace($existingAmounts, $amountsByProduct);

		if (function_exists('mf_catalog_warm_price_maps'))
		{
			mf_catalog_warm_price_maps($clusterIds);
		}
	}
}

if (!function_exists('mf_catalog_batch_products_have_stock'))
{
	/**
	 * @param int[] $productIds
	 * @return array<int, bool>
	 */
	function mf_catalog_batch_products_have_stock(array $productIds): array
	{
		$productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
		if ($productIds === [])
		{
			return [];
		}

		if (function_exists('mf_catalog_warm_products'))
		{
			mf_catalog_warm_products($productIds);
		}

		$warm = [];
		if (isset($GLOBALS['MF_CATALOG_WARM']['store_amounts']) && is_array($GLOBALS['MF_CATALOG_WARM']['store_amounts']))
		{
			$warm = $GLOBALS['MF_CATALOG_WARM']['store_amounts'];
		}

		$out = [];
		foreach ($productIds as $pid)
		{
			$pid = (int)$pid;
			if ($pid <= 0)
			{
				continue;
			}

			$sum = 0.0;
			if (isset($warm[$pid]) && is_array($warm[$pid]))
			{
				foreach ($warm[$pid] as $amt)
				{
					$sum += (float)$amt;
				}
			}
			elseif (function_exists('mf_catalog_product_store_amounts'))
			{
				foreach (mf_catalog_product_store_amounts($pid) as $amt)
				{
					$sum += (float)$amt;
				}
			}

			$out[$pid] = $sum > 1e-9;
		}

		return $out;
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

if (!function_exists('mf_product_has_any_external_warehouse'))
{
	/**
	 * Участвует ли в отображаемой матрице товара хотя бы один внешний склад.
	 * Для плитки: при нуле суммы остатков вместо «Нет в наличии» — «Под заказ» (когда есть внешний).
	 * Учитываем кластер и внешние склады с валидной ценой без строки b_catalog_store_product.
	 */
	function mf_product_has_any_external_warehouse(int $productId): bool
	{
		$productId = (int)$productId;
		if ($productId <= 0 || !class_exists(\CCatalogStoreProduct::class))
		{
			return false;
		}
		if (!function_exists('mf_ep_store_is_external_warehouse'))
		{
			return false;
		}

		$byStore = [];
		$clusterIds = function_exists('mf_catalog_product_cluster_ids')
			? mf_catalog_product_cluster_ids($productId)
			: [$productId];
		foreach ($clusterIds as $cid)
		{
			$cid = (int)$cid;
			if ($cid <= 0)
			{
				continue;
			}
			$rs = \CCatalogStoreProduct::GetList([], ['PRODUCT_ID' => $cid], false, false, ['STORE_ID', 'AMOUNT']);
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
		if (function_exists('mf_supplier_store_to_price_group') && function_exists('mf_ep_display_price_for_store'))
		{
			foreach (array_keys(mf_supplier_store_to_price_group()) as $extSid)
			{
				$extSid = (int)$extSid;
				if ($extSid <= 0 || !mf_ep_store_is_external_warehouse($extSid))
				{
					continue;
				}
				$p = mf_ep_display_price_for_store($productId, $extSid, 1.0);
				if ($p === null || $p <= 0)
				{
					continue;
				}
				if (!array_key_exists($extSid, $byStore))
				{
					$byStore[$extSid] = 0.0;
				}
			}
		}

		foreach (array_keys($byStore) as $sid)
		{
			if (mf_ep_store_is_external_warehouse((int)$sid))
			{
				return true;
			}
		}

		return false;
	}
}

if (!function_exists('mf_product_prices_catalog_maps'))
{
	/**
	 * @return array{prices: array<int, float>, currencies: array<int, string>}
	 */
	function mf_product_prices_catalog_maps(int $productId): array
	{
		static $cache = [];
		$productId = (int)$productId;
		if ($productId <= 0)
		{
			return ['prices' => [], 'currencies' => []];
		}
		if (!empty($GLOBALS['MF_CATALOG_WARM']['price_maps'][$productId])
			&& is_array($GLOBALS['MF_CATALOG_WARM']['price_maps'][$productId]))
		{
			$cache[$productId] = $GLOBALS['MF_CATALOG_WARM']['price_maps'][$productId];

			return $cache[$productId];
		}
		if (isset($cache[$productId]))
		{
			return $cache[$productId];
		}

		$map = [];
		$cur = [];
		if (class_exists(\Bitrix\Catalog\PriceTable::class))
		{
			$rs = \Bitrix\Catalog\PriceTable::getList([
				'filter' => ['=PRODUCT_ID' => $productId],
				'select' => ['CATALOG_GROUP_ID', 'PRICE', 'CURRENCY'],
			]);
			while ($p = $rs->fetch())
			{
				$gid = (int)$p['CATALOG_GROUP_ID'];
				$map[$gid] = (float)$p['PRICE'];
				$c = isset($p['CURRENCY']) ? trim((string)$p['CURRENCY']) : '';
				$cur[$gid] = $c !== '' ? $c : 'RUB';
			}
		}
		elseif (class_exists(\CPrice::class))
		{
			$rs = \CPrice::GetList([], ['PRODUCT_ID' => $productId], false, false, ['CATALOG_GROUP_ID', 'PRICE', 'CURRENCY']);
			while ($p = $rs->Fetch())
			{
				$gid = (int)$p['CATALOG_GROUP_ID'];
				$map[$gid] = (float)$p['PRICE'];
				$c = isset($p['CURRENCY']) ? trim((string)$p['CURRENCY']) : '';
				$cur[$gid] = $c !== '' ? $c : 'RUB';
			}
		}
		$cache[$productId] = ['prices' => $map, 'currencies' => $cur];

		return $cache[$productId];
	}
}

if (!function_exists('mf_product_prices_by_group'))
{
	function mf_product_prices_by_group(int $productId): array
	{
		return mf_product_prices_catalog_maps($productId)['prices'];
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

if (!function_exists('mf_raw_store_price'))
{
	/**
	 * Закупочная цена в рублях по типу цены склада (без наценки). Сумма в каталоге может быть в USD/EUR —
	 * пересчёт в ₽ по текущему курсу (mf_ep_convert_to_rub / модуль «Валюты»).
	 */
	function mf_raw_store_price(int $productId, int $storeId): ?float
	{
		$productId = (int)$productId;
		$storeId = (int)$storeId;
		if ($productId <= 0 || $storeId <= 0)
		{
			return null;
		}

		$storeToGroup = mf_supplier_store_to_price_group();
		$gid = (int)($storeToGroup[$storeId] ?? 0);
		if ($gid <= 0)
		{
			return null;
		}

		$amount = 0.0;
		$currency = 'RUB';
		foreach (mf_catalog_product_cluster_ids($productId) as $cid)
		{
			$maps = mf_product_prices_catalog_maps((int)$cid);
			$prices = $maps['prices'];
			$currencies = $maps['currencies'];
			$amount = (float)($prices[$gid] ?? 0);
			if ($amount > 0)
			{
				$currency = (string)($currencies[$gid] ?? 'RUB');
				break;
			}
		}

		if ($amount <= 0)
		{
			return null;
		}

		if (!function_exists('mf_ep_convert_to_rub'))
		{
			return $amount;
		}

		try
		{
			return mf_ep_convert_to_rub($amount, $currency);
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}
}

if (!function_exists('mf_calc_store_price'))
{
	/**
	 * Розничная цена за единицу по складу:
	 * без веса: Закуп_₽ × (1 + Наценка/100);
	 * с весом (внешний склад, UF): (Закуп_₽ + вес_кг_единицы × ₽/кг) × (1 + Наценка/100).
	 * Закуп в ₽ по текущему курсу из суммы в b_catalog_price (любая валюта типа цены).
	 */
	function mf_calc_store_price(int $productId, int $storeId): ?float
	{
		$raw = mf_raw_store_price($productId, $storeId);
		if ($raw === null)
		{
			return null;
		}

		$pct = mf_store_markup_pct($storeId);
		$base = $raw;

		if (function_exists('mf_ep_store_weight_fields') && function_exists('mf_ep_product_weight_kg'))
		{
			$wf = mf_ep_store_weight_fields($storeId);
			if ($wf['use'] && (float)$wf['rub_per_kg'] > 0)
			{
				$kgPerUnit = mf_ep_product_weight_kg($productId, 1.0);
				if ($kgPerUnit > 0)
				{
					$base = $raw + $kgPerUnit * (float)$wf['rub_per_kg'];
				}
			}
		}

		$computed = mf_apply_markup($base, $pct);

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
		$discKey = function_exists('mf_user_customer_discount_percent') ? mf_user_customer_discount_percent() : 0;
		$cacheKey = $productId . '|' . $discKey;
		if (isset($cache[$cacheKey])) return $cache[$cacheKey];

		if (!class_exists(\CCatalogStoreProduct::class)) return [null, 0];

		$min = null;
		$minStoreId = 0;

		$byStore = function_exists('mf_catalog_product_store_amounts')
			? mf_catalog_product_store_amounts($productId)
			: [];

		foreach ($byStore as $storeId => $amt)
		{
			if ($storeId <= 0 || $amt <= 0)
			{
				continue;
			}

			$computed = function_exists('mf_ep_display_price_for_store')
				? mf_ep_display_price_for_store($productId, $storeId, 1.0)
				: mf_calc_store_price($productId, $storeId);
			if ($computed === null) continue;

			if ($min === null || $computed < $min)
			{
				$min = $computed;
				$minStoreId = $storeId;
			}
		}

		$cache[$cacheKey] = [$min !== null ? (function_exists('mf_round_price') ? mf_round_price((float)$min) : (float)(ceil((float)$min / 10.0) * 10.0)) : null, $minStoreId];
		return $cache[$cacheKey];
	}
}

if (!function_exists('mf_catalog_product_has_positive_stock'))
{
	/**
	 * Есть ли положительный остаток хотя бы на одном складе (по кластеру каталожных PRODUCT_ID).
	 */
	function mf_catalog_product_has_positive_stock(int $productId): bool
	{
		$productId = (int)$productId;
		if ($productId <= 0 || !class_exists(\CCatalogStoreProduct::class))
		{
			return false;
		}
		if (!function_exists('mf_catalog_product_cluster_ids'))
		{
			return false;
		}
		foreach (mf_catalog_product_cluster_ids($productId) as $cid)
		{
			$cid = (int)$cid;
			if ($cid <= 0)
			{
				continue;
			}
			$rs = \CCatalogStoreProduct::GetList(
				[],
				['PRODUCT_ID' => $cid, '>AMOUNT' => 0],
				false,
				['nTopCount' => 1],
				['ID']
			);
			if ($rs->Fetch())
			{
				return true;
			}
		}

		return false;
	}
}

if (!function_exists('mf_catalog_storefront_price_when_in_stock'))
{
	/**
	 * Минимальная витринная цена по складам с остатком (как в таблице на карточке/в поиске).
	 * Если нигде нет остатка — null (не показываем «От …»; в списке — «Запросить цену»).
	 */
	function mf_catalog_storefront_price_when_in_stock(int $productId): ?float
	{
		$productId = (int)$productId;
		if ($productId <= 0)
		{
			return null;
		}

		if (function_exists('mf_product_search_card_stores'))
		{
			$min = null;
			foreach (mf_product_search_card_stores($productId) as $row)
			{
				if ((float)($row['amount'] ?? 0) <= 1e-9)
				{
					continue;
				}
				$p = $row['price'] ?? null;
				if ($p === null || (float)$p <= 0)
				{
					continue;
				}
				$p = (float)$p;
				if ($min === null || $p < $min)
				{
					$min = $p;
				}
			}
			if ($min !== null && $min > 0)
			{
				return function_exists('mf_round_price') ? mf_round_price($min) : (float)(ceil($min / 10.0) * 10.0);
			}

			return null;
		}

		if (!function_exists('mf_min_price_from_available_stores'))
		{
			return null;
		}
		[$min,] = mf_min_price_from_available_stores($productId);
		if ($min === null || (float)$min <= 0)
		{
			return null;
		}

		return function_exists('mf_round_price') ? mf_round_price((float)$min) : (float)(ceil((float)$min / 10.0) * 10.0);
	}
}

if (!function_exists('mf_catalog_min_price_for_store_ids'))
{
	/**
	 * Минимальная витринная цена по списку складов (только те, что показаны в таблице).
	 */
	function mf_catalog_min_price_for_store_ids(int $productId, array $storeIds): ?float
	{
		$productId = (int)$productId;
		if ($productId <= 0 || empty($storeIds) || !function_exists('mf_ep_display_price_for_store'))
		{
			return null;
		}

		$min = null;
		foreach ($storeIds as $sid)
		{
			$sid = (int)$sid;
			if ($sid <= 0)
			{
				continue;
			}
			$p = mf_ep_display_price_for_store($productId, $sid, 1.0);
			if ($p === null || (float)$p <= 0)
			{
				continue;
			}
			$p = (float)$p;
			if ($min === null || $p < $min)
			{
				$min = $p;
			}
		}
		if ($min === null)
		{
			return null;
		}

		return function_exists('mf_round_price') ? mf_round_price($min) : (float)(ceil($min / 10.0) * 10.0);
	}
}

if (!function_exists('mf_catalog_listing_display_price'))
{
	/**
	 * Цена для витрины («От» на карточке, в поиске, в списках): минимум по всем строкам
	 * таблицы складов с валидной ценой, независимо от остатка (в т.ч. внешние «Под заказ»).
	 */
	function mf_catalog_listing_display_price(int $productId): ?float
	{
		static $cache = [];
		$productId = (int)$productId;
		if ($productId <= 0)
		{
			return null;
		}
		$discKey = function_exists('mf_user_customer_discount_percent') ? mf_user_customer_discount_percent() : 0;
		$cacheKey = $productId . '|' . $discKey;
		if (array_key_exists($cacheKey, $cache))
		{
			return $cache[$cacheKey];
		}

		if (function_exists('mf_product_search_card_stores'))
		{
			$storeIds = [];
			foreach (mf_product_search_card_stores($productId) as $row)
			{
				$sid = (int)($row['store_id'] ?? 0);
				if ($sid > 0)
				{
					$storeIds[] = $sid;
				}
			}
			if (!empty($storeIds) && function_exists('mf_catalog_min_price_for_store_ids'))
			{
				$best = mf_catalog_min_price_for_store_ids($productId, $storeIds);
				if ($best !== null && $best > 0)
				{
					$cache[$cacheKey] = $best;

					return $cache[$cacheKey];
				}
			}
		}

		$cache[$cacheKey] = null;

		return null;
	}
}

if (!function_exists('mf_catalog_listing_preferred_store_id'))
{
	/**
	 * Склад для кнопки «В корзину» в списках каталога: минимальная цена среди складов с остатком.
	 */
	function mf_catalog_listing_preferred_store_id(int $productId): int
	{
		$productId = (int)$productId;
		if ($productId <= 0 || !function_exists('mf_product_search_card_stores'))
		{
			return 0;
		}

		$bestSid = 0;
		$bestPrice = null;
		foreach (mf_product_search_card_stores($productId) as $row)
		{
			if ((float)($row['amount'] ?? 0) <= 1e-9)
			{
				continue;
			}
			$p = $row['price'] ?? null;
			if ($p === null || (float)$p <= 0)
			{
				continue;
			}
			$p = (float)$p;
			if ($bestPrice === null || $p < $bestPrice)
			{
				$bestPrice = $p;
				$bestSid = (int)($row['store_id'] ?? 0);
			}
		}

		if ($bestSid <= 0)
		{
			foreach (mf_product_search_card_stores($productId) as $row)
			{
				if ((float)($row['amount'] ?? 0) <= 1e-9)
				{
					continue;
				}
				$sid = (int)($row['store_id'] ?? 0);
				if ($sid > 0)
				{
					return $sid;
				}
			}
		}

		// Внешний склад: цена в прайсе, остаток 0 — «Под заказ», как на карточке товара.
		if ($bestSid <= 0)
		{
			$bestPrice = null;
			foreach (mf_product_search_card_stores($productId) as $row)
			{
				if (empty($row['external_warehouse']))
				{
					continue;
				}
				$p = $row['price'] ?? null;
				if ($p === null || (float)$p <= 0)
				{
					continue;
				}
				$p = (float)$p;
				$sid = (int)($row['store_id'] ?? 0);
				if ($sid <= 0)
				{
					continue;
				}
				if ($bestPrice === null || $p < $bestPrice)
				{
					$bestPrice = $p;
					$bestSid = $sid;
				}
			}
		}

		return $bestSid > 0 ? $bestSid : 0;
	}
}

if (!function_exists('mf_ep_basket_unit_price_with_fallback'))
{
	/**
	 * Цена за единицу для добавления в корзину с выбранного склада.
	 * Для внешнего склада, если по его типу цены строки нет — подставляем минимальную
	 * витринную цену по карте mf_supplier_store_to_price_group (как mf_catalog_listing_display_price),
	 * затем при пустой карте — оптимальную цену каталога Bitrix (если mf_catalog_use_bitrix_base_price_fallback).
	 */
	function mf_ep_basket_unit_price_with_fallback(int $productId, int $storeId, float $qty = 1.0): ?float
	{
		$productId = (int)$productId;
		$storeId = (int)$storeId;
		if ($productId <= 0 || $storeId <= 0 || !function_exists('mf_ep_display_price_for_store'))
		{
			return null;
		}

		$p = mf_ep_display_price_for_store($productId, $storeId, $qty);
		if ($p !== null && (float)$p > 0)
		{
			return (float)$p;
		}

		$ext = function_exists('mf_ep_store_is_external_warehouse') && mf_ep_store_is_external_warehouse($storeId);
		if ($ext && function_exists('mf_catalog_listing_display_price'))
		{
			$p2 = mf_catalog_listing_display_price($productId);
			if ($p2 !== null && (float)$p2 > 0)
			{
				return (float)$p2;
			}
		}

		if ($ext && class_exists(\CCatalogProduct::class))
		{
			global $USER;
			$groups = [2];
			if (is_object($USER) && method_exists($USER, 'IsAuthorized') && $USER->IsAuthorized() && method_exists($USER, 'GetUserGroupArray'))
			{
				$g = $USER->GetUserGroupArray();
				if (is_array($g) && $g !== [])
				{
					$groups = array_map('intval', $g);
				}
			}
			$siteId = defined('SITE_ID') ? SITE_ID : false;
			$opt = \CCatalogProduct::GetOptimalPrice($productId, 1, $groups, 'N', [], $siteId, []);
			if (is_array($opt) && !empty($opt['RESULT_PRICE']) && is_array($opt['RESULT_PRICE']))
			{
				$rp = $opt['RESULT_PRICE'];
				$disc = (float)($rp['DISCOUNT_PRICE'] ?? 0);
				$base = (float)($rp['BASE_PRICE'] ?? 0);
				$pick = $disc > 0 ? $disc : $base;
				if ($pick > 0)
				{
					return $pick;
				}
			}
		}

		return null;
	}
}

if (!function_exists('mf_catalog_use_bitrix_base_price_fallback'))
{
	/**
	 * Показывать ли каталожную цену (BASE), когда внешняя схема не настроена.
	 */
	function mf_catalog_use_bitrix_base_price_fallback(): bool
	{
		if (!function_exists('mf_supplier_store_to_price_group'))
		{
			return true;
		}

		return empty(mf_supplier_store_to_price_group());
	}
}

if (!function_exists('mf_format_display_price_rub'))
{
	/**
	 * Витринное отображение цены в RUB: округление до десятков рублей вверх.
	 */
	function mf_format_display_price_rub(float $amount): string
	{
		$rounded = mf_round_price($amount);
		$nbsp = "\u{00A0}";

		return str_replace(' ', $nbsp, number_format($rounded, 0, '.', ' ')) . $nbsp . '₽';
	}
}

if (!function_exists('mf_sale_format_currency'))
{
	/**
	 * Форматирование суммы с округлением до десятков рублей (обёртка над SaleFormatCurrency).
	 */
	function mf_sale_format_currency($price, $currency = 'RUB', $onlyValue = false)
	{
		$price = mf_round_price((float)$price);
		$currency = (string)$currency;
		if ($currency === 'RUB' && !$onlyValue && function_exists('mf_format_display_price_rub'))
		{
			return mf_format_display_price_rub($price);
		}
		if (function_exists('SaleFormatCurrency'))
		{
			return SaleFormatCurrency($price, $currency, $onlyValue === true);
		}

		return mf_format_display_price_rub($price);
	}
}

if (!function_exists('mf_ensure_rub_price_decimals'))
{
	/**
	 * В Bitrix SaleFormatCurrency / PriceMaths::roundByFormatCurrency берут DECIMALS из справочника валют.
	 */
	function mf_ensure_rub_price_decimals(): void
	{
		static $doneVersion = '';
		$wantVersion = '2026-05-19-whole';
		if ($doneVersion === $wantVersion)
		{
			return;
		}
		$doneVersion = $wantVersion;

		if (!class_exists(\CCurrencyLang::class))
		{
			return;
		}

		try
		{
			$rs = \CCurrencyLang::GetList('lang', 'asc', 'RUB');
			while ($row = $rs->Fetch())
			{
				if ((int)($row['DECIMALS'] ?? 2) === 0)
				{
					continue;
				}
				$cur = (string)($row['CURRENCY'] ?? 'RUB');
				$lid = (string)($row['LID'] ?? '');
				if ($cur === '' || $lid === '')
				{
					continue;
				}
				\CCurrencyLang::Update($cur, $lid, ['DECIMALS' => 0]);
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}
	}
}

mf_ensure_rub_price_decimals();

if (!function_exists('mf_catalog_patch_bitrix_min_price_display'))
{
	/**
	 * Подмена полей MIN_PRICE компонента на расчётную сумму (для блоков без catalog.item).
	 */
	function mf_catalog_patch_bitrix_min_price_display(array &$minPrice, float $displayValue): void
	{
		if ($displayValue <= 0 || !is_array($minPrice))
		{
			return;
		}
		$cur = (string)($minPrice['CURRENCY_ID'] ?? '');
		if ($cur === '' && class_exists('\CCurrency'))
		{
			$cur = \CCurrency::GetBaseCurrency();
		}
		if ($cur === '')
		{
			$cur = 'RUB';
		}
		$minPrice['VALUE'] = mf_round_price($displayValue);
		$minPrice['DISCOUNT_VALUE'] = mf_round_price($displayValue);
		$rnd = mf_round_price($displayValue);
		if ($cur === 'RUB')
		{
			$fmt = mf_format_display_price_rub($rnd);
		}
		elseif (class_exists('\CCurrencyLang'))
		{
			$fmt = \CCurrencyLang::FormatCurrency($rnd, $cur);
		}
		else
		{
			$fmt = mf_format_display_price_rub($rnd);
		}
		$minPrice['PRINT_VALUE'] = $fmt;
		$minPrice['PRINT_DISCOUNT_VALUE'] = $fmt;
		if (isset($minPrice['DISCOUNT_DIFF_PERCENT']))
		{
			$minPrice['DISCOUNT_DIFF_PERCENT'] = 0;
		}
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
					$hasMin = $min > 0;
					$hasMax = $max > 0;

					if ($hasMin && $hasMax)
					{
						$lo = min($min, $max);
						$hi = max($min, $max);
						if ($lo === $hi)
						{
							return (string)$lo . ' ' . mf_store_decl_days_ru($lo);
						}

						return (string)$lo . '–' . (string)$hi . ' ' . mf_store_decl_days_ru($hi);
					}
					if (!$hasMin && $hasMax)
					{
						// min=0 (или пусто): «0-1 день», не «до 1 день».
						return '0-' . (string)$max . ' ' . mf_store_decl_days_ru($max);
					}
					if ($hasMin && !$hasMax)
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

			$price = function_exists('mf_ep_display_price_for_store')
				? mf_ep_display_price_for_store($productId, $storeId, $qty)
				: (function_exists('mf_calc_store_price') ? mf_calc_store_price($productId, $storeId) : null);
			if ($price === null || $price <= 0)
			{
				continue;
			}

			$s = function_exists('mf_store_row') ? mf_store_row($storeId) : null;
			$title = trim((string)($s['TITLE'] ?? ''));
			if ($title === '')
			{
				$title = 'Склад #' . $storeId;
			}

			$spb = function_exists('mf_store_delivery_spb_ui')
				? mf_store_delivery_spb_ui($storeId, $productId)
				: ['ok' => true, 'title' => 'Доставка до склада СПБ включена'];

			$out[] = [
				'store_id' => $storeId,
				'title' => $title,
				'code' => (string)($s['CODE'] ?? ''),
				'xml_id' => (string)($s['XML_ID'] ?? ''),
				'amount' => $amount,
				'price' => (float)$price,
				'price_fmt' => mf_format_display_price_rub((float)$price),
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

// === Customer type: opt tiers 3/5/7/10% off retail dynamic price ===
if (!function_exists('mf_user_customer_type_xml_id'))
{
	function mf_user_customer_type_xml_id(): string
	{
		static $cache = null;
		if ($cache !== null)
		{
			return (string)$cache;
		}

		global $USER;
		if (!is_object($USER) || !$USER->IsAuthorized())
		{
			$cache = '';
			return '';
		}

		$userId = (int)$USER->GetID();
		if ($userId <= 0 || !class_exists(\CUser::class) || !class_exists(\CUserFieldEnum::class))
		{
			$cache = '';
			return '';
		}

		$by = 'ID';
		$order = 'ASC';
		$rs = \CUser::GetList($by, $order, ['ID' => $userId], ['SELECT' => ['UF_MF_CUSTOMER_TYPE']]);
		$u = $rs ? $rs->Fetch() : null;
		$enumId = (int)($u['UF_MF_CUSTOMER_TYPE'] ?? 0);
		if ($enumId <= 0)
		{
			$cache = '';
			return '';
		}

		$enum = \CUserFieldEnum::GetList([], ['ID' => $enumId])->Fetch();
		$cache = trim((string)($enum['XML_ID'] ?? ''));

		return (string)$cache;
	}
}

if (!function_exists('mf_user_customer_discount_percent'))
{
	function mf_user_customer_discount_percent(): int
	{
		static $cache = null;
		if ($cache !== null)
		{
			return (int)$cache;
		}

		$xml = mf_user_customer_type_xml_id();
		$cache = mf_customer_type_discount_percent_by_xml($xml);

		return (int)$cache;
	}
}

if (!function_exists('mf_customer_type_apply_discount'))
{
	function mf_customer_type_apply_discount(float $price): float
	{
		$price = (float)$price;
		if ($price <= 0)
		{
			return $price;
		}

		$pct = mf_user_customer_discount_percent();
		if ($pct <= 0)
		{
			return $price;
		}

		$mult = 1.0 - ($pct / 100.0);
		$new = $price * $mult;
		if (function_exists('mf_round_price'))
		{
			$new = mf_round_price($new);
		}

		return ($new > 0 && $new < $price) ? (float)$new : $price;
	}
}

/** @deprecated используйте mf_user_customer_discount_percent() > 0 */
if (!function_exists('mf_user_is_wholesale'))
{
	function mf_user_is_wholesale(): bool
	{
		return mf_user_customer_discount_percent() > 0;
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
				$min = mf_round_price((float)$min);
				// Retail dynamic base price = min computed among available stores.
				$arResult['RESULT_PRICE']['BASE_PRICE'] = $min;
				$arResult['RESULT_PRICE']['DISCOUNT_PRICE'] = $min;
				$arResult['DISCOUNT_PRICE'] = $min;
				$arResult['RESULT_PRICE']['DISCOUNT'] = 0.0;
				$arResult['RESULT_PRICE']['PERCENT'] = 0;

				// stash store for debug/UI if needed
				$arResult['MF_MIN_STORE_ID'] = (int)$minStoreId;
			}
		}

		$discountPct = mf_user_customer_discount_percent();
		if ($discountPct > 0)
		{
			$rp = &$arResult['RESULT_PRICE'];
			$base = (float)($rp['BASE_PRICE'] ?? 0);
			$cur = (float)($rp['DISCOUNT_PRICE'] ?? 0);
			if ($cur > 0)
			{
				$new = mf_customer_type_apply_discount($cur);
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

$mfOrderAccountDisplayInclude = __DIR__ . '/include/mf_order_account_display.php';
if (is_file($mfOrderAccountDisplayInclude))
{
	require_once $mfOrderAccountDisplayInclude;
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

if (!function_exists('mf_basket_merge_store_ids_csv'))
{
	function mf_basket_merge_store_ids_csv(?string $existingCsv, int $newStoreId): string
	{
		$idsMap = [];
		foreach (explode(',', (string)$existingCsv) as $p)
		{
			$x = (int)trim((string)$p);
			if ($x > 0)
			{
				$idsMap[$x] = true;
			}
		}
		if ($newStoreId > 0)
		{
			$idsMap[$newStoreId] = true;
		}
		ksort($idsMap, SORT_NUMERIC);

		return implode(',', array_keys($idsMap));
	}
}

if (!function_exists('mf_basket_merged_store_ids_for_item'))
{
	function mf_basket_merged_store_ids_for_item(\Bitrix\Sale\BasketItemBase $item, int $newStoreId): string
	{
		$csv = '';
		if (function_exists('mf_basket_get_prop'))
		{
			$csv = (string)(mf_basket_get_prop($item, 'MF_STORE_IDS') ?? '');
			if (trim($csv) === '')
			{
				$oldMain = mf_basket_get_prop($item, 'MF_STORE_ID');
				if ($oldMain !== null && trim((string)$oldMain) !== '')
				{
					$csv = (string)$oldMain;
				}
			}
		}

		return mf_basket_merge_store_ids_csv($csv, $newStoreId);
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
			if ($code === '') continue;

			$name = $code;
			if (is_array($val))
			{
				$name = trim((string)($val['NAME'] ?? $val['name'] ?? $code));
				$val = (string)($val['VALUE'] ?? $val['value'] ?? '');
			}
			else
			{
				$val = (string)$val;
			}
			if ($name === '')
			{
				$name = $code;
			}

			if (isset($byCode[$code]))
			{
				$byCode[$code]->setField('NAME', $name);
				$byCode[$code]->setField('VALUE', $val);
				continue;
			}

			$pi = $pc->createItem();
			$pi->setFields([
				'NAME' => $name,
				'CODE' => $code,
				'VALUE' => $val,
				'SORT' => 1000,
			]);
		}
	}
}

if (!function_exists('mf_basket_remove_props_by_codes'))
{
	/**
	 * Удаляет свойства позиции корзины по CODE (например, устаревшие дубли для 1С).
	 */
	function mf_basket_remove_props_by_codes(\Bitrix\Sale\BasketItemBase $item, array $codes): void
	{
		$pc = $item->getPropertyCollection();
		if (!$pc)
		{
			return;
		}
		$want = [];
		foreach ($codes as $code)
		{
			$code = trim((string)$code);
			if ($code !== '')
			{
				$want[$code] = true;
			}
		}
		if ($want === [])
		{
			return;
		}

		foreach ($pc as $propItem)
		{
			if (!$propItem || !method_exists($propItem, 'getField'))
			{
				continue;
			}
			$code = trim((string)$propItem->getField('CODE'));
			if ($code === '' || !isset($want[$code]))
			{
				continue;
			}
			if (method_exists($propItem, 'delete'))
			{
				$propItem->delete();
			}
		}
	}
}

if (!function_exists('mf_basket_dedupe_props_for_display'))
{
	/**
	 * Одна строка на подпись в корзине: убирает дубли «Артикул» / «Категория» из разных CODE.
	 *
	 * @param array<int, array<string, mixed>> $props
	 * @return array<int, array<string, mixed>>
	 */
	function mf_basket_dedupe_props_for_display(array $props): array
	{
		if ($props === [])
		{
			return $props;
		}

		$preferCode = static function (string $nameKey) use ($props): ?string {
			$prio = [
				'артикул' => ['CML2_ARTICLE', 'ARTNUMBER', 'ARTICLE'],
				'категория' => ['Категория', 'MF_CATEGORY', 'MF_BRAND'],
			];
			$list = $prio[$nameKey] ?? [];
			foreach ($list as $code)
			{
				foreach ($props as $p)
				{
					if (!is_array($p))
					{
						continue;
					}
					if ((string)($p['CODE'] ?? '') === $code)
					{
						return $code;
					}
				}
			}

			return null;
		};

		$out = [];
		$seenName = [];

		foreach (['артикул', 'категория'] as $nameKey)
		{
			$code = $preferCode($nameKey);
			if ($code === null)
			{
				continue;
			}
			foreach ($props as $p)
			{
				if (!is_array($p) || (string)($p['CODE'] ?? '') !== $code)
				{
					continue;
				}
				$seenName[$nameKey] = true;
				$out[] = $p;
				break;
			}
		}

		foreach ($props as $p)
		{
			if (!is_array($p))
			{
				continue;
			}
			$name = trim((string)($p['NAME'] ?? ''));
			$key = mb_strtolower($name);
			if ($key === 'артикул' || $key === 'категория')
			{
				continue;
			}
			$out[] = $p;
		}

		return $out;
	}
}

if (!function_exists('mf_plain_text_from_html'))
{
	function mf_plain_text_from_html(string $html): string
	{
		if ($html === '')
		{
			return '';
		}
		$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/\s+/u', ' ', $text);

		return trim((string)$text);
	}
}

if (!function_exists('mf_normalize_simple_token'))
{
	function mf_normalize_simple_token(string $value): string
	{
		$value = mb_strtoupper(trim($value));

		return (string)preg_replace('/[^0-9A-ZА-Я]/u', '', $value);
	}
}

if (!function_exists('mf_name_is_trivial_article'))
{
	/** true, если наименование совпадает с артикулом (42657A4 и т.п.) */
	function mf_name_is_trivial_article(string $name, string $article): bool
	{
		$name = trim($name);
		$article = trim($article);
		if ($name === '' || $article === '')
		{
			return false;
		}
		if (strcasecmp($name, $article) === 0)
		{
			return true;
		}

		$n = mf_normalize_simple_token($name);
		$a = mf_normalize_simple_token($article);

		return ($n !== '' && $a !== '' && $n === $a);
	}
}

if (!function_exists('mf_catalog_resolve_export_name'))
{
	/**
	 * Человекочитаемое наименование для 1С / заказа: не подставляем голый артикул, если есть описание.
	 */
	function mf_catalog_resolve_export_name(
		string $iblockName,
		string $previewText,
		string $detailText,
		string $article,
		string $brand,
		string $fallback = ''
	): string {
		$candidates = [];
		foreach ([$fallback, $iblockName] as $candidate)
		{
			$candidate = trim($candidate);
			if ($candidate !== '' && !mf_name_is_trivial_article($candidate, $article))
			{
				$candidates[] = $candidate;
			}
		}
		foreach ([$previewText, $detailText] as $html)
		{
			$plain = mf_plain_text_from_html($html);
			if ($plain !== '' && mb_strlen($plain) >= 3 && !mf_name_is_trivial_article($plain, $article))
			{
				$candidates[] = mb_substr($plain, 0, 250);
			}
		}
		if (!empty($candidates))
		{
			usort($candidates, static function (string $a, string $b): int {
				return mb_strlen($b) <=> mb_strlen($a);
			});

			return $candidates[0];
		}

		$iblockName = trim($iblockName);
		if ($iblockName !== '' && mb_strlen($iblockName) > mb_strlen($article) + 5)
		{
			return $iblockName;
		}

		$plain = mf_plain_text_from_html($previewText);
		if ($plain === '')
		{
			$plain = mf_plain_text_from_html($detailText);
		}
		if ($plain !== '')
		{
			return mb_substr($plain, 0, 250);
		}
		if ($iblockName !== '')
		{
			return $iblockName;
		}
		$fallback = trim($fallback);
		if ($fallback !== '')
		{
			return $fallback;
		}
		$combo = trim($brand . ' ' . $article);

		return $combo !== '' ? $combo : $article;
	}
}

if (!function_exists('mf_catalog_product_export_name'))
{
	function mf_catalog_product_export_name(int $productId, string $fallback = ''): string
	{
		if ($productId <= 0)
		{
			return trim($fallback);
		}
		$meta = function_exists('mf_catalog_brand_article_by_product_id')
			? mf_catalog_brand_article_by_product_id($productId)
			: ['brand' => '', 'article' => '', 'name' => ''];
		$name = trim((string)($meta['name'] ?? ''));
		$article = trim((string)($meta['article'] ?? ''));
		$fallback = trim($fallback);
		if (
			$fallback !== ''
			&& !mf_name_is_trivial_article($fallback, $article)
			&& (mf_name_is_trivial_article($name, $article) || mb_strlen($fallback) > mb_strlen($name))
		)
		{
			return $fallback;
		}

		return $name;
	}
}

if (!function_exists('mf_catalog_parse_manufacturer_from_text'))
{
	function mf_catalog_parse_manufacturer_from_text(string $text): string
	{
		$text = trim($text);
		if ($text === '')
		{
			return '';
		}
		if (preg_match('/Производитель\s*:\s*(.+?)(?:Номер\s+по\s+каталогу|Оригинальные\s+номера|$)/su', $text, $m))
		{
			return trim((string)$m[1]);
		}

		return '';
	}
}

if (!function_exists('mf_catalog_brand_from_section_id'))
{
	/**
	 * Бренд из цепочки разделов каталога (Mercury, Bosch, …), если свойство MF_BRAND пустое.
	 */
	function mf_catalog_brand_from_section_id(int $iblockId, int $sectionId): string
	{
		if ($iblockId <= 0 || $sectionId <= 0 || !class_exists(\CIBlockSection::class))
		{
			return '';
		}

		static $cache = [];
		$key = $iblockId . ':' . $sectionId;
		if (array_key_exists($key, $cache))
		{
			return $cache[$key];
		}

		$skip = ['каталог', 'catalog', 'прочее', 'разное', 'misc', 'from bitrix'];
		$names = [];
		$nav = \CIBlockSection::GetNavChain($iblockId, $sectionId, ['ID', 'NAME']);
		if ($nav)
		{
			while ($section = $nav->Fetch())
			{
				$name = trim((string)($section['NAME'] ?? ''));
				if ($name === '')
				{
					continue;
				}
				if (in_array(mb_strtolower($name, 'UTF-8'), $skip, true))
				{
					continue;
				}
				$names[] = $name;
			}
		}

		$brand = $names !== [] ? $names[0] : '';
		$cache[$key] = $brand;

		return $brand;
	}
}

if (!function_exists('mf_catalog_brand_article_by_product_id'))
{
	/**
	 * Бренд и артикул из карточки товара (инфоблок), если в позиции корзины/заказа они не продублированы в b_sale_basket_props.
	 * Учитывает связку торговое предложение → товар (SKU).
	 *
	 * @return array{brand: string, article: string, name: string}
	 */
	function mf_catalog_brand_article_by_product_id(int $productId): array
	{
		static $cache = [];
		if ($productId <= 0)
		{
			return ['brand' => '', 'article' => '', 'name' => ''];
		}
		if (array_key_exists($productId, $cache))
		{
			return $cache[$productId];
		}

		$empty = ['brand' => '', 'article' => '', 'name' => ''];
		if (!class_exists(\CIBlockElement::class) || !\Bitrix\Main\Loader::includeModule('iblock'))
		{
			$cache[$productId] = $empty;

			return $empty;
		}

		$select = [
			'ID',
			'IBLOCK_ID',
			'IBLOCK_SECTION_ID',
			'NAME',
			'PREVIEW_TEXT',
			'DETAIL_TEXT',
			'PROPERTY_CML2_ARTICLE',
			'PROPERTY_MF_BRAND',
			'PROPERTY_MF_BRAND_NORM',
			'PROPERTY_MF_ARTICLE_NORM',
			'PROPERTY_ARTNUMBER',
			'PROPERTY_CML2_MANUFACTURER',
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
			if ($brand === '')
			{
				$brand = trim((string)($row['PROPERTY_CML2_MANUFACTURER_VALUE'] ?? ''));
			}
			$name = trim((string)($row['NAME'] ?? ''));

			return ['brand' => $brand, 'article' => $article, 'name' => $name];
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
		$name = '';
		$previewText = '';
		$detailText = '';
		$iblockId = 0;
		$sectionId = 0;
		foreach ($ids as $id)
		{
			$row = $load($id);
			if ($row === null)
			{
				continue;
			}
			if ($iblockId <= 0)
			{
				$iblockId = (int)($row['IBLOCK_ID'] ?? 0);
			}
			if ($sectionId <= 0)
			{
				$sectionId = (int)($row['IBLOCK_SECTION_ID'] ?? 0);
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
			if ($name === '' && $p['name'] !== '')
			{
				$name = $p['name'];
			}
			if ($previewText === '' && trim((string)($row['PREVIEW_TEXT'] ?? '')) !== '')
			{
				$previewText = (string)$row['PREVIEW_TEXT'];
			}
			if ($detailText === '' && trim((string)($row['DETAIL_TEXT'] ?? '')) !== '')
			{
				$detailText = (string)$row['DETAIL_TEXT'];
			}
		}

		if ($brand === '' && function_exists('mf_catalog_brand_from_section_id'))
		{
			$brand = mf_catalog_brand_from_section_id($iblockId, $sectionId);
		}
		if ($brand === '' && function_exists('mf_catalog_parse_manufacturer_from_text'))
		{
			foreach ([$previewText, $detailText, $name] as $text)
			{
				$brand = mf_catalog_parse_manufacturer_from_text((string)$text);
				if ($brand !== '')
				{
					break;
				}
			}
		}

		$name = mf_catalog_resolve_export_name($name, $previewText, $detailText, $article, $brand, '');

		$out = ['brand' => $brand, 'article' => $article, 'name' => $name];
		$cache[$productId] = $out;

		return $out;
	}
}

if (!function_exists('mf_basket_item_display_name'))
{
	/**
	 * Наименование позиции заказа/корзины: из строки заказа, иначе из карточки товара каталога.
	 */
	function mf_basket_item_display_name(array $item): string
	{
		$basketName = '';
		foreach (['NAME~', 'NAME'] as $key)
		{
			$name = trim(html_entity_decode(strip_tags((string)($item[$key] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			if ($name !== '')
			{
				$basketName = $name;
				break;
			}
		}

		$productId = (int)($item['PRODUCT_ID'] ?? 0);
		if ($productId > 0 && function_exists('mf_catalog_product_export_name'))
		{
			$name = mf_catalog_product_export_name($productId, $basketName);
			if ($name !== '')
			{
				return $name;
			}
		}

		return $basketName;
	}
}

if (!function_exists('mf_basket_product_identity'))
{
	/**
	 * NAME / PRODUCT_XML_ID / CATALOG_XML_ID для синхронизации заказов Bitrix ↔ 1С.
	 *
	 * @return array{NAME: string, PRODUCT_XML_ID: string, CATALOG_XML_ID: string}|null
	 */
	function mf_basket_product_identity(int $productId): ?array
	{
		if ($productId <= 0)
		{
			return null;
		}
		if (!class_exists(\Bitrix\Main\Loader::class))
		{
			return null;
		}
		if (!\Bitrix\Main\Loader::includeModule('iblock') || !\Bitrix\Main\Loader::includeModule('catalog'))
		{
			return null;
		}

		$product = \CIBlockElement::GetList(
			[],
			['ID' => $productId],
			false,
			['nTopCount' => 1],
			['ID', 'IBLOCK_ID', 'NAME', 'XML_ID']
		)->Fetch();
		if (!$product)
		{
			return null;
		}

		$name = function_exists('mf_catalog_product_export_name')
			? mf_catalog_product_export_name($productId)
			: trim((string)($product['NAME'] ?? ''));
		$productXmlId = trim((string)($product['XML_ID'] ?? ''));

		if (class_exists(\CCatalogSKU::class))
		{
			$skuInfo = \CCatalogSKU::GetProductInfo($productId);
			if (is_array($skuInfo) && !empty($skuInfo['ID']) && $productXmlId !== '' && strpos($productXmlId, '#') === false)
			{
				$parent = \CIBlockElement::GetList(
					[],
					['ID' => (int)$skuInfo['ID']],
					false,
					['nTopCount' => 1],
					['XML_ID']
				)->Fetch();
				$parentXmlId = trim((string)($parent['XML_ID'] ?? ''));
				if ($parentXmlId !== '')
				{
					$productXmlId = $parentXmlId . '#' . $productXmlId;
				}
			}
		}

		$iblockId = (int)$product['IBLOCK_ID'];
		$catalogXmlId = trim((string)\CIBlock::GetArrayByID($iblockId, 'XML_ID'));
		if ($catalogXmlId === '')
		{
			$iblock = \CIBlock::GetArrayByID($iblockId);
			$catalogXmlId = trim((string)($iblock['CODE'] ?? ''));
		}

		return [
			'NAME' => $name,
			'PRODUCT_XML_ID' => $productXmlId,
			'CATALOG_XML_ID' => $catalogXmlId,
		];
	}
}

if (!function_exists('mf_basket_delete_prop_from_db'))
{
	function mf_basket_delete_prop_from_db(int $basketId, string $code): void
	{
		$basketId = (int)$basketId;
		$code = trim($code);
		if ($basketId <= 0 || $code === '' || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return;
		}

		$rs = \Bitrix\Sale\Internals\BasketPropertyTable::getList([
			'order' => ['ID' => 'ASC'],
			'filter' => ['BASKET_ID' => $basketId, 'CODE' => $code],
			'select' => ['ID'],
		]);
		while ($row = $rs->fetch())
		{
			$id = (int)($row['ID'] ?? 0);
			if ($id > 0)
			{
				\Bitrix\Sale\Internals\BasketPropertyTable::delete($id);
			}
		}
	}
}

if (!function_exists('mf_basket_upsert_prop'))
{
	function mf_basket_upsert_prop(int $basketId, string $code, string $name, string $value): void
	{
		if ($basketId <= 0 || $code === '' || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return;
		}

		$name = trim($name) !== '' ? trim($name) : $code;
		$value = (string)$value;

		$existing = null;
		$rs = \Bitrix\Sale\Internals\BasketPropertyTable::getList([
			'order' => ['ID' => 'ASC'],
			'filter' => ['BASKET_ID' => $basketId, 'CODE' => $code],
			'limit' => 1,
			'select' => ['ID', 'CODE', 'NAME', 'VALUE'],
		]);
		if ($row = $rs->fetch())
		{
			$existing = $row;
		}

		if (is_array($existing))
		{
			if ((string)($existing['VALUE'] ?? '') === $value && (string)($existing['NAME'] ?? '') === $name)
			{
				return;
			}
			\Bitrix\Sale\Internals\BasketPropertyTable::update((int)$existing['ID'], [
				'VALUE' => $value,
				'NAME' => $name,
			]);

			return;
		}

		if ($value === '')
		{
			return;
		}

		\Bitrix\Sale\Internals\BasketPropertyTable::add([
			'BASKET_ID' => $basketId,
			'CODE' => $code,
			'NAME' => $name,
			'VALUE' => $value,
			'SORT' => 500,
		]);
	}
}

if (!function_exists('mf_basket_persist_item_1c_fields'))
{
	/**
	 * Гарантированно пишет поля/свойства позиции в b_sale_basket / b_sale_basket_props (для экспорта в 1С).
	 */
	function mf_basket_persist_item_1c_fields(\Bitrix\Sale\BasketItemBase $item): void
	{
		mf_basket_apply_1c_sync_fields($item);

		$basketId = (int)$item->getId();
		if ($basketId <= 0 || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return;
		}

		$fields = [];
		foreach (['NAME', 'PRODUCT_XML_ID', 'CATALOG_XML_ID'] as $fieldName)
		{
			$val = trim((string)$item->getField($fieldName));
			if ($val !== '')
			{
				$fields[$fieldName] = $val;
			}
		}
		if (!empty($fields))
		{
			\CSaleBasket::Update($basketId, $fields);
		}

		$pc = $item->getPropertyCollection();
		if (!$pc)
		{
			return;
		}

		foreach ($pc as $propItem)
		{
			if (!$propItem || !method_exists($propItem, 'getField'))
			{
				continue;
			}
			$code = trim((string)$propItem->getField('CODE'));
			if ($code === '')
			{
				continue;
			}
			mf_basket_upsert_prop(
				$basketId,
				$code,
				(string)$propItem->getField('NAME'),
				(string)$propItem->getField('VALUE')
			);
		}

		if (function_exists('mf_basket_delete_prop_from_db'))
		{
			foreach (['ARTNUMBER', 'MF_CATEGORY', 'MF_BRAND'] as $legacyCode)
			{
				mf_basket_delete_prop_from_db($basketId, $legacyCode);
			}
		}
	}
}

if (!function_exists('mf_basket_sync_order_for_1c'))
{
	function mf_basket_sync_order_for_1c(\Bitrix\Sale\Order $order, bool $persistToDb = false): void
	{
		$basket = $order->getBasket();
		if (!$basket)
		{
			return;
		}

		foreach ($basket as $item)
		{
			if (!$item instanceof \Bitrix\Sale\BasketItemBase)
			{
				continue;
			}
			if ($persistToDb)
			{
				mf_basket_persist_item_1c_fields($item);
				continue;
			}
			mf_basket_apply_1c_sync_fields($item);
		}
	}
}

if (!function_exists('mf_basket_apply_1c_sync_fields'))
{
	/**
	 * Поля и свойства корзины для стандартного обмена заказов Bitrix ↔ 1С.
	 * В XML уходит как СвойствоКорзины#CODE — 1С:УНФ мапит их на реквизиты номенклатуры.
	 */
	function mf_basket_apply_1c_sync_fields(\Bitrix\Sale\BasketItemBase $item): void
	{
		$productId = (int)$item->getProductId();
		if ($productId <= 0)
		{
			return;
		}

		$identity = mf_basket_product_identity($productId);
		if (is_array($identity))
		{
			$fields = [];
			$exportName = function_exists('mf_catalog_product_export_name')
				? mf_catalog_product_export_name($productId, trim((string)$item->getField('NAME')))
				: trim((string)($identity['NAME'] ?? ''));
			if ($exportName !== '')
			{
				$fields['NAME'] = $exportName;
			}
			if ($identity['PRODUCT_XML_ID'] !== '')
			{
				$fields['PRODUCT_XML_ID'] = $identity['PRODUCT_XML_ID'];
			}
			if ($identity['CATALOG_XML_ID'] !== '')
			{
				$fields['CATALOG_XML_ID'] = $identity['CATALOG_XML_ID'];
			}
			if (!empty($fields))
			{
				$item->setFields($fields);
			}
		}

		$meta = function_exists('mf_catalog_brand_article_by_product_id')
			? mf_catalog_brand_article_by_product_id($productId)
			: ['brand' => '', 'article' => '', 'name' => ''];

		$props = [];
		if (is_array($identity))
		{
			if ($identity['CATALOG_XML_ID'] !== '')
			{
				$props['CATALOG.XML_ID'] = $identity['CATALOG_XML_ID'];
			}
			if ($identity['PRODUCT_XML_ID'] !== '')
			{
				$props['PRODUCT.XML_ID'] = $identity['PRODUCT_XML_ID'];
			}
		}
		if (trim((string)($meta['article'] ?? '')) !== '')
		{
			$article = trim((string)$meta['article']);
			// Одна строка в корзине; 1С читает CML2_ARTICLE / «Артикул» (см. mf_1c_export).
			$props['CML2_ARTICLE'] = [
				'NAME' => 'Артикул',
				'VALUE' => $article,
			];
		}
		if (trim((string)($meta['brand'] ?? '')) !== '')
		{
			$brand = trim((string)$meta['brand']);
			// Категория номенклатуры в 1С ← бренд товара («В группе» не трогаем).
			$props['Категория'] = [
				'NAME' => 'Категория',
				'VALUE' => $brand,
			];
			$props['CML2_MANUFACTURER'] = [
				'NAME' => 'Производитель',
				'VALUE' => $brand,
			];
		}

		if (!empty($props) && function_exists('mf_basket_set_props'))
		{
			mf_basket_set_props($item, $props);
		}

		if (function_exists('mf_basket_remove_props_by_codes'))
		{
			mf_basket_remove_props_by_codes($item, ['ARTNUMBER', 'MF_CATEGORY', 'MF_BRAND']);
		}

		$basketId = (int)$item->getId();
		if ($basketId > 0 && function_exists('mf_basket_delete_prop_from_db'))
		{
			foreach (['ARTNUMBER', 'MF_CATEGORY', 'MF_BRAND'] as $legacyCode)
			{
				mf_basket_delete_prop_from_db($basketId, $legacyCode);
			}
		}
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

		$qty = (float)$item->getQuantity();
		if ($qty <= 0)
		{
			$qty = 1.0;
		}
		if (!function_exists('mf_ep_display_price_for_store'))
		{
			return;
		}
		$computed = mf_ep_display_price_for_store($productId, $storeId, $qty);
		if ($computed === null || $computed <= 0)
		{
			return;
		}
		$computed = mf_round_price((float)$computed);

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

		if (function_exists('mf_ep_product_weight_grams_cluster'))
		{
			$catalogWeight = (int)mf_ep_product_weight_grams_cluster($productId);
			if ($catalogWeight > 0)
			{
				$item->setField('WEIGHT', $catalogWeight);
			}
		}

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
			mf_basket_apply_1c_sync_fields($basketItem);
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
				mf_basket_apply_1c_sync_fields($item);
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
						$nomVal = (string)$req->getPost('MF_NOMINATIM_JSON');
						$p = mf_sale_order_prop_value_by_code($propCol, 'MF_NOMINATIM_JSON');
						if ($p && method_exists($p, 'setValue'))
						{
							$p->setValue($nomVal);
						}
						if ($nomVal !== '' && function_exists('mf_checkout_nominatim_display_line'))
						{
							$nomLine = mf_checkout_nominatim_display_line($nomVal);
							if ($nomLine !== '')
							{
								$pLoc = mf_sale_order_prop_value_by_code($propCol, 'DELIVERY_LOCATION_TEXT');
								if ($pLoc && method_exists($pLoc, 'setValue'))
								{
									$pLoc->setValue($nomLine);
								}
							}
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

					$pZipSave = mf_sale_order_prop_value_by_code($propCol, 'DELIVERY_ZIP');
					if ($pZipSave && method_exists($pZipSave, 'getValue') && method_exists($pZipSave, 'setValue'))
					{
						$zipVal = trim((string)$pZipSave->getValue());
						if ($zipVal !== '' && !preg_match('/^\d{5,6}$/u', $zipVal))
						{
							$pZipSave->setValue('');
						}
					}

					$deliveryModeSave = function_exists('mf_checkout_resolve_delivery_mode')
						? mf_checkout_resolve_delivery_mode(
							(string)$req->getPost('MF_DELIVERY_MODE'),
							(string)$req->getPost('MF_EDOST_TARIF_ID'),
							trim((string)$req->getPost('MF_EDOST_TARIF_COMPANY'))
						)
						: 'delivery';
					$mfStreetSave = trim((string)$req->getPost('MF_DELIVERY_ADDRESS'));
					if ($deliveryModeSave !== 'pickup')
					{
						$pAddrSave = mf_sale_order_prop_value_by_code($propCol, 'DELIVERY_ADDRESS');
						if ($pAddrSave && method_exists($pAddrSave, 'getValue') && method_exists($pAddrSave, 'setValue')
							&& function_exists('mf_checkout_pickup_delivery_config'))
						{
							$pickupCfg = mf_checkout_pickup_delivery_config();
							$pickupName = trim((string)($pickupCfg['NAME'] ?? ''));
							$addrVal = trim((string)$pAddrSave->getValue());
							if ($pickupName !== '' && $addrVal === $pickupName)
							{
								if ($mfStreetSave !== '')
								{
									$pAddrSave->setValue($mfStreetSave);
								}
								else
								{
									$pAddrSave->setValue('');
								}
							}
						}
					}

					if (function_exists('mf_checkout_apply_mf_delivery_post_to_order'))
					{
						mf_checkout_apply_mf_delivery_post_to_order($propCol, $req);
					}
				}

				$managerFb = (trim((string)$req->getPost('MF_EDOST_MANAGER_FALLBACK')) === 'Y');
				$tid = trim((string)$req->getPost('MF_EDOST_TARIF_ID'));
				if ($managerFb && $tid === '')
				{
					$shipmentCollection = $order->getShipmentCollection();
					if ($shipmentCollection)
					{
						foreach ($shipmentCollection as $shipment)
						{
							if (!$shipment || $shipment->isSystem())
							{
								continue;
							}
							try
							{
								$shipment->setField('CUSTOM_PRICE_DELIVERY', 'Y');
								$shipment->setField('BASE_PRICE_DELIVERY', 0.0);
								$shipment->setField('PRICE_DELIVERY', 0.0);
							}
							catch (\Throwable $eShip)
							{
							}
						}
					}
					$comments = (string)$order->getField('COMMENTS');
					$comments = preg_replace('~\\n?Доставка: стоимость будет рассчитана менеджером[^\\n]*~u', '', $comments);
					$comments = trim((string)$comments);
					$comments = trim($comments . "\n" . 'Доставка: стоимость будет рассчитана менеджером (оформление без тарифа eDost, 0 ₽ в заказе).');
					$order->setField('COMMENTS', $comments);
				}

				if (
					$tid === 'pickup'
					|| (function_exists('mf_checkout_is_pickup_tariff') && mf_checkout_is_pickup_tariff($tid, trim((string)$req->getPost('MF_EDOST_TARIF_COMPANY'))))
				)
				{
					$shipmentCollection = $order->getShipmentCollection();
					if ($shipmentCollection)
					{
						foreach ($shipmentCollection as $shipment)
						{
							if (!$shipment || $shipment->isSystem())
							{
								continue;
							}
							try
							{
								$shipment->setField('CUSTOM_PRICE_DELIVERY', 'Y');
								$shipment->setField('BASE_PRICE_DELIVERY', 0.0);
								$shipment->setField('PRICE_DELIVERY', 0.0);
							}
							catch (\Throwable $eShipPickup)
							{
							}
						}
					}
				}

				if ($tid !== '')
				{
					$company = trim((string)$req->getPost('MF_EDOST_TARIF_COMPANY'));
					$name = trim((string)$req->getPost('MF_EDOST_TARIF_NAME'));
					$price = trim((string)$req->getPost('MF_EDOST_TARIF_PRICE'));
					$daysSuffix = function_exists('mf_format_tariff_days_suffix')
						? mf_format_tariff_days_suffix(
							$req->getPost('MF_EDOST_TARIF_DAYS_FROM'),
							$req->getPost('MF_EDOST_TARIF_DAYS_TO')
						)
						: '';

					$comments = (string)$order->getField('COMMENTS');
					$comments = preg_replace('~\\n?Доставка: стоимость будет рассчитана менеджером[^\\n]*~u', '', $comments);
					$comments = trim((string)$comments);

					$line = 'Доставка (eDost, справочно, не входит в Итого): '
						. ($company !== '' ? ($company . ' — ') : '')
						. ($name !== '' ? $name : ('тариф ' . $tid))
						. ' — ' . ($price !== '' ? ($price . ' ₽') : 'оплата при получении')
						. $daysSuffix
						. ' (tarif_id=' . $tid . ')';

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

if (!function_exists('mf_on_order_saved_1c_sync'))
{
	/**
	 * Не вызывать из OnSaleOrderSaved: CSaleBasket::Update внутри save() даёт deadlock.
	 * Используется только из mf_on_1c_exchange_backfill().
	 */
	function mf_on_order_saved_1c_sync(\Bitrix\Sale\Order $order): void
	{
		if (function_exists('mf_basket_sync_order_for_1c'))
		{
			mf_basket_sync_order_for_1c($order, true);
		}
	}
}

if (!function_exists('mf_on_1c_exchange_backfill'))
{
	/**
	 * Перед выгрузкой заказов в 1С (type=sale&mode=query) дописываем артикул/бренд в позиции.
	 */
	function mf_on_1c_exchange_backfill(): void
	{
		if (PHP_SAPI === 'cli')
		{
			return;
		}

		$script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
		if (stripos($script, '1c_exchange.php') === false)
		{
			return;
		}
		if ((string)($_REQUEST['type'] ?? '') !== 'sale')
		{
			return;
		}
		$mode = (string)($_REQUEST['mode'] ?? $_GET['mode'] ?? $_POST['mode'] ?? '');
		if ($mode !== 'query')
		{
			return;
		}
		if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return;
		}

		static $done = false;
		if ($done)
		{
			return;
		}
		$done = true;

		try
		{
			$rs = \Bitrix\Sale\Internals\OrderTable::getList([
				'select' => ['ID'],
				'order' => ['ID' => 'DESC'],
				'limit' => 100,
			]);
			while ($row = $rs->fetch())
			{
				$orderId = (int)($row['ID'] ?? 0);
				if ($orderId <= 0)
				{
					continue;
				}
				$order = \Bitrix\Sale\Order::load($orderId);
				if ($order && function_exists('mf_on_order_saved_1c_sync'))
				{
					mf_on_order_saved_1c_sync($order);
				}
			}
		}
		catch (\Throwable $e)
		{
		}
	}
}

if (class_exists(\Bitrix\Main\EventManager::class))
{
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('sale', 'OnSaleBasketItemBeforeSaved', 'mf_on_basket_item_before_saved');
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('sale', 'OnSaleOrderBeforeSaved', 'mf_on_order_before_saved');
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', 'OnBeforeProlog', 'mf_on_1c_exchange_backfill');
}

// --- UNF integration (orders -> 1C:UNF via HTTP API) ------------------------
// Kept in a separate file to avoid bloating init.php.
$mf1cExportInclude = __DIR__ . '/include/mf_1c_export.php';
if (is_file($mf1cExportInclude))
{
	require_once $mf1cExportInclude;
}

// --- Custom order statuses from 1C (HL mf_order_custom_status) --------------
$mfOrderCustomStatusInclude = __DIR__ . '/include/mf_order_custom_status.php';
if (is_file($mfOrderCustomStatusInclude))
{
	require_once $mfOrderCustomStatusInclude;
}
$mfOrderCustomStatusAdminInclude = __DIR__ . '/include/mf_order_custom_status_admin.php';
if (is_file($mfOrderCustomStatusAdminInclude))
{
	require_once $mfOrderCustomStatusAdminInclude;
}

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

// --- Catalog: strip boilerplate phrases from preview/detail text (display only) ---
$mfCatalogTextInclude = __DIR__ . '/include/mf_catalog_text.php';
if (is_file($mfCatalogTextInclude))
{
	require_once $mfCatalogTextInclude;
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
	if (function_exists('mf_ensure_iblock4_oem_property'))
	{
		mf_ensure_iblock4_oem_property();
	}
}

$mfCatalogSortInclude = __DIR__ . '/include/mf_catalog_sort.php';
if (is_file($mfCatalogSortInclude))
{
	require_once $mfCatalogSortInclude;
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
$mfEpClearJobLib = __DIR__ . '/include/mf_ep_clear_warehouse_job.php';
if (is_file($mfEpClearJobLib))
{
	require_once $mfEpClearJobLib;
}

// --- UNF: заказы поставщику (sync из API; таблицы mf_supplier_order*) -------
$mfSupplierOrdersLib = __DIR__ . '/include/mf_supplier_orders_lib.php';
if (is_file($mfSupplierOrdersLib))
{
	require_once $mfSupplierOrdersLib;
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

// --- Письма покупателю: оформление заказа и смена статуса --------------------
$mfOrderAccountDisplay = __DIR__ . '/include/mf_order_account_display.php';
if (is_file($mfOrderAccountDisplay))
{
	require_once $mfOrderAccountDisplay;
}
$mfOrderMailInclude = __DIR__ . '/include/mf_order_mail.php';
if (is_file($mfOrderMailInclude))
{
	require_once $mfOrderMailInclude;
	if (class_exists('\\Mf\\OrderMail\\Bootstrap'))
	{
		\Mf\OrderMail\Bootstrap::init();
	}
}

// --- Новый заказ: полное описание на e-mail -----------------------------------
$mfOrderNotifyInclude = __DIR__ . '/include/mf_order_notify.php';
if (is_file($mfOrderNotifyInclude))
{
    require_once $mfOrderNotifyInclude;
    if (class_exists('\\Mf\\OrderNotify\\Bootstrap'))
    {
        \Mf\OrderNotify\Bootstrap::init();
    }
}

$mfContactMapInclude = __DIR__ . '/include/mf_contact_map.php';
if (is_file($mfContactMapInclude))
{
	require_once $mfContactMapInclude;
}

$mfSiteMailInclude = __DIR__ . '/include/mf_site_mail.php';
if (is_file($mfSiteMailInclude))
{
	require_once $mfSiteMailInclude;
	if (class_exists('\\Mf\\SiteMail\\Bootstrap'))
	{
		\Mf\SiteMail\Bootstrap::init();
	}
}
