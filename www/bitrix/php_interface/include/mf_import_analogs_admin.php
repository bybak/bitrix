<?php

declare(strict_types=1);

use Bitrix\Main\Loader;

if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
{
	die('Admin only');
}

global $APPLICATION, $USER;

if (!is_object($APPLICATION))
{
	die('No $APPLICATION');
}

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
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не найден файл библиотеки аналогов: ' . $analogsLib]);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}
require_once $analogsLib;

$iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? 0);
$elementId = (int)($_REQUEST['ID'] ?? 0);
$backUrl = (string)($_REQUEST['back_url'] ?? '');

if ($iblockId <= 0 || $elementId <= 0)
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не указан IBLOCK_ID или ID элемента.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

$perm = \CIBlock::GetPermission($iblockId);
if ($perm < 'W')
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Недостаточно прав на изменение инфоблока.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

$el = \CIBlockElement::GetList([], ['=ID' => $elementId, '=IBLOCK_ID' => $iblockId], false, false, ['ID', 'NAME'])->Fetch();
if (!$el)
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Элемент не найден.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

$elementName = (string)($el['NAME'] ?? '');

function mf_analogs_escape(string $s): string
{
	if (function_exists('htmlspecialcharsbx'))
	{
		return (string)htmlspecialcharsbx($s);
	}
	return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mf_analogs_parse_list(string $text): array
{
	$lines = preg_split('~\\R+~u', $text) ?: [];
	$out = [];
	foreach ($lines as $line)
	{
		$line = trim((string)$line);
		if ($line === '')
		{
			continue;
		}
		// CSV-ish: split by ; , tab
		$parts = preg_split('~[;,\t]+~', $line) ?: [];
		$val = trim((string)($parts[0] ?? ''));
		if ($val === '' || $val === 'id' || $val === 'xml_id' || $val === 'code' || $val === 'article')
		{
			continue;
		}
		$out[] = $val;
	}
	$out = array_values(array_unique($out));
	return $out;
}

function mf_analogs_to_utf8(string $s): string
{
	$s = (string)$s;
	if ($s === '')
	{
		return '';
	}
	if (mb_check_encoding($s, 'UTF-8'))
	{
		return $s;
	}
	// Common case for supplier CSVs in RU.
	$converted = @iconv('CP1251', 'UTF-8//IGNORE', $s);
	return is_string($converted) && $converted !== '' ? $converted : $s;
}

function mf_analogs_norm_article(string $s): string
{
	$s = mb_strtoupper(trim((string)$s));
	$s = preg_replace('~[^A-Z0-9]+~', '', $s) ?? '';
	return $s;
}

function mf_analogs_norm_brand(string $s): string
{
	$s = mb_strtoupper(trim((string)$s));
	$s = str_replace('Ё', 'Е', $s);
	$s = preg_replace('~[^A-ZА-Я0-9]+~u', '', $s) ?? '';
	return $s;
}

/**
 * Parse supplier CSV with header:
 *   Бренд;Артикул;Остаток;Цена;Картинки
 *
 * Supports comment lines starting with "#".
 *
 * @return array<int, array{brand:string,article:string,stock:?float,price:?float,images:array<int,string>,raw:string}>
 */
function mf_analogs_parse_supplier_csv(string $text): array
{
	$text = mf_analogs_to_utf8($text);
	$lines = preg_split('~\\R+~u', $text) ?: [];
	$rows = [];

	$header = null;
	$idx = [];
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

		$brand = trim((string)($cols[$idx['Бренд'] ?? -1] ?? ''));
		$article = trim((string)($cols[$idx['Артикул'] ?? -1] ?? ''));
		if ($brand === '' || $article === '')
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
				if ($p !== '')
				{
					$images[] = $p;
				}
			}
		}

		$rows[] = [
			'brand' => $brand,
			'article' => $article,
			'stock' => $stock,
			'price' => $price,
			'images' => $images,
			'raw' => $line,
		];
	}

	return $rows;
}

