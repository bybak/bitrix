<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	// allow include without Bitrix prolog in rare cases
}

global $APPLICATION;

$sort = (string)($_GET['mf_sort'] ?? 'default');
$view = (string)($_GET['mf_view'] ?? 'grid');
if (!in_array($view, ['grid', 'tiles', 'list'], true)) $view = 'grid';

// Preserve only REAL query-string params (Bitrix SEF variables may appear in $_GET and must not be re-emitted).
$qs = [];
parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $qs);
if (!is_array($qs)) $qs = [];
foreach (array_keys($qs) as $k)
{
	if (strpos($k, 'PAGEN_') === 0) unset($qs[$k]);
	if (strpos($k, 'SIZEN_') === 0) unset($qs[$k]);
}
unset($qs['mf_sort'], $qs['mf_view']);
// SEF pages already carry section in path; duplicating SECTION_CODE in query may cause 404 in Bitrix routing.
unset($qs['SECTION_CODE'], $qs['SECTION_ID']);

// IMPORTANT:
// - On some setups REQUEST_URI / GetCurPage() may point to a physical script (e.g. /products/section.php),
//   while the user-facing URL is SEF (/products/category/.../). That breaks navigation (404).
// - Prefer explicit base path if provided, otherwise derive SEF path from SECTION_CODE.
$basePath = (string)($GLOBALS['MF_SHOP_BASEPATH'] ?? '');
$basePath = trim($basePath);

if ($basePath === '')
{
	// Derive SEF base URL for the Shop section.
	$siteDir = defined('SITE_DIR') ? (string)SITE_DIR : '/';
	$siteDir = $siteDir !== '' ? $siteDir : '/';

	$sec = trim((string)($_REQUEST['SECTION_CODE'] ?? ''));
	if ($sec !== '')
	{
		$basePath = rtrim($siteDir, '/') . '/products/category/' . $sec . '/';
	}
	else
	{
		$basePath = rtrim($siteDir, '/') . '/products/';
	}
}

// Fallback to request path if still empty
if ($basePath === '')
{
	$basePath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
}
if ($basePath === '') $basePath = '/';

$buildUrl = function(array $add) use ($basePath, $qs): string
{
	$all = $qs;
	foreach ($add as $k => $v)
	{
		if ($v === null) unset($all[$k]);
		else $all[$k] = $v;
	}
	$q = http_build_query($all);
	return $q ? ($basePath . '?' . $q) : $basePath;
};

$sortOptions = [
	'default' => 'Без сортировки',
	'name_asc' => 'По названию (А‑Я)',
	'name_desc' => 'По названию (Я‑А)',
	'price_asc' => 'По цене (сначала дешевле)',
	'price_desc' => 'По цене (сначала дороже)',
];
if (!isset($sortOptions[$sort])) $sort = 'default';
?>
<div class="mf-shop-toolbar" aria-label="Управление списком товаров">
	<form class="mf-shop-sort" action="<?=$basePath?>" method="get">
		<?foreach ($qs as $k => $v):?>
			<?if (is_array($v)) continue;?>
			<input type="hidden" name="<?=htmlspecialcharsbx((string)$k)?>" value="<?=htmlspecialcharsbx((string)$v)?>" />
		<?endforeach;?>
		<input type="hidden" name="mf_view" value="<?=htmlspecialcharsbx($view)?>" />
		<select class="mf-shop-sort__select" name="mf_sort" onchange="this.form.submit()">
			<?foreach ($sortOptions as $val => $label):?>
				<option value="<?=htmlspecialcharsbx($val)?>"<?=$val===$sort?' selected':''?>><?=htmlspecialcharsbx($label)?></option>
			<?endforeach;?>
		</select>
	</form>

	<div class="mf-shop-view" role="group" aria-label="Вид товаров">
		<a class="mf-shop-view__btn<?=$view==='list'?' is-active':''?>" href="<?=$buildUrl(['mf_view'=>'list'])?>" aria-label="Список">
			<svg width="22" height="22" viewBox="0 0 22 22" fill="currentColor" aria-hidden="true">
				<path d="M4 6h14v2H4V6zm0 4h14v2H4v-2zm0 4h14v2H4v-2z"/>
			</svg>
		</a>
		<a class="mf-shop-view__btn<?=$view==='tiles'?' is-active':''?>" href="<?=$buildUrl(['mf_view'=>'tiles'])?>" aria-label="Плитка">
			<svg width="22" height="22" viewBox="0 0 22 22" fill="currentColor" aria-hidden="true">
				<path d="M4 5h6v6H4V5zm8 0h6v6h-6V5zM4 13h6v6H4v-6zm8 0h6v6h-6v-6z"/>
			</svg>
		</a>
		<a class="mf-shop-view__btn<?=$view==='grid'?' is-active':''?>" href="<?=$buildUrl(['mf_view'=>'grid'])?>" aria-label="Сетка">
			<svg width="22" height="22" viewBox="0 0 22 22" fill="currentColor" aria-hidden="true">
				<path d="M4 4h4v4H4V4zm5 0h4v4H9V4zm5 0h4v4h-4V4zM4 9h4v4H4V9zm5 0h4v4H9V9zm5 0h4v4h-4V9zM4 14h4v4H4v-4zm5 0h4v4H9v-4zm5 0h4v4h-4v-4z"/>
			</svg>
		</a>
	</div>
</div>

