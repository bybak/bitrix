<?php
/**
 * Удаление «лишних» фото у канонических товаров после синка картинок с motor-force по CSV:
 * скрипт sync_motor_force_images_to_bitrix_from_csv.py может агрегировать галерею с нескольких страниц,
 * когда в каталоге были дубли (одинаковый MF_UNIQ_KEY, отдельные элементы-редиректы).
 *
 * По умолчанию обрабатываются только канонические элементы (PROPERTY_MF_IS_REDIRECT ≠ Y),
 * у которых по тому же MF_UNIQ_KEY существует хотя бы один редирект-дубль.
 * Режим --all-products обрабатывает все элементы инфоблока.
 *
 * На карточке товара (catalog.element bootstrap_v4) галерея берётся из свойства MF_EXT_IMAGES (URL, multiple),
 * иначе из MORE_PHOTO / схемы img. Очищаем оба: MORE_PHOTO (файлы) и MF_EXT_IMAGES (строки-URL) — по одному лимиту --keep-more.
 *
 * По умолчанию: обнулить галерею (0); опционально оставить первые N строк/файлов;
 * опционально выровнять DETAIL_PICTURE по PREVIEW_PICTURE.
 *
 * Запуск (DOCUMENT_ROOT — каталог www):
 *   php tools/mf_catalog_strip_merged_duplicate_images.php --dry-run
 *   php tools/mf_catalog_strip_merged_duplicate_images.php --apply
 *   php tools/mf_catalog_strip_merged_duplicate_images.php --apply --keep-more=1 --sync-detail-to-preview
 *
 * Внутри контейнера:
 *   php /var/www/html/tools/mf_catalog_strip_merged_duplicate_images.php --apply
 */
declare(strict_types=1);

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

Loader::includeModule('iblock');

// --- CLI -----------------------------------------------------------------

$argv = isset($GLOBALS['argv']) && is_array($GLOBALS['argv']) ? $GLOBALS['argv'] : [];

function arg_flag(array $argv, string $needle): bool
{
	foreach ($argv as $a)
	{
		if ($a === $needle)
		{
			return true;
		}
	}

	return false;
}

function arg_value(array $argv, string $prefix, ?string $default = null): ?string
{
	foreach ($argv as $a)
	{
		if (str_starts_with($a, $prefix))
		{
			return trim(substr($a, strlen($prefix)));
		}
	}

	return $default;
}

$iblockId = (int)(arg_value($argv, '--iblock-id=', '4'));
$dryRun = arg_flag($argv, '--dry-run') || !arg_flag($argv, '--apply');
$quiet = arg_flag($argv, '--quiet');
$syncDetailPreview = arg_flag($argv, '--sync-detail-to-preview');
$allProducts = arg_flag($argv, '--all-products');

$keepMore = (int)(arg_value($argv, '--keep-more=', '0'));

if (arg_flag($argv, '--help') || arg_flag($argv, '-h'))
{
	$exe = basename($argv[0] ?? 'mf_catalog_strip_merged_duplicate_images.php');
	fwrite(STDOUT, <<<TXT
MF: обрезать подмешанные галереи у канонических SKU с редирект-дублями.

  --iblock-id=4           инфоблок каталога
  --dry-run               только отчёт (по умолчанию если нет --apply)
  --apply                 записать изменения
  --keep-more=N           для MORE_PHOTO (файлы) и MF_EXT_IMAGES (URL на деталке): оставить первые N (0 — снять всё)
  --sync-detail-to-preview после правок выставить DETAIL_PICTURE = копия PREVIEW_PICTURE
  --all-products          обработать весь инфоблок (все элементы), без фильтра по дублям
  --quiet

Пример:
  php {$exe} --apply --keep-more=0

TXT);
	exit(0);
}

if ($iblockId <= 0)
{
	fwrite(STDERR, "Укажи --iblock-id\n");
	exit(2);
}

if ($keepMore < 0)
{
	fwrite(STDERR, "--keep-more не может быть отрицательным\n");
	exit(2);
}

