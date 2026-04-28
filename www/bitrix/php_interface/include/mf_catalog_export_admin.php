<?php

declare(strict_types=1);

/**
 * Админка: выгрузка каталога в CSV / XLSX.
 * Колонки фиксированные; фото — как на витрине (MF_EXT_IMAGES → meta → mf_mf_product_img_url).
 */

use Bitrix\Main\Loader;
use Bitrix\Iblock\InheritedProperty\ElementValues;
use Bitrix\Iblock\InheritedProperty\ValuesQueue;

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

$mfBrandDict = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/mf_brand_dict.php';
if (is_file($mfBrandDict))
{
	require_once $mfBrandDict;
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
 * @param array<string, mixed> $el fetch из CIBlockElement::GetList(GetNext)
 * @return list<string> непустые URL из свойства MF_EXT_IMAGES
 */
function mf_ce_collect_ext_image_urls(array $el): array
{
	$v = $el['PROPERTY_MF_EXT_IMAGES_VALUE'] ?? null;
	if ($v === null || $v === '')
	{
		return [];
	}
	if (is_array($v))
	{
		$out = [];
		foreach ($v as $one)
		{
			$s = trim((string)$one);
			if ($s !== '')
			{
				$out[] = $s;
			}
		}

		return array_values($out);
	}
	$s = trim((string)$v);

	return $s !== '' ? [$s] : [];
}

/**
 * ID свойств MF_BRAND / MF_BRAND_NORM в инфоблоке.
 *
 * @return list<int>
 */
function mf_ce_brand_property_ids(int $iblockId): array
{
	$iblockId = (int)$iblockId;
	if ($iblockId <= 0)
	{
		return [];
	}

	$propIds = [];
	foreach (['MF_BRAND', 'MF_BRAND_NORM'] as $propCode)
	{
		$rs = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $propCode]);
		if ($p = $rs->Fetch())
		{
			$id = (int)($p['ID'] ?? 0);
			if ($id > 0)
			{
				$propIds[$id] = true;
			}
		}
	}

	return array_keys($propIds);
}

/**
 * Ограничения на b_iblock_element как у CIBlockElement::GetList без SHOW_HISTORY:
 * актуальная версия документа (workflow) и не редирект (MF_IS_REDIRECT ≠ Y).
 */
function mf_ce_sql_and_exportable_element(string $eAlias, int $iblockId): string
{
	$iblockId = (int)$iblockId;
	$eAlias = preg_replace('~[^A-Za-z0-9_]+~', '', $eAlias) ?: 'e';
	$and = " AND ({$eAlias}.WF_STATUS_ID = 1) AND ({$eAlias}.WF_PARENT_ELEMENT_ID IS NULL)";

	$rs = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => 'MF_IS_REDIRECT']);
	if ($p = $rs->Fetch())
	{
		$redirectPid = (int)($p['ID'] ?? 0);
		if ($redirectPid > 0)
		{
			$and .= "
			AND NOT EXISTS (
				SELECT 1 FROM b_iblock_element_property prd
				WHERE prd.IBLOCK_ELEMENT_ID = {$eAlias}.ID
				AND prd.IBLOCK_PROPERTY_ID = {$redirectPid}
				AND prd.VALUE = 'Y'
			)";
		}
	}

	return $and;
}

/**
 * Список непустых значений MF_BRAND / MF_BRAND_NORM для выпадающего списка фильтра.
 *
 * @return list<string>
 */
