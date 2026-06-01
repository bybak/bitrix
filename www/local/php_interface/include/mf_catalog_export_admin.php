<?php

declare(strict_types=1);

/**
 * Админка: выгрузка каталога в CSV / XLSX и загрузка CSV того же формата (обновление по id).
 * Колонки фиксированные; колонка «Фото» — все URL как на карточке catalog.element / bootstrap_v4 (через « | »):
 * все MF_EXT_IMAGES → все URL meta аналогов → при непустой галерее (MORE_PHOTO или анонс/деталь)
 * все слоты mf_mf_product_img_url(CODE,n), как после подмены SRC в шаблоне → иначе только /upload/.
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

require_once __DIR__ . '/mf_ce_brand_choices_inc.php';

$iblockId = 4;

require_once __DIR__ . '/mf_catalog_export_xlsx_cell.php';
require_once __DIR__ . '/mf_catalog_import_job_lib.php';
require_once __DIR__ . '/mf_catalog_import_job_worker_inc.php';

// JSON-поллинг без тяжёлой инициализации страницы.
if ((string)($_GET['mf_catalog_job_poll'] ?? '') === '1')
{
	$jobPollId = (int)($_GET['job'] ?? 0);
	$tokenPoll = preg_replace('~[^a-f0-9]~', '', (string)($_GET['token'] ?? ''));
	header('Content-Type: application/json; charset=utf-8');
	if (!is_object($USER) || !$USER->IsAdmin() || $jobPollId <= 0 || strlen($tokenPoll) !== 32)
	{
		echo json_encode(['ok' => false, 'error' => 'Доступ запрещён.'], JSON_UNESCAPED_UNICODE);
		die;
	}
	$rowP = mf_catalog_import_job_get($jobPollId);
	if (
		!$rowP
		|| (int)($rowP['UF_USER_ID'] ?? 0) !== (int)$USER->GetID()
		|| (string)($rowP['UF_TOKEN'] ?? '') !== $tokenPoll
	)
	{
		echo json_encode(['ok' => false, 'error' => 'Задание не найдено.'], JSON_UNESCAPED_UNICODE);
		die;
	}
	$stP = (string)($rowP['UF_STATUS'] ?? '');
	$outP = [
		'ok' => true,
		'status' => $stP,
		'rows_done' => (int)($rowP['UF_ROWS_DONE'] ?? 0),
		'rows_total' => (int)($rowP['UF_ROWS_TOTAL'] ?? 0),
		'progress_pct' => array_key_exists('UF_PROGRESS_PCT', $rowP) && $rowP['UF_PROGRESS_PCT'] !== null && $rowP['UF_PROGRESS_PCT'] !== ''
			? max(0, min(100, (int)$rowP['UF_PROGRESS_PCT']))
			: null,
		'progress_note' => trim((string)($rowP['UF_PROGRESS_NOTE'] ?? '')),
		'progress_at' => trim((string)($rowP['UF_PROGRESS_AT'] ?? '')),
	];
	if ($stP === 'running' && !empty($rowP['UF_PROGRESS_AT']))
	{
		$ts = strtotime((string)$rowP['UF_PROGRESS_AT']);
		if ($ts > 0 && (time() - $ts) > 600)
		{
			$outP['stale_hint'] = 'Прогресс не обновлялся более 10 минут — вероятно, PHP был остановлен (таймаут FPM, OOM). См. mf_catalog_import.log.';
		}
	}
	if ($stP === 'done' && !empty($rowP['UF_RESULT_JSON']))
	{
		$decoded = json_decode((string)$rowP['UF_RESULT_JSON'], true);
		$outP['stats'] = is_array($decoded) ? $decoded : null;
	}
	if ($stP === 'failed' && !empty($rowP['UF_ERROR_TEXT']))
	{
		$outP['error'] = (string)$rowP['UF_ERROR_TEXT'];
	}
	echo json_encode($outP, JSON_UNESCAPED_UNICODE);
	die;
}

if ((string)($_GET['mf_catalog_job_nudge'] ?? '') === '1')
{
	$nudgeId = (int)($_GET['job'] ?? 0);
	$nudgeTok = preg_replace('~[^a-f0-9]~', '', (string)($_GET['token'] ?? ''));
	if (!is_object($USER) || !$USER->IsAdmin() || $nudgeId <= 0 || strlen($nudgeTok) !== 32)
	{
		header('Content-Type: text/plain; charset=utf-8');
		echo '0';
		die;
	}
	mf_ci_catalog_import_job_run_or_die($nudgeId, false);
	die;
}

if (
	$_SERVER['REQUEST_METHOD'] === 'POST'
	&& (string)($_POST['mf_catalog_job_run'] ?? '') === 'Y'
	&& is_object($USER)
	&& $USER->IsAdmin()
)
{
	mf_ci_catalog_import_job_run_or_die((int)($_POST['job'] ?? 0), true);
	die;
}

function mf_ce_esc(string|int|float|bool|null $s): string
{
	$s = trim((string)$s);

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

/** Как normalizeArticle в mf_update_supplier_stock.php — для MF_ARTICLE_NORM и MF_UNIQ_KEY. */
function mf_ce_normalize_article(string $s): string
{
	$s = mb_strtoupper(trim($s));
	$s = preg_replace('~[^A-Z0-9]+~', '', $s) ?? '';

	return $s;
}

/** Как normalizeBrand в mf_update_supplier_stock.php. */
function mf_ce_normalize_brand(string $s): string
{
	$s = mb_strtoupper(trim($s));
	$s = str_replace('Ё', 'Е', $s);
	$s = preg_replace('~[^A-ZА-Я0-9]+~u', '', $s) ?? '';

	return $s;
}

