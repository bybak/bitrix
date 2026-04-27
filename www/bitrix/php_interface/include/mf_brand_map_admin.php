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

function mf_bm_get_prop_id(int $iblockId, string $code): int
{
	if (!class_exists(\CIBlockProperty::class)) return 0;
	$r = \CIBlockProperty::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'CODE' => $code])->Fetch();
	return (int)($r['ID'] ?? 0);
}

/**
 * Все варианты бренда для селекта: товары (инфоблок) + ТП, HL mf_brand_alias.
 * Раньше опирались только на b_iblock_element_prop_s* для single — там часто пусто или ID списка вместо текста.
 */
function mf_bm_load_catalog_brands(\Bitrix\Main\DB\Connection $conn, int $iblockId = 4): array
{
	$out = [];
	$add = static function (string $b) use (&$out): void {
		$b = trim($b);
		if ($b !== '')
		{
			$out[$b] = true;
		}
	};

	$loadForIblock = static function (int $ibId) use ($conn, $add): void {
		$ibId = (int)$ibId;
		if ($ibId <= 0)
		{
			return;
		}
		$propId = mf_bm_get_prop_id($ibId, 'MF_BRAND');
		if ($propId <= 0)
		{
			return;
		}
		$p = \CIBlockProperty::GetByID($propId, $ibId)->Fetch();
		if (!is_array($p))
		{
			return;
		}
		$multiple = (string)($p['MULTIPLE'] ?? 'N') === 'Y';
		$propType = (string)($p['PROPERTY_TYPE'] ?? 'S');
		$h = $conn->getSqlHelper();
		$propId = (int)$propId;

		// Список (L): в VALUE — ID варианта; подписи — в b_iblock_property_enum.
		if ($propType === 'L')
		{
			try
			{
				$rs = $conn->query("
					SELECT DISTINCT TRIM(en.VALUE) AS BRAND
					FROM b_iblock_element_property ep
					INNER JOIN b_iblock_element e ON e.ID = ep.IBLOCK_ELEMENT_ID
					INNER JOIN b_iblock_property_enum en
						ON en.PROPERTY_ID = {$propId} AND en.ID = ep.VALUE
					WHERE e.IBLOCK_ID = {$ibId}
					  AND e.ACTIVE = 'Y'
					  AND ep.IBLOCK_PROPERTY_ID = {$propId}
					LIMIT 8000
				");
				while ($r = $rs->fetch())
				{
					$add((string)($r['BRAND'] ?? ''));
				}
			}
			catch (\Throwable $e)
			{
				// ignore
			}

			return;
		}

		// Строка и прочие: b_iblock_element_property (и для single тоже — в новых схемах так часто).
		try
		{
			$rs = $conn->query("
				SELECT DISTINCT TRIM(ep.VALUE) AS BRAND
				FROM b_iblock_element_property ep
				INNER JOIN b_iblock_element e ON e.ID = ep.IBLOCK_ELEMENT_ID
				WHERE e.IBLOCK_ID = {$ibId}
				  AND e.ACTIVE = 'Y'
				  AND ep.IBLOCK_PROPERTY_ID = {$propId}
				  AND ep.VALUE IS NOT NULL AND TRIM(ep.VALUE) <> ''
				LIMIT 8000
			");
			while ($r = $rs->fetch())
			{
				$add((string)($r['BRAND'] ?? ''));
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}

		// Старое хранение single-строки в prop_s* (доп. источник).
		if (!$multiple && $propType === 'S')
		{
			$col = 'PROPERTY_' . $propId;
			$tbl = 'b_iblock_element_prop_s' . $ibId;
			try
			{
				$r0 = $conn->query("SHOW TABLES LIKE '" . $h->forSql($tbl) . "'")->fetch();
				if ($r0)
				{
					$rs = $conn->query("
						SELECT DISTINCT p.`{$col}` AS BRAND
						FROM `{$tbl}` p
						INNER JOIN b_iblock_element e ON e.ID = p.IBLOCK_ELEMENT_ID
						WHERE e.IBLOCK_ID = {$ibId}
						  AND e.ACTIVE = 'Y'
						  AND p.`{$col}` IS NOT NULL AND p.`{$col}` <> ''
						LIMIT 8000
					");
					while ($r = $rs->fetch())
					{
						$add((string)($r['BRAND'] ?? ''));
					}
				}
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}
	};

	try
	{
		$loadForIblock($iblockId);
	}
	catch (\Throwable $e)
	{
		// ignore
	}

	// Бренд может быть только на карточках ТП, а не на родителе.
	if (class_exists(\CCatalogSKU::class) && Loader::includeModule('catalog'))
	{
		$sku = \CCatalogSKU::GetInfoByProductIBlock($iblockId);
		if (is_array($sku) && !empty($sku['IBLOCK_ID']))
		{
			$off = (int)$sku['IBLOCK_ID'];
			if ($off > 0 && $off !== (int)$iblockId)
			{
				try
				{
					$loadForIblock($off);
				}
				catch (\Throwable $e)
				{
					// ignore
				}
			}
		}
	}

	// Уже введённые канонические бренды в справочнике алиасов — тоже в список, чтобы не терять значения.
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
				$add((string)($r['BRAND'] ?? ''));
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
	sort($keys, SORT_NATURAL | SORT_FLAG_CASE);

	return $keys;
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

// Сколько брендов на «странице» (только в браузере, без повторных запросов к серверу)
$bmPerPage = 50;

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

$catalogBrands = mf_bm_load_catalog_brands($conn, 4);

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

?>
<div style="max-width: 1200px;">
	<?php if (is_array($adminNotice)): ?>
		<?php \CAdminMessage::ShowMessage($adminNotice); ?>
	<?php endif; ?>

	<div style="margin: 8px 0 12px 0; color:#666;">
		Слева бренды из <code>mf_stock_import_missing</code>. Выбери канонический бренд из каталога — сохраним как alias→canonical в HL <code>mf_brand_alias</code>.
		Вариант «Не сопоставлять» помечает бренд в таблице <code>mf_brand_import_skip</code>: такие строки не обрабатываются при импорте остатков и при загрузке внешних прайсов (остатки и цены по ним не меняются).
		<br/>Список в селекте: уникальные значения <code>MF_BRAND</code> по активным элементам инфоблока каталога и инфоблока торговых предложений (если есть), плюс каноны из <code>mf_brand_alias</code>. Раньше учитывалась только таблица <code>b_iblock_element_prop_s*</code> для single-свойства — из‑за этого список мог быть пустым или неполным.
	</div>

	<form method="get" action="<?= mf_bm_escape($APPLICATION->GetCurPage()) ?>">
		<input type="hidden" name="lang" value="<?= mf_bm_escape((string)LANGUAGE_ID) ?>">
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
		data-bm-n="<?= (int)$bmN ?>">
		<?php if ($bmN > 0): ?>
			<div class="js-bm-range" style="margin:10px 0 6px 0; color:#555;">
				Показаны бренды <b class="js-bm-from"><?= (int)$bmShowFrom0 ?></b>–<b class="js-bm-to"><?= (int)$bmShowTo0 ?></b> из <b class="js-bm-total"><?= (int)$bmN ?></b>
				(по <?= (int)$bmPerPage ?> на странице<span class="js-bm-pagenum-wrap"<?= $bmTotalPages < 2 ? ' style="display:none;"' : '' ?>>, стр. <span class="js-bm-curpage"><?= (int)$bmClientPage ?></span> из <span class="js-bm-ntp"><?= (int)$bmTotalPages ?></span></span>).
				Перелистывание без запроса к серверу.
			</div>
			<div class="js-bm-nav bm-pager-ui" style="margin:0 0 12px 0;contain:layout;isolation:isolate;transform:translateZ(0);-webkit-backface-visibility:hidden;backface-visibility:hidden;user-select:none;touch-action:manipulation;"></div>
		<?php endif; ?>

		<form method="post" action="<?= mf_bm_escape($APPLICATION->GetCurPageParam('', ['sessid'])) ?>" style="margin-top:0;">
		<?= bitrix_sessid_post() ?>
		<input type="hidden" name="lang" value="<?= mf_bm_escape((string)LANGUAGE_ID) ?>">
		<input type="hidden" name="find_warehouse" value="<?= mf_bm_escape($find_warehouse) ?>">

		<table class="adm-list-table" style="width:100%;margin-top:10px;">
			<thead>
			<tr class="adm-list-table-header">
				<td class="adm-list-table-cell">Бренд (в ненайденных)</td>
				<td class="adm-list-table-cell">Кол-во</td>
				<td class="adm-list-table-cell">Последнее</td>
				<td class="adm-list-table-cell">Сейчас сопоставлен</td>
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

	var rows = Array.prototype.slice.call(root.querySelectorAll("tr.js-bm-row"));
	if (rows.length !== n) { return; }

	var navPrevS = (cur - 1) * per, navPrevE = cur * per;

	function setUrlPage(p) {
		var q = { lang: lang, find_warehouse: wh };
		if (p > 1) { q.bm_page = p; }
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

