<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

// Rewrite gallery image URLs to external host (no downloads into Bitrix).
// We keep the same number of photos, but replace SRC with:
//   http://img-motor-force.ru/products/<CODE>/0001.jpg, 0002.jpg, ...
if (is_array($arResult) && function_exists('mf_mf_product_img_url'))
{
	// If external image URLs are provided (e.g. analog stubs), use them as-is.
	$extImgs = [];
	if (!empty($arResult['PROPERTIES']['MF_EXT_IMAGES']['VALUE']))
	{
		$v = $arResult['PROPERTIES']['MF_EXT_IMAGES']['VALUE'];
		if (is_array($v)) $extImgs = $v;
		else $extImgs = [$v];
	}
	if (empty($extImgs) && class_exists('CIBlockElement') && (int)($arResult['IBLOCK_ID'] ?? 0) > 0 && (int)($arResult['ID'] ?? 0) > 0)
	{
		$rsP = \CIBlockElement::GetProperty((int)$arResult['IBLOCK_ID'], (int)$arResult['ID'], ['sort' => 'asc'], ['CODE' => 'MF_EXT_IMAGES']);
		while ($p = $rsP->Fetch())
		{
			$u = trim((string)($p['VALUE'] ?? ''));
			if ($u !== '') $extImgs[] = $u;
		}
	}
	// Fallback: if element has no MF_EXT_IMAGES, try supplier meta table (reverse lookup).
	if (empty($extImgs) && function_exists('mf_analogs_meta_images_for_product'))
	{
		$extImgs = mf_analogs_meta_images_for_product((int)($arResult['ID'] ?? 0));
	}
	$extImgs = array_values(array_filter(array_map('trim', $extImgs), static fn($s) => $s !== ''));
	if (!empty($extImgs))
	{
		$arResult['MORE_PHOTO'] = [];
		foreach ($extImgs as $u)
		{
			$arResult['MORE_PHOTO'][] = ['SRC' => (string)$u];
		}
	}

	$code = trim((string)($arResult['CODE'] ?? ''));
	if ($code !== '' && empty($extImgs))
	{
		// Main gallery
		if (isset($arResult['MORE_PHOTO']) && is_array($arResult['MORE_PHOTO']))
		{
			$i = 0;
			foreach ($arResult['MORE_PHOTO'] as $k => $p)
			{
				$i++;
				$src = mf_mf_product_img_url($code, $i);
				if ($src === '')
				{
					continue;
				}
				if (!is_array($p))
				{
					$arResult['MORE_PHOTO'][$k] = ['SRC' => $src];
				}
				else
				{
					$arResult['MORE_PHOTO'][$k]['SRC'] = $src;
				}
			}
		}

		// Offers gallery (if any)
		if (isset($arResult['OFFERS']) && is_array($arResult['OFFERS']))
		{
			foreach ($arResult['OFFERS'] as $ok => $offer)
			{
				if (empty($offer['MORE_PHOTO']) || !is_array($offer['MORE_PHOTO']))
				{
					continue;
				}
				$i = 0;
				foreach ($offer['MORE_PHOTO'] as $k => $p)
				{
					$i++;
					$src = mf_mf_product_img_url($code, $i);
					if ($src === '')
					{
						continue;
					}
					if (!is_array($p))
					{
						$arResult['OFFERS'][$ok]['MORE_PHOTO'][$k] = ['SRC' => $src];
					}
					else
					{
						$arResult['OFFERS'][$ok]['MORE_PHOTO'][$k]['SRC'] = $src;
					}
				}
			}
		}
	}
}

// Force correct availability from store stocks (import writes per-store amounts).
// Bitrix may compute CAN_BUY differently depending on global catalog settings; we want:
// "нет на складе" -> not available for purchase.
if (is_array($arResult) && \CModule::IncludeModule('catalog'))
{
	$productId = (int)($arResult['ID'] ?? 0);
	if ($productId > 0)
	{
		$sum = 0.0;
		$cluster = function_exists('mf_catalog_product_cluster_ids')
			? mf_catalog_product_cluster_ids($productId)
			: [$productId];
		foreach ($cluster as $cid)
		{
			$rs = \CCatalogStoreProduct::GetList([], ['PRODUCT_ID' => (int)$cid], false, false, ['AMOUNT']);
			while ($r = $rs->Fetch())
			{
				$sum += (float)($r['AMOUNT'] ?? 0);
			}
		}
		$canBuy = ($sum > 0);
		$arResult['CAN_BUY'] = $canBuy;
		$arResult['CATALOG_QUANTITY'] = $sum;
		$arResult['CATALOG_AVAILABLE'] = $canBuy ? 'Y' : 'N';
		// У товаров с SKU шаблон берёт $actualItem из $arResult['OFFERS'][*], не из корня — иначе CAN_BUY остаётся false.
		if (!empty($arResult['OFFERS']) && is_array($arResult['OFFERS']))
		{
			foreach ($arResult['OFFERS'] as $k => $_offer)
			{
				$arResult['OFFERS'][$k]['CAN_BUY'] = $canBuy;
				$arResult['OFFERS'][$k]['CATALOG_QUANTITY'] = $sum;
				$arResult['OFFERS'][$k]['CATALOG_AVAILABLE'] = $canBuy ? 'Y' : 'N';
				if (isset($arResult['OFFERS'][$k]['PRODUCT']) && is_array($arResult['OFFERS'][$k]['PRODUCT']))
				{
					$arResult['OFFERS'][$k]['PRODUCT']['QUANTITY'] = $sum;
					if ($canBuy)
					{
						$arResult['OFFERS'][$k]['PRODUCT']['AVAILABLE'] = 'Y';
					}
				}
			}
		}
	}
}

// Delegate rendering to the stock Bitrix template.
$templateFolder = '/bitrix/components/bitrix/catalog.element/templates/bootstrap_v4';
include($_SERVER['DOCUMENT_ROOT'] . $templateFolder . '/template.php');