/** Как makeUniqKey в mf_update_supplier_stock.php. */
function mf_ce_make_uniq_key(string $articleNorm, string $brandNorm): string
{
	$articleNorm = trim($articleNorm);
	$brandNorm = trim($brandNorm);
	if ($brandNorm === '')
	{
		$brandNorm = 'UNKNOWNBRAND';
	}

	return $articleNorm . '_' . $brandNorm;
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

function mf_ce_iblock_file_web_path(int $fileId): string
{
	if ($fileId <= 0 || !class_exists(\CFile::class))
	{
		return '';
	}
	$p = (string)\CFile::GetPath($fileId);
	$p = trim($p);

	return $p === '' ? $p : (($p[0] ?? '/') === '/' ? $p : '/' . $p);
}

/**
 * Есть ли у элемента слоты файловой галереи MORE_PHOTO (как на деталке до подмены SRC на /mf-img/).
 */
function mf_ce_more_photo_has_slots(array $el): bool
{
	return mf_ce_more_photo_slot_count($el) > 0;
}

/** Число файловых слотов MORE_PHOTO (сколько картинок в галерее на деталке до подмены SRC). */
function mf_ce_more_photo_slot_count(array $el): int
{
	$v = $el['PROPERTY_MORE_PHOTO_VALUE'] ?? null;
	if ($v === null || $v === false || $v === '')
	{
		return 0;
	}
	if (is_array($v))
	{
		$n = 0;
		foreach ($v as $one)
		{
			if ((int)$one > 0)
			{
				$n++;
			}
			elseif (is_string($one) && trim($one) !== '')
			{
				$n++;
			}
		}

		return $n;
	}

	return (int)$v > 0 ? 1 : 0;
}

/**
 * Число слотов галереи на деталке, которые шаблон bootstrap_v4 переписывает на mf_mf_product_img_url(CODE,n),
 * когда MF_EXT_IMAGES и картинки meta аналогов пусты: сначала файловое MORE_PHOTO, иначе 1–2 картинки из PREVIEW/DETAIL
 * (как собирает компонент catalog.element при отсутствии свойства MORE_PHOTO).
 */
function mf_ce_catalog_detail_gallery_slot_count(array $el): int
{
	$n = mf_ce_more_photo_slot_count($el);
	if ($n > 0)
	{
		return $n;
	}
	$prevId = (int)($el['PREVIEW_PICTURE'] ?? 0);
	$detailId = (int)($el['DETAIL_PICTURE'] ?? 0);
	if ($prevId <= 0 && $detailId <= 0)
	{
		return 0;
	}
	if ($prevId > 0 && $detailId <= 0)
	{
		return 1;
	}
	if ($detailId > 0 && $prevId <= 0)
	{
		return 1;
	}
	if ($prevId === $detailId)
	{
		return 1;
	}
	$prevPath = mf_ce_iblock_file_web_path($prevId);
	$detailPath = mf_ce_iblock_file_web_path($detailId);
	if ($prevPath !== '' && $detailPath !== '' && $prevPath === $detailPath)
	{
		return 1;
	}

	return 2;
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
 * Цепочка названий разделов от корня до $sectionId (как в навигации), через « => ».
 */
function mf_ce_section_chain_path(int $iblockId, int $sectionId): string
{
	if ($sectionId <= 0 || $iblockId <= 0)
	{
		return '';
	}
	static $cache = [];
	$key = $iblockId . ':' . $sectionId;
	if (array_key_exists($key, $cache))
	{
		return $cache[$key];
	}
	$names = [];
	$nav = \CIBlockSection::GetNavChain($iblockId, $sectionId, ['ID', 'NAME']);
	if ($nav)
	{
		while ($p = $nav->Fetch())
		{
			$n = trim((string)($p['NAME'] ?? ''));
			if ($n !== '')
			{
				$names[] = $n;
			}
		}
	}
	$cache[$key] = $names === [] ? '' : implode(' => ', $names);

	return $cache[$key];
}

/**
 * Все URL картинок как на детальной с шаблоном eshop_bootstrap_v4 → catalog.element / bootstrap_v4.
 * Порядок: все MF_EXT_IMAGES → все URL meta аналогов → при непустой «эффективной» галерее (см. mf_ce_catalog_detail_gallery_slot_count)
 * по слотам mf_mf_product_img_url(CODE,1..n) → иначе превью и (если отличается) детальная из /upload/.
 * Если есть символьный код и хотя бы одна картинка (MORE_PHOTO или анонс/деталь), подставляется та же схема URL, что на сайте после подмены SRC.
 *
 * @return list<string>
 */
function mf_ce_all_photo_urls(array $el, int $elementId): array
{
	$push = static function (array &$out, string $url): void {
		$url = trim($url);
		if ($url !== '')
		{
			$out[] = $url;
		}
	};
	$out = [];

	$ext = mf_ce_collect_ext_image_urls($el);
	if ($ext !== [])
	{
		foreach ($ext as $u)
		{
			$push($out, (string)$u);
		}

		return $out;
	}

	if ($elementId > 0)
	{
		$analogsPath = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_analogs.php';
		if (is_file($analogsPath))
		{
			require_once $analogsPath;
		}
		if (function_exists('mf_analogs_meta_images_for_product'))
		{
			try
			{
				$meta = mf_analogs_meta_images_for_product($elementId);
			}
			catch (\Throwable $e)
			{
				$meta = [];
			}
			if (is_array($meta) && $meta !== [])
			{
				foreach ($meta as $u)
				{
					$push($out, (string)$u);
				}
				if ($out !== [])
				{
					return $out;
				}
			}
		}
	}

	$code = trim((string)($el['CODE'] ?? ''));
	$nSlots = mf_ce_catalog_detail_gallery_slot_count($el);
	if ($code !== '' && $nSlots > 0 && function_exists('mf_mf_product_img_url'))
	{
		for ($i = 1; $i <= $nSlots; $i++)
		{
			$s = (string)mf_mf_product_img_url($code, $i);
			$push($out, $s);
		}

		return $out;
	}

	$prevPath = mf_ce_iblock_file_web_path((int)($el['PREVIEW_PICTURE'] ?? 0));
	if ($prevPath !== '')
	{
		$push($out, $prevPath);
	}
	$detailPath = mf_ce_iblock_file_web_path((int)($el['DETAIL_PICTURE'] ?? 0));
	if ($detailPath !== '' && $detailPath !== $prevPath)
	{
		$push($out, $detailPath);
	}

	return $out;
}

/** Первый URL из mf_ce_all_photo_urls() (обратная совместимость). */
function mf_ce_primary_photo_url(array $el, int $elementId): string
{
	$all = mf_ce_all_photo_urls($el, $elementId);

	return $all[0] ?? '';
}

function mf_ce_decode_iprop(?string $s): string
{
	if ($s === null || $s === '')
	{
		return '';
	}

	return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Текст для ячеек выгрузки: сущности вида &lt;…&gt; → реальные «&lt;» в значении (теги и символы, не entity-код). */
function mf_ce_export_plain(?string $s): string
{
	return mf_ce_decode_iprop($s);
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

/** @param resource $fp */
function mf_ce_fgetcsv_row($fp): array|false|null
{
	if (!\is_resource($fp))
	{
		return null;
	}

	return PHP_VERSION_ID >= 70400
		? fgetcsv($fp, 0, ';', '"', '\\')
		: fgetcsv($fp, 0, ';', '"');
}

function mf_ce_strip_utf8_bom(string $s): string
{
	if ($s !== '' && strncmp($s, "\xEF\xBB\xBF", 3) === 0)
	{
		return substr($s, 3);
	}

	return $s;
}

/**
 * Первый раздел инфоблока с точным именем (активный цепочкой).
 */
function mf_ce_section_id_by_name(int $iblockId, string $name): ?int
{
	$name = trim($name);
	if ($name === '' || $iblockId <= 0)
	{
		return null;
	}

	$rs = \CIBlockSection::GetList(
		['ID' => 'ASC'],
		['IBLOCK_ID' => $iblockId, 'NAME' => $name, 'GLOBAL_ACTIVE' => 'Y'],
		false,
		['nTopCount' => 1],
		['ID']
	);
	$r = $rs->Fetch();

	return $r ? (int)($r['ID'] ?? 0) : null;
}

/**
 * ID конечного раздела по цепочке «Родитель => Дочерний => …» (как в выгрузке).
 * Файлы со старым разделителем « / » при импорте по-прежнему поддерживаются.
 * Одно имя без разделителей — первый подходящий активный раздел с таким NAME.
 */
function mf_ce_section_id_by_chain_path(int $iblockId, string $path): ?int
{
	$path = trim($path);
	if ($path === '' || $iblockId <= 0)
	{
		return null;
	}
	if (strpos($path, '=>') !== false)
	{
		$parts = preg_split('#\s*=>\s*#u', $path, -1, PREG_SPLIT_NO_EMPTY);
	}
	else
	{
		$parts = preg_split('#\s*/\s*#u', $path, -1, PREG_SPLIT_NO_EMPTY);
	}
	if ($parts === false)
	{
		return null;
	}
	$parts = array_values(array_filter(array_map('trim', $parts), static fn($s) => $s !== ''));
	if ($parts === [])
	{
		return null;
	}
	if (count($parts) === 1)
	{
		return mf_ce_section_id_by_name($iblockId, $parts[0]);
	}
	$parentId = 0;
	foreach ($parts as $segment)
	{
		$filter = [
			'IBLOCK_ID' => $iblockId,
			'GLOBAL_ACTIVE' => 'Y',
			'NAME' => $segment,
			'SECTION_ID' => $parentId,
		];
		$rs = \CIBlockSection::GetList(['ID' => 'ASC'], $filter, false, ['nTopCount' => 1], ['ID']);
		$r = $rs->Fetch();
		if (!is_array($r))
		{
			return null;
		}
		$parentId = (int)($r['ID'] ?? 0);
		if ($parentId <= 0)
		{
			return null;
		}
	}

	return $parentId;
}

/**
 * @param array<string, string> $row колонки по заголовкам выгрузки
 * @param array{updated:int, skipped:int, skipped_redirect:int, skipped_no_id:int, skipped_brand:int, errors:list<string>} $stats
 */
function mf_ce_import_apply_row(int $iblockId, array $row, int $lineNum, array &$stats): void
{
	$id = (int)trim((string)($row['id'] ?? ''));
	if ($id <= 0)
	{
		$stats['skipped_no_id']++;

		return;
	}

	$rsEl = \CIBlockElement::GetList(
		[],
		['IBLOCK_ID' => $iblockId, 'ID' => $id, 'CHECK_PERMISSIONS' => 'N'],
		false,
		false,
		['ID', 'IBLOCK_ID']
	);
	$arEl = $rsEl->Fetch();
	if (!is_array($arEl) || (int)($arEl['IBLOCK_ID'] ?? 0) !== $iblockId)
	{
		$stats['errors'][] = 'Строка ' . $lineNum . ': элемент #' . $id . ' не найден в инфоблоке ' . $iblockId;

		return;
	}

	$rsRed = \CIBlockElement::GetProperty($iblockId, $id, 'sort', 'asc', ['CODE' => 'MF_IS_REDIRECT']);
	$red = $rsRed ? $rsRed->Fetch() : null;
	if (is_array($red) && (string)($red['VALUE'] ?? '') === 'Y')
	{
		$stats['skipped_redirect']++;

		return;
	}

	$brandFromCsv = trim((string)($row['Бренд'] ?? ''));
	if ($brandFromCsv !== '' && function_exists('mf_brand_import_is_skipped') && mf_brand_import_is_skipped($brandFromCsv))
	{
		$stats['skipped_brand']++;

		return;
	}

	global $USER;
	$uid = (is_object($USER) && method_exists($USER, 'GetID')) ? (int)$USER->GetID() : 1;

	$fields = [
		'MODIFIED_BY' => $uid,
		'PREVIEW_TEXT' => (string)($row['Краткий текст'] ?? ''),
		'PREVIEW_TEXT_TYPE' => 'html',
		'DETAIL_TEXT' => (string)($row['Описание'] ?? ''),
		'DETAIL_TEXT_TYPE' => 'html',
		'IPROPERTY_TEMPLATES' => [
			'ELEMENT_META_TITLE' => trim((string)($row['Заголовок страницы (title)'] ?? '')),
			'ELEMENT_META_DESCRIPTION' => trim((string)($row['Описание страницы (description)'] ?? '')),
			'ELEMENT_META_KEYWORDS' => trim((string)($row['Ключевые слова страницы (keywords)'] ?? '')),
		],
	];

	$name = trim((string)($row['Наименование'] ?? ''));
	if ($name !== '')
	{
		$fields['NAME'] = $name;
	}

	$slug = trim((string)($row['ЧПУ страницы (slug)'] ?? ''));
	if ($slug !== '')
	{
		$fields['CODE'] = $slug;
	}

	$sectionName = trim((string)($row['Раздел товара'] ?? ''));
	if ($sectionName !== '')
	{
		$sid = mf_ce_section_id_by_chain_path($iblockId, $sectionName);
		if ($sid !== null)
		{
			$fields['IBLOCK_SECTION_ID'] = $sid;
		}
		else
		{
			$stats['errors'][] = 'Строка ' . $lineNum . ' (id ' . $id . '): раздел «' . $sectionName . '» не найден — раздел не изменён';
		}
	}

	// Как mf_update_supplier_stock с --brand-dict=Y: канон из mf_brand_dict.php для MF_BRAND* и MF_UNIQ_KEY.
	$brandRaw = $brandFromCsv;
	if ($brandRaw !== '' && function_exists('mf_brand_find'))
	{
		$canon = (string)mf_brand_find($brandRaw, true);
		if ($canon !== '')
		{
			$brandRaw = $canon;
		}
	}
	$artRaw = trim((string)($row['Артикул'] ?? ''));
	$articleNorm = mf_ce_normalize_article($artRaw);
	$brandNorm = $brandRaw !== '' ? mf_ce_normalize_brand($brandRaw) : '';
	$uniqKey = mf_ce_make_uniq_key($articleNorm, $brandNorm);

	$props = [
		'MF_BRAND' => $brandRaw,
		'MF_BRAND_NORM' => $brandRaw,
		'CML2_ARTICLE' => $artRaw,
		'MF_ARTICLE_NORM' => $articleNorm,
		'OEM' => trim((string)($row['OEM'] ?? '')),
		'MF_UNIQ_KEY' => $uniqKey,
	];
	$photo = trim((string)($row['Фото'] ?? ''));
	if ($photo !== '')
	{
		$photoUrls = [];
		foreach (preg_split('#\s*\|\s*#u', $photo, -1, PREG_SPLIT_NO_EMPTY) as $chunk)
		{
			$one = trim((string)$chunk);
			if ($one !== '')
			{
				$photoUrls[] = $one;
			}
		}
		if ($photoUrls !== [])
		{
			// MF_EXT_IMAGES — множественное строковое (URL); как в mf_import_analogs_admin / strip tool.
			$props['MF_EXT_IMAGES'] = array_values($photoUrls);
		}
	}
	// Не передаём PROPERTY_VALUES в Update: иначе ядро проходит по всем свойствам инфоблока и обнуляет
	// не указанные в массиве. SetPropertyValuesEx меняет только ключи из $props (включая MF_UNIQ_KEY).
	$ibEl = new \CIBlockElement();
	if (!$ibEl->Update($id, $fields, false, false))
	{
		$stats['errors'][] = 'Строка ' . $lineNum . ' (id ' . $id . '): ошибка обновления: ' . (string)$ibEl->LAST_ERROR;

		return;
	}

	\CIBlockElement::SetPropertyValuesEx($id, $iblockId, $props);

	$stats['updated']++;
}

/**
 * Проверка заголовка CSV и индекс колонок.
 *
 * @return array{ok:bool, idx?:array<string,int>, error?:string}
 */
function mf_ce_csv_parse_header_index($fp): array
{
	$expected = mf_ce_export_headers();
	$headerRow = mf_ce_fgetcsv_row($fp);
	if ($headerRow === null || $headerRow === false)
	{
		return ['ok' => false, 'error' => 'Пустой файл или нет строки заголовка.'];
	}
	$headerRow[0] = isset($headerRow[0]) ? mf_ce_strip_utf8_bom((string)$headerRow[0]) : '';
	$headers = array_map(static fn($c) => trim((string)$c), $headerRow);
	$idx = [];
	foreach ($headers as $i => $label)
	{
		if ($label !== '' && !isset($idx[$label]))
		{
			$idx[$label] = $i;
		}
	}
	foreach ($expected as $col)
	{
		if (!isset($idx[$col]))
		{
			return [
				'ok' => false,
				'error' => 'В заголовке CSV нет колонки «' . $col . '». Используйте файл выгрузки без изменения заголовков.',
			];
		}
	}

	return ['ok' => true, 'idx' => $idx];
}

/**
 * @return array{ok:bool, total:int, error?:string}
 */
function mf_ce_csv_count_data_rows(string $absolutePath): array
{
	$fp = fopen($absolutePath, 'rb');
	if ($fp === false)
	{
		return ['ok' => false, 'total' => 0, 'error' => 'Не удалось открыть файл.'];
	}
	$hdr = mf_ce_csv_parse_header_index($fp);
	if (empty($hdr['ok']))
	{
		fclose($fp);

		return ['ok' => false, 'total' => 0, 'error' => (string)($hdr['error'] ?? 'Ошибка заголовка')];
	}
	$total = 0;
	while (($cells = mf_ce_fgetcsv_row($fp)) !== false)
	{
		if (!is_array($cells))
		{
			continue;
		}
		foreach ($cells as $c)
		{
			if (trim((string)$c) !== '')
			{
				$total++;
				break;
			}
		}
	}
	fclose($fp);

	return ['ok' => true, 'total' => $total];
}

/**
 * @param array{job_id?:int, rows_total?:int} $options
 * @return array{updated:int, skipped:int, skipped_redirect:int, skipped_no_id:int, skipped_brand:int, rows_processed?:int, errors:list<string>}
 */
function mf_ce_run_csv_import(int $iblockId, string $absolutePath, array $options = []): array
{
	$stats = [
		'updated' => 0,
		'skipped' => 0,
		'skipped_redirect' => 0,
		'skipped_no_id' => 0,
		'skipped_brand' => 0,
		'rows_processed' => 0,
		'errors' => [],
	];

	$jobId = (int)($options['job_id'] ?? 0);
	$rowsTotal = max(0, (int)($options['rows_total'] ?? 0));

	$expected = mf_ce_export_headers();
	$fp = fopen($absolutePath, 'rb');
	if ($fp === false)
	{
		$stats['errors'][] = 'Не удалось открыть файл.';

		return $stats;
	}

	$hdr = mf_ce_csv_parse_header_index($fp);
	if (empty($hdr['ok']))
	{
		fclose($fp);
		$stats['errors'][] = (string)($hdr['error'] ?? 'Ошибка заголовка CSV.');

		return $stats;
	}
	/** @var array<string, int> $idx */
	$idx = $hdr['idx'] ?? [];

	$lineNum = 1;
	while (($cells = mf_ce_fgetcsv_row($fp)) !== false)
	{
		$lineNum++;
		if (!is_array($cells))
		{
			continue;
		}
		$nonEmpty = false;
		foreach ($cells as $c)
		{
			if (trim((string)$c) !== '')
			{
				$nonEmpty = true;
				break;
			}
		}
		if (!$nonEmpty)
		{
			$stats['skipped']++;

			continue;
		}

		$row = [];
		foreach ($expected as $col)
		{
			$row[$col] = (string)($cells[$idx[$col]] ?? '');
		}

		mf_ce_import_apply_row($iblockId, $row, $lineNum, $stats);
		$stats['rows_processed']++;

		if ($jobId > 0 && (
			$stats['rows_processed'] === 1
			|| ($rowsTotal > 0 && $stats['rows_processed'] === $rowsTotal)
			|| ($stats['rows_processed'] % 10) === 0
		))
		{
			if (function_exists('mf_catalog_import_job_update'))
			{
				mf_catalog_import_job_update($jobId, [
					'UF_ROWS_DONE' => $stats['rows_processed'],
					'UF_ROWS_TOTAL' => $rowsTotal > 0 ? $rowsTotal : null,
				]);
			}
			if (function_exists('mf_catalog_import_job_progress_apply'))
			{
				$tot = $rowsTotal > 0 ? $rowsTotal : max($stats['rows_processed'], 1);
				$remaining = max(0, $tot - $stats['rows_processed']);
				$pct = (int)round(100.0 * $stats['rows_processed'] / $tot);
				$note = 'Обновлено ' . (int)$stats['updated']
					. ' товаров, обработано ' . $stats['rows_processed'] . ' из ' . $tot
					. ', осталось ~' . $remaining;
				mf_catalog_import_job_progress_apply($jobId, $pct, $note);
			}
		}
		if ($stats['rows_processed'] > 0 && ($stats['rows_processed'] % 200) === 0 && function_exists('mf_ci_bootstrap_long_import'))
		{
			mf_ci_bootstrap_long_import();
		}
	}

	fclose($fp);

	return $stats;
}

/**
 * @param array<string, mixed> $el
 * @return list<string>
 */
function mf_ce_build_row(int $iblockId, array $el): array
{
	$id = (int)($el['ID'] ?? 0);
	$brand = mf_ce_export_plain(trim((string)($el['PROPERTY_MF_BRAND_VALUE'] ?? '')));
	if ($brand === '')
	{
		$brand = mf_ce_export_plain(trim((string)($el['PROPERTY_MF_BRAND_NORM_VALUE'] ?? '')));
	}
	$article = mf_ce_export_plain(trim((string)($el['PROPERTY_CML2_ARTICLE_VALUE'] ?? '')));
	if ($article === '')
	{
		$article = mf_ce_export_plain(trim((string)($el['PROPERTY_MF_ARTICLE_NORM_VALUE'] ?? '')));
	}
	$oem = mf_ce_export_plain(trim((string)($el['PROPERTY_OEM_VALUE'] ?? '')));
	$name = mf_ce_export_plain(trim((string)($el['NAME'] ?? '')));
	$sectionId = (int)($el['IBLOCK_SECTION_ID'] ?? 0);
	$sectionName = mf_ce_export_plain(mf_ce_section_chain_path($iblockId, $sectionId));
	$preview = mf_ce_export_plain((string)($el['PREVIEW_TEXT'] ?? ''));
	$detail = mf_ce_export_plain((string)($el['DETAIL_TEXT'] ?? ''));
	$slug = mf_ce_export_plain(trim((string)($el['CODE'] ?? '')));

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

	$urls = mf_ce_all_photo_urls($el, $id);
	$photo = mf_ce_export_plain(implode(' | ', $urls));

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

$mfCeImportReport = null;
$mfCeImportError = null;
$mfCeViewJob = null;

if (isset($_GET['catalog_import_job']))
{
	$vji = (int)($_GET['catalog_import_job'] ?? 0);
	$vjt = preg_replace('~[^a-f0-9]~', '', (string)($_GET['token'] ?? ''));
	if ($vji > 0 && strlen($vjt) === 32 && is_object($USER) && (int)$USER->GetID() > 0)
	{
		$vjr = mf_catalog_import_job_get($vji);
		if (
			$vjr
			&& (int)($vjr['UF_USER_ID'] ?? 0) === (int)$USER->GetID()
			&& (string)($vjr['UF_TOKEN'] ?? '') === $vjt
		)
		{
			$mfCeViewJob = $vjr;
		}
	}
}

// ——— Import CSV (фоновое задание + прогресс) ———

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['mf_catalog_import_do'] ?? '') === 'Y')
{
	if (!check_bitrix_sessid())
	{
		$mfCeImportError = 'Неверная сессия (sessid). Обновите страницу.';
	}
	else
	{
		if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE)
		{
			session_write_close();
		}

		$file = $_FILES['mf_catalog_csv'] ?? null;
		if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
		{
			$err = is_array($file) ? (int)($file['error'] ?? 0) : UPLOAD_ERR_NO_FILE;
			$mfCeImportError = $err === UPLOAD_ERR_NO_FILE
				? 'Выберите CSV-файл.'
				: ('Ошибка загрузки файла (код ' . $err . ').');
		}
		else
		{
			$tmp = (string)($file['tmp_name'] ?? '');
			$origName = (string)($file['name'] ?? '');
			$ext = mb_strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));
			if ($ext !== 'csv')
			{
				$mfCeImportError = 'Допустим только формат .csv (как при выгрузке).';
			}
			elseif ($tmp === '' || !is_uploaded_file($tmp))
			{
				$mfCeImportError = 'Некорректный временный файл загрузки.';
			}
			elseif (!mf_catalog_import_job_ensure_table())
			{
				$mfCeImportError = 'Не удалось создать таблицу фоновых заданий (MySQL). Проверьте права к БД.';
			}
			else
			{
				$jobToken = bin2hex(random_bytes(16));
				$relJobPath = 'upload/mf_catalog_import/jobs/' . $jobToken . '.csv';
				$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT']), '/\\');
				$absJobDir = $docRoot . '/upload/mf_catalog_import/jobs';
				$absJobPath = $docRoot . '/' . $relJobPath;
				if (!is_dir($absJobDir) && !@mkdir($absJobDir, 0755, true) && !is_dir($absJobDir))
				{
					$mfCeImportError = 'Не удалось создать каталог upload/mf_catalog_import/jobs/.';
				}
				elseif (!@move_uploaded_file($tmp, $absJobPath))
				{
					$mfCeImportError = 'Не удалось сохранить CSV на сервере.';
					if (is_dir($absJobDir) && !is_writable($absJobDir))
					{
						$mfCeImportError .= ' Каталог недоступен для записи от имени PHP (владелец/chmod пула php-fpm).';
					}
				}
				else
				{
					$importUserId = (int)$USER->GetID();
					$newJobId = mf_catalog_import_job_insert([
						'UF_TOKEN' => $jobToken,
						'UF_USER_ID' => $importUserId,
						'UF_STATUS' => 'pending',
						'UF_FILE_PATH' => $relJobPath,
						'UF_ORIG_NAME' => $origName,
						'UF_FILE_SIZE' => (int)@filesize($absJobPath),
						'UF_IBLOCK_ID' => $iblockId,
						'UF_ROWS_TOTAL' => 0,
						'UF_ROWS_DONE' => 0,
					]);
					if ($newJobId <= 0)
					{
						@unlink($absJobPath);
						$mfCeImportError = 'Не удалось создать задание импорта.';
					}
					else
					{
						$q = [
							'catalog_import_job' => $newJobId,
							'token' => $jobToken,
						];
						if (defined('LANGUAGE_ID') && (string)LANGUAGE_ID !== '')
						{
							$q['lang'] = (string)LANGUAGE_ID;
						}
						LocalRedirect($APPLICATION->GetCurPage() . '?' . http_build_query($q));
					}
				}
			}
		}
	}
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
		$brandIds = mf_ce_element_ids_for_brand_value($iblockId, $brandFilter, $onlyActive);
		// Несовпадение по TRIM между списком и GetList больше не возможно; при пустом списке id — пустая выгрузка.
		$filter['ID'] = $brandIds === [] ? -1 : $brandIds;
	}

	$select = [
		'ID',
		'NAME',
		'CODE',
		'IBLOCK_SECTION_ID',
		'PREVIEW_PICTURE',
		'DETAIL_PICTURE',
		'PREVIEW_TEXT',
		'DETAIL_TEXT',
		'PROPERTY_MF_BRAND',
		'PROPERTY_MF_BRAND_NORM',
		'PROPERTY_CML2_ARTICLE',
		'PROPERTY_MF_ARTICLE_NORM',
		'PROPERTY_OEM',
		'PROPERTY_MF_EXT_IMAGES',
		'PROPERTY_MORE_PHOTO',
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
		while (ob_get_level() > 0)
		{
			ob_end_clean();
		}
		if (!headers_sent())
		{
			header('HTTP/1.1 500 Internal Server Error');
			header('Content-Type: text/plain; charset=utf-8');
		}
		echo 'Ошибка выгрузки: ' . $e->getMessage();
		exit(1);
	}
}

