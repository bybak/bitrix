<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}
IncludeTemplateLangFile(__FILE__);
/** @var array $arParams */
/** @var array $arResult */
/** @global CMain $APPLICATION */
/** @var CBitrixComponent $component */

$queryValue = (string)($arResult['REQUEST']['QUERY'] ?? '');
$queryValueAttr = htmlspecialcharsbx($queryValue);
$how = (($arResult['REQUEST']['HOW'] ?? '') === 'd') ? 'd' : 'r';
?>

<div class="mf-search">
	<div class="mf-search__panel">
		<div class="mf-search__top">
			<div class="mf-shop-search">
				<form class="mf-shop-search__form" action="" method="get">
						<label class="mf-shop-search__label" for="mfSearchInput">Поиск</label>
						<input
							id="mfSearchInput"
							class="mf-shop-search__input"
							type="text"
							name="q"
							value="<?=$queryValueAttr?>"
							placeholder="Введите запрос…"
							autocomplete="off"
						/>
						<button class="mf-shop-search__btn" type="submit"><?=GetMessage('SEARCH_GO')?></button>
						<input type="hidden" name="how" value="<?=$how?>" />
						<?php if ($arParams['SHOW_WHERE']): ?>
							<select name="where" class="mf-search__where">
								<option value=""><?=GetMessage('SEARCH_ALL')?></option>
								<?php foreach (($arResult['DROPDOWN'] ?? []) as $key => $value): ?>
									<option value="<?=htmlspecialcharsbx($key)?>"<?=((string)($arResult['REQUEST']['WHERE'] ?? '') === (string)$key) ? ' selected' : ''?>>
										<?=htmlspecialcharsbx($value)?>
									</option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>
				</form>
			</div>

				<?php if (isset($arResult['REQUEST']['ORIGINAL_QUERY'])): ?>
				<?php
				$keyboardWarning = GetMessage(
					'CT_BSP_KEYBOARD_WARNING',
					[
						'#query#' => '<a href="' . htmlspecialcharsbx($arResult['ORIGINAL_QUERY_URL']) . '">' . htmlspecialcharsbx($arResult['REQUEST']['ORIGINAL_QUERY']) . '</a>',
					]
				);
				?>
				<?php if ($keyboardWarning !== ''): ?>
					<div class="mf-search__note mf-search__note--info">
						<?=$keyboardWarning?>
					</div>
				<?php endif; ?>
				<?php endif; ?>
		</div>

			<?php if ($arResult['REQUEST']['QUERY'] === false && $arResult['REQUEST']['TAGS'] === false): ?>
				<div class="mf-search__empty">
					<strong>Введите запрос</strong>, чтобы начать поиск.
				</div>
			<?php elseif ((int)($arResult['ERROR_CODE'] ?? 0) !== 0): ?>
				<div class="mf-search__note mf-search__note--error">
					<strong><?=GetMessage('SEARCH_ERROR')?></strong><br />
					<?php ShowError($arResult['ERROR_TEXT']); ?>
				</div>
			<?php elseif (!empty($arResult['SEARCH'])): ?>
				<?php
				$total = 0;
				if (is_object($arResult['NAV_RESULT'] ?? null))
				{
					$total = (int)$arResult['NAV_RESULT']->NavRecordCount;
				}
				?>
				<div class="mf-search__summary">
					<span>Найдено: <strong><?=$total ?: count($arResult['SEARCH'])?></strong></span>
					<?php if (!empty($queryValue)): ?>
						<span>по запросу <strong>«<?=htmlspecialcharsbx($queryValue)?>»</strong></span>
					<?php endif; ?>
					<span>
						<?php if ($how === 'd'): ?>
							<a href="<?=$arResult['URL']?>&amp;how=r<?=($arResult['REQUEST']['FROM'] ? '&amp;from=' . urlencode($arResult['REQUEST']['FROM']) : '')?><?=($arResult['REQUEST']['TO'] ? '&amp;to=' . urlencode($arResult['REQUEST']['TO']) : '')?>">
								<?=GetMessage('SEARCH_SORT_BY_RANK')?>
							</a>
							<span> / </span>
							<strong><?=GetMessage('SEARCH_SORTED_BY_DATE')?></strong>
						<?php else: ?>
							<strong><?=GetMessage('SEARCH_SORTED_BY_RANK')?></strong>
							<span> / </span>
							<a href="<?=$arResult['URL']?>&amp;how=d<?=($arResult['REQUEST']['FROM'] ? '&amp;from=' . urlencode($arResult['REQUEST']['FROM']) : '')?><?=($arResult['REQUEST']['TO'] ? '&amp;to=' . urlencode($arResult['REQUEST']['TO']) : '')?>">
								<?=GetMessage('SEARCH_SORT_BY_DATE')?>
							</a>
						<?php endif; ?>
					</span>
				</div>

				<?php if (($arParams['DISPLAY_TOP_PAGER'] ?? 'N') !== 'N'): ?>
					<?=$arResult['NAV_STRING']?>
				<?php endif; ?>

				<div class="mf-search__results">
					<?php foreach ($arResult['SEARCH'] as $arItem): ?>
						<?php
						// Fix broken URLs for catalog items when IBLOCK URL templates are empty.
						$href = (string)($arItem['URL'] ?? '');
						$qs = '';
						if ($href !== '' && $href[0] === '?')
						{
							$qs = $href;
							$href = '';
						}
						if ($href === '' && (string)($arItem['MODULE_ID'] ?? '') === 'iblock')
						{
							$iblockId = (int)($arItem['PARAM2'] ?? 0);
							$itemId = (int)($arItem['ITEM_ID'] ?? 0);
							if ($iblockId === 4 && $itemId > 0 && class_exists('CIBlockElement'))
							{
								static $mfCodeCache = [];
								if (!isset($mfCodeCache[$itemId]))
								{
									$row = \CIBlockElement::GetList(
										[],
										['IBLOCK_ID' => $iblockId, 'ID' => $itemId],
										false,
										['nTopCount' => 1],
										['ID', 'CODE']
									)->Fetch();
									$mfCodeCache[$itemId] = $row ? trim((string)($row['CODE'] ?? '')) : '';
								}
								$code = (string)$mfCodeCache[$itemId];
								if ($code !== '')
								{
									$href = '/products/' . rawurlencode($code) . '/';
								}
							}
						}
						if ($href === '')
						{
							$href = (string)($arItem['URL'] ?? '');
						}
						if ($qs !== '' && $href !== '' && strpos($href, '?') === false)
						{
							$href .= $qs;
						}
						?>
						<article class="mf-search-card">
							<a class="mf-search-card__title" href="<?=htmlspecialcharsbx($href)?>">
								<?=$arItem['TITLE_FORMATED']?>
							</a>
							<?php if (!empty($arItem['BODY_FORMATED'])): ?>
								<div class="mf-search-card__snippet">
									<?=$arItem['BODY_FORMATED']?>
								</div>
							<?php endif; ?>
							<div class="mf-search-card__meta">
								<span><?=GetMessage('SEARCH_MODIFIED')?> <?=htmlspecialcharsbx($arItem['DATE_CHANGE'])?></span>
								<?php if (!empty($arItem['CHAIN_PATH'])): ?>
									<span><?=GetMessage('SEARCH_PATH')?> <?=$arItem['CHAIN_PATH']?></span>
								<?php endif; ?>
							</div>
						</article>
					<?php endforeach; ?>
				</div>

				<?php if (($arParams['DISPLAY_BOTTOM_PAGER'] ?? 'N') !== 'N'): ?>
					<div style="margin-top: 14px;">
						<?=$arResult['NAV_STRING']?>
					</div>
				<?php endif; ?>
			<?php else: ?>
				<div class="mf-search__empty">
					<strong>Ничего не найдено</strong><?php if (!empty($queryValue)): ?> по запросу «<?=htmlspecialcharsbx($queryValue)?>»<?php endif; ?>.
					<div style="margin-top: 8px;">
						Попробуйте изменить формулировку или сократить запрос.
					</div>
				</div>
			<?php endif; ?>
	</div>
</div>

