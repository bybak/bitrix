<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Localization\Loc;

// Ensure Bitrix catalog item JS is present (binds "add to basket" handlers).
if (is_object($this) && method_exists($this, 'addExternalJs'))
{
	$this->addExternalJs('/bitrix/components/bitrix/catalog.item/templates/.default/script.js');
}

/**
 * Redesigned product card (list/line).
 *
 * @global CMain $APPLICATION
 * @var array $arParams
 * @var array $item
 * @var array $actualItem
 * @var array $minOffer
 * @var array $itemIds
 * @var array|null $price
 * @var float|int|null $measureRatio
 * @var bool $haveOffers
 * @var bool $showSubscribe
 * @var array $morePhoto
 * @var bool $showSlider
 * @var bool $itemHasDetailUrl
 * @var string $imgTitle
 * @var string $productTitle
 * @var string $buttonSizeClass
 * @var string $discountPositionClass
 * @var string $labelPositionClass
 * @var CatalogSectionComponent $component
 */

$btnText = ($arParams['ADD_TO_BASKET_ACTION'] === 'BUY')
	? $arParams['MESS_BTN_BUY']
	: $arParams['MESS_BTN_ADD_TO_BASKET'];

$canBuy = (!$haveOffers && !empty($actualItem['CAN_BUY'])) || ($haveOffers && !empty($actualItem['CAN_BUY']));

// Dynamic min price across available stores (RAW per store + markup), optional wholesale -10%.
$mfDynPrice = null;
if (function_exists('mf_min_price_from_available_stores'))
{
	[$p] = mf_min_price_from_available_stores((int)($actualItem['ID'] ?? 0));
	if ($p !== null && (float)$p > 0)
	{
		$mfDynPrice = (float)$p;
		if (function_exists('mf_user_is_wholesale') && mf_user_is_wholesale())
		{
			$mfDynPrice = round($mfDynPrice * 0.9, 2);
		}
	}
}

// Force availability from store stocks (import writes per-store amounts).
if (\CModule::IncludeModule('catalog'))
{
	$pid = (int)($actualItem['ID'] ?? 0);
	if ($pid > 0)
	{
		$sum = 0.0;
		$rs = \CCatalogStoreProduct::GetList([], ['PRODUCT_ID' => $pid], false, false, ['AMOUNT']);
		while ($r = $rs->Fetch())
		{
			$sum += (float)($r['AMOUNT'] ?? 0);
		}
		$canBuy = ($sum > 0);
		$actualItem['CAN_BUY'] = $canBuy;
		$item['CAN_BUY'] = $canBuy;
	}
}

$code = trim((string)($item['CODE'] ?? ''));
$placeholder = function_exists('mf_mf_placeholder_img_url') ? (string)mf_mf_placeholder_img_url() : '';
$imgSrc = function_exists('mf_mf_product_img_url') ? (string)mf_mf_product_img_url($code, 1) : '';
if ($imgSrc === '')
{
	$imgSrc = $placeholder;
}

// Brand + article (SKU) for line meta.
$mfNormVal = static function($v): string
{
	if (is_array($v))
	{
		$v = reset($v);
	}
	$v = trim((string)$v);
	return $v;
};
$mfGetProp = static function(array $src, string $code) use ($mfNormVal): string
{
	if (isset($src['DISPLAY_PROPERTIES'][$code]))
	{
		$p = $src['DISPLAY_PROPERTIES'][$code];
		$v = $p['DISPLAY_VALUE'] ?? ($p['VALUE'] ?? '');
		return $mfNormVal($v);
	}
	if (isset($src['DISPLAY_PROPERTIES']) && is_array($src['DISPLAY_PROPERTIES']))
	{
		foreach ($src['DISPLAY_PROPERTIES'] as $p)
		{
			if (is_array($p) && (string)($p['CODE'] ?? '') === $code)
			{
				$v = $p['DISPLAY_VALUE'] ?? ($p['VALUE'] ?? '');
				return $mfNormVal($v);
			}
		}
	}
	if (isset($src['PROPERTIES'][$code]))
	{
		$p = $src['PROPERTIES'][$code];
		$v = $p['VALUE'] ?? '';
		return $mfNormVal($v);
	}
	if (isset($src['PROPERTIES']) && is_array($src['PROPERTIES']))
	{
		foreach ($src['PROPERTIES'] as $p)
		{
			if (is_array($p) && (string)($p['CODE'] ?? '') === $code)
			{
				$v = $p['VALUE'] ?? '';
				return $mfNormVal($v);
			}
		}
	}
	if (array_key_exists($code, $src))
	{
		return $mfNormVal($src[$code]);
	}
	return '';
};

