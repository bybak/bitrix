<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	// allow include without Bitrix prolog in rare cases
}

$q = '';
if (isset($_GET['text'])) $q = (string)$_GET['text'];
else if (isset($_GET['q'])) $q = (string)$_GET['q'];
$q = trim($q);
?>
<div class="mf-shop-search" role="search" aria-label="Поиск по каталогу">
	<form class="mf-shop-search__form" action="<?=SITE_DIR?>products/search/" method="get">
		<label class="mf-shop-search__label" for="mfCatalogSearchInput">Поиск по каталогу</label>
		<input
			class="mf-shop-search__input"
			id="mfCatalogSearchInput"
			type="search"
			name="text"
			placeholder="Поиск по товарам"
			value="<?=htmlspecialcharsbx($q)?>"
			autocomplete="off"
		/>
		<button class="mf-shop-search__btn" type="submit">Найти</button>
	</form>
</div>