function mf_analogs_find_product_id_by_brand_article(int $iblockId, string $brand, string $article): ?int
{
	$brandNorm = mf_analogs_norm_brand($brand);
	$articleNorm = mf_analogs_norm_article($article);
	if ($brandNorm === '' || $articleNorm === '')
	{
		return null;
	}

	// Prefer normalized properties (our catalog uses them heavily).
	$r = \CIBlockElement::GetList(
		[],
		[
			'=IBLOCK_ID' => $iblockId,
			'=ACTIVE' => 'Y',
			'=PROPERTY_MF_BRAND_NORM' => $brandNorm,
			'=PROPERTY_MF_ARTICLE_NORM' => $articleNorm,
		],
		false,
		['nTopCount' => 1],
		['ID']
	)->Fetch();
	if ($r && (int)$r['ID'] > 0)
	{
		return (int)$r['ID'];
	}

	// Fallback: match by article only, then choose best candidate by brand "contains".
	$candidates = [];
	$rs = \CIBlockElement::GetList(
		['ID' => 'ASC'],
		[
			'=IBLOCK_ID' => $iblockId,
			'=ACTIVE' => 'Y',
			'=PROPERTY_MF_ARTICLE_NORM' => $articleNorm,
		],
		false,
		['nTopCount' => 5],
		['ID', 'PROPERTY_MF_BRAND', 'PROPERTY_MF_BRAND_NORM', 'PROPERTY_CML2_ARTICLE']
	);
	while ($x = $rs->Fetch())
	{
		$id = (int)($x['ID'] ?? 0);
		if ($id <= 0) continue;
		$candidates[] = $x;
	}
	if (empty($candidates))
	{
		$rs = \CIBlockElement::GetList(
			['ID' => 'ASC'],
			[
				'=IBLOCK_ID' => $iblockId,
				'=ACTIVE' => 'Y',
				'=PROPERTY_CML2_ARTICLE' => $article,
			],
			false,
			['nTopCount' => 5],
			['ID', 'PROPERTY_MF_BRAND', 'PROPERTY_MF_BRAND_NORM', 'PROPERTY_CML2_ARTICLE']
		);
		while ($x = $rs->Fetch())
		{
			$id = (int)($x['ID'] ?? 0);
			if ($id <= 0) continue;
			$candidates[] = $x;
		}
	}

	if (!empty($candidates))
	{
		$brandLower = mb_strtolower(trim($brand));
		$bestId = 0;
		$bestScore = -1;
		foreach ($candidates as $x)
		{
			$id = (int)($x['ID'] ?? 0);
			if ($id <= 0) continue;
			$bn = (string)($x['PROPERTY_MF_BRAND_NORM_VALUE'] ?? '');
			$br = (string)($x['PROPERTY_MF_BRAND_VALUE'] ?? '');
			$score = 0;
			if ($bn !== '' && str_contains($bn, $brandNorm)) $score += 2;
			if ($brandLower !== '' && $br !== '' && str_contains(mb_strtolower($br), $brandLower)) $score += 1;
			if ($score > $bestScore || ($score === $bestScore && ($bestId === 0 || $id < $bestId)))
			{
				$bestScore = $score;
				$bestId = $id;
			}
		}
		if ($bestId > 0)
		{
			return $bestId;
		}
	}

	return null;
}

function mf_analogs_generate_unique_code(int $iblockId, string $base): string
{
	$base = trim($base);
	if ($base === '')
	{
		$base = 'analog';
	}

	if (class_exists('CUtil'))
	{
		$code = (string)\CUtil::translit($base, 'ru', [
			'change_case' => 'L',
			'replace_space' => '-',
			'replace_other' => '-',
			'delete_repeat_replace' => true,
			'use_google' => false,
		]);
	}
	else
	{
		$code = strtolower(preg_replace('~[^a-z0-9]+~i', '-', $base) ?? $base);
	}

	$code = trim($code, '-');
	if ($code === '')
	{
		$code = 'analog';
	}

	// Ensure unique in this iblock.
	$try = $code;
	$i = 1;
	while (true)
	{
		$exists = \CIBlockElement::GetList(
			[],
			['=IBLOCK_ID' => $iblockId, '=CODE' => $try],
			false,
			['nTopCount' => 1],
			['ID']
		)->Fetch();
		if (!$exists)
		{
			return $try;
		}
		$i++;
		$try = $code . '-' . $i;
		if ($i > 50)
		{
			// Fallback to timestamp-based suffix.
			return $code . '-' . time();
		}
	}
}

