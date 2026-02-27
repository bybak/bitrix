<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

// Rewrite image URLs to external host (no downloads into Bitrix).
if (isset($arResult['ITEM']) && is_array($arResult['ITEM']) && function_exists('mf_mf_product_img_url'))
{
	$fallback = function_exists('mf_mf_placeholder_img_url') ? (string)mf_mf_placeholder_img_url() : '';
	$cssWrap = function(string $primary) use ($fallback): string
	{
		$primary = trim($primary);
		if ($primary === '' || $fallback === '')
		{
			return $primary;
		}
		// This value is later used inside url('<SRC>') in both PHP templates and JS:
		// url('<primary>'), url('<fallback>')
		return $primary . "'), url('" . $fallback;
	};

	$code = trim((string)($arResult['ITEM']['CODE'] ?? ''));
	if ($code !== '')
	{
		$src1 = $cssWrap((string)mf_mf_product_img_url($code, 1));
		if ($src1 !== '')
		{
			if (isset($arResult['ITEM']['PREVIEW_PICTURE']) && is_array($arResult['ITEM']['PREVIEW_PICTURE']))
			{
				$arResult['ITEM']['PREVIEW_PICTURE']['SRC'] = $src1;
			}
			if (isset($arResult['ITEM']['PREVIEW_PICTURE_SECOND']) && is_array($arResult['ITEM']['PREVIEW_PICTURE_SECOND']))
			{
				$arResult['ITEM']['PREVIEW_PICTURE_SECOND']['SRC'] = $src1;
			}
		}

		if (isset($arResult['ITEM']['MORE_PHOTO']) && is_array($arResult['ITEM']['MORE_PHOTO']))
		{
			$i = 0;
			foreach ($arResult['ITEM']['MORE_PHOTO'] as $k => $p)
			{
				$i++;
				$src = $cssWrap((string)mf_mf_product_img_url($code, $i));
				if ($src === '')
				{
					continue;
				}
				if (!is_array($p))
				{
					$arResult['ITEM']['MORE_PHOTO'][$k] = ['SRC' => $src];
				}
				else
				{
					$arResult['ITEM']['MORE_PHOTO'][$k]['SRC'] = $src;
				}
			}
		}

		// Offers (if any) should also use the external host + fallback.
		if (isset($arResult['ITEM']['OFFERS']) && is_array($arResult['ITEM']['OFFERS']))
		{
			foreach ($arResult['ITEM']['OFFERS'] as $ok => $offer)
			{
				if (isset($offer['PREVIEW_PICTURE']) && is_array($offer['PREVIEW_PICTURE']))
				{
					$arResult['ITEM']['OFFERS'][$ok]['PREVIEW_PICTURE']['SRC'] = $src1;
				}
				if (isset($offer['PREVIEW_PICTURE_SECOND']) && is_array($offer['PREVIEW_PICTURE_SECOND']))
				{
					$arResult['ITEM']['OFFERS'][$ok]['PREVIEW_PICTURE_SECOND']['SRC'] = $src1;
				}
				if (isset($offer['MORE_PHOTO']) && is_array($offer['MORE_PHOTO']))
				{
					$i = 0;
					foreach ($offer['MORE_PHOTO'] as $k => $p)
					{
						$i++;
						$src = $cssWrap((string)mf_mf_product_img_url($code, $i));
						if ($src === '')
						{
							continue;
						}
						if (!is_array($p))
						{
							$arResult['ITEM']['OFFERS'][$ok]['MORE_PHOTO'][$k] = ['SRC' => $src];
						}
						else
						{
							$arResult['ITEM']['OFFERS'][$ok]['MORE_PHOTO'][$k]['SRC'] = $src;
						}
					}
				}
			}
		}
	}
}

// Delegate rendering to the stock Bitrix template, but force $templateFolder to module path
// so sub-templates (card/line) keep working without copying assets.
// IMPORTANT: we include the stock main template, but we keep $templateFolder pointing to THIS template folder.
// This lets us fully redesign card/line sub-templates without copying Bitrix core assets/logic.
include($_SERVER['DOCUMENT_ROOT'] . '/bitrix/components/bitrix/catalog.item/templates/.default/template.php');

