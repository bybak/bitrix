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
	$code = trim((string)($arResult['CODE'] ?? ''));
	if ($code !== '')
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

// Delegate rendering to the stock Bitrix template.
$templateFolder = '/bitrix/components/bitrix/catalog.element/templates/bootstrap_v4';
include($_SERVER['DOCUMENT_ROOT'] . $templateFolder . '/template.php');