// ——— Form ———

$APPLICATION->SetTitle('Выгрузка и загрузка каталога (CSV)');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$mfCeShowJobPanel = false;
$mfCeJobIdForJs = 0;
$mfCeJobTokenForJs = '';
if (is_array($mfCeViewJob) && (int)($mfCeViewJob['ID'] ?? 0) > 0)
{
	$mfCeViewJob = mf_catalog_import_job_get((int)$mfCeViewJob['ID']) ?? $mfCeViewJob;
	$jst0 = (string)($mfCeViewJob['UF_STATUS'] ?? '');
	if ($jst0 === 'done' && !empty($mfCeViewJob['UF_RESULT_JSON']))
	{
		$decSt = json_decode((string)$mfCeViewJob['UF_RESULT_JSON'], true);
		if (is_array($decSt))
		{
			$mfCeImportReport = ['ok' => true, 'stats' => $decSt];
		}
	}
	elseif ($jst0 === 'failed' && $mfCeImportError === null && !empty($mfCeViewJob['UF_ERROR_TEXT']))
	{
		$mfCeImportError = (string)$mfCeViewJob['UF_ERROR_TEXT'];
	}
	$mfCeShowJobPanel = in_array($jst0, ['pending', 'running'], true);
	$mfCeJobIdForJs = (int)$mfCeViewJob['ID'];
	$mfCeJobTokenForJs = (string)($mfCeViewJob['UF_TOKEN'] ?? '');
}