$brand = '';
foreach (['MF_BRAND', 'CML2_MANUFACTURER', 'BRAND', 'MANUFACTURER', 'MF_BRAND_NORM'] as $c)
{
	$brand = $mfGetProp($item, $c);
	if ($brand === '' && is_array($actualItem)) $brand = $mfGetProp($actualItem, $c);
	if ($brand !== '') break;
}
$article = '';
foreach (['CML2_ARTICLE', 'MF_ARTICLE_NORM', 'ARTNUMBER', 'ARTICLE'] as $c)
{
	$article = $mfGetProp($item, $c);
	if ($article === '' && is_array($actualItem)) $article = $mfGetProp($actualItem, $c);
	if ($article !== '') break;
}

// Fallback: if properties weren't selected in catalog.section result, fetch by ID.
if (($brand === '' || $article === '') && \Bitrix\Main\Loader::includeModule('iblock'))
{
	static $mfPropsById = [];
	$pid = (int)($item['ID'] ?? 0);
	$iblockId = (int)($item['IBLOCK_ID'] ?? 0);
	if ($pid > 0 && !isset($mfPropsById[$pid]))
	{
		$filter = ['ID' => $pid];
		// Some component result arrays don't include IBLOCK_ID; don't break the query with null.
		if ($iblockId > 0)
		{
			$filter['IBLOCK_ID'] = $iblockId;
		}
		$r = \CIBlockElement::GetList(
			[],
			$filter,
			false,
			['nTopCount' => 1],
			[
				'ID',
				'PROPERTY_MF_BRAND',
				'PROPERTY_CML2_ARTICLE',
				'PROPERTY_MF_ARTICLE_NORM',
				'PROPERTY_MF_BRAND_NORM',
			]
		)->Fetch();
		$mfPropsById[$pid] = [
			'brand' => trim((string)($r['PROPERTY_MF_BRAND_VALUE'] ?? ($r['PROPERTY_MF_BRAND_NORM_VALUE'] ?? ''))),
			'article' => trim((string)($r['PROPERTY_CML2_ARTICLE_VALUE'] ?? ($r['PROPERTY_MF_ARTICLE_NORM_VALUE'] ?? ''))),
		];
	}
	if ($pid > 0 && isset($mfPropsById[$pid]))
	{
		if ($brand === '') $brand = $mfPropsById[$pid]['brand'];
		if ($article === '') $article = $mfPropsById[$pid]['article'];
	}
}
?>

