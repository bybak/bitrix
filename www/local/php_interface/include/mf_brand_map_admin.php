<?php

declare(strict_types=1);

use Bitrix\Main\Application;
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

$brandDict = $_SERVER['DOCUMENT_ROOT'] . '/mf_brand_dict.php';
if (!is_file($brandDict))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не найден файл словаря брендов: ' . $brandDict]);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}
require_once $brandDict;

/** POST: «Не импортировать» вместо канона. */
const MF_BM_MAP_SKIP = '__MF_SKIP__';

/** Приоритет ручных сопоставлений (выше встроенных сидов в mf_brand_dict). */
const MF_BM_MANUAL_ALIAS_SORT = 400;

Loader::includeModule('highloadblock');
Loader::includeModule('iblock');

function mf_bm_escape(string $s): string
{
	return function_exists('htmlspecialcharsbx')
		? (string)htmlspecialcharsbx($s)
		: htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mf_bm_format_dt($value): string
{
	if ($value === null || $value === '')
	{
		return '—';
	}
	if (is_object($value) && method_exists($value, 'format'))
	{
		return (string)$value->format('Y-m-d H:i');
	}

	return (string)$value;
}

/**
 * @return list<array<string, mixed>>
 */
function mf_bm_load_alias_rows(bool $activeOnly, string $find): array
{
	$rows = [];
	$find = mb_strtolower(trim($find));
	try
	{
		$hl = mf_brand_hl_ensure(false);
		if (!$hl || empty($hl['DATA_CLASS']))
		{
			return [];
		}
		$filter = [];
		if ($activeOnly)
		{
			$filter['=UF_ACTIVE'] = 1;
		}
		$rs = $hl['DATA_CLASS']::getList([
			'filter' => $filter,
			'select' => [
				'ID',
				'UF_ALIAS',
				'UF_ALIAS_NORM',
				'UF_CANONICAL',
				'UF_CANONICAL_NORM',
				'UF_SORT',
				'UF_ACTIVE',
				'UF_UPDATED_AT',
			],
			'order' => ['UF_SORT' => 'DESC', 'UF_ALIAS' => 'ASC', 'ID' => 'ASC'],
		]);
		while ($r = $rs->fetch())
		{
			if ($find !== '')
			{
				$hay = mb_strtolower(
					trim((string)($r['UF_ALIAS'] ?? '')) . ' '
					. trim((string)($r['UF_CANONICAL'] ?? '')) . ' '
					. trim((string)($r['UF_ALIAS_NORM'] ?? '')) . ' '
					. trim((string)($r['UF_CANONICAL_NORM'] ?? ''))
				);
				if (mb_strpos($hay, $find) === false)
				{
					continue;
				}
			}
			$rows[] = $r;
		}
	}
	catch (\Throwable $e)
	{
		return [];
	}

	return $rows;
}

/**
 * @return list<array<string, mixed>>
 */
function mf_bm_load_import_skip_rows(string $find): array
{
	if (!function_exists('mf_brand_import_skip_ensure_table') || !mf_brand_import_skip_ensure_table())
	{
		return [];
	}
	$find = mb_strtolower(trim($find));
	$out = [];
	try
	{
		$conn = Application::getConnection();
		$rs = $conn->query("
			SELECT ID, UF_ALIAS_RAW, UF_ALIAS_NORM, UF_ACTIVE, UF_UPDATED_AT
			FROM mf_brand_import_skip
			WHERE UF_ACTIVE = 'Y'
			ORDER BY UF_ALIAS_RAW ASC, ID ASC
		");
		while ($r = $rs->fetch())
		{
			if ($find !== '')
			{
				$hay = mb_strtolower(
					trim((string)($r['UF_ALIAS_RAW'] ?? '')) . ' '
					. trim((string)($r['UF_ALIAS_NORM'] ?? ''))
				);
				if (mb_strpos($hay, $find) === false)
				{
					continue;
				}
			}
			$out[] = $r;
		}
	}
	catch (\Throwable $e)
	{
		return [];
	}

	return $out;
}

/**
 * @return list<string>
 */
function mf_bm_catalog_brand_choices(): array
{
	if (!function_exists('mf_ce_load_brand_choices'))
	{
		require_once __DIR__ . '/mf_ce_brand_choices_inc.php';
	}
	$out = [];
	foreach (mf_ce_load_brand_choices(4, true, ['MF_BRAND']) as $b)
	{
		$b = trim((string)$b);
		if ($b !== '')
		{
			$out[$b] = true;
		}
	}
	try
	{
		$hl = mf_brand_hl_ensure(false);
		if ($hl && !empty($hl['DATA_CLASS']))
		{
			$rs = $hl['DATA_CLASS']::getList([
				'filter' => ['=UF_ACTIVE' => 1],
				'select' => ['UF_CANONICAL'],
				'limit' => 5000,
			]);
			while ($r = $rs->fetch())
			{
				$b = trim((string)($r['UF_CANONICAL'] ?? ''));
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
	natcasesort($keys);

	return array_values($keys);
}

/**
 * HTML <option> для выбора канона (каталог + текущее значение, если его нет в списке).
 */
function mf_bm_canon_options_html(array $catalogBrands, string $selected = ''): string
{
	$selected = trim($selected);
	$html = '<option value="">— выберите —</option>';
	$skipSel = ($selected === MF_BM_MAP_SKIP) ? ' selected' : '';
	$html .= '<option value="' . mf_bm_escape(MF_BM_MAP_SKIP) . '" title="Не импортировать строки с этим брендом"' . $skipSel . '>— не импортировать —</option>';
	$inList = false;
	foreach ($catalogBrands as $bCh)
	{
		$bCh = (string)$bCh;
		if ($bCh === '')
		{
			continue;
		}
		if ($selected !== '' && $selected === $bCh)
		{
			$inList = true;
		}
		$esc = mf_bm_escape($bCh);
		$selAttr = ($selected !== '' && $selected === $bCh) ? ' selected' : '';
		$html .= '<option value="' . $esc . '" title="' . $esc . '"' . $selAttr . '>' . $esc . '</option>';
	}
	if ($selected !== '' && $selected !== MF_BM_MAP_SKIP && !$inList)
	{
		$esc = mf_bm_escape($selected);
		$html .= '<option value="' . $esc . '" title="' . $esc . '" selected>' . $esc . ' (не в каталоге)</option>';
	}

	return $html;
}

/**
 * Переводит сопоставление HL в «не импортировать» (удаляет запись HL, пишет skip).
 *
 * @return string|null сообщение об ошибке или null при успехе
 */
function mf_bm_convert_alias_to_skip(int $id, string $alias): ?string
{
	$row = mf_bm_fetch_alias_by_id($id);
	if ($row === null)
	{
		return 'Запись #' . $id . ' не найдена.';
	}

	$alias = trim($alias);
	if ($alias === '')
	{
		return 'Укажите текст бренда (алиас).';
	}

	if (!function_exists('mf_brand_import_skip_set'))
	{
		return 'Функция mf_brand_import_skip_set недоступна.';
	}

	try
	{
		$hl = mf_brand_hl_ensure(false);
		if ($hl && !empty($hl['DATA_CLASS']))
		{
			$hl['DATA_CLASS']::delete($id);
		}
		mf_brand_import_skip_set($alias, true);
	}
	catch (\Throwable $e)
	{
		return 'Ошибка: ' . $e->getMessage();
	}

	return null;
}

/**
 * @return array<string, mixed>|null
 */
function mf_bm_fetch_alias_by_id(int $id): ?array
{
	if ($id <= 0)
	{
		return null;
	}
	try
	{
		$hl = mf_brand_hl_ensure(false);
		if (!$hl || empty($hl['DATA_CLASS']))
		{
			return null;
		}
		$row = $hl['DATA_CLASS']::getList([
			'filter' => ['=ID' => $id],
			'select' => [
				'ID',
				'UF_ALIAS',
				'UF_ALIAS_NORM',
				'UF_CANONICAL',
				'UF_CANONICAL_NORM',
				'UF_SORT',
				'UF_ACTIVE',
			],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? $row : null;
	}
	catch (\Throwable $e)
	{
		return null;
	}
}

/**
 * @return string|null сообщение об ошибке или null при успехе
 */
function mf_bm_update_alias_by_id(int $id, string $alias, string $canon, bool $active): ?string
{
	$row = mf_bm_fetch_alias_by_id($id);
	if ($row === null)
	{
		return 'Запись #' . $id . ' не найдена.';
	}

	$alias = trim($alias);
	$canon = trim($canon);
	if ($alias === '' || $canon === '')
	{
		return 'Заполните алиас и канон.';
	}

	$aliasNorm = mf_brand_norm($alias);
	$canonNorm = mf_brand_norm($canon);
	if ($aliasNorm === '' || $canonNorm === '')
	{
		return 'Не удалось нормализовать алиас или канон.';
	}

	try
	{
		$hl = mf_brand_hl_ensure(false);
		if (!$hl || empty($hl['DATA_CLASS']))
		{
			return 'HL mf_brand_alias недоступен.';
		}
		$dc = $hl['DATA_CLASS'];
		$dup = $dc::getList([
			'filter' => ['=UF_ALIAS_NORM' => $aliasNorm],
			'select' => ['ID'],
			'limit' => 2,
		]);
		while ($d = $dup->fetch())
		{
			if ((int)($d['ID'] ?? 0) !== $id)
			{
				return 'Алиас «' . $alias . '» уже сопоставлен в записи #' . (int)$d['ID'] . '.';
			}
		}

		if (function_exists('mf_brand_import_skip_set'))
		{
			mf_brand_import_skip_set($alias, false);
		}

		$dc::update($id, [
			'UF_ALIAS' => $alias,
			'UF_ALIAS_NORM' => $aliasNorm,
			'UF_CANONICAL' => $canon,
			'UF_CANONICAL_NORM' => $canonNorm,
			'UF_ACTIVE' => $active ? 1 : 0,
			'UF_SORT' => (int)($row['UF_SORT'] ?? 0),
			'UF_UPDATED_AT' => new \Bitrix\Main\Type\DateTime(),
		]);
		mf_brand_aliases_reset_cache();
	}
	catch (\Throwable $e)
	{
		return 'Ошибка сохранения: ' . $e->getMessage();
	}

	return null;
}

/**
 * @return string|null сообщение об ошибке или null при успехе
 */
function mf_bm_update_skip_by_id(int $id, string $aliasRaw): ?string
{
	if (!mf_brand_import_skip_ensure_table())
	{
		return 'Таблица mf_brand_import_skip недоступна.';
	}

	$aliasRaw = trim($aliasRaw);
	if ($aliasRaw === '')
	{
		return 'Укажите текст бренда.';
	}

	$aliasNorm = mf_brand_norm($aliasRaw);
	if ($aliasNorm === '')
	{
		return 'Не удалось нормализовать бренд.';
	}

	try
	{
		$conn = Application::getConnection();
		$h = $conn->getSqlHelper();
		$cur = $conn->query('SELECT ID FROM mf_brand_import_skip WHERE ID=' . $id . ' LIMIT 1')->fetch();
		if (!$cur)
		{
			return 'Запись пропуска #' . $id . ' не найдена.';
		}
		$dup = $conn->query(
			"SELECT ID FROM mf_brand_import_skip WHERE UF_ALIAS_NORM='"
				. $h->forSql($aliasNorm) . "' AND ID<>" . $id . " LIMIT 1"
		)->fetch();
		if ($dup)
		{
			return 'Бренд «' . $aliasRaw . '» уже есть в записи #' . (int)$dup['ID'] . '.';
		}
		$now = date('Y-m-d H:i:s');
		$conn->queryExecute(
			"UPDATE mf_brand_import_skip SET UF_ALIAS_RAW='"
				. $h->forSql($aliasRaw) . "', UF_ALIAS_NORM='"
				. $h->forSql($aliasNorm) . "', UF_ACTIVE='Y', UF_UPDATED_AT='"
				. $h->forSql($now) . "' WHERE ID=" . $id
		);
	}
	catch (\Throwable $e)
	{
		return 'Ошибка сохранения: ' . $e->getMessage();
	}

	return null;
}

$adminNotice = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && check_bitrix_sessid())
{
	$action = trim((string)($_POST['bm_action'] ?? ''));

	if ($action === 'add')
	{
		$alias = trim((string)($_POST['add_alias'] ?? ''));
		$mode = trim((string)($_POST['add_mode'] ?? 'map'));
		$canonSelect = trim((string)($_POST['add_canon'] ?? ''));
		$canonCustom = trim((string)($_POST['add_canon_custom'] ?? ''));

		if ($alias === '')
		{
			$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Укажите текст бренда (как в прайсе/остатках).'];
		}
		elseif ($mode === 'skip')
		{
			if (function_exists('mf_brand_import_skip_set'))
			{
				mf_brand_import_skip_set($alias, true);
				$adminNotice = ['TYPE' => 'OK', 'MESSAGE' => 'Бренд «' . $alias . '» помечен: не импортировать.'];
			}
			else
			{
				$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Функция mf_brand_import_skip_set недоступна.'];
			}
		}
		else
		{
			$canon = $canonCustom !== '' ? $canonCustom : $canonSelect;
			if ($canon === MF_BM_MAP_SKIP)
			{
				if (function_exists('mf_brand_import_skip_set'))
				{
					mf_brand_import_skip_set($alias, true);
					$adminNotice = ['TYPE' => 'OK', 'MESSAGE' => 'Бренд «' . $alias . '» помечен: не импортировать.'];
				}
				else
				{
					$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Функция mf_brand_import_skip_set недоступна.'];
				}
			}
			elseif ($canon === '')
			{
				$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Укажите канонический бренд (из списка или своим текстом).'];
			}
			else
			{
				$hl = mf_brand_hl_ensure(true);
				if (!$hl)
				{
					$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Не удалось открыть HL mf_brand_alias.'];
				}
				else
				{
					mf_brand_register_alias($hl, $canon, $alias, true, MF_BM_MANUAL_ALIAS_SORT);
					$adminNotice = [
						'TYPE' => 'OK',
						'MESSAGE' => 'Сохранено: «' . $alias . '» → «' . $canon . '» (приоритет ' . MF_BM_MANUAL_ALIAS_SORT . ').',
					];
				}
			}
		}
	}
	elseif ($action === 'update_alias')
	{
		$editId = (int)($_POST['edit_id'] ?? 0);
		$alias = trim((string)($_POST['edit_alias'] ?? ''));
		$canon = trim((string)($_POST['edit_canon'] ?? ''));
		$active = (string)($_POST['edit_active'] ?? '') === 'Y';

		if ($canon === MF_BM_MAP_SKIP)
		{
			$err = mf_bm_convert_alias_to_skip($editId, $alias);
			if ($err !== null)
			{
				$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => $err];
			}
			else
			{
				$adminNotice = [
					'TYPE' => 'OK',
					'MESSAGE' => 'Бренд «' . $alias . '» перенесён в «не импортировать» (сопоставление #' . $editId . ' снято).',
				];
			}
		}
		else
		{
			$err = mf_bm_update_alias_by_id($editId, $alias, $canon, $active);
			if ($err !== null)
			{
				$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => $err];
			}
			else
			{
				$adminNotice = [
					'TYPE' => 'OK',
					'MESSAGE' => 'Сопоставление #' . $editId . ' обновлено: «' . $alias . '» → «' . $canon . '».',
				];
			}
		}
	}
	elseif ($action === 'update_skip')
	{
		$editId = (int)($_POST['edit_id'] ?? 0);
		$aliasRaw = trim((string)($_POST['edit_skip_alias'] ?? ''));

		$err = mf_bm_update_skip_by_id($editId, $aliasRaw);
		if ($err !== null)
		{
			$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => $err];
		}
		else
		{
			$adminNotice = [
				'TYPE' => 'OK',
				'MESSAGE' => 'Запись пропуска #' . $editId . ' обновлена: «' . $aliasRaw . '».',
			];
		}
	}
	elseif ($action === 'delete_alias')
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
				$hl = mf_brand_hl_ensure(false);
				if ($hl && !empty($hl['DATA_CLASS']))
				{
					$hl['DATA_CLASS']::delete($delId);
					mf_brand_aliases_reset_cache();
					$adminNotice = ['TYPE' => 'OK', 'MESSAGE' => 'Сопоставление #' . $delId . ' удалено.'];
				}
			}
			catch (\Throwable $e)
			{
				$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Ошибка удаления: ' . $e->getMessage()];
			}
		}
	}
	elseif ($action === 'delete_skip')
	{
		$delId = (int)($_POST['delete_id'] ?? 0);
		if ($delId <= 0)
		{
			$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Не передан ID записи.'];
		}
		elseif (!mf_brand_import_skip_ensure_table())
		{
			$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Таблица mf_brand_import_skip недоступна.'];
		}
		else
		{
			try
			{
				Application::getConnection()->queryExecute(
					'DELETE FROM mf_brand_import_skip WHERE ID=' . $delId
				);
				$adminNotice = ['TYPE' => 'OK', 'MESSAGE' => 'Запись пропуска #' . $delId . ' удалена.'];
			}
			catch (\Throwable $e)
			{
				$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Ошибка удаления: ' . $e->getMessage()];
			}
		}
	}
}