$mfCePageClean = (string)($APPLICATION->GetCurPage() ?? '');
$mfCeQClean = [
	'lang' => (defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru'),
];
$mfCeNewUploadUrl = $mfCePageClean . (strpos($mfCePageClean, '?') !== false ? '&' : '?') . http_build_query($mfCeQClean);

if ($mfCeImportError !== null && $mfCeImportError !== '')
{
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => mf_ce_esc($mfCeImportError)]);
}

if (is_array($mfCeImportReport))
{
	if (empty($mfCeImportReport['ok']))
	{
		\CAdminMessage::ShowMessage([
			'TYPE' => 'ERROR',
			'MESSAGE' => mf_ce_esc((string)($mfCeImportReport['message'] ?? 'Ошибка импорта')),
		]);
	}
	else
	{
		$st = $mfCeImportReport['stats'] ?? [];
		$tUp = (int)($st['updated'] ?? 0);
		$tNoId = (int)($st['skipped_no_id'] ?? 0);
		$tRed = (int)($st['skipped_redirect'] ?? 0);
		$tEmpty = (int)($st['skipped'] ?? 0);
		$tBrandSkip = (int)($st['skipped_brand'] ?? 0);
		$errs = $st['errors'] ?? [];
		$msg = 'Импорт завершён. Обновлено элементов: ' . $tUp
			. '; пропущено строк без id: ' . $tNoId
			. '; пропущено редиректов: ' . $tRed
			. '; пропущено брендов из списка «не сопоставлять»: ' . $tBrandSkip
			. '; пустых строк: ' . $tEmpty . '.';
		\CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => $msg]);
		if ($errs !== [])
		{
			$slice = array_slice($errs, 0, 12);
			\CAdminMessage::ShowMessage([
				'TYPE' => 'ERROR',
				'MESSAGE' => mf_ce_esc('Предупреждения и ошибки (до 12): ' . implode(' | ', $slice)),
			]);
		}
	}
}

