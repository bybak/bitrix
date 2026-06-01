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
 * выгружаемые активные элементы инфоблока; для скорости — только MF_BRAND (меньше строк в JOIN, чем BRAND+NORM).
 * Плюс каноны из HL mf_brand_alias.
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
	foreach (mf_ce_load_brand_choices($iblockId, true, ['MF_BRAND']) as $b)
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

/**
 * UF_ALIAS_NORM → true для записей «не импортировать» (без N+1 на каждый бренд).
 *
 * @param list<string> $norms
 * @return array<string, true>
 */
function mf_bm_batch_import_skip_by_norm(array $norms): array
{
	$norms = array_values(array_unique(array_filter(array_map('trim', $norms), static fn($s) => $s !== '')));
	if ($norms === [] || !function_exists('mf_brand_import_skip_ensure_table') || !mf_brand_import_skip_ensure_table())
	{
		return [];
	}
	$out = [];
	$conn = Application::getConnection();
	$h = $conn->getSqlHelper();
	$chunk = 400;
	for ($i = 0; $i < count($norms); $i += $chunk)
	{
		$part = array_slice($norms, $i, $chunk);
		$in = implode(',', array_map(static fn($n) => "'" . $h->forSql($n) . "'", $part));
		if ($in === '')
		{
			continue;
		}
		try
		{
			$rs = $conn->query("SELECT UF_ALIAS_NORM FROM mf_brand_import_skip WHERE UF_ACTIVE='Y' AND UF_ALIAS_NORM IN ({$in})");
			while ($r = $rs->fetch())
			{
				$k = trim((string)($r['UF_ALIAS_NORM'] ?? ''));
				if ($k !== '')
				{
					$out[$k] = true;
				}
			}
		}
		catch (\Throwable $e)
		{
			continue;
		}
	}

	return $out;
}

/**
 * Канон по нормализованному алиасу (как mf_bm_get_alias_exact, но пакетом).
 * Порядок выборки: UF_SORT DESC, ID DESC — берём первую строку на каждый норм.
 *
 * @param list<string> $norms
 * @return array<string, string>
 */