function mf_analogs_create_product_stub(int $iblockId, string $brand, string $article, array $images = []): ?int
{
	$brand = trim($brand);
	$article = trim($article);
	if ($brand === '' || $article === '')
	{
		return null;
	}

	$brandNorm = mf_analogs_norm_brand($brand);
	$articleNorm = mf_analogs_norm_article($article);

	$name = $brand . ' ' . $article;
	$codeBase = $brandNorm . '-' . $articleNorm;
	$code = mf_analogs_generate_unique_code($iblockId, $codeBase !== '-' ? $codeBase : $name);

	$el = new \CIBlockElement();
	$newId = (int)$el->Add([
		'IBLOCK_ID' => $iblockId,
		'ACTIVE' => 'Y',
		'NAME' => $name,
		'CODE' => $code,
		'XML_ID' => 'ANALOG_' . $brandNorm . '_' . $articleNorm,
	]);

	if ($newId <= 0)
	{
		return null;
	}

	// Best effort: set catalog fields we rely on for matching + hide from catalog by default.
	$props = [
		'MF_BRAND' => $brand,
		'CML2_ARTICLE' => $article,
		'MF_BRAND_NORM' => $brandNorm,
		'MF_ARTICLE_NORM' => $articleNorm,
		// This property may not exist yet; SetPropertyValuesEx will ignore unknown codes.
		'MF_SHOW_IN_CATALOG' => 'N',
	];
	if (!empty($images))
	{
		$props['MF_EXT_IMAGES'] = array_values(array_filter(array_map('trim', $images), static fn($s) => $s !== ''));
	}
	\CIBlockElement::SetPropertyValuesEx($newId, $iblockId, $props);

	// Ensure the element is searchable immediately.
	try
	{
		if (class_exists('\\Bitrix\\Main\\Loader') && \Bitrix\Main\Loader::includeModule('search'))
		{
			\CIBlockElement::UpdateSearch($newId, true);
		}
	}
	catch (\Throwable $e)
	{
		// ignore
	}

	return $newId;
}

function mf_analogs_find_element_ids(int $iblockId, array $values, string $matchBy): array
{
	$found = [];
	$notFound = [];

	foreach ($values as $v)
	{
		$v = trim((string)$v);
		if ($v === '')
		{
			continue;
		}

		$filter = ['=IBLOCK_ID' => $iblockId, '=ACTIVE' => 'Y'];
		if ($matchBy === 'ID')
		{
			if (!ctype_digit($v))
			{
				$notFound[] = $v;
				continue;
			}
			$filter['=ID'] = (int)$v;
		}
		elseif ($matchBy === 'XML_ID')
		{
			$filter['=XML_ID'] = $v;
		}
		elseif ($matchBy === 'CODE')
		{
			$filter['=CODE'] = $v;
		}
		elseif ($matchBy === 'NAME')
		{
			$filter['=NAME'] = $v;
		}
		elseif ($matchBy === 'CML2_ARTICLE')
		{
			$filter['=PROPERTY_CML2_ARTICLE'] = $v;
		}
		else
		{
			$notFound[] = $v;
			continue;
		}

		$r = \CIBlockElement::GetList([], $filter, false, ['nTopCount' => 1], ['ID'])->Fetch();
		if ($r && (int)$r['ID'] > 0)
		{
			$found[] = (int)$r['ID'];
		}
		else
		{
			$notFound[] = $v;
		}
	}

	$found = array_values(array_unique($found));
	return ['found' => $found, 'not_found' => $notFound];
}

$errors = [];
$report = null;

