<?php

declare(strict_types=1);

use Bitrix\Main\Loader;

if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
{
	die('Admin only');
}

global $APPLICATION, $USER;

if (!is_object($USER) || !$USER->IsAdmin())
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Недостаточно прав.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

if (!Loader::includeModule('iblock'))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Нужен модуль iblock.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

$libCandidates = [
	$_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_brand_stats_lib.php',
	$_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_brand_stats_lib.php',
];
$libPath = null;
foreach ($libCandidates as $p)
{
	if (is_file($p))
	{
		$libPath = $p;
		break;
	}
}
if ($libPath === null)
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не найден mf_brand_stats_lib.php']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}
require_once $libPath;

function mf_bss_admin_h(?string $s): string
{
	$s = (string)$s;

	return function_exists('htmlspecialcharsbx') ? (string)htmlspecialcharsbx($s) : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
$activeOnly = (string)($_REQUEST['active_only'] ?? 'Y') !== 'N';
$exportableOnly = (string)($_REQUEST['exportable_only'] ?? 'Y') !== 'N';
$findBrand = trim((string)($_REQUEST['find_brand'] ?? ''));
$sort = trim((string)($_REQUEST['sort'] ?? 'brand'));
if (!in_array($sort, ['brand', 'brand_desc', 'cnt', 'cnt_desc'], true))
{
	$sort = 'brand';
}

$stats = mf_bss_load_brand_product_counts([
	'active_only' => $activeOnly,
	'exportable_only' => $exportableOnly,
	'brand_substr' => $findBrand,
]);

$rows = $stats['rows'];
usort(
	$rows,
	static function (array $a, array $b) use ($sort): int {
		switch ($sort)
		{
			case 'cnt':
				$c = ($b['cnt'] <=> $a['cnt']);
				return $c !== 0 ? $c : strnatcasecmp($a['brand'], $b['brand']);
			case 'cnt_desc':
				$c = ($a['cnt'] <=> $b['cnt']);
				return $c !== 0 ? $c : strnatcasecmp($a['brand'], $b['brand']);
			case 'brand_desc':
				return strnatcasecmp($b['brand'], $a['brand']);
			default:
				return strnatcasecmp($a['brand'], $b['brand']);
		}
	}
);

$maxCnt = 0;
foreach ($rows as $row)
{
	$maxCnt = max($maxCnt, (int)$row['cnt']);
}

$sortArrowBrand = '';
if ($sort === 'brand')
{
	$sortArrowBrand = ' ↑';
}
elseif ($sort === 'brand_desc')
{
	$sortArrowBrand = ' ↓';
}
$sortArrowCnt = '';
if ($sort === 'cnt')
{
	$sortArrowCnt = ' ↓';
}
elseif ($sort === 'cnt_desc')
{
	$sortArrowCnt = ' ↑';
}

$curPage = (string)($APPLICATION->GetCurPage() ?? 'mf_brand_stats.php');
$baseUrl = $curPage . '?lang=' . rawurlencode($lang);

$sortUrl = static function (string $col) use ($baseUrl, $activeOnly, $exportableOnly, $findBrand, $sort): string {
	$next = $col;
	if ($col === 'brand' && $sort === 'brand')
	{
		$next = 'brand_desc';
	}
	elseif ($col === 'cnt' && $sort === 'cnt')
	{
		$next = 'cnt_desc';
	}
	$q = [
		'lang' => defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru',
		'active_only' => $activeOnly ? 'Y' : 'N',
		'exportable_only' => $exportableOnly ? 'Y' : 'N',
		'find_brand' => $findBrand,
		'sort' => $next,
	];

	return $baseUrl . '&' . http_build_query($q);
};

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$APPLICATION->SetTitle('Бренды каталога — статистика');

if ($stats['error'] !== '')
{
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => $stats['error']]);
}

