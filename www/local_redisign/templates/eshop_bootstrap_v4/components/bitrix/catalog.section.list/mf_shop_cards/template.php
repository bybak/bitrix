<?if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

if (empty($arResult["SECTIONS"]))
{
	return;
}
?>
<div class="mf-shop-cards">
	<?foreach ($arResult["SECTIONS"] as $arSection):?>
		<?php
		$name = htmlspecialcharsbx((string)($arSection["NAME"] ?? ""));
		$url = (string)($arSection["SECTION_PAGE_URL"] ?? "");
		$img = function_exists('mf_mf_section_img_url')
			? (string)mf_mf_section_img_url((int)($arSection["ID"] ?? 0))
			: "";
		$imgClass = ($img !== "") ? " mf-ccard__media--has-img" : "";
		?>
		<a class="mf-ccard" href="<?=$url?>">
			<div class="mf-ccard__media<?=$imgClass?>">
				<?php if ($img !== ""): ?>
					<img
						class="mf-ccard__img"
						src="<?=$img?>"
						alt="<?=$name?>"
						loading="lazy"
					/>
				<?php endif; ?>
			</div>
			<div class="mf-ccard__body">
				<div class="mf-ccard__title"><?=$name?></div>
				<div class="mf-ccard__hint">Смотреть товары</div>
			</div>
		</a>
	<?endforeach;?>
</div>

