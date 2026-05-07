<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

/** @var array $arParams */
/** @var array $arResult */

$this->setFrameMode(true);

if (empty($arResult))
{
	return;
}
?>
<nav class="mf-personal-menu" aria-label="Личный кабинет">
	<ul class="mf-personal-menu__list">
		<?php foreach ($arResult as $arItem): ?>
			<?php if ((string)$arItem["DEPTH_LEVEL"] !== "1") { continue; } ?>
			<li class="mf-personal-menu__item<?= !empty($arItem["SELECTED"]) ? ' is-active' : '' ?>">
				<a class="mf-personal-menu__link" href="<?= htmlspecialcharsbx($arItem["LINK"]) ?>">
					<span class="mf-personal-menu__text"><?= htmlspecialcharsbx($arItem["TEXT"]) ?></span>
					<svg class="mf-personal-menu__arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 6l6 6-6 6"/></svg>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
