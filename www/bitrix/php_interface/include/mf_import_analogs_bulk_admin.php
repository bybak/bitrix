<?php

declare(strict_types=1);

use Bitrix\Main\Loader;

if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
{
	die('Admin only');
}

global $APPLICATION, $USER;

if (!Loader::includeModule('iblock'))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Модуль iblock не подключён.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

$analogsLib = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_analogs.php';
if (!is_file($analogsLib))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не найден файл: ' . $analogsLib]);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}
require_once $analogsLib;

// Shared helpers (normalize/find/create by brand+article).
$lib = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_import_analogs_lib.php';
if (!is_file($lib))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не найден файл: ' . $lib]);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}
require_once $lib;

function mf_analogs_escape(string $s): string
{
	return function_exists('htmlspecialcharsbx')
		? (string)htmlspecialcharsbx($s)
		: htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Parses list of originals from a cell.
 * Format: "Brand:Article, Brand2:Article2" (separated by comma).
 *
 * @return array<int, array{brand:string,article:string,raw:string}>
 */
function mf_parse_originals_cell(string $s): array
{
	$s = trim((string)$s);
	if ($s === '')
	{
		return [];
	}
	$parts = preg_split('~\\s*,\\s*~u', $s) ?: [];
	$out = [];
	foreach ($parts as $p)
	{
		$p = trim((string)$p);
		if ($p === '')
		{
			continue;
		}
		if (str_contains($p, ':'))
		{
			[$b, $a] = explode(':', $p, 2);
		}
		elseif (str_contains($p, '|'))
		{
			[$b, $a] = explode('|', $p, 2);
		}
		else
		{
			$out[] = ['brand' => '', 'article' => '', 'raw' => $p];
			continue;
		}
		$b = trim((string)$b);
		$a = trim((string)$a);
		$out[] = ['brand' => $b, 'article' => $a, 'raw' => $p];
	}
	return $out;
}

/**
 * Bulk analog import CSV schema:
 * Column order is not important (we map by header names).
 *
 *   Option A (multi originals in one cell):
 *     Бренд;Артикул;Остаток;Цена;Картинки;Оригиналы
 *     where Оригиналы = "Brand:Article, Brand2:Article2"
 *
 *   Option B (single brand, multiple articles):
 *     Бренд;Артикул;Остаток;Цена;Картинки;ОригиналБренд;ОригиналАртикул
 *     where ОригиналАртикул may be "A1, A2, A3"
 *
 * One analog row can relate to multiple originals.
 */
function mf_parse_bulk_analogs_csv(string $text): array
{
	$text = mf_analogs_to_utf8((string)$text);
	$lines = preg_split('~\\R+~u', $text) ?: [];

	$header = null;
	$idx = [];
	$rows = [];

	foreach ($lines as $line)
	{
		$line = trim((string)$line);
		if ($line === '' || str_starts_with($line, '#'))
		{
			continue;
		}

		$cols = str_getcsv($line, ';', '"');
		if (!is_array($cols) || empty($cols))
		{
			continue;
		}

		if ($header === null)
		{
			$header = $cols;
			foreach ($header as $i => $name)
			{
				$name = trim((string)$name);
				if ($name !== '')
				{
					$idx[$name] = (int)$i;
				}
			}
			continue;
		}

		$orig = [];
		if (isset($idx['Оригиналы']))
		{
			$origCell = trim((string)($cols[$idx['Оригиналы']] ?? ''));
			$orig = mf_parse_originals_cell($origCell);
		}
		elseif (isset($idx['ОригиналБренд']) && isset($idx['ОригиналАртикул']))
		{
			$ob = trim((string)($cols[$idx['ОригиналБренд']] ?? ''));
			$oaCell = trim((string)($cols[$idx['ОригиналАртикул']] ?? ''));
			if ($ob !== '' && $oaCell !== '')
			{
				$arts = preg_split('~\\s*,\\s*~u', $oaCell) ?: [];
				foreach ($arts as $a)
				{
					$a = trim((string)$a);
					if ($a === '') continue;
					$orig[] = ['brand' => $ob, 'article' => $a, 'raw' => $ob . ':' . $a];
				}
			}
		}

		$brand = trim((string)($cols[$idx['Бренд'] ?? -1] ?? ''));
		$article = trim((string)($cols[$idx['Артикул'] ?? -1] ?? ''));
		if ($brand === '' || $article === '' || empty($orig))
		{
			continue;
		}

		$stock = null;
		$price = null;

		$stockRaw = trim((string)($cols[$idx['Остаток'] ?? -1] ?? ''));
		if ($stockRaw !== '' && is_numeric(str_replace(',', '.', $stockRaw)))
		{
			$stock = (float)str_replace(',', '.', $stockRaw);
		}

		$priceRaw = trim((string)($cols[$idx['Цена'] ?? -1] ?? ''));
		if ($priceRaw !== '' && is_numeric(str_replace(',', '.', $priceRaw)))
		{
			$price = (float)str_replace(',', '.', $priceRaw);
		}

		$images = [];
		$imgRaw = trim((string)($cols[$idx['Картинки'] ?? -1] ?? ''));
		if ($imgRaw !== '')
		{
			$parts = preg_split('~\\s*,\\s*~', $imgRaw) ?: [];
			foreach ($parts as $p)
			{
				$p = trim((string)$p);
				if ($p !== '') $images[] = $p;
			}
		}

		$rows[] = [
			'originals' => $orig,
			'analog_brand' => $brand,
			'analog_article' => $article,
			'stock' => $stock,
			'price' => $price,
			'images' => $images,
			'raw' => $line,
		];
	}

	return ['rows' => $rows, 'idx' => $idx];
}

$errors = [];
$report = null;

$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($requestMethod === 'POST' && check_bitrix_sessid())
{
	$iblockId = (int)($_POST['IBLOCK_ID'] ?? 4);
	$mode = (string)($_POST['MODE'] ?? 'merge'); // merge|replace not supported in bulk; kept for future
	$createMissingAnalogs = ((string)($_POST['CREATE_MISSING_ANALOGS'] ?? 'Y') === 'Y');
	$createMissingOriginals = ((string)($_POST['CREATE_MISSING_ORIGINALS'] ?? 'N') === 'Y');

	$text = '';
	if (!empty($_FILES['FILE']['tmp_name']) && is_uploaded_file($_FILES['FILE']['tmp_name']))
	{
		$text = (string)file_get_contents((string)$_FILES['FILE']['tmp_name']);
	}
	else
	{
		$text = (string)($_POST['TEXT'] ?? '');
	}

	$parsed = mf_parse_bulk_analogs_csv($text);
	$rows = (array)($parsed['rows'] ?? []);
	if (empty($rows))
	{
			$errors[] = 'Файл пуст или не распознан. Ожидаемые колонки (в любом порядке): Бренд; Артикул; и либо Оригиналы, либо ОригиналБренд+ОригиналАртикул. Разделитель: ;';
	}

	if (empty($errors))
	{
		$source = 'admin:' . (int)($USER && is_object($USER) ? $USER->GetID() : 0);
		$createdAnalogs = 0;
		$createdOriginals = 0;
		$linked = 0;
		$notFound = [];

		foreach ($rows as $row)
		{
			$analogId = mf_analogs_find_product_id_by_brand_article($iblockId, (string)$row['analog_brand'], (string)$row['analog_article']);
			if ((!$analogId || $analogId <= 0) && $createMissingAnalogs)
			{
				$analogId = mf_analogs_create_product_stub($iblockId, (string)$row['analog_brand'], (string)$row['analog_article'], (array)($row['images'] ?? []));
				if ($analogId && $analogId > 0)
				{
					$createdAnalogs++;
				}
			}

			if (!$analogId || $analogId <= 0)
			{
				$notFound[] = 'АНАЛОГ: ' . (string)$row['analog_brand'] . ';' . (string)$row['analog_article'];
				continue;
			}

			// Ensure analog element has external images for its own detail page.
			$imgs = array_values(array_filter(array_map('trim', (array)($row['images'] ?? [])), static fn($s) => $s !== ''));
			if (!empty($imgs))
			{
				$has = false;
				$rsP = \CIBlockElement::GetProperty($iblockId, (int)$analogId, ['sort' => 'asc'], ['CODE' => 'MF_EXT_IMAGES']);
				while ($pp = $rsP->Fetch())
				{
					$v = trim((string)($pp['VALUE'] ?? ''));
					if ($v !== '') { $has = true; break; }
				}
				if (!$has)
				{
					\CIBlockElement::SetPropertyValuesEx((int)$analogId, $iblockId, ['MF_EXT_IMAGES' => $imgs]);
				}
			}

			foreach ((array)$row['originals'] as $o)
			{
				$ob = trim((string)($o['brand'] ?? ''));
				$oa = trim((string)($o['article'] ?? ''));
				if ($ob === '' || $oa === '')
				{
					$notFound[] = 'ОРИГИНАЛ: ' . (string)($o['raw'] ?? '');
					continue;
				}

				$origId = mf_analogs_find_product_id_by_brand_article($iblockId, $ob, $oa);
				if ((!$origId || $origId <= 0) && $createMissingOriginals)
				{
					$origId = mf_analogs_create_product_stub($iblockId, $ob, $oa, []);
					if ($origId && $origId > 0)
					{
						$createdOriginals++;
					}
				}
				if (!$origId || $origId <= 0)
				{
					$notFound[] = 'ОРИГИНАЛ: ' . $ob . ';' . $oa;
					continue;
				}

				// Link and store meta (directed original->analog).
				mf_analogs_merge_for_product((int)$origId, [(int)$analogId], $source);
				$linked++;

				if (function_exists('mf_analogs_meta_upsert'))
				{
					mf_analogs_meta_upsert(
						(int)$origId,
						(int)$analogId,
						$row['stock'] === null ? null : (float)$row['stock'],
						$row['price'] === null ? null : (float)$row['price'],
						$imgs,
						$source,
						'RUB'
					);
				}
			}
		}

		$report = [
			'rows' => count($rows),
			'linked' => $linked,
			'created_analogs' => $createdAnalogs,
			'created_originals' => $createdOriginals,
			'not_found' => $notFound,
		];
	}
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$APPLICATION->SetTitle('Импорт аналогов (общий)');

if (!empty($errors))
{
	\CAdminMessage::ShowMessage([
		'TYPE' => 'ERROR',
		'MESSAGE' => implode('<br>', array_map('mf_analogs_escape', $errors)),
		'HTML' => true,
	]);
}

if (is_array($report))
{
	$msg = 'Готово. Строк: ' . (int)$report['rows']
		. ', связей добавлено: ' . (int)$report['linked']
		. ', создано аналогов: ' . (int)$report['created_analogs']
		. ', создано оригиналов: ' . (int)$report['created_originals'] . '.';
	if (!empty($report['not_found']))
	{
		$msg .= '<br>Не найдены/не распознаны:<br><pre style="white-space:pre-wrap;max-height:240px;overflow:auto;">'
			. mf_analogs_escape(implode("\n", $report['not_found']))
			. '</pre>';
	}
	\CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => $msg, 'HTML' => true]);
}