$catalogBrands = mf_bm_catalog_brand_choices();

$activeOnly = (string)($_REQUEST['active_only'] ?? 'Y') !== 'N';
$find = trim((string)($_REQUEST['find'] ?? ''));
$perPage = max(10, min(200, (int)($_REQUEST['per_page'] ?? 50)));
$page = max(1, (int)($_REQUEST['page'] ?? 1));

$aliasRows = mf_bm_load_alias_rows($activeOnly, $find);
$skipRows = mf_bm_load_import_skip_rows($find);

$aliasTotal = count($aliasRows);
$aliasPages = max(1, (int)ceil($aliasTotal / $perPage));
if ($page > $aliasPages)
{
	$page = $aliasPages;
}
$aliasSlice = array_slice($aliasRows, ($page - 1) * $perPage, $perPage);

$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
$curPage = (string)($APPLICATION->GetCurPage() ?? 'mf_brand_map.php');
$baseUrl = $curPage . '?lang=' . rawurlencode($lang);

$navRemove = ['page'];
$baseParams = [
	'lang' => $lang,
	'active_only' => $activeOnly ? 'Y' : 'N',
	'find' => $find,
	'per_page' => (string)$perPage,
];

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$APPLICATION->SetTitle('Сопоставление брендов');

?>
<style>
.mf-bss { max-width: 1280px; font-family: var(--ui-font-family-primary, "Helvetica Neue", Helvetica, Arial, sans-serif); color: #333; }
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
.mf-bss__field input[type="text"], .mf-bss__field select {
	padding: 7px 10px;
	border: 1px solid #cbd5e0;
	border-radius: 6px;
	font-size: 13px;
	box-sizing: border-box;
}
.mf-bss__field input[type="text"] { min-width: 200px; }
.mf-bss__field select { min-width: 120px; width: auto; max-width: none; }
/* Bitrix .adm-workarea select { height:27px } — режет текст по вертикали */
.mf-bss__field select,
.mf-bss__field select option {
	line-height: 1.45;
}
.mf-bss__field select {
	height: auto !important;
	min-height: 2.35em;
	padding: 8px 28px 8px 10px !important;
}
.mf-bss__field select option {
	padding: 6px 10px;
	min-height: 1.75em;
}
.mf-bss__field--canon { flex: 1 1 280px; max-width: 520px; min-width: 240px; }
.mf-bss__field--canon select {
	width: 100%;
	min-width: 240px;
	max-width: 520px;
}
.mf-bss__field input[type="text"]:focus, .mf-bss__field select:focus { border-color: #4299e1; outline: none; box-shadow: 0 0 0 2px rgba(66,153,225,.2); }
.mf-bss__checks { display: flex; flex-direction: column; gap: 8px; padding-bottom: 2px; }
.mf-bss__check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #4a5568; cursor: pointer; user-select: none; }
.mf-bss__check input { margin: 0; }
.mf-bss__actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.mf-bss__reset { font-size: 13px; color: #718096; text-decoration: none; }
.mf-bss__reset:hover { color: #2b6cb0; text-decoration: underline; }
.mf-bss__section-title { font-size: 15px; font-weight: 600; margin: 0 0 12px 0; color: #2d3748; }
.mf-bss__table-wrap { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(15,23,42,.06); margin-bottom: 24px; }
.mf-bss__table { width: 100%; border-collapse: collapse; font-size: 13px; }
.mf-bss__table thead { background: #f7fafc; border-bottom: 1px solid #e2e8f0; }
.mf-bss__table th { padding: 11px 14px; text-align: left; font-weight: 600; font-size: 12px; color: #4a5568; text-transform: uppercase; letter-spacing: .03em; }
.mf-bss__table th.mf-bss__th-num { width: 52px; text-align: center; }
.mf-bss__table th.mf-bss__th-arrow { width: 36px; text-align: center; }
.mf-bss__table th.mf-bss__th-meta { width: 100px; text-align: center; }
.mf-bss__table th.mf-bss__th-date { width: 130px; text-align: right; }
.mf-bss__table td { padding: 10px 14px; border-bottom: 1px solid #edf2f7; vertical-align: middle; }
.mf-bss__table tbody tr:last-child td { border-bottom: none; }
.mf-bss__table tbody tr:hover { background: #f7fafc; }
.mf-bss__table tbody tr:nth-child(even) { background: #fafbfc; }
.mf-bss__table tbody tr:nth-child(even):hover { background: #f1f5f9; }
.mf-bss__num { text-align: center; color: #a0aec0; font-size: 12px; font-variant-numeric: tabular-nums; }
.mf-bss__alias { font-weight: 500; color: #4a5568; }
.mf-bss__canon { font-weight: 600; color: #2d3748; }
.mf-bss__arrow { text-align: center; color: #a0aec0; font-size: 16px; }
.mf-bss__meta { text-align: center; font-variant-numeric: tabular-nums; color: #4a5568; }
.mf-bss__date { text-align: right; color: #718096; font-size: 12px; white-space: nowrap; }
.mf-bss__badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
.mf-bss__badge--ok { background: #c6f6d5; color: #22543d; }
.mf-bss__badge--off { background: #fed7d7; color: #742a2a; }
.mf-bss__badge--skip { background: #feebc8; color: #7b341e; }
.mf-bss__empty { padding: 32px 20px; text-align: center; color: #718096; font-size: 14px; }
.mf-bss__pager { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 14px; }
.mf-bss__pager-btn { display: inline-block; padding: 6px 12px; border: 1px solid #cbd5e0; border-radius: 6px; background: #fff; color: #2b6cb0; text-decoration: none; font-size: 13px; }
.mf-bss__pager-btn:hover { background: #ebf8ff; border-color: #90cdf4; }
.mf-bss__pager-btn--cur { background: #4299e1; border-color: #4299e1; color: #fff; pointer-events: none; }
.mf-bss__footer { margin-top: 8px; padding-top: 14px; font-size: 12px; color: #718096; line-height: 1.6; }
.mf-bss__footer a { color: #2b6cb0; text-decoration: none; }
.mf-bss__footer a:hover { text-decoration: underline; }
.mf-bss__add { margin-bottom: 20px; padding: 16px 18px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 1px 3px rgba(15,23,42,.05); }
.mf-bss__add-hint { font-size: 12px; color: #718096; margin: 0 0 12px 0; line-height: 1.45; }
.mf-bss__add-row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 14px 18px; }
.mf-bss__field--wide input[type="text"] { min-width: 260px; }
.mf-bss__th-actions { width: 150px; text-align: center; }
.mf-bss__actions-cell { text-align: center; white-space: nowrap; }
.mf-bss__row-actions { display: flex; flex-wrap: wrap; gap: 6px; justify-content: center; align-items: center; }
.mf-bss__table--map { table-layout: fixed; }
.mf-bss__th-alias { width: 36%; }
.mf-bss__th-canon { width: 30%; }
.mf-bss__th-more { width: 44px; text-align: center; }
.mf-bss__inp-cell { min-width: 120px; }
.mf-bss__inp-cell--alias input[type="text"] {
	width: 100%;
	min-width: 160px;
	max-width: none;
	padding: 6px 8px;
	font-size: 13px;
}
.mf-bss__inp-cell input[type="text"] {
	width: 100%;
	min-width: 100px;
	padding: 6px 8px;
	font-size: 13px;
}
.mf-bss__inp-cell--canon select {
	width: 100%;
	min-width: 160px;
	max-width: none;
}
.mf-bss__btn-more {
	font-size: 11px;
	line-height: 1;
	color: #4a5568;
	background: #edf2f7;
	border: 1px solid #cbd5e0;
	border-radius: 6px;
	padding: 5px 8px;
	cursor: pointer;
	vertical-align: middle;
}
.mf-bss__btn-more:hover { background: #e2e8f0; color: #2d3748; }
.mf-bss__btn-more[aria-expanded="true"] { background: #ebf8ff; border-color: #90cdf4; color: #2b6cb0; }
.mf-bss__details-row td {
	background: #f7fafc;
	padding: 8px 14px 10px 52px;
	font-size: 12px;
	color: #718096;
	border-bottom: 1px solid #edf2f7;
}
.mf-bss__details-row[hidden] { display: none; }
.mf-bss__details-kv { display: flex; flex-wrap: wrap; gap: 8px 20px; }
.mf-bss__details-kv strong { color: #4a5568; font-weight: 600; }
.mf-bss__check-inline {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	font-size: 12px;
	color: #4a5568;
	cursor: pointer;
	white-space: nowrap;
}
.mf-bss__check-inline input { margin: 0; }
.mf-bss__btn-save { font-size: 12px; color: #2b6cb0; background: #ebf8ff; border: 1px solid #90cdf4; border-radius: 6px; padding: 4px 10px; cursor: pointer; }
.mf-bss__btn-save:hover { background: #bee3f8; }
.mf-bss__btn-del { font-size: 12px; color: #c53030; background: transparent; border: 1px solid #feb2b2; border-radius: 6px; padding: 4px 10px; cursor: pointer; }
.mf-bss__btn-del:hover { background: #fff5f5; }
.mf-bss__sort-readonly { color: #a0aec0; font-size: 12px; }
.adm-workarea .mf-bss select {
	height: auto !important;
	min-height: 2.35em !important;
	line-height: 1.45 !important;
	padding-top: 8px !important;
	padding-bottom: 8px !important;
}
.adm-workarea .mf-bss__field--canon select {
	max-width: 520px !important;
	width: 100% !important;
}
</style>

<div class="mf-bss">
	<div class="mf-bss__lead">
		Справочник <strong>алиас → канон</strong> из HL <code>mf_brand_alias</code>: как бренд приходит в прайсе/остатках и во что он переводится при импорте.
		Отдельно — бренды из <code>mf_brand_import_skip</code>, которые <strong>не импортируются</strong>.
		Ниже можно <strong>добавить</strong> сопоставление или пометку «не импортировать»; в таблицах — <strong>редактирование</strong> и удаление.
	</div>

	<?php if (is_array($adminNotice)): ?>
		<?php \CAdminMessage::ShowMessage($adminNotice); ?>
	<?php endif; ?>

	<form method="post" action="<?= mf_bm_escape($curPage) ?>" class="mf-bss__add">
		<?= bitrix_sessid_post() ?>
		<input type="hidden" name="lang" value="<?= mf_bm_escape($lang) ?>">
		<input type="hidden" name="bm_action" value="add">
		<input type="hidden" name="active_only" value="<?= $activeOnly ? 'Y' : 'N' ?>">
		<input type="hidden" name="find" value="<?= mf_bm_escape($find) ?>">
		<input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
		<input type="hidden" name="page" value="<?= (int)$page ?>">
		<div class="mf-bss__filters-title">Добавить запись</div>
		<p class="mf-bss__add-hint">
			Текст бренда — <b>как в файле поставщика</b>. Выберите канон из списка или укажите свой («Свой канон» в приоритете, если заполнен).
			Приоритет новых сопоставлений: <?= (int)MF_BM_MANUAL_ALIAS_SORT ?>.
		</p>
		<div class="mf-bss__add-row">
			<div class="mf-bss__field mf-bss__field--wide">
				<label for="mf_bm_add_alias">Бренд в файле (алиас)</label>
				<input type="text" name="add_alias" id="mf_bm_add_alias" value="" placeholder="например Ski-Doo" required>
			</div>
			<div class="mf-bss__field">
				<label for="mf_bm_add_mode">Действие</label>
				<select name="add_mode" id="mf_bm_add_mode">
					<option value="map">Сопоставить с каноном</option>
					<option value="skip">Не импортировать</option>
				</select>
			</div>
			<div class="mf-bss__field mf-bss__field--canon">
				<label for="mf_bm_add_canon">Канон (из каталога)</label>
				<select name="add_canon" id="mf_bm_add_canon" title="Канонический бренд из MF_BRAND">
					<?= mf_bm_canon_options_html($catalogBrands) ?>
				</select>
			</div>
			<div class="mf-bss__field mf-bss__field--wide">
				<label for="mf_bm_add_canon_custom">Свой канон (необязательно)</label>
				<input type="text" name="add_canon_custom" id="mf_bm_add_canon_custom" value="" placeholder="если нет в списке">
			</div>
			<div class="mf-bss__actions">
				<input type="submit" class="adm-btn-save" value="Сохранить">
			</div>
		</div>
	</form>
	<script>
	(function () {
		var sel = document.getElementById('mf_bm_add_canon');
		if (!sel) { return; }
		function syncTitle() {
			var opt = sel.options[sel.selectedIndex];
			sel.title = opt ? (opt.text || opt.value || '') : '';
		}
		sel.addEventListener('change', syncTitle);
		syncTitle();
	})();
	</script>

	<select id="mf_bm_canon_options_tpl" class="mf-bss__canon-tpl" hidden aria-hidden="true" tabindex="-1">
		<?= mf_bm_canon_options_html($catalogBrands) ?>
	</select>

	<form method="get" action="<?= mf_bm_escape($curPage) ?>" class="mf-bss__filters">
		<input type="hidden" name="lang" value="<?= mf_bm_escape($lang) ?>">
		<div class="mf-bss__filters-title">Фильтры</div>
		<div class="mf-bss__filters-row">
			<div class="mf-bss__checks">
				<label class="mf-bss__check">
					<input type="checkbox" name="active_only" value="Y" <?= $activeOnly ? 'checked' : '' ?>>
					Только активные сопоставления
				</label>
			</div>
			<div class="mf-bss__field">
				<label for="mf_bm_find">Поиск (алиас или канон)</label>
				<input type="text" id="mf_bm_find" name="find" value="<?= mf_bm_escape($find) ?>" placeholder="например Ski-Doo">
			</div>
			<div class="mf-bss__field">
				<label for="mf_bm_per_page">На странице</label>
				<select name="per_page" id="mf_bm_per_page">
					<?php foreach ([25, 50, 100, 200] as $pp): ?>
						<option value="<?= (int)$pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= (int)$pp ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="mf-bss__actions">
				<input type="submit" class="adm-btn-save" value="Показать">
				<a class="mf-bss__reset" href="<?= mf_bm_escape($baseUrl) ?>">Сбросить</a>
			</div>
		</div>
	</form>

	<div class="mf-bss__cards">
		<div class="mf-bss__card">
			<div class="mf-bss__card-label">Сопоставлений</div>
			<div class="mf-bss__card-value"><?= number_format($aliasTotal, 0, '', ' ') ?></div>
			<?php if ($aliasPages > 1): ?>
				<div class="mf-bss__card-hint">стр. <?= (int)$page ?> из <?= (int)$aliasPages ?></div>
			<?php endif; ?>
		</div>
		<div class="mf-bss__card">
			<div class="mf-bss__card-label">Не импортировать</div>
			<div class="mf-bss__card-value"><?= number_format(count($skipRows), 0, '', ' ') ?></div>
			<div class="mf-bss__card-hint">mf_brand_import_skip</div>
		</div>
	</div>

	<h2 class="mf-bss__section-title">Сопоставления</h2>
	<div class="mf-bss__table-wrap">
		<?php if ($aliasTotal === 0): ?>
			<div class="mf-bss__empty">Нет записей по выбранным фильтрам.</div>
		<?php else: ?>
			<table class="mf-bss__table mf-bss__table--map">
				<thead>
				<tr>
					<th class="mf-bss__th-num">#</th>
					<th class="mf-bss__th-alias">Алиас</th>
					<th class="mf-bss__th-arrow"></th>
					<th class="mf-bss__th-canon">Канон</th>
					<th class="mf-bss__th-meta">Статус</th>
					<th class="mf-bss__th-more" title="Приоритет и дата обновления"></th>
					<th class="mf-bss__th-actions"></th>
				</tr>
				</thead>
				<tbody>
				<?php
				$rowNum = ($page - 1) * $perPage;
				foreach ($aliasSlice as $row):
					$rowNum++;
					$rowId = (int)($row['ID'] ?? 0);
					$isActive = !empty($row['UF_ACTIVE']);
					$formId = 'mf_bm_alias_' . $rowId;
					$aliasVal = (string)($row['UF_ALIAS'] ?? '');
					$canonVal = (string)($row['UF_CANONICAL'] ?? '');
					$sortVal = (int)($row['UF_SORT'] ?? 0);
					$updatedStr = mf_bm_format_dt($row['UF_UPDATED_AT'] ?? null);
					?>
					<tr class="mf-bss__data-row">
						<td class="mf-bss__num"><?= $rowNum ?></td>
						<td class="mf-bss__inp-cell mf-bss__inp-cell--alias">
							<label class="adm-invisible">Алиас</label>
							<input
								type="text"
								form="<?= mf_bm_escape($formId) ?>"
								name="edit_alias"
								value="<?= mf_bm_escape($aliasVal) ?>"
								title="<?= mf_bm_escape($aliasVal) ?>"
							>
						</td>
						<td class="mf-bss__arrow" aria-hidden="true">→</td>
						<td class="mf-bss__inp-cell mf-bss__inp-cell--canon">
							<label class="adm-invisible">Канон</label>
							<select
								form="<?= mf_bm_escape($formId) ?>"
								name="edit_canon"
								class="js-mf-bm-edit-canon"
								data-selected="<?= mf_bm_escape($canonVal) ?>"
								title="<?= mf_bm_escape($canonVal) ?>"
							>
								<option value="">— загрузка списка —</option>
							</select>
						</td>
						<td class="mf-bss__meta">
							<label class="mf-bss__check-inline" title="Активно">
								<input
									type="checkbox"
									form="<?= mf_bm_escape($formId) ?>"
									name="edit_active"
									value="Y"
									<?= $isActive ? 'checked' : '' ?>
								>
								<?= $isActive ? 'вкл' : 'выкл' ?>
							</label>
						</td>
						<td class="mf-bss__meta">
							<button
								type="button"
								class="mf-bss__btn-more js-mf-bm-details-toggle"
								aria-expanded="false"
								aria-controls="mf_bm_details_<?= $rowId ?>"
								title="Приоритет и дата обновления"
							>⋯</button>
						</td>
						<td class="mf-bss__actions-cell">
							<div class="mf-bss__row-actions">
								<button type="submit" form="<?= mf_bm_escape($formId) ?>" class="mf-bss__btn-save">Сохранить</button>
								<form method="post" action="<?= mf_bm_escape($curPage) ?>" style="margin:0;display:inline;" onsubmit="return confirm('Удалить сопоставление #<?= $rowId ?>?');">
									<?= bitrix_sessid_post() ?>
									<input type="hidden" name="lang" value="<?= mf_bm_escape($lang) ?>">
									<input type="hidden" name="bm_action" value="delete_alias">
									<input type="hidden" name="delete_id" value="<?= $rowId ?>">
									<input type="hidden" name="active_only" value="<?= $activeOnly ? 'Y' : 'N' ?>">
									<input type="hidden" name="find" value="<?= mf_bm_escape($find) ?>">
									<input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
									<input type="hidden" name="page" value="<?= (int)$page ?>">
									<button type="submit" class="mf-bss__btn-del">Удалить</button>
								</form>
							</div>
							<form id="<?= mf_bm_escape($formId) ?>" method="post" action="<?= mf_bm_escape($curPage) ?>" style="display:none;">
								<?= bitrix_sessid_post() ?>
								<input type="hidden" name="lang" value="<?= mf_bm_escape($lang) ?>">
								<input type="hidden" name="bm_action" value="update_alias">
								<input type="hidden" name="edit_id" value="<?= $rowId ?>">
								<input type="hidden" name="active_only" value="<?= $activeOnly ? 'Y' : 'N' ?>">
								<input type="hidden" name="find" value="<?= mf_bm_escape($find) ?>">
								<input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
								<input type="hidden" name="page" value="<?= (int)$page ?>">
							</form>
						</td>
					</tr>
					<tr class="mf-bss__details-row" id="mf_bm_details_<?= $rowId ?>" hidden>
						<td colspan="7">
							<div class="mf-bss__details-kv">
								<span><strong>ID:</strong> <?= $rowId ?></span>
								<span><strong>Приоритет:</strong> <?= $sortVal ?> <span class="mf-bss__sort-readonly">(не меняется при сохранении)</span></span>
								<span><strong>Обновлено:</strong> <?= mf_bm_escape($updatedStr) ?></span>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<?php if ($aliasPages > 1): ?>
		<div class="mf-bss__pager">
			<?php
			$lo = max(1, $page - 3);
			$hi = min($aliasPages, $page + 3);
			if ($page > 1):
				$params = $baseParams;
				$params['page'] = (string)($page - 1);
				$url = htmlspecialcharsbx($APPLICATION->GetCurPageParam(http_build_query($params), $navRemove));
				?>
				<a class="mf-bss__pager-btn" href="<?= $url ?>">← Назад</a>
			<?php endif;
			for ($p = $lo; $p <= $hi; $p++):
				$params = $baseParams;
				$params['page'] = (string)$p;
				$url = htmlspecialcharsbx($APPLICATION->GetCurPageParam(http_build_query($params), $navRemove));
				$cls = $p === $page ? 'mf-bss__pager-btn mf-bss__pager-btn--cur' : 'mf-bss__pager-btn';
				?>
				<a class="<?= $cls ?>" href="<?= $url ?>"><?= (int)$p ?></a>
			<?php endfor;
			if ($page < $aliasPages):
				$params = $baseParams;
				$params['page'] = (string)($page + 1);
				$url = htmlspecialcharsbx($APPLICATION->GetCurPageParam(http_build_query($params), $navRemove));
				?>
				<a class="mf-bss__pager-btn" href="<?= $url ?>">Вперёд →</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<h2 class="mf-bss__section-title">Не импортировать</h2>
	<div class="mf-bss__table-wrap">
		<?php if ($skipRows === []): ?>
			<div class="mf-bss__empty">
				Нет активных записей<?= !function_exists('mf_brand_import_skip_ensure_table') ? ' (таблица ещё не создана)' : '' ?>.
			</div>
		<?php else: ?>
			<table class="mf-bss__table">
				<thead>
				<tr>
					<th class="mf-bss__th-num">#</th>
					<th>Бренд в файле</th>
					<th class="mf-bss__th-meta">Пометка</th>
					<th class="mf-bss__th-date">Обновлено</th>
					<th class="mf-bss__th-actions"></th>
				</tr>
				</thead>
				<tbody>
				<?php $n = 0; foreach ($skipRows as $row): $n++;
					$skipId = (int)($row['ID'] ?? 0);
					$skipFormId = 'mf_bm_skip_' . $skipId;
					$skipVal = (string)($row['UF_ALIAS_RAW'] ?? '');
					?>
					<tr>
						<td class="mf-bss__num"><?= $n ?></td>
						<td class="mf-bss__inp-cell">
							<label class="adm-invisible">Бренд в файле</label>
							<input
								type="text"
								form="<?= mf_bm_escape($skipFormId) ?>"
								name="edit_skip_alias"
								value="<?= mf_bm_escape($skipVal) ?>"
								title="<?= mf_bm_escape($skipVal) ?>"
							>
						</td>
						<td class="mf-bss__meta"><span class="mf-bss__badge mf-bss__badge--skip">пропуск</span></td>
						<td class="mf-bss__date"><?= mf_bm_escape(mf_bm_format_dt($row['UF_UPDATED_AT'] ?? null)) ?></td>
						<td class="mf-bss__actions-cell">
							<div class="mf-bss__row-actions">
								<button type="submit" form="<?= mf_bm_escape($skipFormId) ?>" class="mf-bss__btn-save">Сохранить</button>
								<form method="post" action="<?= mf_bm_escape($curPage) ?>" style="margin:0;display:inline;" onsubmit="return confirm('Удалить запись пропуска #<?= $skipId ?>?');">
									<?= bitrix_sessid_post() ?>
									<input type="hidden" name="lang" value="<?= mf_bm_escape($lang) ?>">
									<input type="hidden" name="bm_action" value="delete_skip">
									<input type="hidden" name="delete_id" value="<?= $skipId ?>">
									<input type="hidden" name="active_only" value="<?= $activeOnly ? 'Y' : 'N' ?>">
									<input type="hidden" name="find" value="<?= mf_bm_escape($find) ?>">
									<input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
									<input type="hidden" name="page" value="<?= (int)$page ?>">
									<button type="submit" class="mf-bss__btn-del">Удалить</button>
								</form>
							</div>
							<form id="<?= mf_bm_escape($skipFormId) ?>" method="post" action="<?= mf_bm_escape($curPage) ?>" style="display:none;">
								<?= bitrix_sessid_post() ?>
								<input type="hidden" name="lang" value="<?= mf_bm_escape($lang) ?>">
								<input type="hidden" name="bm_action" value="update_skip">
								<input type="hidden" name="edit_id" value="<?= $skipId ?>">
								<input type="hidden" name="active_only" value="<?= $activeOnly ? 'Y' : 'N' ?>">
								<input type="hidden" name="find" value="<?= mf_bm_escape($find) ?>">
								<input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
								<input type="hidden" name="page" value="<?= (int)$page ?>">
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<script>
	(function () {
		document.querySelectorAll('.js-mf-bm-details-toggle').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var id = btn.getAttribute('aria-controls');
				if (!id) { return; }
				var row = document.getElementById(id);
				if (!row) { return; }
				var open = row.hidden;
				row.hidden = !open;
				btn.setAttribute('aria-expanded', open ? 'true' : 'false');
				btn.textContent = open ? '▲' : '⋯';
			});
		});
	})();
	(function () {
		var tpl = document.getElementById('mf_bm_canon_options_tpl');
		if (!tpl) { return; }
		var tplHtml = tpl.innerHTML;
		function syncCanonTitle(sel) {
			var opt = sel.options[sel.selectedIndex];
			sel.title = opt ? (opt.text || opt.value || '') : '';
		}
		var skipValue = <?= json_encode(MF_BM_MAP_SKIP, JSON_UNESCAPED_UNICODE) ?>;
		document.querySelectorAll('.js-mf-bm-edit-canon').forEach(function (sel) {
			var want = sel.getAttribute('data-selected') || '';
			sel.innerHTML = tplHtml;
			if (want !== '') {
				var found = false;
				for (var i = 0; i < sel.options.length; i++) {
					if (sel.options[i].value === want) {
						sel.selectedIndex = i;
						found = true;
						break;
					}
				}
				if (!found && want !== skipValue) {
					var o = document.createElement('option');
					o.value = want;
					o.textContent = want + ' (не в каталоге)';
					o.selected = true;
					o.title = want;
					sel.insertBefore(o, sel.options[2] || null);
				}
			}
			sel.addEventListener('change', function () { syncCanonTitle(sel); });
			syncCanonTitle(sel);
		});
	})();
	</script>

	<div class="mf-bss__footer">
		Связанные разделы:
		<a href="mf_brand_stats.php?lang=<?= rawurlencode($lang) ?>">Бренды каталога</a>
		·
		<a href="mf_catalog_export.php?lang=<?= rawurlencode($lang) ?>">Выгрузка каталога</a>
	</div>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