$canonicalIds = [];
$uniqWithDup = [];

if ($allProducts)
{
	$rsAll = CIBlockElement::GetList(
		['ID' => 'ASC'],
		['IBLOCK_ID' => $iblockId],
		false,
		false,
		['ID', 'NAME', 'CODE', 'PROPERTY_MF_UNIQ_KEY', 'PREVIEW_PICTURE', 'DETAIL_PICTURE']
	);
	while ($e = $rsAll->Fetch())
	{
		$uniq = trim((string)($e['PROPERTY_MF_UNIQ_KEY_VALUE'] ?? ''));
		$canonicalIds[(int)$e['ID']] = [
			'id' => (int)$e['ID'],
			'name' => (string)$e['NAME'],
			'code' => (string)$e['CODE'],
			'uniq_key' => $uniq,
			'preview_id' => (int)($e['PREVIEW_PICTURE'] ?? 0),
			'detail_id' => (int)($e['DETAIL_PICTURE'] ?? 0),
		];
	}
}
else
{
	// --- Data: uniq_key => redirect_count >= 1 --------------------------------
	$rDir = CIBlockElement::GetList(
		['ID' => 'ASC'],
		[
			'IBLOCK_ID' => $iblockId,
			'=PROPERTY_MF_IS_REDIRECT' => 'Y',
		],
		false,
		false,
		['PROPERTY_MF_UNIQ_KEY']
	);
	while ($r = $rDir->Fetch())
	{
		$key = trim((string)($r['PROPERTY_MF_UNIQ_KEY_VALUE'] ?? ''));
		if ($key === '')
		{
			continue;
		}
		if (!isset($uniqWithDup[$key]))
		{
			$uniqWithDup[$key] = 0;
		}
		$uniqWithDup[$key]++;
	}

	if (!$uniqWithDup)
	{
		fwrite(STDOUT, "Нет элементов PROPERTY_MF_IS_REDIRECT=Y или пустые MF_UNIQ_KEY. Выход.\n");
		exit(0);
	}

	$uniqKeys = array_keys($uniqWithDup);
	$fetchCanonicalBatch = static function (int $iblockId, array $keysChunk) {
		return CIBlockElement::GetList(
			['ID' => 'ASC'],
			[
				'IBLOCK_ID' => $iblockId,
				'!=PROPERTY_MF_IS_REDIRECT' => 'Y',
				'=PROPERTY_MF_UNIQ_KEY' => $keysChunk,
			],
			false,
			false,
			['ID', 'NAME', 'CODE', 'PROPERTY_MF_UNIQ_KEY', 'PREVIEW_PICTURE', 'DETAIL_PICTURE']
		);
	};

	foreach (array_chunk($uniqKeys, 400) as $chunk)
	{
		$rs = $fetchCanonicalBatch($iblockId, $chunk);
		if (!$rs)
		{
			continue;
		}

		while ($e = $rs->Fetch())
		{
			$uniq = trim((string)($e['PROPERTY_MF_UNIQ_KEY_VALUE'] ?? ''));
			if ($uniq === '')
			{
				continue;
			}
			if (!isset($uniqWithDup[$uniq]) || $uniqWithDup[$uniq] < 1)
			{
				continue;
			}
			$canonicalIds[(int)$e['ID']] = [
				'id' => (int)$e['ID'],
				'name' => (string)$e['NAME'],
				'code' => (string)$e['CODE'],
				'uniq_key' => $uniq,
				'preview_id' => (int)($e['PREVIEW_PICTURE'] ?? 0),
				'detail_id' => (int)($e['DETAIL_PICTURE'] ?? 0),
			];
		}
	}
}

$totalCanon = count($canonicalIds);

if (!$canonicalIds)
{
	fwrite(STDOUT, "Не найдено канонических товаров с MF_UNIQ_KEY из групп с редиректами.\n");
	exit(0);
}

// --- Helpers --------------------------------------------------------------

