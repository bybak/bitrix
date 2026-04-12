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

function mf_bm_load_catalog_brands(\Bitrix\Main\DB\Connection $conn, int $iblockId = 4): array
{
	$propId = mf_bm_get_prop_id($iblockId, 'MF_BRAND');
	if ($propId <= 0)
	{
		return [];
	}

	// Determine storage for property value.
	$p = \CIBlockProperty::GetByID($propId, $iblockId)->Fetch();
	$multiple = (string)($p['MULTIPLE'] ?? 'N') === 'Y';

	$out = [];
	try
	{
		$h = $conn->getSqlHelper();
		if (!$multiple)
		{
			$col = 'PROPERTY_' . $propId;
			$tbl = 'b_iblock_element_prop_s' . (int)$iblockId;
			// Table may not exist in some Bitrix configs.
			$r0 = $conn->query("SHOW TABLES LIKE '" . $h->forSql($tbl) . "'")->fetch();
			if ($r0)
			{
				$rs = $conn->query("
					SELECT DISTINCT p.`{$col}` AS BRAND
					FROM `{$tbl}` p
					INNER JOIN b_iblock_element e ON e.ID=p.IBLOCK_ELEMENT_ID
					WHERE e.IBLOCK_ID=" . (int)$iblockId . "
					  AND e.ACTIVE='Y'
					  AND p.`{$col}` IS NOT NULL AND p.`{$col}` <> ''
					ORDER BY BRAND ASC
					LIMIT 5000
				");
				while ($r = $rs->fetch())
				{
					$b = trim((string)($r['BRAND'] ?? ''));
					if ($b !== '') $out[$b] = true;
				}
			}
		}
		else
		{
			$rs = $conn->query("
				SELECT DISTINCT ep.VALUE AS BRAND
				FROM b_iblock_element_property ep
				INNER JOIN b_iblock_element e ON e.ID=ep.IBLOCK_ELEMENT_ID
				WHERE e.IBLOCK_ID=" . (int)$iblockId . "
				  AND e.ACTIVE='Y'
				  AND ep.IBLOCK_PROPERTY_ID=" . (int)$propId . "
				  AND ep.VALUE IS NOT NULL AND ep.VALUE <> ''
				ORDER BY BRAND ASC
				LIMIT 5000
			");
			while ($r = $rs->fetch())
			{
				$b = trim((string)($r['BRAND'] ?? ''));
				if ($b !== '') $out[$b] = true;
			}
		}
	}
	catch (\Throwable $e)
	{
		return [];
	}

	return array_values(array_keys($out));
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
		if ($alias === '' || $canonical === '')
		{
			$skipped++;
			continue;
		}
		mf_brand_register_alias($hl, $canonical, $alias, true, 100);
		$saved++;
	}
	$adminNotice = ['TYPE' => 'OK', 'MESSAGE' => 'Сохранено сопоставлений: ' . $saved . ', пропущено: ' . $skipped];
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
		ORDER BY CNT DESC, BRAND ASC
		LIMIT 2000
	");
	while ($r = $rs->fetch())
	{
		$b = trim((string)($r['BRAND'] ?? ''));
		if ($b === '') continue;
		$missing[] = [
			'BRAND' => $b,
			'CNT' => (int)($r['CNT'] ?? 0),
			'LAST_SEEN' => (string)($r['LAST_SEEN'] ?? ''),
			'CANON' => mf_bm_get_alias_exact($b),
		];
	}
}
catch (\Throwable $e)
{
	$missing = [];
}

$catalogBrands = mf_bm_load_catalog_brands($conn, 4);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$APPLICATION->SetTitle('Сопоставление брендов (импорт складов)');

?>
<div style="max-width: 1200px;">
	<?php if (is_array($adminNotice)): ?>
		<?php \CAdminMessage::ShowMessage($adminNotice); ?>
	<?php endif; ?>

	<div style="margin: 8px 0 12px 0; color:#666;">
		Слева бренды из <code>mf_stock_import_missing</code>. Выбери канонический бренд из каталога — сохраним как alias→canonical в HL <code>mf_brand_alias</code>.
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

	<form method="post" action="<?= mf_bm_escape($APPLICATION->GetCurPageParam('', ['sessid'])) ?>" style="margin-top:12px;">
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
				<?php foreach ($missing as $m): ?>
					<?php
					$alias = (string)$m['BRAND'];
					$canon = (string)$m['CANON'];
					?>
					<tr class="adm-list-table-row">
						<td class="adm-list-table-cell"><b><?= mf_bm_escape($alias) ?></b></td>
						<td class="adm-list-table-cell"><?= (int)$m['CNT'] ?></td>
						<td class="adm-list-table-cell"><?= mf_bm_escape((string)$m['LAST_SEEN']) ?></td>
						<td class="adm-list-table-cell"><?= ($canon !== '' ? mf_bm_escape($canon) : '—') ?></td>
						<td class="adm-list-table-cell">
							<select name="map[<?= mf_bm_escape($alias) ?>]" style="min-width:420px;">
								<option value="">— не менять —</option>
								<?php if ($canon !== '' && !in_array($canon, $catalogBrands, true)): ?>
									<option value="<?= mf_bm_escape($canon) ?>"><?= mf_bm_escape($canon) ?> (текущий)</option>
								<?php endif; ?>
								<?php foreach ($catalogBrands as $b): ?>
									<option value="<?= mf_bm_escape((string)$b) ?>" <?= ($canon !== '' && $canon === (string)$b ? 'selected' : '') ?>>
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

		<div style="margin-top:12px;">
			<input type="submit" class="adm-btn-save" value="Сохранить сопоставления">
		</div>
	</form>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

