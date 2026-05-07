<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) {
	die();
}

/** @var array $arResult */

$this->setFrameMode(true);

if (empty($arResult)) {
	return;
}
?>
<ul class="nh-footer-columns__links-list">
	<?php foreach ($arResult as $arItem): ?>
		<?php if ((string)$arItem["DEPTH_LEVEL"] !== "1") { continue; } ?>
		<li class="nh-footer-columns__links-item">
			<a
				class="nh-footer-columns__link"
				href="<?= htmlspecialcharsbx($arItem["LINK"]) ?>"
				<?php if (!empty($arItem["PARAMS"]["TARGET"])): ?>target="<?= htmlspecialcharsbx($arItem["PARAMS"]["TARGET"]) ?>"<?php endif; ?>
				<?php if (!empty($arItem["PARAMS"]["REL"])): ?>rel="<?= htmlspecialcharsbx($arItem["PARAMS"]["REL"]) ?>"<?php endif; ?>
			><?= htmlspecialcharsbx($arItem["TEXT"], ENT_COMPAT, false) ?></a>
		</li>
	<?php endforeach; ?>
</ul>