$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($requestMethod === 'POST' && check_bitrix_sessid())
{
	$mode = (string)($_POST['MODE'] ?? 'merge'); // merge|replace

	$text = '';
	if (!empty($_FILES['FILE']['tmp_name']) && is_uploaded_file($_FILES['FILE']['tmp_name']))
	{
		$text = (string)file_get_contents((string)$_FILES['FILE']['tmp_name']);
	}
	else
	{
		$text = (string)($_POST['TEXT'] ?? '');
	}

	$textUtf = mf_analogs_to_utf8($text);

	// Autodetect supplier CSV by header.
	$looksLikeSupplierCsv =
		(stripos($textUtf, 'Бренд;') !== false && stripos($textUtf, ';Артикул') !== false)
		|| (stripos($textUtf, 'Бренд') !== false && stripos($textUtf, 'Артикул') !== false && stripos($textUtf, 'Остаток') !== false);

	$ids = [];
	$notFound = [];
	$matched = 0;
	$metaByAnalog = [];
	$created = 0;
	$createMissing = ((string)($_POST['CREATE_MISSING'] ?? 'Y') === 'Y');

	if ($looksLikeSupplierCsv)
	{
		$rows = mf_analogs_parse_supplier_csv($textUtf);
		if (empty($rows))
		{
			$errors[] = 'CSV не распознан или пуст. Проверьте заголовок: Бренд;Артикул;Остаток;Цена;Картинки';
		}
		else
		{
			foreach ($rows as $row)
			{
				$aid = mf_analogs_find_product_id_by_brand_article($iblockId, (string)$row['brand'], (string)$row['article']);
				if ((!$aid || $aid <= 0) && $createMissing)
				{
					$aid = mf_analogs_create_product_stub($iblockId, (string)$row['brand'], (string)$row['article'], (array)($row['images'] ?? []));
					if ($aid && $aid > 0)
					{
						$created++;
					}
				}
				if ($aid && $aid > 0)
				{
					$ids[] = (int)$aid;
					$matched++;
					$metaByAnalog[(int)$aid] = [
						'stock' => $row['stock'],
						'price' => $row['price'],
						'images' => $row['images'],
					];
				}
				else
				{
					$notFound[] = (string)$row['brand'] . ';' . (string)$row['article'];
				}
			}
		}
	}
	else
	{
		// Fallback: simple "one value per line" list.
		$matchBy = (string)($_POST['MATCH_BY'] ?? 'XML_ID');
		$values = mf_analogs_parse_list($textUtf);
		if (!empty($values))
		{
			$res = mf_analogs_find_element_ids($iblockId, $values, $matchBy);
			$ids = (array)($res['found'] ?? []);
			$notFound = (array)($res['not_found'] ?? []);
		}
	}

	$ids = array_values(array_unique(array_map('intval', $ids)));
	$ids = array_values(array_diff($ids, [$elementId])); // never reference itself

	if (empty($errors) && empty($ids) && empty($notFound))
	{
		$errors[] = 'Список аналогов пуст. Загрузите файл или вставьте список.';
	}

	if (empty($errors))
	{
		$source = 'admin:' . (int)($USER && is_object($USER) ? $USER->GetID() : 0);
		if ($mode === 'replace')
		{
			$res2 = mf_analogs_replace_for_product($elementId, $ids, $source);
		}
		else
		{
			$res2 = mf_analogs_merge_for_product($elementId, $ids, $source);
		}

		// Save supplier metadata (stock/price/images) for directed (product -> analog) pairs.
		if ($looksLikeSupplierCsv && !empty($metaByAnalog) && function_exists('mf_analogs_meta_upsert'))
		{
			foreach ($metaByAnalog as $aid => $m)
			{
				// only save for actually linked items
				if (!in_array((int)$aid, $ids, true)) continue;
				mf_analogs_meta_upsert(
					$elementId,
					(int)$aid,
					isset($m['stock']) ? ($m['stock'] === null ? null : (float)$m['stock']) : null,
					isset($m['price']) ? ($m['price'] === null ? null : (float)$m['price']) : null,
					is_array($m['images'] ?? null) ? (array)$m['images'] : [],
					$source,
					'RUB'
				);

				// Also store images on the analog product itself (so its own detail page can show them).
				$imgs = is_array($m['images'] ?? null) ? array_values(array_filter(array_map('trim', (array)$m['images']), static fn($s) => $s !== '')) : [];
				if (!empty($imgs))
				{
					$has = false;
					$rsP = \CIBlockElement::GetProperty($iblockId, (int)$aid, ['sort' => 'asc'], ['CODE' => 'MF_EXT_IMAGES']);
					while ($pp = $rsP->Fetch())
					{
						$v = trim((string)($pp['VALUE'] ?? ''));
						if ($v !== '') { $has = true; break; }
					}
					if (!$has)
					{
						\CIBlockElement::SetPropertyValuesEx((int)$aid, $iblockId, ['MF_EXT_IMAGES' => $imgs]);
					}
				}
			}
		}

		$report = [
			'added' => count($ids),
			'total' => null,
			'not_found' => $notFound,
			'format' => $looksLikeSupplierCsv ? 'supplier_csv' : 'list',
			'created' => $created,
		];
	}
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$APPLICATION->SetTitle('Импорт аналогов');

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
	$msg = 'Импорт выполнен. Добавлено связей: ' . (int)$report['added'] . '.';
	if (($report['format'] ?? '') === 'supplier_csv')
	{
		$msg .= '<br>Формат: CSV поставщика (бренд+артикул).';
		$msg .= '<br>Создано новых товаров: ' . (int)($report['created'] ?? 0) . '.';
	}
	if (!empty($report['not_found']))
	{
		$msg .= '<br>Не найдены: <br><pre style="white-space:pre-wrap;max-height:200px;overflow:auto;">'
			. mf_analogs_escape(implode("\n", $report['not_found']))
			. '</pre>';
	}
	\CAdminMessage::ShowMessage([
		'TYPE' => 'OK',
		'MESSAGE' => $msg,
		'HTML' => true,
	]);
}