?>
<style>
.mf-bss { max-width: 920px; font-family: var(--ui-font-family-primary, "Helvetica Neue", Helvetica, Arial, sans-serif); color: #333; }
.mf-bss__lead { margin: 0 0 20px 0; padding: 14px 16px; background: linear-gradient(135deg, #f0f4f8 0%, #e8eef5 100%); border: 1px solid #d5dde8; border-radius: 8px; font-size: 13px; line-height: 1.55; color: #4a5568; }
.mf-bss__lead strong { color: #1a202c; }
.mf-bss__lead code { background: rgba(255,255,255,.7); padding: 1px 5px; border-radius: 3px; font-size: 12px; }
.mf-bss__cards { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
.mf-bss__card { flex: 1 1 140px; min-width: 120px; padding: 16px 18px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 1px 3px rgba(15,23,42,.06); }
.mf-bss__card-label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #718096; margin-bottom: 6px; }
.mf-bss__card-value { font-size: 28px; font-weight: 600; line-height: 1.1; color: #2d3748; }
.mf-bss__card-value--sm { font-size: 22px; }
.mf-bss__card-hint { font-size: 11px; color: #a0aec0; margin-top: 6px; }
.mf-bss__filters { margin-bottom: 20px; padding: 16px 18px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 1px 3px rgba(15,23,42,.05); }
.mf-bss__filters-title { font-size: 14px; font-weight: 600; margin: 0 0 14px 0; color: #2d3748; }
.mf-bss__filters-row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 16px 20px; }
.mf-bss__field label { display: block; font-size: 12px; color: #4a5568; margin-bottom: 5px; }
.mf-bss__field input[type="text"] { min-width: 220px; padding: 7px 10px; border: 1px solid #cbd5e0; border-radius: 6px; font-size: 13px; }
.mf-bss__field input[type="text"]:focus { border-color: #4299e1; outline: none; box-shadow: 0 0 0 2px rgba(66,153,225,.2); }
.mf-bss__checks { display: flex; flex-direction: column; gap: 8px; padding-bottom: 2px; }
.mf-bss__check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #4a5568; cursor: pointer; user-select: none; }
.mf-bss__check input { margin: 0; }
.mf-bss__actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.mf-bss__reset { font-size: 13px; color: #718096; text-decoration: none; }
.mf-bss__reset:hover { color: #2b6cb0; text-decoration: underline; }
.mf-bss__table-wrap { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(15,23,42,.06); }
.mf-bss__table { width: 100%; border-collapse: collapse; font-size: 13px; }
.mf-bss__table thead { background: #f7fafc; border-bottom: 1px solid #e2e8f0; }
.mf-bss__table th { padding: 11px 14px; text-align: left; font-weight: 600; font-size: 12px; color: #4a5568; text-transform: uppercase; letter-spacing: .03em; }
.mf-bss__table th a { color: #2b6cb0; text-decoration: none; }
.mf-bss__table th a:hover { text-decoration: underline; }
.mf-bss__table th.mf-bss__th-num { width: 52px; text-align: center; }
.mf-bss__table th.mf-bss__th-cnt { width: 200px; text-align: right; }
.mf-bss__table td { padding: 10px 14px; border-bottom: 1px solid #edf2f7; vertical-align: middle; }
.mf-bss__table tbody tr:last-child td { border-bottom: none; }
.mf-bss__table tbody tr:hover { background: #f7fafc; }
.mf-bss__table tbody tr:nth-child(even) { background: #fafbfc; }
.mf-bss__table tbody tr:nth-child(even):hover { background: #f1f5f9; }
.mf-bss__num { text-align: center; color: #a0aec0; font-size: 12px; font-variant-numeric: tabular-nums; }
.mf-bss__brand { font-weight: 500; color: #2d3748; }
.mf-bss__cnt-cell { text-align: right; white-space: nowrap; }
.mf-bss__cnt-num { font-weight: 600; font-variant-numeric: tabular-nums; color: #2d3748; min-width: 3.5em; display: inline-block; }
.mf-bss__bar-wrap { display: inline-block; width: 100px; max-width: 40%; height: 6px; background: #edf2f7; border-radius: 3px; margin-left: 10px; vertical-align: middle; overflow: hidden; }
.mf-bss__bar { height: 100%; background: linear-gradient(90deg, #63b3ed, #4299e1); border-radius: 3px; min-width: 2px; }
.mf-bss__empty { padding: 32px 20px; text-align: center; color: #718096; font-size: 14px; }
.mf-bss__footer { margin-top: 18px; padding-top: 14px; font-size: 12px; color: #718096; line-height: 1.6; }
.mf-bss__footer a { color: #2b6cb0; text-decoration: none; }
.mf-bss__footer a:hover { text-decoration: underline; }
.mf-bss__sort-active { color: #1a365d; font-weight: 700; }
</style>

<div class="mf-bss">
	<div class="mf-bss__lead">
		Сводка по свойству <code>MF_BRAND</code> в инфоблоке каталога <strong>№<?= (int)$stats['iblock_id'] ?></strong>.
		Каждый товар учитывается один раз.
		<?php if ($exportableOnly): ?>Только выгружаемые позиции (без workflow-черновиков и <code>MF_IS_REDIRECT</code>).<?php endif; ?>
		<?php if ($activeOnly): ?> Только <code>ACTIVE=Y</code>.<?php endif; ?>
		На большом каталоге пересчёт может занять несколько секунд.
	</div>

	<form method="get" action="<?= mf_bss_admin_h($curPage) ?>" class="mf-bss__filters">
		<input type="hidden" name="lang" value="<?= mf_bss_admin_h($lang) ?>"/>
		<input type="hidden" name="sort" value="<?= mf_bss_admin_h($sort) ?>"/>
		<div class="mf-bss__filters-title">Фильтры</div>
		<div class="mf-bss__filters-row">
			<div class="mf-bss__checks">
				<label class="mf-bss__check">
					<input type="checkbox" name="active_only" value="Y" <?= $activeOnly ? 'checked' : '' ?>/>
					Только активные товары
				</label>
				<label class="mf-bss__check">
					<input type="checkbox" name="exportable_only" value="Y" <?= $exportableOnly ? 'checked' : '' ?>/>
					Только выгружаемые (как на витрине)
				</label>
			</div>
			<div class="mf-bss__field">
				<label for="mf_bss_find_brand">Бренд содержит</label>
				<input type="text" id="mf_bss_find_brand" name="find_brand" value="<?= mf_bss_admin_h($findBrand) ?>" placeholder="например Yamaha"/>
			</div>
			<div class="mf-bss__actions">
				<input type="submit" class="adm-btn-save" value="Показать"/>
				<a class="mf-bss__reset" href="<?= mf_bss_admin_h($baseUrl) ?>">Сбросить</a>
			</div>
		</div>
	</form>

	<?php if ($stats['error'] === ''): ?>
		<div class="mf-bss__cards">
			<div class="mf-bss__card">
				<div class="mf-bss__card-label">Брендов</div>
				<div class="mf-bss__card-value"><?= (int)$stats['total_brands'] ?></div>
			</div>
			<div class="mf-bss__card">
				<div class="mf-bss__card-label">Товаров</div>
				<div class="mf-bss__card-value"><?= number_format((int)$stats['total_products'], 0, '', ' ') ?></div>
				<div class="mf-bss__card-hint">сумма по строкам таблицы</div>
			</div>
			<div class="mf-bss__card">
				<div class="mf-bss__card-label">Инфоблок</div>
				<div class="mf-bss__card-value mf-bss__card-value--sm"><?= (int)$stats['iblock_id'] ?></div>
				<div class="mf-bss__card-hint">каталог</div>
			</div>
		</div>

		<div class="mf-bss__table-wrap">
			<?php if ($rows === []): ?>
				<div class="mf-bss__empty">Нет брендов по выбранным фильтрам.</div>
			<?php else: ?>
				<table class="mf-bss__table">
					<thead>
					<tr>
						<th class="mf-bss__th-num">#</th>
						<th>
							<a href="<?= mf_bss_admin_h($sortUrl('brand')) ?>" class="<?= in_array($sort, ['brand', 'brand_desc'], true) ? 'mf-bss__sort-active' : '' ?>">
								Бренд<?= mf_bss_admin_h($sortArrowBrand) ?>
							</a>
						</th>
						<th class="mf-bss__th-cnt">
							<a href="<?= mf_bss_admin_h($sortUrl('cnt')) ?>" class="<?= in_array($sort, ['cnt', 'cnt_desc'], true) ? 'mf-bss__sort-active' : '' ?>">
								Товаров<?= mf_bss_admin_h($sortArrowCnt) ?>
							</a>
						</th>
					</tr>
					</thead>
					<tbody>
					<?php $n = 0; foreach ($rows as $row): $n++;
						$cnt = (int)$row['cnt'];
						$pct = $maxCnt > 0 ? min(100, (int)round(100 * $cnt / $maxCnt)) : 0;
						?>
						<tr>
							<td class="mf-bss__num"><?= $n ?></td>
							<td class="mf-bss__brand"><?= mf_bss_admin_h((string)$row['brand']) ?></td>
							<td class="mf-bss__cnt-cell">
								<span class="mf-bss__cnt-num"><?= number_format($cnt, 0, '', ' ') ?></span>
								<?php if ($maxCnt > 0 && $pct > 0): ?>
									<span class="mf-bss__bar-wrap" title="<?= $pct ?>% от максимума">
										<span class="mf-bss__bar" style="width:<?= $pct ?>%"></span>
									</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="mf-bss__footer">
			Связанные разделы:
			<a href="mf_brand_map.php?lang=<?= rawurlencode($lang) ?>">Сопоставление брендов</a>
			·
			<a href="mf_catalog_export.php?lang=<?= rawurlencode($lang) ?>">Выгрузка каталога</a>
		</div>
	<?php endif; ?>
</div>
<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