function mf_ce_load_brand_choices(int $iblockId): array
{
	global $DB;

	$iblockId = (int)$iblockId;
	if ($iblockId <= 0)
	{
		return [];
	}

	$propIds = mf_ce_brand_property_ids($iblockId);
	if ($propIds === [])
	{
		return [];
	}

	$in = implode(',', $propIds);
	$exEl = mf_ce_sql_and_exportable_element('e', $iblockId);
	$sql = "
		SELECT DISTINCT TRIM(p.VALUE) AS V
		FROM b_iblock_element_property p
		INNER JOIN b_iblock_element e ON e.ID = p.IBLOCK_ELEMENT_ID AND e.IBLOCK_ID = {$iblockId}
		WHERE p.IBLOCK_PROPERTY_ID IN ({$in})
			AND p.VALUE IS NOT NULL
			AND TRIM(p.VALUE) <> ''
			{$exEl}
	";

	$q = $DB->Query($sql);
	if (!$q)
	{
		return [];
	}

	$seen = [];
	while ($r = $q->Fetch())
	{
		$v = trim((string)($r['V'] ?? ''));
		if ($v === '')
		{
			continue;
		}
		$seen[$v] = true;
	}

	$out = array_keys($seen);
	natcasesort($out);

	return array_values($out);
}

/**
 * Элементы, у которых в MF_BRAND или MF_BRAND_NORM после TRIM совпадает значение с выбранным в списке.
 * Так же, как DISTINCT в mf_ce_load_brand_choices — без расхождений из-за пробелов в БД и без OR-фильтра GetList.
 *
 * @return list<int>
 */
function mf_ce_element_ids_for_brand_value(int $iblockId, string $brand): array
{
	global $DB;

	$iblockId = (int)$iblockId;
	$brand = trim($brand);
	if ($iblockId <= 0 || $brand === '')
	{
		return [];
	}

	$propIds = mf_ce_brand_property_ids($iblockId);
	if ($propIds === [])
	{
		return [];
	}

	$in = implode(',', $propIds);
	$b = $DB->ForSql($brand);
	$exEl = mf_ce_sql_and_exportable_element('e', $iblockId);
	$sql = "
		SELECT DISTINCT p.IBLOCK_ELEMENT_ID AS ID
		FROM b_iblock_element_property p
		INNER JOIN b_iblock_element e ON e.ID = p.IBLOCK_ELEMENT_ID AND e.IBLOCK_ID = {$iblockId}
		WHERE p.IBLOCK_PROPERTY_ID IN ({$in})
			AND TRIM(p.VALUE) = TRIM('{$b}')
			{$exEl}
	";

	$q = $DB->Query($sql);
	if (!$q)
	{
		return [];
	}

	$out = [];
	while ($r = $q->Fetch())
	{
		$id = (int)($r['ID'] ?? 0);
		if ($id > 0)
		{
			$out[$id] = true;
		}
	}

	return array_keys($out);
}

function mf_ce_section_name(int $iblockId, int $sectionId): string
{
	static $cache = [];
	if ($sectionId <= 0)
	{
		return '';
	}
	$key = $iblockId . ':' . $sectionId;
	if (array_key_exists($key, $cache))
	{
		return $cache[$key];
	}
	$cache[$key] = '';
	$rs = \CIBlockSection::GetList(
		[],
		['IBLOCK_ID' => $iblockId, 'ID' => $sectionId],
		false,
		['nTopCount' => 1],
		['ID', 'NAME']
	);
	if ($r = $rs->Fetch())
	{
		$cache[$key] = trim((string)($r['NAME'] ?? ''));
	}

	return $cache[$key];
}

/**
 * Первое фото как на детальной (bootstrap_v4): EXT → meta аналогов → mf_mf_product_img_url(CODE,1).
 */
function mf_ce_primary_photo_url(array $el, int $elementId): string
{
	$ext = mf_ce_collect_ext_image_urls($el);
	if ($ext !== [])
	{
		return $ext[0];
	}
	if ($elementId > 0)
	{
		$analogsPath = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/bitrix/php_interface/include/mf_analogs.php';
		if (is_file($analogsPath))
		{
			require_once $analogsPath;
		}
		if (function_exists('mf_analogs_meta_images_for_product'))
		{
			$meta = mf_analogs_meta_images_for_product($elementId);
			if (is_array($meta))
			{
				foreach ($meta as $u)
				{
					$s = trim((string)$u);
					if ($s !== '')
					{
						return $s;
					}
				}
			}
		}
	}
	$code = trim((string)($el['CODE'] ?? ''));
	if ($code !== '' && function_exists('mf_mf_product_img_url'))
	{
		return (string)mf_mf_product_img_url($code, 1);
	}

	return '';
}