/**
 * Удалить значения MORE_PHOTO кроме первых $keepFirst; вернуть [removed, kept].
 *
 * @return array{removed:int,kept:int,prop_exists:bool}
 */
function mf_strip_more_photo_keep(string $propCode, int $elId, int $iblockId, int $keepFirst, bool $apply): array
{
	$prop = CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $propCode])->Fetch();
	if (!$prop)
	{
		return ['removed' => 0, 'kept' => 0, 'prop_exists' => false];
	}

	$res = CIBlockElement::GetProperty(
		$iblockId,
		$elId,
		['sort' => 'asc'],
		['CODE' => $propCode]
	);
	$rows = [];
	while ($row = $res->Fetch())
	{
		if ((string)($row['VALUE'] ?? '') !== '' || (int)($row['VALUE'] ?? 0) > 0)
		{
			$rows[] = $row;
		}
	}
	$cnt = count($rows);
	if ($cnt <= $keepFirst)
	{
		return ['removed' => 0, 'kept' => $cnt, 'prop_exists' => true];
	}

	$removed = array_slice($rows, $keepFirst);

	$delMap = [];
	foreach ($removed as $r)
	{
		$pvid = (int)($r['PROPERTY_VALUE_ID'] ?? 0);
		if ($pvid <= 0)
		{
			continue;
		}
		$delMap[$pvid] = ['VALUE' => ['del' => 'Y']];
	}

	if (!$apply || $delMap === [])
	{
		return ['removed' => count($removed), 'kept' => $keepFirst, 'prop_exists' => true];
	}

	CIBlockElement::SetPropertyValuesEx($elId, $iblockId, [$propCode => $delMap]);

	return ['removed' => count($removed), 'kept' => $keepFirst, 'prop_exists' => true];
}

/**
 * Строковое множественное свойство (URL), как MF_EXT_IMAGES — оставить первые $keepFirst значений.
 *
 * @return array{removed:int,kept:int,prop_exists:bool}
 */
function mf_strip_string_mult_urls_keep(string $propCode, int $elId, int $iblockId, int $keepFirst, bool $apply): array
{
	$prop = CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $propCode])->Fetch();
	if (!$prop)
	{
		return ['removed' => 0, 'kept' => 0, 'prop_exists' => false];
	}
	if (($prop['PROPERTY_TYPE'] ?? '') !== 'S' || ($prop['MULTIPLE'] ?? '') !== 'Y')
	{
		return ['removed' => 0, 'kept' => 0, 'prop_exists' => false];
	}

	$res = CIBlockElement::GetProperty(
		$iblockId,
		$elId,
		['sort' => 'asc'],
		['CODE' => $propCode]
	);
	$urls = [];
	while ($row = $res->Fetch())
	{
		$u = trim((string)($row['VALUE'] ?? ''));
		if ($u !== '')
		{
			$urls[] = $u;
		}
	}
	$cnt = count($urls);
	if ($cnt <= $keepFirst)
	{
		return ['removed' => 0, 'kept' => $cnt, 'prop_exists' => true];
	}

	$newUrls = $keepFirst > 0 ? array_slice($urls, 0, $keepFirst) : [];

	if (!$apply)
	{
		return ['removed' => $cnt - $keepFirst, 'kept' => $keepFirst, 'prop_exists' => true];
	}

	if ($newUrls === [])
	{
		CIBlockElement::SetPropertyValuesEx($elId, $iblockId, [$propCode => false]);
	}
	else
	{
		CIBlockElement::SetPropertyValuesEx($elId, $iblockId, [$propCode => $newUrls]);
	}

	return ['removed' => $cnt - $keepFirst, 'kept' => count($newUrls), 'prop_exists' => true];
}