$langUi = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';

@set_time_limit(300);
@ini_set('memory_limit', '512M');

$mfCeBrandCodesForSelect = ['MF_BRAND'];
$mfCeBrandMeta = function_exists('mf_ce_load_brand_choices_meta')
	? mf_ce_load_brand_choices_meta($iblockId, true, $mfCeBrandCodesForSelect)
	: ['brands' => mf_ce_load_brand_choices($iblockId, true, $mfCeBrandCodesForSelect), 'source' => 'unknown', 'redis_key' => ''];
$mfCeBrandChoices = (array)($mfCeBrandMeta['brands'] ?? []);
$mfCeBrandChoicesSource = (string)($mfCeBrandMeta['source'] ?? '');
$mfCeBrandChoicesRedisKey = (string)($mfCeBrandMeta['redis_key'] ?? '');

// Тяжёлый GROUP BY по всем значениям MF_BRAND* — только по явному запросу (иначе страница «обрывается» по таймауту PHP/nginx).
$mfCeShowOrphans = (string)($_GET['mf_ce_show_orphans'] ?? '') === 'Y';
$mfCeOrphanBrands = [];
if ($mfCeShowOrphans)
{
	$mfCeOrphanBrands = mf_ce_brands_only_on_non_exportable_elements($iblockId, 400);
}