function mf_ce_decode_iprop(?string $s): string
{
	if ($s === null || $s === '')
	{
		return '';
	}

	return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** @return list<string> */
function mf_ce_export_headers(): array
{
	return [
		'id',
		'Бренд',
		'Артикул',
		'OEM',
		'Наименование',
		'Раздел товара',
		'Краткий текст',
		'Описание',
		'Заголовок страницы (title)',
		'Описание страницы (description)',
		'Ключевые слова страницы (keywords)',
		'ЧПУ страницы (slug)',
		'Фото',
	];
}

/**
 * @param array<string, mixed> $el
 * @return list<string>
 */
function mf_ce_build_row(int $iblockId, array $el): array
{
	$id = (int)($el['ID'] ?? 0);
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
	$oem = trim((string)($el['PROPERTY_OEM_VALUE'] ?? ''));
	$name = trim((string)($el['NAME'] ?? ''));
	$sectionId = (int)($el['IBLOCK_SECTION_ID'] ?? 0);
	$sectionName = mf_ce_section_name($iblockId, $sectionId);
	$preview = (string)($el['PREVIEW_TEXT'] ?? '');
	$detail = (string)($el['DETAIL_TEXT'] ?? '');
	$slug = trim((string)($el['CODE'] ?? ''));

	$seoTitle = '';
	$seoDesc = '';
	$seoKw = '';
	if ($id > 0)
	{
		$ip = new ElementValues($iblockId, $id);
		$iprops = $ip->getValues();
		$seoTitle = mf_ce_decode_iprop((string)($iprops['ELEMENT_META_TITLE'] ?? ''));
		$seoDesc = mf_ce_decode_iprop((string)($iprops['ELEMENT_META_DESCRIPTION'] ?? ''));
		$seoKw = mf_ce_decode_iprop((string)($iprops['ELEMENT_META_KEYWORDS'] ?? ''));
	}

	$photo = mf_ce_primary_photo_url($el, $id);

	// ElementValues копит все element_id в статической очереди — без сброса на большом каталоге съедает сотни MB RAM.
	if (class_exists(ValuesQueue::class))
	{
		ValuesQueue::deleteAll();
	}

	return [
		(string)$id,
		$brand,
		$article,
		$oem,
		$name,
		$sectionName,
		$preview,
		$detail,
		$seoTitle,
		$seoDesc,
		$seoKw,
		$slug,
		$photo,
	];
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

	// Пока скрипт держит сессию открытой, PHP блокирует её файл: другие вкладки с тем же пользователем
	// (витрина, корзина) ждут на session_start() и «висят». Sessid уже проверен — можно отпустить блокировку.
	if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE)
	{
		session_write_close();
	}

	$format = mb_strtolower(trim((string)($_POST['format'] ?? 'csv')));
	if ($format !== 'csv' && $format !== 'xlsx')
	{
		$format = 'csv';
	}

	$onlyActive = isset($_POST['only_active']) && $_POST['only_active'] === 'Y';

	$brandFilter = trim((string)($_POST['brand'] ?? ''));

	$filter = [
		'IBLOCK_ID' => $iblockId,
		'!=PROPERTY_MF_IS_REDIRECT' => 'Y',
		'CHECK_PERMISSIONS' => 'N',
	];
	if ($onlyActive)
	{
		$filter['ACTIVE'] = 'Y';
	}

	if ($brandFilter !== '')
	{
		$brandIds = mf_ce_element_ids_for_brand_value($iblockId, $brandFilter);
		// Несовпадение по TRIM между списком и GetList больше не возможно; при пустом списке id — пустая выгрузка.
		$filter['ID'] = $brandIds === [] ? -1 : $brandIds;
	}

	$select = [
		'ID',
		'NAME',
		'CODE',
		'IBLOCK_SECTION_ID',
		'PREVIEW_TEXT',
		'DETAIL_TEXT',
		'PROPERTY_MF_BRAND',
		'PROPERTY_MF_BRAND_NORM',
		'PROPERTY_CML2_ARTICLE',
		'PROPERTY_MF_ARTICLE_NORM',
		'PROPERTY_OEM',
		'PROPERTY_MF_EXT_IMAGES',
	];

	$headers = mf_ce_export_headers();

	@set_time_limit(0);
	@ini_set('memory_limit', '1024M');

	$gen = static function () use ($filter, $select, $iblockId): \Generator {
		$res = \CIBlockElement::GetList(['ID' => 'ASC'], $filter, false, false, $select);
		while ($el = $res->GetNext(false, false))
		{
			$row = mf_ce_build_row($iblockId, $el);
			unset($el);
			yield $row;
		}
	};

	$fname = 'catalog_' . date('Y-m-d');
	if ($brandFilter !== '')
	{
		$slug = preg_replace('~[^a-zA-Z0-9_.-]+~', '_', $brandFilter) ?? '';
		$slug = trim((string)$slug, '_.');
		if ($slug === '')
		{
			$slug = 'brand_' . substr(md5($brandFilter), 0, 10);
		}
		$fname .= '_' . $slug;
	}
	try
	{
		// Админский prolog держит свой output buffer: без сброса CSV/XLSX часто не попадают в ответ или приходят «пустыми».
		while (ob_get_level() > 0)
		{
			ob_end_clean();
		}

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

		if (class_exists(\Bitrix\Main\Application::class))
		{
			\Bitrix\Main\Application::getInstance()->terminate();
		}
		exit(0);
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

$langUi = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
$mfCeBrandChoices = mf_ce_load_brand_choices($iblockId);

?>
<form method="post" action="<?= mf_ce_esc((string)$APPLICATION->GetCurPage()) ?>?lang=<?= mf_ce_esc($langUi) ?>">
	<?= bitrix_sessid_post() ?>
	<input type="hidden" name="mf_catalog_export_do" value="Y" />

	<p class="adm-info-message" style="max-width:720px">
		Каталог: инфоблок ID <strong><?= (int)$iblockId ?></strong>.
		В файл всегда входят колонки: id, бренд, артикул, OEM, наименование, <strong>основной раздел</strong>, краткий и детальный текст, SEO (title / description / keywords), ЧПУ (slug), <strong>URL первого фото как на сайте</strong>
		(MF_EXT_IMAGES при наличии, иначе метаданные аналогов, иначе схема <code>mf_mf_product_img_url</code> — без файлов в <code>/upload/</code>).
		Редиректы (MF_IS_REDIRECT) не выгружаются.
		Долгая выгрузка больше не держит сессию заблокированной: параллельно можно открывать витрину и корзину в других вкладках.
		XLSX — ZIP со сжатием, при тех же данных файл обычно меньше CSV; на очень больших каталогах надёжнее CSV (быстрее, меньше таймаутов).
		По желанию ограничьте выгрузку <strong>брендом</strong> (список строится по заполненным MF_BRAND / MF_BRAND_NORM в каталоге).
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
			<td>Бренд</td>
			<td>
				<select name="brand" style="max-width:420px">
					<option value="">— весь каталог —</option>
					<?php foreach ($mfCeBrandChoices as $b): ?>
						<option value="<?= mf_ce_esc($b) ?>"><?= mf_ce_esc($b) ?></option>
					<?php endforeach; ?>
				</select>
				<div style="margin-top:6px;color:#666;font-size:12px;">Только значения у элементов, которые реально попадают в выгрузку: не редирект (MF_IS_REDIRECT), актуальная запись без родителя в workflow (как у GetList). «Лишние» длинные строки из дублей/черновиков в списке не показываются.</div>
			</td>
		</tr>
	</table>

	<br />
	<input type="submit" class="adm-btn-save" value="Скачать выгрузку" />
</form>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
