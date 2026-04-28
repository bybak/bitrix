<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Catalog\StoreTable;

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

if (!class_exists(Application::class))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Bitrix\\Main\\Application недоступен.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

$brandDict = $_SERVER['DOCUMENT_ROOT'] . '/mf_brand_dict.php';
if (!is_file($brandDict))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не найден файл словаря брендов: ' . $brandDict]);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}
require_once $brandDict;

/** POST value for «Не сопоставлять» — строки с этим брендом пропускаются при импорте остатков и внешних прайсов. */
const MF_BM_MAP_SKIP = '__MF_SKIP__';

/**
 * UF_SORT для ручного сопоставления: выше, чем у сидов в mf_brand_dict (обычно 80–200),
 * чтобы «Ski-Doo → BRP» и т.п. перекрывали встроенный «Ski-Doo → Ski-Doo».
 */
const MF_BM_MANUAL_ALIAS_SORT = 400;

Loader::includeModule('iblock');
Loader::includeModule('catalog');
Loader::includeModule('highloadblock');

function mf_bm_escape(string $s): string
{
	return function_exists('htmlspecialcharsbx')
		? (string)htmlspecialcharsbx($s)
		: htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mf_bm_table_exists(\Bitrix\Main\DB\Connection $conn, string $table): bool
{
	try
	{
		$r = $conn->query("SHOW TABLES LIKE '" . $conn->getSqlHelper()->forSql($table) . "'")->fetch();
		return (bool)$r;
	}
	catch (\Throwable $e)
	{
		return false;
	}
}

/**
 * Варианты для селекта «Считать как бренд каталога» / «Сопоставить с брендом каталога»:
 * те же правила, что список брендов в выгрузке каталога (mf_ce_load_brand_choices — только выгружаемые активные товары
 * инфоблока, MF_BRAND + MF_BRAND_NORM), плюс каноны из HL mf_brand_alias (чтобы цель сопоставления не пропадала из списка).
 *
 * @return list<string>
 */
function mf_bm_select_brand_choices(\Bitrix\Main\DB\Connection $conn, int $iblockId = 4): array
{
	$iblockId = (int)$iblockId;
	if (!function_exists('mf_ce_load_brand_choices'))
	{
		require_once __DIR__ . '/mf_ce_brand_choices_inc.php';
	}

	$out = [];
	foreach (mf_ce_load_brand_choices($iblockId, true) as $b)
	{
		$b = trim((string)$b);
		if ($b !== '')
		{
			$out[$b] = true;
		}
	}

	try
	{
		if (mf_bm_table_exists($conn, 'mf_brand_alias'))
		{
			$rs = $conn->query("
				SELECT DISTINCT TRIM(UF_CANONICAL) AS BRAND
				FROM mf_brand_alias
				WHERE UF_ACTIVE = 1
				  AND UF_CANONICAL IS NOT NULL AND TRIM(UF_CANONICAL) <> ''
				LIMIT 5000
			");
			while ($r = $rs->fetch())
			{
				$b = trim((string)($r['BRAND'] ?? ''));
				if ($b !== '')
				{
					$out[$b] = true;
				}
			}
		}
	}
	catch (\Throwable $e)
	{
		// ignore
	}

	$keys = array_keys($out);
	if ($keys === [])
	{
		return [];
	}
	natcasesort($keys);

	return array_values($keys);
}

function mf_bm_get_alias_exact(string $alias): string
{
	$alias = trim($alias);
	if ($alias === '' || !function_exists('mf_brand_hl_ensure') || !function_exists('mf_brand_norm'))
	{
		return '';
	}
	try
	{
		$hl = mf_brand_hl_ensure(true);
		if (!$hl) return '';
		$dataClass = $hl['DATA_CLASS'];
		$an = mf_brand_norm($alias);
		$r = $dataClass::getList([
			'filter' => ['=UF_ALIAS_NORM' => $an, '=UF_ACTIVE' => 1],
			'select' => ['UF_CANONICAL', 'UF_SORT', 'UF_ALIAS_NORM'],
			'order' => ['UF_SORT' => 'DESC', 'ID' => 'DESC'],
			'limit' => 1,
		])->fetch();
		return trim((string)($r['UF_CANONICAL'] ?? ''));
	}
	catch (\Throwable $e)
	{
		return '';
	}
}

$conn = Application::getConnection();
if (!mf_bm_table_exists($conn, 'mf_stock_import_missing'))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Таблица не найдена: mf_stock_import_missing']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

$h = $conn->getSqlHelper();
$adminNotice = null;

// Filters: warehouse
$find_warehouse = trim((string)($_REQUEST['find_warehouse'] ?? ''));

/** Сортировка таблицы ненайденных по колонке «Сейчас сопоставлен»: asc | desc | '' (как в SQL: по имени бренда). */
$bm_sort_canon = trim((string)($_REQUEST['bm_sort_canon'] ?? ''));
if (!in_array($bm_sort_canon, ['asc', 'desc'], true))
{
	$bm_sort_canon = '';
}

// Сколько брендов на «странице» (только в браузере, без повторных запросов к серверу)
$bmPerPage = 50;

// Ручное сопоставление: любой текст бренда (не обязан быть в mf_stock_import_missing)
if (
	($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
	&& (string)($_POST['bm_action'] ?? '') === 'manual_map'
	&& check_bitrix_sessid()
)
{
	$ma = trim((string)($_POST['manual_alias'] ?? ''));
	$mt = trim((string)($_POST['manual_target'] ?? ''));
	if ($ma === '' || $mt === '')
	{
		$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Укажи строку бренда (как в файле) и вариант в списке.'];
	}
	else
	{
		$hlM = mf_brand_hl_ensure(true);
		if ($mt === MF_BM_MAP_SKIP)
		{
			if (function_exists('mf_brand_import_skip_set'))
			{
				mf_brand_import_skip_set($ma, true);
			}
			$adminNotice = ['TYPE' => 'OK', 'MESSAGE' => 'Для введённого бренда включён пропуск при импорте.'];
		}
		else
		{
			mf_brand_register_alias($hlM, $mt, $ma, true, MF_BM_MANUAL_ALIAS_SORT);
			$adminNotice = [
				'TYPE' => 'OK',
				'MESSAGE' => 'Сохранено сопоставление: «' . mf_bm_escape($ma) . '» → «' . mf_bm_escape($mt) . '» (приоритет '
					. (int)MF_BM_MANUAL_ALIAS_SORT . ', перекрывает встроенные алиасы с меньшим сортом).',
			];
		}
	}
}

// Удаление записи ручного сопоставления (HL mf_brand_alias, UF_SORT >= MF_BM_MANUAL_ALIAS_SORT)
if (
	($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
	&& (string)($_POST['bm_action'] ?? '') === 'delete_manual_alias'
	&& check_bitrix_sessid()
)
{
	$delId = (int)($_POST['delete_id'] ?? 0);
	if ($delId <= 0)
	{
		$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Не передан ID записи.'];
	}
	else
	{
		try
		{
			$hlD = mf_brand_hl_ensure(true);
			$dcD = $hlD['DATA_CLASS'];
			$rowD = $dcD::getList([
				'filter' => ['=ID' => $delId],
				'select' => ['ID', 'UF_SORT'],
				'limit' => 1,
			])->fetch();
			if (!$rowD || (int)($rowD['UF_SORT'] ?? 0) < MF_BM_MANUAL_ALIAS_SORT)
			{
				$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Запись не найдена или не относится к ручным сопоставлениям.'];
			}
			else
			{
				$resDel = $dcD::delete($delId);
				if ($resDel->isSuccess())
				{
					if (function_exists('mf_brand_aliases_reset_cache'))
					{
						mf_brand_aliases_reset_cache();
					}
					$adminNotice = ['TYPE' => 'OK', 'MESSAGE' => 'Ручное сопоставление удалено (ID ' . $delId . ').'];
				}
				else
				{
					$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Не удалось удалить: ' . implode('; ', $resDel->getErrorMessages())];
				}
			}
		}
		catch (\Throwable $e)
		{
			$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Ошибка: ' . $e->getMessage()];
		}
	}
}

// Save mappings
if (
	($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
	&& isset($_POST['map'])
	&& is_array($_POST['map'])
	&& check_bitrix_sessid()
)
{
	$hl = mf_brand_hl_ensure(true);
	$saved = 0;
	$skipped = 0;
	foreach ($_POST['map'] as $alias => $canonical)
	{
		$alias = trim((string)$alias);
		$canonical = trim((string)$canonical);
		if ($alias === '')
		{
			$skipped++;
			continue;
		}
		if ($canonical === '')
		{
			$skipped++;
			continue;
		}
		if ($canonical === MF_BM_MAP_SKIP)
		{
			if (function_exists('mf_brand_import_skip_set'))
			{
				mf_brand_import_skip_set($alias, true);
			}
			$saved++;
			continue;
		}
		mf_brand_register_alias($hl, $canonical, $alias, true, 100);
		$saved++;
	}
	$adminNotice = ['TYPE' => 'OK', 'MESSAGE' => 'Сохранено записей (сопоставления и «не импортировать»): ' . $saved . ', без изменений: ' . $skipped];
}

// Warehouses dropdown (from stores + fallback from table)
$warehouses = [];
try
{
	$rs = StoreTable::getList([
		'filter' => ['%XML_ID' => 'SUPPLIER_'],
		'select' => ['XML_ID', 'TITLE'],
		'order' => ['TITLE' => 'ASC'],
	]);
	while ($s = $rs->fetch())
	{
		$xml = trim((string)($s['XML_ID'] ?? ''));
		if ($xml === '') continue;
		$warehouses[$xml] = (string)($s['TITLE'] ?? $xml);
	}
}
catch (\Throwable $e)
{
	// ignore
}
try
{
	$rs = $conn->query("SELECT DISTINCT UF_WAREHOUSE_XML_ID, UF_WAREHOUSE_TITLE FROM mf_stock_import_missing ORDER BY UF_WAREHOUSE_TITLE ASC LIMIT 3000");
	while ($r = $rs->fetch())
	{
		$xml = trim((string)($r['UF_WAREHOUSE_XML_ID'] ?? ''));
		if ($xml === '') continue;
		if (!isset($warehouses[$xml]))
		{
			$warehouses[$xml] = (string)($r['UF_WAREHOUSE_TITLE'] ?? $xml);
		}
	}
}
catch (\Throwable $e)
{
	// ignore
}

// Missing brands list
$where = "WHERE UF_BRAND IS NOT NULL AND UF_BRAND <> ''";
if ($find_warehouse !== '')
{
	$where .= " AND UF_WAREHOUSE_XML_ID='" . $h->forSql($find_warehouse) . "'";
}

$missing = [];
try
{
	$rs = $conn->query("
		SELECT UF_BRAND AS BRAND, COUNT(*) AS CNT, MAX(UF_LAST_SEEN) AS LAST_SEEN
		FROM mf_stock_import_missing
		{$where}
		GROUP BY UF_BRAND
		ORDER BY BRAND ASC
	");
	while ($r = $rs->fetch())
	{
		$b = trim((string)($r['BRAND'] ?? ''));
		if ($b === '') continue;
		$isSkip = function_exists('mf_brand_import_is_skipped') && mf_brand_import_is_skipped($b);
		$missing[] = [
			'BRAND' => $b,
			'CNT' => (int)($r['CNT'] ?? 0),
			'LAST_SEEN' => (string)($r['LAST_SEEN'] ?? ''),
			'CANON' => $isSkip ? '' : mf_bm_get_alias_exact($b),
			'IS_SKIP' => $isSkip,
		];
	}
}
catch (\Throwable $e)
{
	$missing = [];
}

/**
 * Текст колонки «Сейчас сопоставлен» для сортировки (как видит пользователь, без HTML).
 */
$mf_bm_canon_col_sort_val = static function (array $m): string {
	if (!empty($m['IS_SKIP']))
	{
		return '— не импортировать —';
	}
	$c = trim((string)($m['CANON'] ?? ''));

	return $c !== '' ? $c : '—';
};

if ($bm_sort_canon !== '' && $missing !== [])
{
	$dir = $bm_sort_canon === 'desc' ? -1 : 1;
	usort(
		$missing,
		static function (array $a, array $b) use ($dir, $mf_bm_canon_col_sort_val): int {
			$va = mb_strtolower($mf_bm_canon_col_sort_val($a));
			$vb = mb_strtolower($mf_bm_canon_col_sort_val($b));
			$c = strnatcasecmp($va, $vb);
			if ($c !== 0)
			{
				return $c * $dir;
			}

			return strnatcasecmp((string)$a['BRAND'], (string)$b['BRAND']);
		}
	);
}

$catalogBrands = mf_bm_select_brand_choices($conn, 4);

/** Записи HL, созданные блоком «Ручное сопоставление» (UF_SORT >= MF_BM_MANUAL_ALIAS_SORT). */
$manualAliasRows = [];
try
{
	$hlList = mf_brand_hl_ensure(false);
	if ($hlList)
	{
		$dcList = $hlList['DATA_CLASS'];
		$rsList = $dcList::getList([
			'filter' => [
				'>=UF_SORT' => MF_BM_MANUAL_ALIAS_SORT,
				'=UF_ACTIVE' => 1,
			],
			'select' => ['ID', 'UF_ALIAS', 'UF_CANONICAL', 'UF_SORT', 'UF_UPDATED_AT'],
			'order' => ['UF_ALIAS' => 'ASC', 'ID' => 'ASC'],
		]);
		while ($mr = $rsList->fetch())
		{
			$manualAliasRows[] = $mr;
		}
	}
}
catch (\Throwable $e)
{
	$manualAliasRows = [];
}

$bmN = count($missing);
$bmTotalPages = $bmN > 0 ? (int)ceil($bmN / $bmPerPage) : 1;
$bmClientPage = (int)($_GET['bm_page'] ?? 1);
if ($bmClientPage < 1)
{
	$bmClientPage = 1;
}
if ($bmClientPage > $bmTotalPages)
{
	$bmClientPage = $bmTotalPages;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$APPLICATION->SetTitle('Сопоставление брендов (импорт складов)');

$bmSortNavRemove = ['sessid', 'bm_page', 'bm_sort_canon'];
$bmUrlSortAsc = htmlspecialcharsbx($APPLICATION->GetCurPageParam('bm_sort_canon=asc', $bmSortNavRemove));
$bmUrlSortDesc = htmlspecialcharsbx($APPLICATION->GetCurPageParam('bm_sort_canon=desc', $bmSortNavRemove));
$bmUrlSortReset = htmlspecialcharsbx($APPLICATION->GetCurPageParam('', $bmSortNavRemove));

?>
<div style="max-width: 1200px;">
	<?php if (is_array($adminNotice)): ?>
		<?php \CAdminMessage::ShowMessage($adminNotice); ?>
	<?php endif; ?>

	<div style="margin: 8px 0 12px 0; color:#666;">
		Слева бренды из <code>mf_stock_import_missing</code>. Выбери канонический бренд из каталога — сохраним как alias→canonical в HL <code>mf_brand_alias</code>.
		Вариант «Не сопоставлять» помечает бренд в таблице <code>mf_brand_import_skip</code>: такие строки не обрабатываются при импорте остатков и при загрузке внешних прайсов (остатки и цены по ним не меняются).
		<br/>Список в селекте совпадает с выгрузкой каталога: значения <code>MF_BRAND</code> и <code>MF_BRAND_NORM</code> только у <b>выгружаемых</b> активных <b>товаров</b> (инфоблок каталога, без торговых предложений). Дополнительно подмешиваются каноны из <code>mf_brand_alias</code>, чтобы выбранная цель сопоставления не пропадала из списка.
		<br/>Блок <b>ниже</b> нужен, если в файле поставщика встречается бренд, которого <b>нет</b> в списке «ненайденных» (он уже сопоставляется с товарами) — например, завести «Ski-Doo» → «BRP» при существующем в каталоге Ski-Doo. Сохраняется с приоритетом, выше встроенных правил.
	</div>

	<form method="get" action="<?= mf_bm_escape($APPLICATION->GetCurPage()) ?>">
		<input type="hidden" name="lang" value="<?= mf_bm_escape((string)LANGUAGE_ID) ?>">
		<?php if ($bm_sort_canon !== ''): ?>
			<input type="hidden" name="bm_sort_canon" value="<?= mf_bm_escape($bm_sort_canon) ?>">
		<?php endif; ?>
		<label style="display:inline-block;min-width:160px;">Склад:</label>
		<select name="find_warehouse" style="min-width:420px;" onchange="this.form.submit();">
			<option value="" <?= ($find_warehouse === '' ? 'selected' : '') ?>>— все —</option>
			<?php foreach ($warehouses as $xml => $title): ?>
				<option value="<?= mf_bm_escape((string)$xml) ?>" <?= ($find_warehouse === (string)$xml ? 'selected' : '') ?>>
					<?= mf_bm_escape((string)$title) ?> (<?= mf_bm_escape((string)$xml) ?>)
				</option>
			<?php endforeach; ?>
		</select>
	</form>

	<form method="post" action="<?= mf_bm_escape($APPLICATION->GetCurPageParam('', ['sessid'])) ?>" style="margin:14px 0 18px 0; padding:12px; border:1px solid #d0d4dc; background:#f9fafb; border-radius:2px; max-width: 1160px;">
		<?= bitrix_sessid_post() ?>
		<input type="hidden" name="lang" value="<?= mf_bm_escape((string)LANGUAGE_ID) ?>">
		<input type="hidden" name="find_warehouse" value="<?= mf_bm_escape($find_warehouse) ?>">
		<?php if ($bm_sort_canon !== ''): ?>
			<input type="hidden" name="bm_sort_canon" value="<?= mf_bm_escape($bm_sort_canon) ?>">
		<?php endif; ?>
		<input type="hidden" name="bm_action" value="manual_map">
		<div style="font-weight:600; margin-bottom:8px;">Ручное сопоставление (любой текст бренда)</div>
		<div style="color:#555; font-size:13px; margin-bottom:10px; line-height:1.4;">
			Впиши строку <b>как в прайсе/остатках</b> и выбери, к какому бренду из каталога относить при импорте.
			Это не меняет уже записанные на товарах значения <code>MF_BRAND</code>, только логику поиска/матчинг при загрузке.
		</div>
		<div style="display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px;">
			<div>
				<label for="id_manual_alias" style="display:block; margin-bottom:4px; color:#333;">Текст бренда (из файла)</label>
				<input type="text" name="manual_alias" id="id_manual_alias" value="" placeholder="например Ski-Doo" style="min-width:280px; padding:4px 8px;"/>
			</div>
			<div>
				<label for="id_manual_target" style="display:block; margin-bottom:4px; color:#333;">Считать как бренд каталога</label>
				<select name="manual_target" id="id_manual_target" style="min-width:360px; padding:4px 8px;">
					<option value="">— выбери —</option>
					<option value="<?= mf_bm_escape(MF_BM_MAP_SKIP) ?>">— Не сопоставлять (пропуск при импорте) —</option>
					<?php foreach ($catalogBrands as $bCh): ?>
						<option value="<?= mf_bm_escape((string)$bCh) ?>"><?= mf_bm_escape((string)$bCh) ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<input type="submit" class="adm-btn-save" name="bm_manual_save" value="Сохранить в словарь"/>
			</div>
		</div>
	</form>

	<div style="margin: 0 0 22px 0; max-width: 1160px;">
		<div style="font-weight:600; margin-bottom:6px;">Список ручных сопоставлений</div>
		<div style="color:#555; font-size:13px; margin-bottom:8px; line-height:1.4;">
			Здесь только записи с приоритетом <b>≥ <?= (int)MF_BM_MANUAL_ALIAS_SORT ?></b> (кнопка «Сохранить в словарь»). Сопоставления из списка ненайденных ниже
			(сорт 100) сюда не попадают. Полные данные по всем алиасам смотри в Bitrix: <b>Highload-блоки</b> → сущность MfBrandAlias, таблица
			<code>mf_brand_alias</code>.
		</div>
		<?php if (empty($manualAliasRows)): ?>
			<div style="color:#888;">Пока нет таких записей.</div>
		<?php else: ?>
		<table class="adm-list-table" style="width:100%;">
			<thead>
			<tr class="adm-list-table-header">
				<td class="adm-list-table-cell">ID</td>
				<td class="adm-list-table-cell">Алиас (текст из файла)</td>
				<td class="adm-list-table-cell">→ канон (каталог)</td>
				<td class="adm-list-table-cell">Сорт</td>
				<td class="adm-list-table-cell">Обновлено</td>
				<td class="adm-list-table-cell"></td>
			</tr>
			</thead>
			<tbody>
			<?php foreach ($manualAliasRows as $mar): ?>
				<?php
				$mid = (int)($mar['ID'] ?? 0);
				$mAlias = (string)($mar['UF_ALIAS'] ?? '');
				$mCanon = (string)($mar['UF_CANONICAL'] ?? '');
				$mSort = (int)($mar['UF_SORT'] ?? 0);
				$mUpd = $mar['UF_UPDATED_AT'] ?? null;
				$mUpdStr = '—';
				if ($mUpd !== null && $mUpd !== '')
				{
					if (is_object($mUpd) && method_exists($mUpd, 'format'))
					{
						$mUpdStr = (string)$mUpd->format('Y-m-d H:i');
					}
					else
					{
						$mUpdStr = (string)$mUpd;
					}
				}
				?>
				<tr class="adm-list-table-row">
					<td class="adm-list-table-cell"><?= $mid ?></td>
					<td class="adm-list-table-cell"><b><?= mf_bm_escape($mAlias) ?></b></td>
					<td class="adm-list-table-cell"><?= mf_bm_escape($mCanon) ?></td>
					<td class="adm-list-table-cell"><?= (int)$mSort ?></td>
					<td class="adm-list-table-cell"><?= mf_bm_escape($mUpdStr) ?></td>
					<td class="adm-list-table-cell" style="white-space:nowrap;">
						<form method="post" action="<?= mf_bm_escape($APPLICATION->GetCurPageParam('', ['sessid'])) ?>" style="display:inline;margin:0;" onsubmit="return confirm('Удалить сопоставление #<?= (int)$mid ?>?');">
							<?= bitrix_sessid_post() ?>
							<input type="hidden" name="lang" value="<?= mf_bm_escape((string)LANGUAGE_ID) ?>">
							<input type="hidden" name="find_warehouse" value="<?= mf_bm_escape($find_warehouse) ?>">
							<?php if ($bm_sort_canon !== ''): ?>
								<input type="hidden" name="bm_sort_canon" value="<?= mf_bm_escape($bm_sort_canon) ?>">
							<?php endif; ?>
							<input type="hidden" name="bm_action" value="delete_manual_alias">
							<input type="hidden" name="delete_id" value="<?= (int)$mid ?>">
							<button type="submit" class="adm-btn" style="font-size:12px;">Удалить</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>

	<?php
	$bmShowFrom0 = $bmN > 0 ? (($bmClientPage - 1) * $bmPerPage + 1) : 0;
	$bmShowTo0 = $bmN > 0 ? min($bmClientPage * $bmPerPage, $bmN) : 0;
	?>
	<div id="bm-client-pager" data-bm-total-pages="<?= (int)$bmTotalPages ?>"
		data-bm-initial="<?= (int)$bmClientPage ?>"
		data-bm-per="<?= (int)$bmPerPage ?>"
		data-bm-cur-url="<?= mf_bm_escape((string)$APPLICATION->GetCurPage()) ?>"
		data-bm-lang="<?= mf_bm_escape((string)LANGUAGE_ID) ?>"
		data-bm-warehouse="<?= mf_bm_escape($find_warehouse) ?>"
		data-bm-sort-canon="<?= mf_bm_escape($bm_sort_canon) ?>"
		data-bm-n="<?= (int)$bmN ?>">
		<?php if ($bmN > 0): ?>
			<div class="js-bm-range" style="margin:10px 0 6px 0; color:#555;">
				Показаны бренды <b class="js-bm-from"><?= (int)$bmShowFrom0 ?></b>–<b class="js-bm-to"><?= (int)$bmShowTo0 ?></b> из <b class="js-bm-total"><?= (int)$bmN ?></b>
				(по <?= (int)$bmPerPage ?> на странице<span class="js-bm-pagenum-wrap"<?= $bmTotalPages < 2 ? ' style="display:none;"' : '' ?>>, стр. <span class="js-bm-curpage"><?= (int)$bmClientPage ?></span> из <span class="js-bm-ntp"><?= (int)$bmTotalPages ?></span></span>).
				Перелистывание без запроса к серверу.
				Сортировка по колонке «Сейчас сопоставлен» — ссылки <b>↑</b> <b>↓</b> в заголовке таблицы (по тексту как на экране; «сброс» — снова порядок по имени бренда из базы).
			</div>
			<div class="js-bm-nav bm-pager-ui" style="margin:0 0 12px 0;contain:layout;isolation:isolate;transform:translateZ(0);-webkit-backface-visibility:hidden;backface-visibility:hidden;user-select:none;touch-action:manipulation;"></div>
		<?php endif; ?>

		<form method="post" action="<?= mf_bm_escape($APPLICATION->GetCurPageParam('', ['sessid'])) ?>" style="margin-top:0;">
		<?= bitrix_sessid_post() ?>
		<input type="hidden" name="lang" value="<?= mf_bm_escape((string)LANGUAGE_ID) ?>">
		<input type="hidden" name="find_warehouse" value="<?= mf_bm_escape($find_warehouse) ?>">
		<?php if ($bm_sort_canon !== ''): ?>
			<input type="hidden" name="bm_sort_canon" value="<?= mf_bm_escape($bm_sort_canon) ?>">
		<?php endif; ?>

		<table class="adm-list-table" style="width:100%;margin-top:10px;">
			<thead>
			<tr class="adm-list-table-header">
				<td class="adm-list-table-cell">Бренд (в ненайденных)</td>
				<td class="adm-list-table-cell">Кол-во</td>
				<td class="adm-list-table-cell">Последнее</td>
				<td class="adm-list-table-cell">
					<span style="display:inline-flex;flex-wrap:wrap;align-items:center;gap:6px;">
						<span>Сейчас сопоставлен</span>
						<span style="font-weight:normal;white-space:nowrap;color:#555;">
							<?php if ($bm_sort_canon === 'asc'): ?>
								<strong title="По возрастанию">↑</strong>
							<?php else: ?>
								<a href="<?= $bmUrlSortAsc ?>" title="По возрастанию значения в колонке">↑</a>
							<?php endif; ?>
							<?php if ($bm_sort_canon === 'desc'): ?>
								<strong title="По убыванию">↓</strong>
							<?php else: ?>
								<a href="<?= $bmUrlSortDesc ?>" title="По убыванию значения в колонке">↓</a>
							<?php endif; ?>
							<?php if ($bm_sort_canon !== ''): ?>
								<a href="<?= $bmUrlSortReset ?>" title="Сортировка как в базе (по имени бренда)" style="font-size:11px;margin-left:2px;">сброс</a>
							<?php endif; ?>
						</span>
					</span>
				</td>
				<td class="adm-list-table-cell">Сопоставить с брендом каталога</td>
			</tr>
			</thead>
			<tbody>
			<?php if (empty($missing)): ?>
				<tr class="adm-list-table-row">
					<td class="adm-list-table-cell" colspan="5">Нет данных.</td>
				</tr>
			<?php else: ?>
				<?php foreach ($missing as $bmIdx => $m): ?>
					<?php
					$alias = (string)$m['BRAND'];
					$canon = (string)$m['CANON'];
					$isSkip = !empty($m['IS_SKIP']);
					$bmInPage = $bmIdx >= ($bmClientPage - 1) * $bmPerPage && $bmIdx < $bmClientPage * $bmPerPage;
					?>
					<tr class="adm-list-table-row js-bm-row" data-bm-i="<?= (int)$bmIdx ?>"
						<?= $bmInPage ? '' : ' style="display:none;"' ?>>
						<td class="adm-list-table-cell"><b><?= mf_bm_escape($alias) ?></b></td>
						<td class="adm-list-table-cell"><?= (int)$m['CNT'] ?></td>
						<td class="adm-list-table-cell"><?= mf_bm_escape((string)$m['LAST_SEEN']) ?></td>
						<td class="adm-list-table-cell"><?= $isSkip ? '— не импортировать —' : ($canon !== '' ? mf_bm_escape($canon) : '—') ?></td>
						<td class="adm-list-table-cell">
							<select name="map[<?= mf_bm_escape($alias) ?>]" style="min-width:420px;">
								<option value="">— не менять —</option>
								<option value="<?= mf_bm_escape(MF_BM_MAP_SKIP) ?>" <?= ($isSkip ? 'selected' : '') ?>>— Не сопоставлять (пропуск при импорте) —</option>
								<?php if ($canon !== '' && !in_array($canon, $catalogBrands, true)): ?>
									<option value="<?= mf_bm_escape($canon) ?>"><?= mf_bm_escape($canon) ?> (текущий)</option>
								<?php endif; ?>
								<?php foreach ($catalogBrands as $b): ?>
									<option value="<?= mf_bm_escape((string)$b) ?>" <?= (!$isSkip && $canon !== '' && $canon === (string)$b ? 'selected' : '') ?>>
										<?= mf_bm_escape((string)$b) ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<?php if ($bmN > 0): ?>
			<div class="js-bm-nav bm-pager-ui" style="margin:12px 0 0 0;contain:layout;isolation:isolate;transform:translateZ(0);-webkit-backface-visibility:hidden;backface-visibility:hidden;user-select:none;touch-action:manipulation;"></div>
		<?php endif; ?>

		<div style="margin-top:12px;">
			<input type="submit" class="adm-btn-save" value="Сохранить сопоставления">
		</div>
	</form>
	</div>

<script>
(function () {
	var root = document.getElementById("bm-client-pager");
	if (!root) return;
	var n = parseInt(root.getAttribute("data-bm-n") || "0", 10) || 0;
	if (n < 1) return;
	var per = Math.max(1, parseInt(root.getAttribute("data-bm-per") || "50", 10) || 50);
	var totalPages = Math.max(1, Math.ceil(n / per));
	var cur = Math.max(1, Math.min(
		totalPages,
		parseInt(root.getAttribute("data-bm-initial") || "1", 10) || 1
	));
	var baseUrl = root.getAttribute("data-bm-cur-url") || window.location.pathname;
	var lang = root.getAttribute("data-bm-lang") || "";
	var wh = root.getAttribute("data-bm-warehouse") || "";
	var sortCanon = root.getAttribute("data-bm-sort-canon") || "";

	var rows = Array.prototype.slice.call(root.querySelectorAll("tr.js-bm-row"));
	if (rows.length !== n) { return; }

	var navPrevS = (cur - 1) * per, navPrevE = cur * per;

	function setUrlPage(p) {
		var q = { lang: lang, find_warehouse: wh };
		if (p > 1) { q.bm_page = p; }
		if (sortCanon === "asc" || sortCanon === "desc") { q.bm_sort_canon = sortCanon; }
		var qs = Object.keys(q).map(function (k) {
			return encodeURIComponent(k) + "=" + encodeURIComponent(q[k] != null ? String(q[k]) : "");
		}).join("&");
		try {
			if (window.history && window.history.replaceState) {
				window.history.replaceState(null, "", baseUrl + (qs ? "?" + qs : ""));
			}
		} catch (e) {}
	}

	function updateRange() {
		var from = (cur - 1) * per + 1, to = Math.min(cur * per, n);
		root.querySelectorAll(".js-bm-from").forEach(function (el) { el.textContent = String(from); });
		root.querySelectorAll(".js-bm-to").forEach(function (el) { el.textContent = String(to); });
		root.querySelectorAll(".js-bm-curpage").forEach(function (el) { el.textContent = String(cur); });
		var w = root.querySelectorAll(".js-bm-pagenum-wrap");
		w.forEach(function (el) { el.style.display = totalPages > 1 ? "" : "none"; });
	}

	function showRows() {
		var s = (cur - 1) * per, e = cur * per;
		if (s === navPrevS && e === navPrevE) { return; }
		var i, j;
		for (i = navPrevS; i < navPrevE; i++) {
			if (i < s || i >= e) { rows[i].style.display = "none"; }
		}
		for (j = s; j < e; j++) {
			if (j < navPrevS || j >= navPrevE) { rows[j].style.display = ""; }
		}
		navPrevS = s;
		navPrevE = e;
	}

	function buildNav() {
		var h = [];
		if (cur > 1) {
			h.push("<button type=\"button\" class=\"adm-btn\" data-bm-p=\"" + (cur - 1) + "\">← Назад</button>");
		} else {
			h.push("<span class=\"adm-btn\" style=\"pointer-events:none;opacity:.5;\">← Назад</span>");
		}
		var lo = Math.max(1, cur - 4), hi = Math.min(totalPages, cur + 4);
		for (var p = lo; p <= hi; p++) {
			if (p === cur) {
				h.push("<span class=\"adm-btn\" style=\"margin-left:4px;pointer-events:none;opacity:.85;\">" + p + "</span>");
			} else {
				h.push("<button type=\"button\" class=\"adm-btn\" style=\"margin-left:4px;\" data-bm-p=\"" + p + "\">" + p + "</button>");
			}
		}
		if (cur < totalPages) {
			h.push("<button type=\"button\" class=\"adm-btn\" style=\"margin-left:4px;\" data-bm-p=\"" + (cur + 1) + "\">Вперёд →</button>");
		} else {
			h.push("<span class=\"adm-btn\" style=\"margin-left:4px;pointer-events:none;opacity:.5;\">Вперёд →</span>");
		}
		return h.join(" ");
	}

	/** Повторно не трогаем innerHTML панели, если страница та же (меньше reflow при лишних вызовах). */
	var lastNavBuildForCur = -1;
	function paint() {
		showRows();
		updateRange();
		if (cur === lastNavBuildForCur) { return; }
		lastNavBuildForCur = cur;
		var html = buildNav();
		var navEls = root.querySelectorAll(".js-bm-nav");
		for (var ni = 0; ni < navEls.length; ni++) { navEls[ni].innerHTML = html; }
	}

	root.addEventListener("click", function (ev) {
		var t = ev.target;
		if (!t || !t.getAttribute) return;
		var p = t.getAttribute("data-bm-p");
		if (p == null) return;
		var np = parseInt(p, 10);
		if (!np || np < 1 || np > totalPages || np === cur) return;
		ev.preventDefault();
		cur = np;
		paint();
		setUrlPage(cur);
	});

	paint();
})();
</script>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

