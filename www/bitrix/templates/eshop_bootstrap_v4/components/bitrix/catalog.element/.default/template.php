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
	$code = trim((string)($arResult['CODE'] ?? ''));
	if ($code !== '')
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

// Delegate rendering to the stock Bitrix template.
$templateFolder = '/bitrix/components/bitrix/catalog.element/templates/.default';
include($_SERVER['DOCUMENT_ROOT'] . $templateFolder . '/template.php');

