<?if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED!==true) die();
/**
 * @global array $arParams
 * @global CUser $USER
 * @global CMain $APPLICATION
 * @global string $cartId
 */
$compositeStub = (isset($arResult['COMPOSITE_STUB']) && $arResult['COMPOSITE_STUB'] == 'Y');
$count = $compositeStub ? 0 : (int)($arResult['NUM_PRODUCTS'] ?? 0);
?>
<div class="basket-line">
	<div class="basket-line-block">
		<?if (!$arResult["DISABLE_USE_BASKET"]):?>
			<a class="mf-cart-link" href="<?=$arParams['PATH_TO_BASKET']?>" aria-label="<?=htmlspecialcharsbx(GetMessage('TSB1_CART'))?>">
				<span class="mf-cart-icon" aria-hidden="true">
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 13 11" fill="currentColor">
						<path d="M12.267 1.62c.187 0 .363.075.475.224.112.15.15.337.1.51l-1.162 4.811a.58.58 0 0 1-.587.46H4.897a.58.58 0 0 1-.587-.46L2.672 1.785H1.46a.599.599 0 1 1 0-1.197h1.687c.274 0 .524.187.587.461l.413.539 8.12.032zM6.554 9.556a1.082 1.082 0 1 1-2.164 0 1.082 1.082 0 0 1 2.164 0zm4.887 0a1.082 1.082 0 1 1-2.164 0 1.082 1.082 0 0 1 2.164 0z" transform="translate(-.647 -.588)"/>
					</svg>
				</span>
				<?if ($arParams['SHOW_NUM_PRODUCTS'] == 'Y' && ($count > 0 || ($arParams['SHOW_EMPTY_VALUES'] ?? 'N') == 'Y')):?>
					<span class="mf-cart-count" data-role="mf-cart-count"><?=$count?></span>
				<?endif?>
			</a>
		<?endif?>
	</div>
</div>

