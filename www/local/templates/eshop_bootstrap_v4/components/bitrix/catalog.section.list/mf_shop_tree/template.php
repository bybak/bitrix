<?if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();
/** @var array $arResult */
/** @var array $arParams */

if (empty($arResult["SECTIONS"]))
{
	return;
}

$currentId = (int)($arParams['MF_CURRENT_SECTION_ID'] ?? 0);
$openSet = [];
if ($currentId > 0)
{
	$nav = CIBlockSection::GetNavChain((int)$arParams['IBLOCK_ID'], $currentId, ['ID']);
	while ($n = $nav->Fetch())
	{
		$openSet[(int)$n['ID']] = true;
	}
	$openSet[$currentId] = true;
}

$shopRootUrl = (defined('SITE_DIR') ? SITE_DIR : '/') . 'products/';
$idToUrl = [];
$idToParentUrl = [];
foreach ($arResult["SECTIONS"] as $s)
{
	$id = (int)($s['ID'] ?? 0);
	if ($id <= 0) continue;
	$idToUrl[$id] = (string)($s['SECTION_PAGE_URL'] ?? '');
}
foreach ($arResult["SECTIONS"] as $s)
{
	$id = (int)($s['ID'] ?? 0);
	if ($id <= 0) continue;
	$pid = (int)($s['IBLOCK_SECTION_ID'] ?? 0);
	if ($pid > 0 && isset($idToUrl[$pid]) && $idToUrl[$pid] !== '')
	{
		$idToParentUrl[$id] = $idToUrl[$pid];
	}
	else
	{
		$idToParentUrl[$id] = $shopRootUrl;
	}
}

$minDepth = null;
foreach ($arResult["SECTIONS"] as $s)
{
	$d = (int)($s['DEPTH_LEVEL'] ?? 0);
	if ($d <= 0) $d = 1;
	if ($minDepth === null || $d < $minDepth) $minDepth = $d;
}
if ($minDepth === null) $minDepth = 1;

$tree = [];
$stack = [
	['depth' => 0, 'children' => &$tree],
];

foreach ($arResult["SECTIONS"] as $s)
{
	$depth = (int)($s['DEPTH_LEVEL'] ?? 0);
	if ($depth <= 0) $depth = 1;
	// Normalize depths when SECTION_ID is not root
	$depth = max(1, $depth - $minDepth + 1);

	while (count($stack) > 0 && $stack[count($stack) - 1]['depth'] >= $depth)
	{
		array_pop($stack);
	}
	if (empty($stack))
	{
		$stack = [['depth' => 0, 'children' => &$tree]];
	}

	$childrenRef = &$stack[count($stack) - 1]['children'];
	$childrenRef[] = ['S' => $s, 'C' => []];
	$k = array_key_last($childrenRef);
	$stack[] = ['depth' => $depth, 'children' => &$childrenRef[$k]['C']];
}

$render = function(array $nodes, int $level) use (&$render, $openSet, $currentId, $idToParentUrl, $shopRootUrl)
{
	?>
	<ul class="mf-shop-tree__list" data-level="<?=$level?>">
		<?foreach ($nodes as $n):?>
			<?php
			$s = $n['S'];
			$id = (int)($s['ID'] ?? 0);
			$name = htmlspecialcharsbx((string)($s['NAME'] ?? ''));
			$url = (string)($s['SECTION_PAGE_URL'] ?? '');
			$parentUrl = (string)($idToParentUrl[$id] ?? $shopRootUrl);
			$children = $n['C'];
			$hasChildren = !empty($children);
			?>
			<li class="mf-shop-tree__li">
				<?if ($hasChildren):?>
					<details class="mf-shop-tree__details" data-level="<?=$level?>"<?=(isset($openSet[$id]) ? ' open' : '')?>>
						<summary class="mf-shop-tree__summary">
							<a class="mf-shop-tree__link<?=(($currentId > 0 && $id === $currentId) ? ' is-current' : '')?>" href="<?=$url?>" data-parent-url="<?=$parentUrl?>">
								<span class="mf-shop-tree__caret" aria-hidden="true"></span>
								<span class="mf-shop-tree__text"><?=$name?></span>
							</a>
						</summary>
						<?$render($children, $level + 1);?>
					</details>
				<?else:?>
					<a class="mf-shop-tree__leaf<?=(($currentId > 0 && $id === $currentId) ? ' is-current' : '')?>" href="<?=$url?>" data-parent-url="<?=$parentUrl?>"><?=$name?></a>
				<?endif?>
			</li>
		<?endforeach;?>
	</ul>
	<?php
};
?>
<nav class="mf-shop-tree" aria-label="Категории">
	<div class="mf-shop-tree__title">Категории</div>
	<?$render($tree, 1);?>
</nav>