?>

<div style="max-width: 980px;">
	<div style="margin: 8px 0 12px 0; color:#666;">
		Формат CSV (разделитель <code>;</code>). <b>Порядок колонок не важен</b>, распознаём по заголовкам. Варианты:
		<pre style="white-space:pre-wrap;max-height:160px;overflow:auto;">Бренд;Артикул;Остаток;Цена;Картинки;Оригиналы
Yamaha;123;5;1290;https://a.jpg,https://b.png;Yamaha:1HP-F582T-00-00, Yamaha:BB5-F514A-00-00</pre>
		Оригиналы в ячейке: <code>Бренд:Артикул</code> (или <code>Бренд|Артикул</code>), несколько через запятую.
		<br><br>
		Или (если у оригиналов один бренд, но много артикулов):
		<pre style="white-space:pre-wrap;max-height:160px;overflow:auto;">Бренд;Артикул;Остаток;Цена;Картинки;ОригиналБренд;ОригиналАртикул
Yamaha;123;5;1290;https://a.jpg;Yamaha;1HP-F582T-00-00, BB5-F514A-00-00</pre>
	</div>

	<form method="post" enctype="multipart/form-data">
		<?= bitrix_sessid_post() ?>

		<table class="adm-detail-content-table edit-table" style="width:100%;">
			<tbody>
			<tr>
				<td class="adm-detail-content-cell-l" width="40%">Инфоблок каталога</td>
				<td class="adm-detail-content-cell-r">
					<input type="number" name="IBLOCK_ID" value="4" min="1" step="1" style="width:120px;">
				</td>
			</tr>
			<tr>
				<td class="adm-detail-content-cell-l">Файл</td>
				<td class="adm-detail-content-cell-r">
					<input type="file" name="FILE" accept=".csv,.txt,text/plain">
				</td>
			</tr>
			<tr>
				<td class="adm-detail-content-cell-l">Или вставить CSV</td>
				<td class="adm-detail-content-cell-r">
					<textarea name="TEXT" rows="10" style="width:100%;max-width:740px;"></textarea>
				</td>
			</tr>
			<tr>
				<td class="adm-detail-content-cell-l">Если аналог не найден</td>
				<td class="adm-detail-content-cell-r">
					<label><input type="checkbox" name="CREATE_MISSING_ANALOGS" value="Y" checked> Создавать аналог как новый товар (скрыт из каталога)</label>
				</td>
			</tr>
			<tr>
				<td class="adm-detail-content-cell-l">Если оригинал не найден</td>
				<td class="adm-detail-content-cell-r">
					<label><input type="checkbox" name="CREATE_MISSING_ORIGINALS" value="Y"> Создавать оригинал как новый товар</label>
				</td>
			</tr>
			</tbody>
		</table>

		<div style="margin-top:12px;">
			<input type="submit" class="adm-btn-save" value="Импортировать">
		</div>
	</form>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