$mfCeOrphansUrl = $mfCePageClean . (strpos($mfCePageClean, '?') !== false ? '&' : '?')
	. http_build_query(array_merge($mfCeQClean, ['mf_ce_show_orphans' => 'Y']));

?>
<form method="post" action="<?= mf_ce_esc((string)$APPLICATION->GetCurPage()) ?>?lang=<?= mf_ce_esc($langUi) ?>">
	<?= bitrix_sessid_post() ?>
	<input type="hidden" name="mf_catalog_export_do" value="Y" />

	<p class="adm-info-message" style="max-width:720px">
		Каталог: инфоблок ID <strong><?= (int)$iblockId ?></strong>.
		В файл всегда входят колонки: id, бренд, артикул, OEM, наименование, <strong>цепочка разделов</strong> (от корня через «<strong> =&gt; </strong>», как в навигации каталога), краткий и детальный текст, SEO (title / description / keywords), ЧПУ (slug), <strong>все URL фото как на сайте</strong> в одной ячейке через « | »
		(все MF_EXT_IMAGES при наличии, иначе все фото из метаданных аналогов, иначе при непустой галерее на деталке — как в шаблоне: слоты MORE_PHOTO или картинки анонс/деталь — по слотам URL через <code>mf_mf_product_img_url</code> (как после подмены SRC на <code>/mf-img/…</code>), иначе только превью и при отличии — деталь в <code>/upload/</code>). Без «фиктивного» <code>/mf-img/.../0001.jpg</code>, если на карточке нет ни одного изображения.
		Редиректы (MF_IS_REDIRECT) не выгружаются.
		Долгая выгрузка больше не держит сессию заблокированной: параллельно можно открывать витрину и корзину в других вкладках.
		XLSX — ZIP со сжатием, при тех же данных файл обычно меньше CSV; на очень больших каталогах надёжнее CSV (быстрее, меньше таймаутов).
		По желанию ограничьте выгрузку <strong>брендом</strong> (список в селекте — уникальные значения свойства <code>MF_BRAND</code> у активных выгружаемых товаров).
		Список брендов в селекте кэшируется в <strong>Redis</strong> (те же BITRIX_REDIS_* что у кэша Битрикс); обновление — кроном, скрипт <code>/mf_refresh_ce_brand_choices_cache.php</code> (см. <code>crontab/refresh_ce_brand_choices_cache_bitrix.sh</code>). Если ключа ещё нет, при открытии страницы выполняется тяжёлый SQL.
		Список брендов в селекте кэшируется в <strong>Redis</strong> (те же BITRIX_REDIS_* что у кэша Битрикс); обновление — кроном, скрипт <code>/mf_refresh_ce_brand_choices_cache.php</code> (см. <code>crontab/refresh_ce_brand_choices_cache_bitrix.sh</code>). Если ключа ещё нет, при открытии страницы выполняется тяжёлый SQL.
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
				<div style="margin-top:6px;color:#666;font-size:12px;">
					В селекте <strong><?= count($mfCeBrandChoices) ?></strong> брендов
					(источник: <code><?= mf_ce_esc($mfCeBrandChoicesSource) ?></code>).
					<?php if ($mfCeBrandChoicesSource === 'redis'): ?>
						Ключ: <code style="font-size:11px"><?= mf_ce_esc($mfCeBrandChoicesRedisKey) ?></code>.
					<?php endif; ?>
					<?php if (count($mfCeBrandChoices) < mf_ce_brand_choices_min_sanity_count()): ?>
						<span style="color:#c00">Мало брендов — возможно битый Redis-кэш. Прогрейте:
						<code>php mf_refresh_ce_brand_choices_cache.php</code> или откройте с <code>MF_CE_BRAND_CHOICES_REDIS=N</code>.</span>
					<?php endif; ?>
				</div>
				<div style="margin-top:4px;color:#666;font-size:12px;">Список — только <code>MF_BRAND</code> (без <code>MF_BRAND_NORM</code>). Фильтр выгрузки по выбранному бренду ищет совпадение в <code>MF_BRAND</code> или <code>MF_BRAND_NORM</code>. Снимите «только активные» — в файл попадут и неактивные; в селекте по-прежнему только активные бренды.</div>
			</td>
		</tr>
	</table>

	<br />
	<input type="submit" class="adm-btn-save" value="Скачать выгрузку" />