function mf_bm_batch_alias_canonical_by_norm(array $norms, ?array $hl): array
{
	$norms = array_values(array_unique(array_filter(array_map('trim', $norms), static fn($s) => $s !== '')));
	if ($norms === [] || !$hl || empty($hl['DATA_CLASS']))
	{
		return [];
	}
	$dc = $hl['DATA_CLASS'];
	$out = [];
	$chunk = 400;
	for ($i = 0; $i < count($norms); $i += $chunk)
	{
		$part = array_slice($norms, $i, $chunk);
		if ($part === [])
		{
			continue;
		}
		try
		{
			$rs = $dc::getList([
				'filter' => [
					'@UF_ALIAS_NORM' => $part,
					'=UF_ACTIVE' => 1,
				],
				'select' => ['UF_ALIAS_NORM', 'UF_CANONICAL', 'UF_SORT', 'ID'],
				'order' => ['UF_SORT' => 'DESC', 'ID' => 'DESC'],
			]);
			while ($r = $rs->fetch())
			{
				$n = trim((string)($r['UF_ALIAS_NORM'] ?? ''));
				if ($n === '' || array_key_exists($n, $out))
				{
					continue;
				}
				$out[$n] = trim((string)($r['UF_CANONICAL'] ?? ''));
			}
		}
		catch (\Throwable $e)
		{
			continue;
		}
	}

	return $out;
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

// Filters: warehouse, brand substring, «only rows without mapping»
$find_warehouse = trim((string)($_REQUEST['find_warehouse'] ?? ''));
$find_brand = trim((string)($_REQUEST['find_brand'] ?? ''));
$bm_only_unmapped = isset($_REQUEST['bm_only_unmapped']) && (string)$_REQUEST['bm_only_unmapped'] === 'Y';

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
	$mtSelect = trim((string)($_POST['manual_target'] ?? ''));
	$mtCustom = trim((string)($_POST['manual_target_custom'] ?? ''));
	if ($mtCustom !== '')
	{
		$mt = $mtCustom;
	}
	elseif ($mtSelect === MF_BM_MAP_SKIP)
	{
		$mt = MF_BM_MAP_SKIP;
	}
	else
	{
		$mt = $mtSelect;
	}
	if ($ma === '' || $mt === '')
	{
		$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Укажи строку бренда (как в файле) и целевой канон: из списка или свой текст в поле ниже.'];
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
	$mapCustom = isset($_POST['map_custom']) && is_array($_POST['map_custom']) ? $_POST['map_custom'] : [];
	$mapAliases = array_values(array_unique(array_merge(
		array_keys($_POST['map']),
		array_keys($mapCustom)
	)));

	foreach ($mapAliases as $mapAliasKey)
	{
		$alias = trim((string)$mapAliasKey);
		$fromSelect = trim((string)(($_POST['map'][$mapAliasKey] ?? '')));
		$customCanon = trim((string)($mapCustom[$mapAliasKey] ?? ''));
		if ($customCanon !== '')
		{
			$canonical = $customCanon;
		}
		elseif ($fromSelect === MF_BM_MAP_SKIP)
		{
			$canonical = MF_BM_MAP_SKIP;
		}
		else
		{
			$canonical = $fromSelect;
		}
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
if ($find_brand !== '')
{
	$where .= " AND UF_BRAND LIKE '%" . $h->forSql($find_brand) . "%'";
}

$missing = [];
$missingRaw = [];
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
		if ($b === '')
		{
			continue;
		}
		$missingRaw[] = [
			'BRAND' => $b,
			'CNT' => (int)($r['CNT'] ?? 0),
			'LAST_SEEN' => (string)($r['LAST_SEEN'] ?? ''),
		];
	}
}
catch (\Throwable $e)
{
	$missingRaw = [];
}

$normsForBatch = [];
foreach ($missingRaw as $row)
{
	$b = (string)$row['BRAND'];
	if (!function_exists('mf_brand_norm'))
	{
		continue;
	}
	$n = mf_brand_norm($b);
	if ($n !== '')
	{
		$normsForBatch[$n] = true;
	}
}
$normList = array_keys($normsForBatch);
$skipSet = function_exists('mf_bm_batch_import_skip_by_norm') ? mf_bm_batch_import_skip_by_norm($normList) : [];
$hlForBatch = null;
try
{
	$hlForBatch = mf_brand_hl_ensure(false);
}
catch (\Throwable $e)
{
	$hlForBatch = null;
}
$canonByNorm = mf_bm_batch_alias_canonical_by_norm($normList, $hlForBatch);

foreach ($missingRaw as $row)
{
	$b = (string)$row['BRAND'];
	$n = function_exists('mf_brand_norm') ? mf_brand_norm($b) : '';
	$isSkip = ($n !== '' && isset($skipSet[$n]));
	$canon = '';
	if (!$isSkip && $n !== '')
	{
		$canon = (string)($canonByNorm[$n] ?? '');
	}
	$missing[] = [
		'BRAND' => $b,
		'CNT' => (int)$row['CNT'],
		'LAST_SEEN' => (string)$row['LAST_SEEN'],
		'CANON' => $canon,
		'IS_SKIP' => $isSkip,
	];
}

if ($bm_only_unmapped)
{
	$missing = array_values(array_filter(
		$missing,
		static function (array $m): bool {
			if (!empty($m['IS_SKIP']))
			{
				return false;
			}

			return trim((string)($m['CANON'] ?? '')) === '';
		}
	));
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
$catalogBrandsJsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE'))
{
	$catalogBrandsJsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
$catalogBrandsJson = json_encode($catalogBrands, $catalogBrandsJsonFlags);
if (!is_string($catalogBrandsJson))
{
	$catalogBrandsJson = '[]';
}
$catalogBrandsLookup = array_fill_keys($catalogBrands, true);
$catalogBrandsCount = count($catalogBrands);

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
		<br/>Список в селекте: <code>MF_BRAND</code> у выгружаемых активных товаров + каноны из <code>mf_brand_alias</code>.
		Можно указать <b>свой канон текстом</b> (поле под селектом в таблице и в блоке ручного сопоставления): непустое значение сохраняется вместо выбора из списка — удобно, если нужного написания ещё нет среди товаров каталога.
		<br/>Блок <b>ниже</b> нужен, если в файле поставщика встречается бренд, которого <b>нет</b> в списке «ненайденных» (он уже сопоставляется с товарами) — например, завести «Ski-Doo» → «BRP» при существующем в каталоге Ski-Doo. Сохраняется с приоритетом, выше встроенных правил.
	</div>

	<form method="get" action="<?= mf_bm_escape($APPLICATION->GetCurPage()) ?>">
		<input type="hidden" name="lang" value="<?= mf_bm_escape((string)LANGUAGE_ID) ?>">
		<?php if ($bm_sort_canon !== ''): ?>
			<input type="hidden" name="bm_sort_canon" value="<?= mf_bm_escape($bm_sort_canon) ?>">
		<?php endif; ?>
		<div style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;margin-bottom:8px;">
			<div>
				<label style="display:block;margin-bottom:4px;">Склад</label>
				<select name="find_warehouse" style="min-width:380px;" onchange="this.form.submit();">
					<option value="" <?= ($find_warehouse === '' ? 'selected' : '') ?>>— все —</option>
					<?php foreach ($warehouses as $xml => $title): ?>
						<option value="<?= mf_bm_escape((string)$xml) ?>" <?= ($find_warehouse === (string)$xml ? 'selected' : '') ?>>
							<?= mf_bm_escape((string)$title) ?> (<?= mf_bm_escape((string)$xml) ?>)
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<label style="display:block;margin-bottom:4px;">Бренд содержит</label>
				<input type="text" name="find_brand" value="<?= mf_bm_escape($find_brand) ?>" placeholder="подстрока" style="min-width:200px;padding:4px 8px;" />
			</div>
			<div style="padding-bottom:4px;">
				<label style="display:flex;align-items:center;gap:6px;cursor:pointer;white-space:nowrap;">
					<input type="checkbox" name="bm_only_unmapped" value="Y" <?= $bm_only_unmapped ? 'checked' : '' ?> />
					Только без сопоставления
				</label>
			</div>
			<div>
				<input type="submit" class="adm-btn" value="Применить" />
			</div>
		</div>
	</form>

	<form method="post" action="<?= mf_bm_escape($APPLICATION->GetCurPageParam('', ['sessid'])) ?>" style="margin:14px 0 18px 0; padding:12px; border:1px solid #d0d4dc; background:#f9fafb; border-radius:2px; max-width: 1160px;">
		<?= bitrix_sessid_post() ?>
		<input type="hidden" name="lang" value="<?= mf_bm_escape((string)LANGUAGE_ID) ?>">
		<input type="hidden" name="find_warehouse" value="<?= mf_bm_escape($find_warehouse) ?>">
		<input type="hidden" name="find_brand" value="<?= mf_bm_escape($find_brand) ?>">
		<?php if ($bm_only_unmapped): ?>
			<input type="hidden" name="bm_only_unmapped" value="Y">
		<?php endif; ?>
		<?php if ($bm_sort_canon !== ''): ?>
			<input type="hidden" name="bm_sort_canon" value="<?= mf_bm_escape($bm_sort_canon) ?>">
		<?php endif; ?>
		<input type="hidden" name="bm_action" value="manual_map">
		<div style="font-weight:600; margin-bottom:8px;">Ручное сопоставление (любой текст бренда)</div>
		<div style="color:#555; font-size:13px; margin-bottom:10px; line-height:1.4;">
			Впиши строку <b>как в прайсе/остатках</b> и укажи канон: из списка или <b>свой текст</b> в поле ниже (непустое поле заменяет выбор в списке; для «Не сопоставлять» список оставь, поле своего канона очисти).
			Это не меняет уже записанные на товарах значения <code>MF_BRAND</code>, только логику поиска/матчинг при загрузке.
		</div>
		<div style="display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px;">
			<div>
				<label for="id_manual_alias" style="display:block; margin-bottom:4px; color:#333;">Текст бренда (из файла)</label>
				<input type="text" name="manual_alias" id="id_manual_alias" value="" placeholder="например Ski-Doo" style="min-width:280px; padding:4px 8px;"/>
			</div>
			<div style="min-width:360px;">
				<label for="id_manual_target" style="display:block; margin-bottom:4px; color:#333;">Считать как бренд каталога (список)</label>
				<select name="manual_target" id="id_manual_target" style="width:100%; max-width:420px; padding:4px 8px;">
					<option value="">— выбери —</option>
					<option value="<?= mf_bm_escape(MF_BM_MAP_SKIP) ?>">— Не сопоставлять (пропуск при импорте) —</option>
					<?php foreach ($catalogBrands as $bCh): ?>
						<option value="<?= mf_bm_escape((string)$bCh) ?>"><?= mf_bm_escape((string)$bCh) ?></option>
					<?php endforeach; ?>
				</select>
				<label for="id_manual_target_custom" style="display:block; margin:8px 0 4px 0; color:#333;">Свой канон (если заполнено — в приоритете над списком)</label>
				<input type="text" name="manual_target_custom" id="id_manual_target_custom" value="" placeholder="например HONDA или W.S.M." style="width:100%; max-width:420px; padding:4px 8px;"/>
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
							<input type="hidden" name="find_brand" value="<?= mf_bm_escape($find_brand) ?>">
							<?php if ($bm_only_unmapped): ?>
								<input type="hidden" name="bm_only_unmapped" value="Y">
							<?php endif; ?>
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
		data-bm-find-brand="<?= mf_bm_escape($find_brand) ?>"
		data-bm-only-unmapped="<?= $bm_only_unmapped ? '1' : '0' ?>"
		data-bm-sort-canon="<?= mf_bm_escape($bm_sort_canon) ?>"
		data-bm-n="<?= (int)$bmN ?>">
		<?php if ($bmN > 0): ?>
			<div class="js-bm-range" style="margin:10px 0 6px 0; color:#555;">
				Показаны бренды <b class="js-bm-from"><?= (int)$bmShowFrom0 ?></b>–<b class="js-bm-to"><?= (int)$bmShowTo0 ?></b> из <b class="js-bm-total"><?= (int)$bmN ?></b>
				(по <?= (int)$bmPerPage ?> на странице<span class="js-bm-pagenum-wrap"<?= $bmTotalPages < 2 ? ' style="display:none;"' : '' ?>>, стр. <span class="js-bm-curpage"><?= (int)$bmClientPage ?></span> из <span class="js-bm-ntp"><?= (int)$bmTotalPages ?></span></span>).
				Перелистывание без запроса к серверу.
				Сортировка по колонке «Сейчас сопоставлен» — ссылки <b>↑</b> <b>↓</b> в заголовке таблицы (по тексту как на экране; «сброс» — снова порядок по имени бренда из базы).
				<br/><span style="color:#555;">В выпадашках — <strong><?= (int)$catalogBrandsCount ?></strong> брендов каталога (<code>MF_BRAND</code>); подгружаются в браузере для <b>текущей</b> страницы пагинации (кнопки «Назад / Вперёд»). Если видите только «не менять» и «не сопоставлять» — перелистните страницу ещё раз или обновите <code>mf_refresh_ce_brand_choices_cache.php</code>.</span>
			</div>
			<div class="js-bm-nav bm-pager-ui" style="margin:0 0 12px 0;contain:layout;isolation:isolate;transform:translateZ(0);-webkit-backface-visibility:hidden;backface-visibility:hidden;user-select:none;touch-action:manipulation;"></div>
		<?php endif; ?>

		<form method="post" action="<?= mf_bm_escape($APPLICATION->GetCurPageParam('', ['sessid'])) ?>" style="margin-top:0;">
		<?= bitrix_sessid_post() ?>
		<input type="hidden" name="lang" value="<?= mf_bm_escape((string)LANGUAGE_ID) ?>">
		<input type="hidden" name="find_warehouse" value="<?= mf_bm_escape($find_warehouse) ?>">
		<input type="hidden" name="find_brand" value="<?= mf_bm_escape($find_brand) ?>">
		<?php if ($bm_only_unmapped): ?>
			<input type="hidden" name="bm_only_unmapped" value="Y">
		<?php endif; ?>
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
				<td class="adm-list-table-cell">Сопоставить с брендом каталога / свой канон</td>
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
							<?php
							$bmPrefCatalog = '';
							if (!$isSkip && $canon !== '' && isset($catalogBrandsLookup[$canon]))
							{
								$bmPrefCatalog = $canon;
							}
							?>
							<select name="map[<?= mf_bm_escape($alias) ?>]" class="js-bm-catalog-select" style="min-width:420px;" data-bm-pref="<?= htmlspecialcharsbx($bmPrefCatalog) ?>">
								<option value="">— не менять —</option>
								<option value="<?= mf_bm_escape(MF_BM_MAP_SKIP) ?>" <?= ($isSkip ? 'selected' : '') ?>>— Не сопоставлять (пропуск при импорте) —</option>
								<?php if ($canon !== '' && !isset($catalogBrandsLookup[$canon])): ?>
									<option value="<?= mf_bm_escape($canon) ?>" <?= (!$isSkip ? 'selected' : '') ?>><?= mf_bm_escape($canon) ?> (текущий)</option>
								<?php endif; ?>
							</select>
							<div style="margin-top:6px;">
								<label style="font-size:12px;color:#555;display:block;margin-bottom:2px;">Свой канон (необязательно; если заполнено — сохранится вместо выбора выше)</label>
								<input type="text" name="map_custom[<?= mf_bm_escape($alias) ?>]" value="" placeholder="<?= mf_bm_escape('Свой текст канона') ?>" style="min-width:420px;max-width:100%;padding:4px 8px;box-sizing:border-box;"/>
							</div>
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

<script type="application/json" id="mf-bm-catalog-brands-json"><?= $catalogBrandsJson ?></script>
<script>
(function () {
	var root = document.getElementById("bm-client-pager");
	if (!root) return;

	var rows = Array.prototype.slice.call(root.querySelectorAll("tr.js-bm-row"));
	var n = rows.length;
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
	var findBrand = root.getAttribute("data-bm-find-brand") || "";
	var onlyUnmapped = root.getAttribute("data-bm-only-unmapped") || "0";
	var sortCanon = root.getAttribute("data-bm-sort-canon") || "";

	var navPrevS = -1, navPrevE = -1;

	var brands = [];
	try {
		var jel = document.getElementById("mf-bm-catalog-brands-json");
		if (jel && jel.textContent) {
			var parsed = JSON.parse(jel.textContent);
			if (Array.isArray(parsed)) {
				brands = parsed;
			}
		}
	} catch (e) {
		brands = [];
	}

	function pageRange(page) {
		var s = (page - 1) * per;
		return { start: s, end: Math.min(page * per, n) };
	}

	function mfBmFillOneCatalogSelect(sel) {
		if (!sel || sel.getAttribute("data-bm-filled") === "1") {
			return;
		}
		if (brands.length === 0) {
			return;
		}
		sel.setAttribute("data-bm-filled", "1");
		var pref = sel.getAttribute("data-bm-pref") || "";
		var frag = document.createDocumentFragment();
		for (var i = 0; i < brands.length; i++) {
			var b = String(brands[i] != null ? brands[i] : "").trim();
			if (b === "") {
				continue;
			}
			var o = document.createElement("option");
			o.value = b;
			o.textContent = b;
			if (pref !== "" && b === pref) {
				o.selected = true;
			}
			frag.appendChild(o);
		}
		sel.appendChild(frag);
	}

	function mfBmFillCatalogSelectsInRange(start, end) {
		for (var i = start; i < end; i++) {
			var row = rows[i];
			if (!row) {
				continue;
			}
			var sel = row.querySelector("select.js-bm-catalog-select");
			if (sel) {
				mfBmFillOneCatalogSelect(sel);
			}
		}
	}

	function setUrlPage(p) {
		var q = { lang: lang, find_warehouse: wh };
		if (findBrand !== "") { q.find_brand = findBrand; }
		if (onlyUnmapped === "1") { q.bm_only_unmapped = "Y"; }
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
		var range = pageRange(cur);
		var s = range.start, e = range.end;
		if (s === navPrevS && e === navPrevE) {
			return;
		}
		var i, j;
		if (navPrevS >= 0) {
			for (i = navPrevS; i < navPrevE; i++) {
				if (rows[i] && (i < s || i >= e)) {
					rows[i].style.display = "none";
				}
			}
		}
		for (j = s; j < e; j++) {
			if (rows[j] && (j < navPrevS || j >= navPrevE)) {
				rows[j].style.display = "";
			}
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
		var range = pageRange(cur);
		showRows();
		mfBmFillCatalogSelectsInRange(range.start, range.end);
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