<article class="mf-pline" data-entity="item-line">
	<div class="mf-pline__media">
		<?php if ($itemHasDetailUrl): ?>
			<a class="mf-pline__media-link" href="<?=$item['DETAIL_PAGE_URL']?>" title="<?=$imgTitle?>" data-entity="image-wrapper">
		<?php else: ?>
			<span class="mf-pline__media-link" data-entity="image-wrapper">
		<?php endif; ?>
				<span class="product-item-image-original" id="<?=$itemIds['PICT']?>">
					<img class="mf-pline__media-img" src="<?=$imgSrc?>" alt="" aria-hidden="true" loading="lazy" decoding="async" />
				</span>
				<span style="display:none" aria-hidden="true">
					<span class="product-item-image-slider-slide-container slide" id="<?=$itemIds['PICT_SLIDER']?>"></span>
					<?php if (!empty($itemIds['SECOND_PICT'])): ?>
						<span class="product-item-image-alternative" id="<?=$itemIds['SECOND_PICT']?>"></span>
					<?php endif; ?>
					<div class="product-item-image-slider-control-container" id="<?=$itemIds['PICT_SLIDER']?>_indicator"></div>
				</span>
		<?php if ($itemHasDetailUrl): ?>
			</a>
		<?php else: ?>
			</span>
		<?php endif; ?>
	</div>

	<div class="mf-pline__main">
		<h3 class="mf-pline__title product-item-title">
			<? if ($itemHasDetailUrl): ?><a href="<?=$item['DETAIL_PAGE_URL']?>" title="<?=$productTitle?>"><? endif; ?>
				<?=$productTitle?>
			<? if ($itemHasDetailUrl): ?></a><? endif; ?>
		</h3>
		<?php if ($brand !== '' || $article !== ''): ?>
			<div class="mf-pline__sub" title="<?=htmlspecialcharsbx(trim($brand . ' ' . $article))?>">
				<?php if ($brand !== ''): ?>
					<span class="mf-pline__sub-brand"><?=htmlspecialcharsbx($brand)?></span>
				<?php endif; ?>
				<?php if ($brand !== '' && $article !== ''): ?>
					<span class="mf-pline__sub-sep" aria-hidden="true">•</span>
				<?php endif; ?>
				<?php if ($article !== ''): ?>
					<span class="mf-pline__sub-article">арт. <?=htmlspecialcharsbx($article)?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="mf-pline__side">
		<div class="mf-pline__price product-item-price-container" data-entity="price-block">
			<?php if ($arParams['SHOW_OLD_PRICE'] === 'Y' && !empty($price)): ?>
				<span class="mf-pline__old product-item-price-old" id="<?=$itemIds['PRICE_OLD']?>"
					<?=($price['RATIO_PRICE'] >= $price['RATIO_BASE_PRICE'] ? 'style="display: none;"' : '')?>>
					<?=$price['PRINT_RATIO_BASE_PRICE']?>
				</span>
			<?php endif; ?>
			<span class="mf-pline__cur product-item-price-current" id="<?=$itemIds['PRICE']?>">
				<?php
				if ($mfDynPrice !== null)
				{
					echo htmlspecialcharsbx(number_format((float)$mfDynPrice, 2, '.', ' ')) . ' &#8381;';
				}
				elseif (!empty($price))
				{
					echo $price['PRINT_RATIO_PRICE'];
				}
				else
				{
					echo 'Цена по запросу';
				}
				?>
			</span>
		</div>

		<div class="mf-pline__actions product-item-hidden" data-entity="buttons-block">
			<?php // Keep quantity input for Bitrix JS (no UI for quantity selector). ?>
			<input type="hidden" id="<?=$itemIds['QUANTITY']?>" name="<?=$arParams['PRODUCT_QUANTITY_VARIABLE']?>" value="<?=$measureRatio?>" />

			<?php if (!$haveOffers): ?>
				<?php if ($canBuy): ?>
					<div class="product-item-button-container" id="<?=$itemIds['BASKET_ACTIONS']?>">
						<a class="btn btn-default <?=$buttonSizeClass?>" id="<?=$itemIds['BUY_LINK']?>" href="javascript:void(0)" rel="nofollow">
							<?=$btnText?>
						</a>
					</div>
				<?php else: ?>
					<div class="mf-pline__na"><?=$arParams['MESS_NOT_AVAILABLE']?></div>
				<?php endif; ?>
			<?php else: ?>
				<?php if ($arParams['PRODUCT_DISPLAY_MODE'] === 'Y'): ?>
					<div class="product-item-button-container">
						<a class="btn btn-link <?=$buttonSizeClass?>" id="<?=$itemIds['NOT_AVAILABLE_MESS']?>" href="javascript:void(0)" rel="nofollow"
							<?=($actualItem['CAN_BUY'] ? 'style="display: none;"' : '')?>>
							<?=$arParams['MESS_NOT_AVAILABLE']?>
						</a>
						<div id="<?=$itemIds['BASKET_ACTIONS']?>" <?=($actualItem['CAN_BUY'] ? '' : 'style="display: none;"')?>>
							<a class="btn btn-default <?=$buttonSizeClass?>" id="<?=$itemIds['BUY_LINK']?>" href="javascript:void(0)" rel="nofollow">
								<?=$btnText?>
							</a>
						</div>
					</div>
				<?php else: ?>
					<div class="product-item-button-container">
						<a class="btn btn-default <?=$buttonSizeClass?>" href="<?=$item['DETAIL_PAGE_URL']?>"><?=$arParams['MESS_BTN_DETAIL']?></a>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
</article>

