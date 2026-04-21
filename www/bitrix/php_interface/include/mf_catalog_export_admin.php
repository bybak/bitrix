<?php

declare(strict_types=1);

/**
 * Админка: выгрузка каталога в CSV / XLSX с выбором полей.
 * Обязательные колонки: бренд, артикул, наименование.
 */

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
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Модуль iblock не подключён.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

$iblockId = 4;

require_once __DIR__ . '/mf_catalog_export_xlsx_cell.php';

function mf_ce_esc(string $s): string
{
	return function_exists('htmlspecialcharsbx')
		? (string)htmlspecialcharsbx($s)
		: htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mf_ce_esc_xml(string $s): string
{
	return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 1-based column index → Excel letters (A, B, …, Z, AA, …). */
function mf_ce_col_letter(int $n): string
{
	$s = '';
	while ($n >= 1)
	{
		$n--;
		$s = chr(65 + ($n % 26)) . $s;
		$n = intdiv($n, 26);
	}

	return $s;
}

/**
 * Дополнительные колонки (кроме бренд / артикул / наименование).
 *
 * @return array<string, array{label: string, group: string}>
 */
function mf_ce_optional_field_map(): array
{
	return [
		'elem_ID' => ['label' => 'ID элемента', 'group' => 'Дополнительно'],
		'elem_DATE_CREATE' => ['label' => 'Дата создания', 'group' => 'Дополнительно'],
		'elem_TIMESTAMP_X' => ['label' => 'Дата изменения', 'group' => 'Дополнительно'],
		'elem_XML_ID' => ['label' => 'Внешний код (XML_ID)', 'group' => 'Дополнительно'],
		'elem_DETAIL_TEXT' => ['label' => 'Детальное описание', 'group' => 'Дополнительно'],
	];
}

/**
 * @param array<string, true> $optKeys
 * @return list<string>
 */
function mf_ce_build_select(array $optKeys): array
{
	$sel = [
		'NAME',
		'PROPERTY_MF_BRAND',
		'PROPERTY_MF_BRAND_NORM',
		'PROPERTY_CML2_ARTICLE',
		'PROPERTY_MF_ARTICLE_NORM',
	];

	$elemToField = [
		'elem_ID' => 'ID',
		'elem_DATE_CREATE' => 'DATE_CREATE',
		'elem_TIMESTAMP_X' => 'TIMESTAMP_X',
		'elem_XML_ID' => 'XML_ID',
		'elem_DETAIL_TEXT' => 'DETAIL_TEXT',
	];

	foreach ($elemToField as $key => $field)
	{
		if (isset($optKeys[$key]))
		{
			$sel[] = $field;
		}
	}

	return array_values(array_unique($sel));
}

/**
 * @param array<string, mixed> $el
 * @param array<string, true> $optKeys
 */
function mf_ce_row_values(array $el, array $optKeys): array
{
	$brand = trim((string)($el['PROPERTY_MF_BRAND_VALUE'] ?? ''));
	if ($brand === '')
	{
		$brand = trim((string)($el['PROPERTY_MF_BRAND_NORM_VALUE'] ?? ''));
	}
	$article = trim((string)($el['PROPERTY_CML2_ARTICLE_VALUE'] ?? ''));
	if ($article === '')
	{
		$article = trim((string)($el['PROPERTY_MF_ARTICLE_NORM_VALUE'] ?? ''));
	}
	$name = trim((string)($el['NAME'] ?? ''));

	$row = [$brand, $article, $name];

	$order = ['elem_ID', 'elem_DATE_CREATE', 'elem_TIMESTAMP_X', 'elem_XML_ID', 'elem_DETAIL_TEXT'];
	foreach ($order as $k)
	{
		if (!isset($optKeys[$k]))
		{
			continue;
		}
		$f = str_replace('elem_', '', $k);
		$row[] = isset($el[$f]) ? (string)$el[$f] : '';
	}

	return $row;
}

/**
 * @param list<string> $headers
 * @param iterable<int, list<string>> $rows
 */
function mf_ce_output_xlsx(string $filename, array $headers, iterable $rows): void
{
	if (!class_exists(ZipArchive::class))
	{
		throw new RuntimeException('Нет расширения ZipArchive — XLSX недоступен.');
	}

	$sstBodyPath = tempnam(sys_get_temp_dir(), 'mfcesst');
	$sheetBodyPath = tempnam(sys_get_temp_dir(), 'mfcesh');
	if ($sstBodyPath === false || $sheetBodyPath === false)
	{
		if ($sstBodyPath !== false)
		{
			@unlink($sstBodyPath);
		}
		if ($sheetBodyPath !== false)
		{
			@unlink($sheetBodyPath);
		}
		throw new RuntimeException('Не удалось создать временный файл.');
	}

	$sstH = fopen($sstBodyPath, 'wb');
	$bh = fopen($sheetBodyPath, 'wb');
	if ($sstH === false || $bh === false)
	{
		if ($sstH !== false)
		{
			fclose($sstH);
		}
		if ($bh !== false)
		{
			fclose($bh);
		}
		@unlink($sstBodyPath);
		@unlink($sheetBodyPath);
		throw new RuntimeException('Не удалось открыть временный файл.');
	}

	$rn = 1;
	$maxCol = 0;
	$siCount = 0;

	$writeRow = static function (array $cells) use ($sstH, $bh, &$rn, &$maxCol, &$siCount): void {
		$n = count($cells);
		if ($n > $maxCol)
		{
			$maxCol = $n;
		}
		fwrite($bh, '<row r="' . $rn . '">');
		$col = 1;
		foreach ($cells as $cell)
		{
			$ref = mf_ce_col_letter($col) . $rn;
			fwrite($bh, '<c r="' . mf_ce_esc_xml($ref) . '" t="s"><v>' . $siCount . '</v></c>');
			fwrite($sstH, '<si><t xml:space="preserve">' . mf_ce_xlsx_cell_xml((string)$cell) . '</t></si>');
			$siCount++;
			$col++;
		}
		fwrite($bh, '</row>');
		$rn++;
	};

	$writeRow($headers);
	foreach ($rows as $r)
	{
		$writeRow($r);
	}

	fclose($sstH);
	fclose($bh);

	$lastRow = max(1, $rn - 1);
	$maxCol = max(1, $maxCol);
	$dimTo = mf_ce_col_letter($maxCol) . $lastRow;
	$dimRef = 'A1:' . $dimTo;

	$sstFullPath = tempnam(sys_get_temp_dir(), 'mfcesstf');
	$sheet = tempnam(sys_get_temp_dir(), 'mfce');
	if ($sstFullPath === false || $sheet === false)
	{
		@unlink($sstBodyPath);
		@unlink($sheetBodyPath);
		throw new RuntimeException('Не удалось создать временный файл.');
	}

	$sstOut = fopen($sstFullPath, 'wb');
	if ($sstOut === false)
	{
		@unlink($sstBodyPath);
		@unlink($sheetBodyPath);
		@unlink($sstFullPath);
		throw new RuntimeException('Не удалось открыть временный файл.');
	}
	$cnt = (string)$siCount;
	fwrite($sstOut, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
	fwrite($sstOut, '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $cnt . '" uniqueCount="' . $cnt . '">');
	$brSst = fopen($sstBodyPath, 'rb');
	if ($brSst !== false)
	{
		stream_copy_to_stream($brSst, $sstOut);
		fclose($brSst);
	}
	fwrite($sstOut, '</sst>');
	fclose($sstOut);
	@unlink($sstBodyPath);

	$h = fopen($sheet, 'wb');
	if ($h === false)
	{
		@unlink($sstFullPath);
		@unlink($sheetBodyPath);
		throw new RuntimeException('Не удалось открыть временный файл.');
	}

	fwrite($h, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
	fwrite($h, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">');
	fwrite($h, '<dimension ref="' . htmlspecialchars($dimRef, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"/>');
	fwrite($h, '<sheetViews><sheetView tabSelected="1" workbookViewId="0"/></sheetViews>');
	fwrite($h, '<sheetFormatPr defaultRowHeight="15" defaultColWidth="9"/>');
	fwrite($h, '<sheetData>');
	$br = fopen($sheetBodyPath, 'rb');
	if ($br !== false)
	{
		stream_copy_to_stream($br, $h);
		fclose($br);
	}
	fwrite($h, '</sheetData><pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/></worksheet>');
	fclose($h);
	@unlink($sheetBodyPath);

	$zipPath = tempnam(sys_get_temp_dir(), 'mfcez');
	$zip = new ZipArchive();
	if ($zip->open($zipPath, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true)
	{
		@unlink($sheet);
		@unlink($sstFullPath);
		throw new RuntimeException('Не удалось создать ZIP.');
	}

	$ts = gmdate('c');
	$coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
		. 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
		. 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
		. '<dc:creator>Bitrix</dc:creator><cp:lastModifiedBy>Bitrix</cp:lastModifiedBy>'
		. '<dcterms:created xsi:type="dcterms:W3CDTF">' . $ts . '</dcterms:created>'
		. '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $ts . '</dcterms:modified>'
		. '</cp:coreProperties>';

	$appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
		. 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
		. '<Application>Microsoft Excel</Application></Properties>';

	$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
		. '<numFmts count="0"/>'
		. '<fonts count="1"><font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/><family val="2"/></font></fonts>'
		. '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
		. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
		. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
		. '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
		. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
		. '</styleSheet>';

	$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
		. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
		. '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
		. '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
		. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
		. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
		. '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
		. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
		. '</Types>';

	$relsRoot = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
		. '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
		. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
		. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
		. '</Relationships>';

	$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
		. '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="28800" windowHeight="12300"/></bookViews>'
		. '<sheets><sheet name="Каталог" sheetId="1" r:id="rId1"/></sheets></workbook>';

	$relsWb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
		. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
		. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
		. '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
		. '</Relationships>';

	$zip->addFromString('[Content_Types].xml', $contentTypes);
	$zip->addFromString('_rels/.rels', $relsRoot);
	$zip->addFromString('docProps/core.xml', $coreXml);
	$zip->addFromString('docProps/app.xml', $appXml);
	$zip->addFromString('xl/workbook.xml', $workbook);
	$zip->addFromString('xl/styles.xml', $stylesXml);
	$zip->addFromString('xl/_rels/workbook.xml.rels', $relsWb);
	$zip->addFile($sstFullPath, 'xl/sharedStrings.xml');
	$zip->addFile($sheet, 'xl/worksheets/sheet1.xml');
	$zip->close();

	header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

	readfile($zipPath);
	@unlink($sheet);
	@unlink($sstFullPath);
	@unlink($zipPath);
}

// ——— Export ———

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['mf_catalog_export_do'] ?? '') === 'Y')
{
	if (!check_bitrix_sessid())
	{
		require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
		\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Неверная сессия (sessid).']);
		require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

		return;
	}

	$format = mb_strtolower(trim((string)($_POST['format'] ?? 'csv')));
	if ($format !== 'csv' && $format !== 'xlsx')
	{
		$format = 'csv';
	}

	$map = mf_ce_optional_field_map();
	$optPosted = $_POST['opt'] ?? [];
	if (!is_array($optPosted))
	{
		$optPosted = [];
	}
	$optKeys = [];
	foreach ($optPosted as $k)
	{
		$k = (string)$k;
		if (isset($map[$k]))
		{
			$optKeys[$k] = true;
		}
	}

	$onlyActive = isset($_POST['only_active']) && $_POST['only_active'] === 'Y';

	$filter = [
		'IBLOCK_ID' => $iblockId,
		'!=PROPERTY_MF_IS_REDIRECT' => 'Y',
	];
	if ($onlyActive)
	{
		$filter['ACTIVE'] = 'Y';
	}

	$select = mf_ce_build_select($optKeys);

	$headers = ['Бренд', 'Артикул', 'Наименование'];
	$order = ['elem_ID', 'elem_DATE_CREATE', 'elem_TIMESTAMP_X', 'elem_XML_ID', 'elem_DETAIL_TEXT'];
	foreach ($order as $k)
	{
		if (isset($optKeys[$k]))
		{
			$headers[] = $map[$k]['label'] ?? $k;
		}
	}

	@set_time_limit(0);
	@ini_set('memory_limit', '512M');

	$gen = static function () use ($filter, $select, $optKeys): \Generator {
		$res = \CIBlockElement::GetList(['ID' => 'ASC'], $filter, false, false, $select);
		while ($el = $res->GetNext(false, false))
		{
			yield mf_ce_row_values($el, $optKeys);
		}
	};

	$fname = 'catalog_' . date('Y-m-d');
	try
	{
		if ($format === 'csv')
		{
			header('Content-Type: text/csv; charset=utf-8');
			header('Content-Disposition: attachment; filename="' . $fname . '.csv"');
			header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
			echo "\xEF\xBB\xBF";
			$out = fopen('php://output', 'wb');
			fputcsv($out, $headers, ';');
			foreach ($gen() as $r)
			{
				fputcsv($out, $r, ';');
			}
			fclose($out);
		}
		else
		{
			mf_ce_output_xlsx($fname . '.xlsx', $headers, $gen());
		}
		require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

		exit;
	}
	catch (Throwable $e)
	{
		if (!headers_sent())
		{
			header('HTTP/1.1 500 Internal Server Error');
		}
		echo htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

		exit;
	}
}

// ——— Form ———

$APPLICATION->SetTitle('Выгрузка товаров');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$optMap = mf_ce_optional_field_map();

$langUi = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';

?>
<form method="post" action="<?= mf_ce_esc((string)$APPLICATION->GetCurPage()) ?>?lang=<?= mf_ce_esc($langUi) ?>">
	<?= bitrix_sessid_post() ?>
	<input type="hidden" name="mf_catalog_export_do" value="Y" />

	<p class="adm-info-message" style="max-width:720px">
		Каталог: инфоблок ID <strong><?= (int)$iblockId ?></strong>.
		Колонки <strong>Бренд</strong> (MF_BRAND / MF_BRAND_NORM), <strong>Артикул</strong> (CML2_ARTICLE / MF_ARTICLE_NORM) и <strong>Наименование</strong> (NAME) всегда присутствуют в файле.
		Редиректы-дубли (MF_IS_REDIRECT) не выгружаются.
		Файл <strong>XLSX</strong> — это ZIP со сжатием: при тех же данных он обычно заметно меньше, чем CSV, это нормально.
	</p>

	<table class="adm-detail-content-table edit-table" style="max-width:920px">
		<tr>
			<td class="adm-detail-content-cell-l" width="35%">Формат файла</td>
			<td>
				<label><input type="radio" name="format" value="csv" checked /> CSV (разделитель «;», UTF-8 с BOM)</label>
				&nbsp;&nbsp;
				<label><input type="radio" name="format" value="xlsx" /> XLSX (Excel)</label>
			</td>
		</tr>
		<tr>
			<td>Фильтр</td>
			<td>
				<label><input type="checkbox" name="only_active" value="Y" checked /> только активные элементы</label>
				<div style="margin-top:6px;color:#666;font-size:12px;">Элементы-редиректы (MF_IS_REDIRECT) в выгрузку не попадают.</div>
			</td>
		</tr>
		<tr>
			<td style="vertical-align:top">Дополнительные колонки</td>
			<td>
				<?php
				foreach ($optMap as $key => $meta)
				{
					$label = $meta['label'];
					$id = 'mf_ce_' . preg_replace('~[^a-z0-9_]+~i', '_', $key);
					echo '<label style="display:block;margin:6px 0">';
					echo '<input type="checkbox" name="opt[]" value="' . mf_ce_esc($key) . '" id="' . mf_ce_esc($id) . '" /> ';
					echo mf_ce_esc($label);
					echo '</label>';
				}
				?>
			</td>
		</tr>
	</table>

	<br />
	<input type="submit" class="adm-btn-save" value="Скачать выгрузку" />
</form>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