// context menu (back)
$aContext = [];
if ($backUrl !== '')
{
	$aContext[] = [
		'TEXT' => 'Назад к товару',
		'LINK' => $backUrl,
		'TITLE' => 'Вернуться на страницу товара',
		'ICON' => 'btn_list',
	];
}
$contextMenu = new \CAdminContextMenu($aContext);
$contextMenu->Show();

?>

<div style="max-width: 980px;">
	<h2 style="margin: 8px 0 12px 0;">Товар: <?= mf_analogs_escape($elementName) ?> (ID <?= (int)$elementId ?>)</h2>

	<form method="post" enctype="multipart/form-data">
		<?= bitrix_sessid_post() ?>
		<input type="hidden" name="IBLOCK_ID" value="<?= (int)$iblockId ?>">
		<input type="hidden" name="ID" value="<?= (int)$elementId ?>">
		<input type="hidden" name="back_url" value="<?= mf_analogs_escape($backUrl) ?>">

		<table class="adm-detail-content-table edit-table" style="width:100%;">
			<tbody>
			<tr>
				<td class="adm-detail-content-cell-l" width="40%">Хранение аналогов</td>
				<td class="adm-detail-content-cell-r">
					Отдельная таблица (Highload‑block) <code>mf_product_analogs</code> (пары товаров).
				</td>
			</tr>

			<tr>
				<td class="adm-detail-content-cell-l">Как искать аналоги</td>
				<td class="adm-detail-content-cell-r">
					<select name="MATCH_BY">
						<option value="XML_ID">XML_ID (рекомендуется)</option>
						<option value="ID">ID элемента</option>
						<option value="CODE">CODE (символьный код)</option>
						<option value="CML2_ARTICLE">CML2_ARTICLE (если есть)</option>
						<option value="NAME">NAME (точное совпадение)</option>
					</select>
					<div style="margin-top:6px;color:#666;">
						Поддерживается:
						<ul style="margin:6px 0 0 18px;">
							<li>CSV поставщика: <code>Бренд;Артикул;Остаток;Цена;Картинки</code> (каждая строка добавится как аналог к этому товару)</li>
							<li>Простой список: по одному значению на строку (ID/XML_ID/CODE/…)</li>
						</ul>
					</div>
				</td>
			</tr>

			<tr>
				<td class="adm-detail-content-cell-l">Режим</td>
				<td class="adm-detail-content-cell-r">
					<label style="margin-right:14px;">
						<input type="radio" name="MODE" value="merge" checked> Добавить к существующим
					</label>
					<label>
						<input type="radio" name="MODE" value="replace"> Полностью заменить
					</label>
				</td>
			</tr>

			<tr>
				<td class="adm-detail-content-cell-l">Если аналог не найден</td>
				<td class="adm-detail-content-cell-r">
					<label>
						<input type="checkbox" name="CREATE_MISSING" value="Y" checked>
						Создавать новый товар (скрыт из каталога по умолчанию)
					</label>
				</td>
			</tr>

			<tr>
				<td class="adm-detail-content-cell-l">Файл</td>
				<td class="adm-detail-content-cell-r">
					<input type="file" name="FILE" accept=".csv,.txt,text/plain">
				</td>
			</tr>

			<tr>
				<td class="adm-detail-content-cell-l">Или вставить список</td>
				<td class="adm-detail-content-cell-r">
					<textarea name="TEXT" rows="10" style="width:100%;max-width:740px;" placeholder="Например:\nA-12345\nA-99999\n..."></textarea>
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

