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
global $USER;
$mfReqIsAuthorized = is_object($USER) && method_exists($USER, 'IsAuthorized') && $USER->IsAuthorized();
$mfReqUserName = '';
$mfReqUserEmail = '';
if ($mfReqIsAuthorized)
{
	$mfReqUserName = trim((string)$USER->GetFirstName() . ' ' . (string)$USER->GetLastName());
	if ($mfReqUserName === '')
	{
		$mfReqUserName = trim((string)$USER->GetLogin());
	}
	$mfReqUserEmail = trim((string)$USER->GetEmail());
}
$mfReqProductName = trim(html_entity_decode(strip_tags((string)($item['NAME'] ?? $productTitle ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$mfReqProductUrl = (string)($item['DETAIL_PAGE_URL'] ?? '');

// Витринная цена «От»: минимум по всем складам (в т.ч. «Под заказ»).
$mfDynPrice = null;
if (function_exists('mf_catalog_listing_display_price'))
{
	$p = mf_catalog_listing_display_price((int)($actualItem['ID'] ?? 0));
	if ($p !== null && (float)$p > 0)
	{
		$mfDynPrice = function_exists('mf_round_price') ? mf_round_price((float)$p) : (float)ceil((float)$p);
	}
}

// Force availability from store stocks (import writes per-store amounts).
if (\CModule::IncludeModule('catalog'))
{
	$pid = (int)($actualItem['ID'] ?? 0);
		if ($pid > 0)
		{
			$sum = 0.0;
			$clusterIds = function_exists('mf_catalog_product_cluster_ids')
				? mf_catalog_product_cluster_ids($pid)
				: [$pid];
			foreach ($clusterIds as $cid)
			{
				$cid = (int)$cid;
				if ($cid <= 0)
				{
					continue;
				}
				$rs = \CCatalogStoreProduct::GetList([], ['PRODUCT_ID' => $cid], false, false, ['AMOUNT']);
				while ($r = $rs->Fetch())
				{
					$sum += (float)($r['AMOUNT'] ?? 0);
				}
			}
			$canBuy = ($sum > 0);
		$actualItem['CAN_BUY'] = $canBuy;
		$item['CAN_BUY'] = $canBuy;
	}
}

$mfAddStoreId = 0;
if ($canBuy && function_exists('mf_catalog_listing_preferred_store_id'))
{
	$mfAddStoreId = (int)mf_catalog_listing_preferred_store_id((int)($actualItem['ID'] ?? 0));
}

$code = trim((string)($item['CODE'] ?? ''));
$placeholder = function_exists('mf_mf_placeholder_img_url') ? (string)mf_mf_placeholder_img_url() : '';
$productId = (int)($item['ID'] ?? 0);
$imgSrc = '';
if (function_exists('mf_mf_product_card_preview_src'))
{
	$imgSrc = (string)mf_mf_product_card_preview_src(
		$productId,
		$code,
		is_array($item) ? $item : null,
		(int)($item['IBLOCK_ID'] ?? 4)
	);
}
elseif ($code !== '' && function_exists('mf_mf_product_img_url'))
{
	$imgSrc = (string)mf_mf_product_img_url($code, 1);
}
if ($imgSrc === '')
{
	$imgSrc = $placeholder;
}
$imgIsPlaceholder = function_exists('mf_mf_is_placeholder_img_url')
	? mf_mf_is_placeholder_img_url($imgSrc)
	: (strpos($imgSrc, 'mf-no-photo') !== false);

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
			<a class="mf-pline__media-link<?=($imgIsPlaceholder ? ' mf-pline__media-link--placeholder' : '')?>" href="<?=$item['DETAIL_PAGE_URL']?>" title="<?=$imgTitle?>" data-entity="image-wrapper">
		<?php else: ?>
			<span class="mf-pline__media-link<?=($imgIsPlaceholder ? ' mf-pline__media-link--placeholder' : '')?>" data-entity="image-wrapper">
		<?php endif; ?>
				<span class="product-item-image-original" id="<?=$itemIds['PICT']?>">
					<img class="mf-pline__media-img<?=($imgIsPlaceholder ? ' mf-img--placeholder' : '')?>" src="<?=$imgSrc?>" alt="" aria-hidden="true"<?=($imgIsPlaceholder ? '' : ' loading="lazy"')?> decoding="async" />
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
			<div class="mf-product-meta" aria-label="Бренд и артикул">
				<?php if ($brand !== ''): ?>
					<div class="mf-product-meta__item">
						<span class="mf-product-meta__label">Бренд:</span>
						<span class="mf-product-meta__value"><?=htmlspecialcharsbx($brand)?></span>
					</div>
				<?php endif; ?>
				<?php if ($article !== ''): ?>
					<div class="mf-product-meta__item">
						<span class="mf-product-meta__label">Артикул:</span>
						<span class="mf-product-meta__value"><?=htmlspecialcharsbx($article)?></span>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="mf-pline__side">
		<div class="mf-pline__price product-item-price-container" data-entity="price-block">
			<?php if ($arParams['SHOW_OLD_PRICE'] === 'Y' && !empty($price)): ?>
				<span class="mf-pline__old product-item-price-old" id="<?=$itemIds['PRICE_OLD']?>"
					<?=($price['RATIO_PRICE'] >= $price['RATIO_BASE_PRICE'] ? 'style="display: none;"' : '')?>>
					<?= (function_exists('mf_format_display_price_rub') && isset($price['RATIO_BASE_PRICE']))
						? htmlspecialcharsbx(mf_format_display_price_rub((float)$price['RATIO_BASE_PRICE']))
						: (string)$price['PRINT_RATIO_BASE_PRICE'] ?>
				</span>
			<?php endif; ?>
			<span class="mf-pline__cur product-item-price-current" id="<?=$itemIds['PRICE']?>">
				<?php
				$mfLinePriceHtml = 'Запросить цену';
				if ($canBuy)
				{
					$mfLineAmount = null;
					if ($mfDynPrice !== null && $mfDynPrice > 0)
					{
						$mfLineAmount = (float)$mfDynPrice;
					}
					elseif (function_exists('mf_catalog_use_bitrix_base_price_fallback') && mf_catalog_use_bitrix_base_price_fallback() && !empty($price['RATIO_PRICE']))
					{
						$mfLineAmount = function_exists('mf_round_price')
							? mf_round_price((float)$price['RATIO_PRICE'])
							: (float)ceil((float)$price['RATIO_PRICE']);
					}
					if ($mfLineAmount !== null && $mfLineAmount > 0)
					{
						$mfLinePriceHtml = function_exists('mf_format_display_price_rub')
							? ('От ' . htmlspecialcharsbx(mf_format_display_price_rub($mfLineAmount)))
							: ('От ' . htmlspecialcharsbx(number_format($mfLineAmount, 0, '.', ' ')) . ' &#8381;');
					}
				}
				echo $mfLinePriceHtml;
				?>
			</span>
		</div>

		<div class="mf-pline__actions" data-entity="buttons-block">
			<?php // Keep quantity input for Bitrix JS (no UI for quantity selector). ?>
			<input type="hidden" id="<?=$itemIds['QUANTITY']?>" name="<?=$arParams['PRODUCT_QUANTITY_VARIABLE']?>" value="<?=$measureRatio?>" />

			<?php if (!$haveOffers): ?>
				<?php if ($canBuy && $mfAddStoreId > 0): ?>
					<div class="product-item-button-container" id="<?=$itemIds['BASKET_ACTIONS']?>">
						<button
							type="button"
							class="btn btn-default <?=$buttonSizeClass?> js-mf-add-store"
							data-product-id="<?= (int)($actualItem['ID'] ?? 0) ?>"
							data-store-id="<?= (int)$mfAddStoreId ?>"
							data-qty="1"
						><?=$btnText?></button>
					</div>
				<?php elseif ($canBuy): ?>
					<div class="product-item-button-container" id="<?=$itemIds['BASKET_ACTIONS']?>">
						<a class="btn btn-default <?=$buttonSizeClass?>" id="<?=$itemIds['BUY_LINK']?>" href="javascript:void(0)" rel="nofollow">
							<?=$btnText?>
						</a>
					</div>
				<?php else: ?>
					<div class="product-item-button-container">
						<button
							type="button"
							class="btn btn-default <?=$buttonSizeClass?> js-mf-request-price-global"
							data-product-id="<?= (int)($actualItem['ID'] ?? 0) ?>"
							data-product-name="<?=htmlspecialcharsbx($mfReqProductName)?>"
							data-product-url="<?=htmlspecialcharsbx($mfReqProductUrl)?>"
							data-user-name="<?=htmlspecialcharsbx($mfReqUserName)?>"
							data-user-email="<?=htmlspecialcharsbx($mfReqUserEmail)?>"
							data-user-locked="<?=$mfReqIsAuthorized ? '1' : '0'?>"
						>Запросить цену</button>
					</div>
				<?php endif; ?>
			<?php else: ?>
				<?php if ($arParams['PRODUCT_DISPLAY_MODE'] === 'Y'): ?>
					<div class="product-item-button-container">
						<button type="button" class="btn btn-default <?=$buttonSizeClass?> js-mf-request-price-global" id="<?=$itemIds['NOT_AVAILABLE_MESS']?>"
							data-product-id="<?= (int)($actualItem['ID'] ?? 0) ?>"
							data-product-name="<?=htmlspecialcharsbx($mfReqProductName)?>"
							data-product-url="<?=htmlspecialcharsbx($mfReqProductUrl)?>"
							data-user-name="<?=htmlspecialcharsbx($mfReqUserName)?>"
							data-user-email="<?=htmlspecialcharsbx($mfReqUserEmail)?>"
							data-user-locked="<?=$mfReqIsAuthorized ? '1' : '0'?>"
							<?=($actualItem['CAN_BUY'] ? 'style="display: none;"' : '')?>>
							Запросить цену
						</button>
						<div id="<?=$itemIds['BASKET_ACTIONS']?>" <?=($actualItem['CAN_BUY'] ? '' : 'style="display: none;"')?>>
							<?php if ($mfAddStoreId > 0): ?>
								<button
									type="button"
									class="btn btn-default <?=$buttonSizeClass?> js-mf-add-store"
									data-product-id="<?= (int)($actualItem['ID'] ?? 0) ?>"
									data-store-id="<?= (int)$mfAddStoreId ?>"
									data-qty="1"
								><?=$btnText?></button>
							<?php else: ?>
								<a class="btn btn-default <?=$buttonSizeClass?>" id="<?=$itemIds['BUY_LINK']?>" href="javascript:void(0)" rel="nofollow">
									<?=$btnText?>
								</a>
							<?php endif; ?>
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