</form>

<hr style="margin:28px 0;border:none;border-top:1px solid #e0e0e0" />

<h2 class="adm-detail-title">Загрузка CSV (обновление по id)</h2>
<?php
if ($mfCeShowJobPanel && $mfCeJobIdForJs > 0 && $mfCeJobTokenForJs !== '')
{
	$sessCe = (string)bitrix_sessid();
	$langQ = (defined('LANGUAGE_ID') && (string)LANGUAGE_ID !== '') ? '&lang=' . rawurlencode((string)LANGUAGE_ID) : '';
	$pollUrl0 = $mfCePageClean . (strpos($mfCePageClean, '?') !== false ? '&' : '?')
		. 'mf_catalog_job_poll=1&job=' . $mfCeJobIdForJs . '&token=' . rawurlencode($mfCeJobTokenForJs) . $langQ;
	$nudgeUrl0 = $mfCePageClean . (strpos($mfCePageClean, '?') !== false ? '&' : '?')
		. 'mf_catalog_job_nudge=1&job=' . $mfCeJobIdForJs . '&token=' . rawurlencode($mfCeJobTokenForJs) . $langQ;
	?>
	<div class="adm-info-message" id="mf_ce_job_panel" style="max-width:900px;margin-bottom:16px">
		<iframe src="<?= mf_ce_esc($nudgeUrl0) ?>" style="width:0;height:0;border:0;position:absolute;left:-9999px" tabindex="-1" title=""></iframe>
		<div id="mf_ce_job_text">Подготовка импорта…</div>
		<div style="height:10px;background:#e8e8e8;border-radius:4px;margin:10px 0;overflow:hidden">
			<div id="mf_ce_job_bar" style="height:100%;width:0;background:#1d54a8;transition:width .2s"></div>
		</div>
		<p style="margin:0 0 8px 0;font-size:12px;color:#666">
			Импорт выполняется в фоне без лимита времени PHP. В подписи — сколько товаров уже обновлено и сколько строк осталось обработать. Опрос — примерно раз в <strong>1,2&nbsp;с</strong>.
		</p>
		<a href="<?= mf_ce_esc($mfCeNewUploadUrl) ?>">Новая загрузка (без ожидания)</a>
	</div>
	<script>
	(function () {
		var jobId = <?= (int)$mfCeJobIdForJs ?>;
		var token = <?= json_encode($mfCeJobTokenForJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
		var sess = <?= json_encode($sessCe, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
		var pollUrl = <?= json_encode($pollUrl0, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
		var runUrl = <?= json_encode($mfCePageClean, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
		var started = false;
		function setBar(pct) {
			var b = document.getElementById('mf_ce_job_bar');
			if (b) { b.style.width = Math.max(0, Math.min(100, pct)) + '%'; }
		}
		function setText(t) {
			var x = document.getElementById('mf_ce_job_text');
			if (x) { x.textContent = t; }
		}
		function startRun() {
			if (started) { return; }
			started = true;
			var fd = new FormData();
			fd.append('mf_catalog_job_run', 'Y');
			fd.append('job', String(jobId));
			fd.append('token', token);
			fd.append('sessid', sess);
			fetch(runUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
		}
		function poll() {
			fetch(pollUrl, { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d || d.ok === false) {
						setText('Не удалось получить статус. Обновите страницу.');
						return;
					}
					var st = d.status || '';
					var done = parseInt(d.rows_done, 10) || 0;
					var tot = parseInt(d.rows_total, 10) || 0;
					var ppRaw = d.progress_pct;
					var pctDb = ppRaw !== undefined && ppRaw !== null ? parseInt(ppRaw, 10) : NaN;
					var pct = !isFinite(pctDb) ? NaN : Math.max(0, Math.min(100, pctDb));
					if (!isFinite(pct) && tot > 0) {
						pct = Math.round(100 * done / tot);
					}
					setBar(isFinite(pct) ? pct : (st === 'done' ? 100 : 0));
					var note = (typeof d.progress_note === 'string' && d.progress_note.trim() !== '')
						? d.progress_note.trim()
						: 'Обновление товаров…';
					if (d.stale_hint) {
						note += '. ' + d.stale_hint;
					} else if (d.progress_at) {
						note += ' (обновлено ' + d.progress_at + ')';
					}
					if (st === 'pending' || st === 'running') {
						setText(note + (tot > 0 ? (' — строк ' + done + ' / ' + tot) : ''));
						if (st === 'pending') { startRun(); }
						setTimeout(poll, 1200);
						return;
					}
					if (st === 'done') {
						setBar(100);
						setText('Готово. Идёт обновление страницы…');
						location.reload();
						return;
					}
					if (st === 'failed') {
						setBar(0);
						setText('Ошибка: ' + (d.error || 'неизвестно'));
						return;
					}
					setText('Статус: ' + st);
					setTimeout(poll, 1200);
				})
				.catch(function () {
					setText('Ошибка сети при опросе статуса.');
					setTimeout(poll, 2500);
				});
		}
		startRun();
		poll();
	})();
	</script>
	<?php
}
?>
<form method="post" enctype="multipart/form-data" action="<?= mf_ce_esc((string)$APPLICATION->GetCurPage()) ?>?lang=<?= mf_ce_esc($langUi) ?>">
	<?= bitrix_sessid_post() ?>
	<input type="hidden" name="mf_catalog_import_do" value="Y" />

	<p class="adm-info-message" style="max-width:720px">
		Файл должен быть в <strong>том же формате</strong>, что и выгрузка CSV: первая строка — заголовки, разделитель полей «<strong>;</strong>», кодировка UTF-8 (с BOM или без).
		Строки с <strong>id</strong>, существующим в инфоблоке <?= (int)$iblockId ?> и не являющимся редиректом (MF_IS_REDIRECT), будут обновлены.
		Новые товары этим способом <strong>не создаются</strong>.
		Свойство <strong>MF_UNIQ_KEY</strong> и нормализованный артикул <strong>MF_ARTICLE_NORM</strong> пересчитываются из колонок «Артикул» и «Бренд» по тем же правилам, что и импорт остатков с <strong>--brand-dict=Y</strong>: подключается <code>mf_brand_dict.php</code> (канон бренда, список пропуска), затем нормализация как в <code>mf_update_supplier_stock.php</code>.
		Колонка «Фото»: непустое значение задаёт множественное свойство <strong>MF_EXT_IMAGES</strong> — все URL из ячейки, разделённые в выгрузке через « | » (один URL без разделителя тоже сохраняется); пустая ячейка — внешние URL из свойства не меняем.
		«Раздел товара»: цепочка <strong>Родитель =&gt; Дочерний =&gt; …</strong> (как в выгрузке; для старых CSV допускается « / ») или одно имя раздела — подбирается активный раздел; если цепочка не найдена, остальные поля строки всё равно сохраняются, привязку к разделу не меняем.
		Большие файлы обрабатываются <strong>в фоне</strong> с индикатором прогресса (как импорт внешних прайсов): таймауты PHP снимаются, сессия не блокирует другие вкладки.
	</p>

	<table class="adm-detail-content-table edit-table" style="max-width:920px">
		<tr>
			<td class="adm-detail-content-cell-l" width="35%">CSV-файл</td>
			<td>
				<input type="file" name="mf_catalog_csv" accept=".csv,text/csv" required />
			</td>
		</tr>
	</table>
	<br />
	<input type="submit" class="adm-btn-save" value="Загрузить и обновить товары" />
</form>

<details class="adm-detail-content-table" style="max-width:920px;margin-top:24px"<?= $mfCeShowOrphans ? ' open' : '' ?>>
	<summary style="cursor:pointer;font-weight:600">Бренды только у невыгружаемых элементов (редирект, копии workflow…)</summary>
	<?php if (!$mfCeShowOrphans): ?>
		<p style="margin:10px 0;color:#666;font-size:13px">
			Таблица не загружена при открытии страницы (тяжёлый запрос к БД).
			<a href="<?= mf_ce_esc($mfCeOrphansUrl) ?>">Загрузить список</a>
			— может занять 10–60 с на большом каталоге.
		</p>
	<?php else: ?>
	<p class="adm-info-message" style="margin-top:10px">
		Ниже — значения из MF_BRAND / MF_BRAND_NORM, которые <strong>ни разу не встречаются у выгружаемого товара</strong> (как в списке брендов выше),
		но есть у других строк каталога (обычно редиректы). Это и есть «мусорные хвосты» для выгрузи/витрины. Реальные бренды с нулём товаров здесь не попадут — у них просто не будет строки в свойствах.
	</p>
	<?php if ($mfCeOrphanBrands === []): ?>
		<p style="color:#060">Таких значений не найдено (или инфоблок без MF_BRAND / MF_BRAND_NORM).</p>
	<?php else: ?>
		<p style="color:#666;font-size:12px">Показано до 400 строк, сортировка: сколько всего элементов с этим текстом в свойстве (редиректы и пр.) — по убыванию.</p>
		<div style="max-height:320px;overflow:auto;border:1px solid #e0e0e0">
		<table class="adm-list-table" style="width:100%;font-size:12px">
			<thead><tr class="heading"><td>Значение в свойстве</td><td style="width:120px">Всего элементов*</td></tr></thead>
			<tbody>
			<?php foreach ($mfCeOrphanBrands as $row): ?>
				<tr>
					<td style="word-break:break-word"><?= mf_ce_esc($row['brand']) ?></td>
					<td style="text-align:center"><?= (int)$row['cnt_any'] ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<p style="color:#666;font-size:11px">* Сколько разных элементов инфоблока имеют это значение в MF_BRAND или MF_BRAND_NORM (все типы строк, без фильтра «выгружаемый»).</p>
	<?php endif; ?>
	<?php endif; ?>
</details>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
