<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * Premium-redesign breadcrumb. Все элементы — ссылки (если LINK задан),
 * с inline SVG-стрелкой и микроразметкой schema.org.
 *
 * @global CMain $APPLICATION
 */

global $APPLICATION;

if (empty($arResult))
{
	return '';
}

$itemSize = count($arResult);

$arrowSvg = '<svg class="mf-bcrumb__sep" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 6l6 6-6 6"/></svg>';
$homeSvg  = '<svg class="mf-bcrumb__home" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 12 9-9 9 9v9a2 2 0 0 1-2 2h-4v-7H9v7H5a2 2 0 0 1-2-2v-9Z"/></svg>';

$out = '<nav class="mf-bcrumb bx-breadcrumb" aria-label="Хлебные крошки" itemprop="http://schema.org/breadcrumb" itemscope itemtype="http://schema.org/BreadcrumbList">';

for ($index = 0; $index < $itemSize; $index++)
{
	$title = htmlspecialcharsex($arResult[$index]['TITLE']);
	$link  = (string)$arResult[$index]['LINK'];
	$arrow = ($index > 0 ? $arrowSvg : '');
	$home  = ($index === 0 ? $homeSvg : '');

	if ($link !== '')
	{
		$out .= $arrow .
			'<div class="mf-bcrumb__item bx-breadcrumb-item" id="bx_breadcrumb_' . $index . '" itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem">' .
				'<a class="mf-bcrumb__link bx-breadcrumb-item-link" href="' . htmlspecialcharsbx($link) . '" title="' . $title . '" itemprop="item">' .
					$home .
					'<span class="mf-bcrumb__text bx-breadcrumb-item-text" itemprop="name">' . $title . '</span>' .
				'</a>' .
				'<meta itemprop="position" content="' . ($index + 1) . '" />' .
			'</div>';
	}
	else
	{
		$out .= $arrow .
			'<div class="mf-bcrumb__item mf-bcrumb__item--current bx-breadcrumb-item" id="bx_breadcrumb_' . $index . '" itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem" aria-current="page">' .
				$home .
				'<span class="mf-bcrumb__text bx-breadcrumb-item-text" itemprop="name">' . $title . '</span>' .
				'<meta itemprop="position" content="' . ($index + 1) . '" />' .
			'</div>';
	}
}

$out .= '</nav>';

return $out;
