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
 * Redesigned product card (grid/tiles).
 *
 * We keep Bitrix JS hooks (ids/data-entity) so JCCatalogItem continues to work.
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

$code = trim((string)($item['CODE'] ?? ''));
$placeholder = function_exists('mf_mf_placeholder_img_url') ? (string)mf_mf_placeholder_img_url() : '';
$imgSrc = function_exists('mf_mf_product_img_url') ? (string)mf_mf_product_img_url($code, 1) : '';
if ($imgSrc === '')
{
	$imgSrc = $placeholder;
}
?>

<article class="mf-pcard" data-entity="item-card">
	<?php if ($itemHasDetailUrl): ?>
		<a class="mf-pcard__media" href="<?=$item['DETAIL_PAGE_URL']?>" title="<?=$imgTitle?>" data-entity="image-wrapper">
	<?php else: ?>
		<span class="mf-pcard__media" data-entity="image-wrapper">
	<?php endif; ?>
			<span class="mf-pcard__media-inner">
				<img class="mf-pcard__media-img" src="<?=$imgSrc?>" alt="" aria-hidden="true" loading="lazy" decoding="async" />
			</span>
			<span style="display:none" aria-hidden="true">
				<span class="product-item-image-slider-slide-container slide" id="<?=$itemIds['PICT_SLIDER']?>"></span>
				<span class="product-item-image-original" id="<?=$itemIds['PICT']?>"></span>
				<?php if (!empty($itemIds['SECOND_PICT'])): ?>
					<span class="product-item-image-alternative" id="<?=$itemIds['SECOND_PICT']?>"></span>
				<?php endif; ?>
				<div class="product-item-image-slider-control-container" id="<?=$itemIds['PICT_SLIDER']?>_indicator"></div>
			</span>
			<span class="mf-pcard__top">
				<?php if (!empty($price) && $arParams['SHOW_DISCOUNT_PERCENT'] === 'Y'): ?>
					<span class="mf-pcard__chip mf-pcard__chip--sale" id="<?=$itemIds['DSC_PERC']?>" <?=($price['PERCENT'] > 0 ? '' : 'style="display: none;"')?>>
						<?=-$price['PERCENT']?>%
					</span>
				<?php endif; ?>
				<span class="mf-pcard__chip <?=$canBuy ? 'mf-pcard__chip--ok' : 'mf-pcard__chip--no'?>">
					<?=$canBuy ? 'В наличии' : 'Нет в наличии'?>
				</span>
			</span>
	<?php if ($itemHasDetailUrl): ?>
		</a>
	<?php else: ?>
		</span>
	<?php endif; ?>

	<div class="mf-pcard__body">
		<h3 class="mf-pcard__title product-item-title">
			<? if ($itemHasDetailUrl): ?><a href="<?=$item['DETAIL_PAGE_URL']?>" title="<?=$productTitle?>"><? endif; ?>
				<?=$productTitle?>
			<? if ($itemHasDetailUrl): ?></a><? endif; ?>
		</h3>

		<div class="mf-pcard__meta">
			<div class="mf-pcard__price product-item-price-container" data-entity="price-block">
				<?php if ($arParams['SHOW_OLD_PRICE'] === 'Y' && !empty($price)): ?>
					<span class="mf-pcard__old product-item-price-old" id="<?=$itemIds['PRICE_OLD']?>"
						<?=($price['RATIO_PRICE'] >= $price['RATIO_BASE_PRICE'] ? 'style="display: none;"' : '')?>>
						<?=$price['PRINT_RATIO_BASE_PRICE']?>
					</span>
				<?php endif; ?>
				<span class="mf-pcard__cur product-item-price-current" id="<?=$itemIds['PRICE']?>">
					<?php
					if (!empty($price))
					{
						if ($arParams['PRODUCT_DISPLAY_MODE'] === 'N' && $haveOffers)
						{
							echo Loc::getMessage(
								'CT_BCI_TPL_MESS_PRICE_SIMPLE_MODE',
								array(
									'#PRICE#' => $price['PRINT_RATIO_PRICE'],
									'#VALUE#' => $measureRatio,
									'#UNIT#' => $minOffer['ITEM_MEASURE']['TITLE']
								)
							);
						}
						else
						{
							echo $price['PRINT_RATIO_PRICE'];
						}
					}
					else
					{
						echo 'Цена по запросу';
					}
					?>
				</span>
			</div>
		</div>

		<div class="mf-pcard__actions product-item-hidden" data-entity="buttons-block">
			<?php // Keep quantity input for Bitrix JS (no UI for quantity selector). ?>
			<input type="hidden" id="<?=$itemIds['QUANTITY']?>" name="<?=$arParams['PRODUCT_QUANTITY_VARIABLE']?>" value="<?=$measureRatio?>" />

			<?php if (!$haveOffers): ?>
				<?php if ($canBuy): ?>
					<div class="mf-pcard__btn product-item-button-container" id="<?=$itemIds['BASKET_ACTIONS']?>">
						<a class="btn btn-default <?=$buttonSizeClass?>" id="<?=$itemIds['BUY_LINK']?>" href="javascript:void(0)" rel="nofollow">
							<?=$btnText?>
						</a>
					</div>
				<?php else: ?>
					<?php if (!empty($showSubscribe)): ?>
						<div class="mf-pcard__btn product-item-button-container">
							<?php
							$APPLICATION->IncludeComponent(
								'bitrix:catalog.product.subscribe',
								'',
								array(
									'PRODUCT_ID' => $actualItem['ID'],
									'BUTTON_ID' => $itemIds['SUBSCRIBE_LINK'],
									'BUTTON_CLASS' => 'btn btn-default ' . $buttonSizeClass,
									'DEFAULT_DISPLAY' => true,
									'MESS_BTN_SUBSCRIBE' => $arParams['~MESS_BTN_SUBSCRIBE'],
								),
								$component,
								array('HIDE_ICONS' => 'Y')
							);
							?>
						</div>
					<?php endif; ?>
					<div class="mf-pcard__na"><?=$arParams['MESS_NOT_AVAILABLE']?></div>
				<?php endif; ?>
			<?php else: ?>
				<?php if ($arParams['PRODUCT_DISPLAY_MODE'] === 'Y'): ?>
					<div class="mf-pcard__btn product-item-button-container">
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
					<div class="mf-pcard__btn product-item-button-container">
						<a class="btn btn-default <?=$buttonSizeClass?>" href="<?=$item['DETAIL_PAGE_URL']?>"><?=$arParams['MESS_BTN_DETAIL']?></a>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
</article>

