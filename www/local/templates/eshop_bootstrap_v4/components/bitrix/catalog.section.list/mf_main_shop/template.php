<?if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

if (empty($arResult["SECTIONS"]))
{
	return;
}
?>
<article class="catalog__list catalog__list_250x330">
	<?foreach ($arResult["SECTIONS"] as $arSection):?>
		<?php
		$sectionId = (int)($arSection["ID"] ?? 0);
		$name = htmlspecialcharsbx((string)($arSection["NAME"] ?? ""));
		$url = (string)($arSection["SECTION_PAGE_URL"] ?? "");
		$img = function_exists('mf_mf_section_img_url') ? (string)mf_mf_section_img_url($sectionId) : "";
		?>
		<div class="catalog__category">
			<div class="category-item category-item" id="item<?=$sectionId?>">
				<a href="<?=$url?>" class="category-item__preview category-item__preview_250x330">
					<?php if ($img !== ""): ?>
						<img
							src="<?=$img?>"
							alt="<?=$name?>"
							class="category-item__image"
							title="<?=$name?>"
							loading="lazy"
							width="250"
						/>
					<?php else: ?>
						<div class="category-item__no-photo" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64" fill="none">
								<rect x="10" y="16" width="44" height="32" rx="3" stroke="rgba(0,0,0,.35)" stroke-width="3"/>
								<path d="M18 41l10-10 8 8 6-6 10 10" stroke="rgba(0,0,0,.35)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
								<circle cx="26" cy="26" r="4" fill="rgba(0,0,0,.35)"/>
							</svg>
						</div>
					<?php endif; ?>
				</a>
				<div class="category-item__link">
					<a href="<?=$url?>"><?=$name?></a>
				</div>
			</div>
		</div>
	<?endforeach;?>
</article>

