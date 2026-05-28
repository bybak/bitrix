<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

// Rewrite gallery image URLs to external host (no downloads into Bitrix).
// We replace SRC with:
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
			$src = function_exists('mf_mf_normalize_img_url')
				? mf_mf_normalize_img_url((string)$u)
				: (string)$u;
			if ($src === '')
			{
				continue;
			}
			$arResult['MORE_PHOTO'][] = ['SRC' => $src, 'ID' => 0];
		}
		$arResult['MORE_PHOTO_COUNT'] = count($arResult['MORE_PHOTO']);
	}

	// Only rewrite to deterministic host when we don't have explicit external images.
	$code = trim((string)($arResult['CODE'] ?? ''));
	if ($code !== '' && empty($extImgs))
	{
		// Ensure we have at least one picture for the template to render.
		if (empty($arResult['MORE_PHOTO']) || !is_array($arResult['MORE_PHOTO']))
		{
			$arResult['MORE_PHOTO'] = [];
		}
		if (count($arResult['MORE_PHOTO']) <= 0)
		{
			$src1 = (string)mf_mf_product_img_url($code, 1);
			if ($src1 !== '')
			{
				$arResult['MORE_PHOTO'][] = ['SRC' => $src1, 'ID' => 0];
			}
		}

		$i = 0;
		foreach ($arResult['MORE_PHOTO'] as $k => $p)
		{
			$i++;
			$src = (string)mf_mf_product_img_url($code, $i);
			if ($src === '')
			{
				continue;
			}
			if (!is_array($p))
			{
				$arResult['MORE_PHOTO'][$k] = ['SRC' => $src, 'ID' => 0];
			}
			else
			{
				$arResult['MORE_PHOTO'][$k]['SRC'] = $src;
				if (!isset($arResult['MORE_PHOTO'][$k]['ID']))
				{
					$arResult['MORE_PHOTO'][$k]['ID'] = 0;
				}
			}
		}

		$arResult['MORE_PHOTO_COUNT'] = is_array($arResult['MORE_PHOTO']) ? count($arResult['MORE_PHOTO']) : 0;

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
					$src = (string)mf_mf_product_img_url($code, $i);
					if ($src === '')
					{
						continue;
					}
					if (!is_array($p))
					{
						$arResult['OFFERS'][$ok]['MORE_PHOTO'][$k] = ['SRC' => $src, 'ID' => 0];
					}
					else
					{
						$arResult['OFFERS'][$ok]['MORE_PHOTO'][$k]['SRC'] = $src;
						if (!isset($arResult['OFFERS'][$ok]['MORE_PHOTO'][$k]['ID']))
						{
							$arResult['OFFERS'][$ok]['MORE_PHOTO'][$k]['ID'] = 0;
						}
					}
				}
			}
		}
	}
}

if (function_exists('mf_catalog_strip_stock_disclaimer') && is_array($arResult ?? null))
{
	foreach (['PREVIEW_TEXT', '~PREVIEW_TEXT', 'DETAIL_TEXT', '~DETAIL_TEXT'] as $mfTextKey)
	{
		if (!empty($arResult[$mfTextKey]) && is_string($arResult[$mfTextKey]))
		{
			$arResult[$mfTextKey] = mf_catalog_strip_stock_disclaimer($arResult[$mfTextKey]);
		}
	}
}

// Delegate rendering to the stock Bitrix template.
$templateFolder = '/bitrix/components/bitrix/catalog.element/templates/.default';
include($_SERVER['DOCUMENT_ROOT'] . $templateFolder . '/template.php');

