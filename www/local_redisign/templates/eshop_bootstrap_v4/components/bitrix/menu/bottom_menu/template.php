<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}
/** @var array $arResult */

$this->setFrameMode(true);

if (empty($arResult))
{
	return;
}
?>
<nav class="mf-bottom-menu" aria-label="Нижнее меню">
	<ul class="mf-bottom-menu__list">
		<?php foreach ($arResult as $arItem): ?>
			<?php if ((string)$arItem["DEPTH_LEVEL"] !== "1") { continue; } ?>
			<li class="mf-bottom-menu__item">
				<a class="mf-bottom-menu__link" href="<?= htmlspecialcharsbx($arItem["LINK"]) ?>"><?= htmlspecialcharsbx($arItem["TEXT"], ENT_COMPAT, false) ?></a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
