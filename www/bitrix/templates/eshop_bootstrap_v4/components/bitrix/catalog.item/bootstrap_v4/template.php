<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

// Rewrite image URLs to external host (no downloads into Bitrix).
if (isset($arResult['ITEM']) && is_array($arResult['ITEM']))
{
	$code = (string)($arResult['ITEM']['CODE'] ?? '');
	$code = trim($code);
	if ($code !== '' && function_exists('mf_mf_product_img_url'))
	{
		$fallback = function_exists('mf_mf_placeholder_img_url') ? (string)mf_mf_placeholder_img_url() : '';
		$cssWrap = function(string $primary) use ($fallback): string
		{
			$primary = trim($primary);
			if ($primary === '' || $fallback === '')
			{
				return $primary;
			}
			return $primary . "'), url('" . $fallback;
		};

		// Main picture (0001.jpg)
		$src1 = $cssWrap(mf_mf_product_img_url($code, 1));
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

		// Slider/gallery (0001..000N.jpg) if template wants it.
		if (isset($arResult['ITEM']['MORE_PHOTO']) && is_array($arResult['ITEM']['MORE_PHOTO']))
		{
			$i = 0;
			foreach ($arResult['ITEM']['MORE_PHOTO'] as $k => $p)
			{
				$i++;
				$src = $cssWrap(mf_mf_product_img_url($code, $i));
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
	}
}

// Delegate rendering to the stock Bitrix template, but force $templateFolder to module path
// so sub-templates (card/line) keep working without copying assets.
$templateFolder = '/bitrix/components/bitrix/catalog.item/templates/bootstrap_v4';
include($_SERVER['DOCUMENT_ROOT'] . $templateFolder . '/template.php');