/** Деталь = копия превью (новый файл в b_file). */
function mf_sync_detail_to_preview_bitrix(int $elId, int $previewFileId): bool
{
	if ($previewFileId <= 0)
	{
		return false;
	}
	$rel = \CFile::GetPath($previewFileId);
	if ($rel === '' || !is_string($rel))
	{
		return false;
	}

	$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
	$abs = $root . '/' . ltrim($rel, '/');
	if (!is_file($abs))
	{
		return false;
	}
	$arFile = \CFile::MakeFileArray($abs);
	if (!$arFile || !is_array($arFile))
	{
		return false;
	}

	$elObj = new \CIBlockElement();
	return $elObj->Update($elId, ['DETAIL_PICTURE' => $arFile]);
}

// --- Run -----------------------------------------------------------------

$removedMoreSlots = 0;
$removedExtUrls = 0;
$syncedDetail = 0;
$skippedNoGalleryProps = 0;

foreach ($canonicalIds as $meta)
{
	$elId = $meta['id'];
	$rPhoto = mf_strip_more_photo_keep('MORE_PHOTO', $elId, $iblockId, $keepMore, !$dryRun);
	$rExt = mf_strip_string_mult_urls_keep('MF_EXT_IMAGES', $elId, $iblockId, $keepMore, !$dryRun);

	if (!$rPhoto['prop_exists'] && !$rExt['prop_exists'])
	{
		$skippedNoGalleryProps++;
		if (!$quiet)
		{
			fwrite(STDERR, "[skip] ELEMENT {$elId} ({$meta['code']}): нет свойств MORE_PHOTO и MF_EXT_IMAGES в инфоблоке.\n");
		}
	}

	if ($rPhoto['prop_exists'] && $rPhoto['removed'] > 0)
	{
		$removedMoreSlots += $rPhoto['removed'];
		if (!$quiet)
		{
			fwrite(STDOUT, sprintf(
				"[%s] element=%d code=%s uniq=%s MORE_PHOTO: removed=%d kept=%d\n",
				$dryRun ? 'dry-run' : 'apply',
				$elId,
				$meta['code'],
				$meta['uniq_key'],
				$rPhoto['removed'],
				$rPhoto['kept']
			));
		}
	}

	if ($rExt['prop_exists'] && $rExt['removed'] > 0)
	{
		$removedExtUrls += $rExt['removed'];
		if (!$quiet)
		{
			fwrite(STDOUT, sprintf(
				"[%s] element=%d code=%s uniq=%s MF_EXT_IMAGES: removed=%d kept=%d\n",
				$dryRun ? 'dry-run' : 'apply',
				$elId,
				$meta['code'],
				$meta['uniq_key'],
				$rExt['removed'],
				$rExt['kept']
			));
		}
	}

	if ($syncDetailPreview && $meta['preview_id'] > 0)
	{
		if ($dryRun)
		{
			if ($meta['detail_id'] !== $meta['preview_id'])
			{
				$syncedDetail++;
				if (!$quiet)
				{
					fwrite(STDOUT, sprintf("  [dry-run] would sync DETAIL <- PREVIEW (element=%d)\n", $elId));
				}
			}
		}
		else
		{
			$detailAfter = CIBlockElement::GetByID($elId)->Fetch();
			$did = (int)($detailAfter['DETAIL_PICTURE'] ?? 0);
			if ($did !== $meta['preview_id'])
			{
				if (mf_sync_detail_to_preview_bitrix($elId, $meta['preview_id']))
				{
					$syncedDetail++;
					if (!$quiet)
					{
						fwrite(STDOUT, sprintf("  synced DETAIL <- PREVIEW (preview_file=%d)\n", $meta['preview_id']));
					}
				}
			}
		}
	}
}

$summary = [
	'dry_run' => $dryRun,
	'iblock_id' => $iblockId,
	'all_products' => $allProducts,
	'uniq_keys_with_redirects' => count($uniqWithDup),
	'canonical_candidates' => $totalCanon,
	'removed_more_photo_values' => $removedMoreSlots,
	'removed_mf_ext_images_values' => $removedExtUrls,
	'keep_more' => $keepMore,
	'gallery_props_both_missing_elements' => $skippedNoGalleryProps,
	'sync_detail_to_preview_updates' => $syncedDetail,
];

fwrite(STDOUT, "\n" . json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
