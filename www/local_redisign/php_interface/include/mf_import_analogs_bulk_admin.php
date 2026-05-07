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

$analogsLib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_analogs.php';
if (!is_file($analogsLib))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не найден файл: ' . $analogsLib]);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}
require_once $analogsLib;

// Shared helpers (normalize/find/create by brand+article).
$lib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_import_analogs_lib.php';
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
 *     Бренд;Артикул;Оригиналы
 *     where Оригиналы = "Brand:Article, Brand2:Article2"
 *
 *   Option B (single brand, multiple articles):
 *     Бренд;Артикул;ОригиналБренд;ОригиналАртикул
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

		$rows[] = [
			'originals' => $orig,
			'analog_brand' => $brand,
			'analog_article' => $article,
			'raw' => $line,
		];
	}

	return ['rows' => $rows, 'idx' => $idx];
}

$errors = [];
$report = null;

/** Пока только каталог с этим ID; поле в форме отключено. */
$catalogIblockIdForBulkAnalogs = 4;

$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($requestMethod === 'POST' && check_bitrix_sessid())
{
	$iblockId = $catalogIblockIdForBulkAnalogs;

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
		$linked = 0;
		$notFound = [];

		foreach ($rows as $row)
		{
			$analogId = mf_analogs_find_product_id_by_brand_article($iblockId, (string)$row['analog_brand'], (string)$row['analog_article']);

			if (!$analogId || $analogId <= 0)
			{
				$notFound[] = 'АНАЛОГ: ' . (string)$row['analog_brand'] . ';' . (string)$row['analog_article'];
				continue;
			}

			$origIds = [];
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
				if (!$origId || $origId <= 0)
				{
					$notFound[] = 'ОРИГИНАЛ: ' . $ob . ';' . $oa;
					continue;
				}

				$origIds[] = (int)$origId;
			}

			$origIds = array_values(array_unique(array_filter($origIds, static fn($x) => (int)$x > 0)));

			foreach ($origIds as $oid)
			{
				mf_analogs_merge_for_product($oid, [(int)$analogId], $source);
				$linked++;
			}

			// Попарные связи только между оригиналами этой строки CSV (не между строками файла).
			$nOrig = count($origIds);
			if ($nOrig >= 2)
			{
				for ($i = 0; $i < $nOrig; $i++)
				{
					for ($j = $i + 1; $j < $nOrig; $j++)
					{
						mf_analogs_merge_for_product((int)$origIds[$i], [(int)$origIds[$j]], $source);
						$linked++;
					}
				}
			}
		}

		$report = [
			'rows' => count($rows),
			'linked' => $linked,
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
		. ', связей добавлено: ' . (int)$report['linked'] . '.';
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
		Связываются только уже существующие в каталоге товары (по бренду и артикулу); новые карточки здесь не создаются.
		<br>Если <b>в одной строке файла</b> указано несколько оригиналов, они дополнительно связываются <b>между собой</b> как аналоги (попарно). Другие строки файла на это не влияют.
		<br><br>
		Формат CSV (разделитель <code>;</code>): заголовки <code>Бренд</code>, <code>Артикул</code>, <code>Оригиналы</code> — порядок колонок может быть любым, строка распознаётся по именам колонок.
		<pre style="white-space:pre-wrap;max-height:160px;overflow:auto;">Бренд;Артикул;Оригиналы
Yamaha;123;Yamaha:1HP-F582T-00-00, Yamaha:BB5-F514A-00-00</pre>
		В колонке <b>Оригиналы</b> — через запятую пары <code>Бренд:Артикул</code> (допустимо <code>Бренд|Артикул</code>).
		<p style="margin:14px 0 10px 0;">
			<strong>Шаблон:</strong>
			<a href="mf_import_analogs_bulk_template.csv" download="import_analogov_shablon.csv">скачать CSV</a>
			<span style="color:#666;"> — колонки <code>Бренд</code>, <code>Артикул</code>, <code>Оригиналы</code> (UTF‑8); в файле комментарии с <code>#</code>.</span>
		</p>
	</div>

	<form method="post" enctype="multipart/form-data">
		<?= bitrix_sessid_post() ?>

		<table class="adm-detail-content-table edit-table" style="width:100%;">
			<tbody>
			<tr>
				<td class="adm-detail-content-cell-l" width="40%">Инфоблок каталога</td>
				<td class="adm-detail-content-cell-r">
					<input type="number" value="<?= (int)$catalogIblockIdForBulkAnalogs ?>" min="1" step="1" style="width:120px;" disabled title="Пока зафиксировано в коде">
					<span style="margin-left:8px;color:#888;font-size:12px;">сейчас не меняется</span>
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
			</tbody>
		</table>

		<div style="margin-top:12px;">
			<input type="submit" class="adm-btn-save" value="Импортировать">
		</div>
	</form>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

