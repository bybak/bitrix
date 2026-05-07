<?php
/**
 * Update Bitrix catalog images based on files already saved under DOCUMENT_ROOT.
 *
 * POST /tools/mf_catalog_update_images.php
 * Content-Type: application/json
 *
 * Body:
 * {
 *   "iblock_id": 4,
 *   "sections": [{"id": 123, "picture": "/upload/mf_sync/sections/123.jpg"}],
 *   "elements": [{
 *     "code": "yamaha1hp-f582t-00-00",
 *     "preview": "/upload/mf_sync/products/yamaha1hp-f582t-00-00/preview.jpg",
 *     "detail": "/upload/mf_sync/products/yamaha1hp-f582t-00-00/detail.jpg",
 *     "more_photos": ["/upload/mf_sync/products/yamaha1hp-f582t-00-00/02.jpg"]
 *   }]
 * }
 */

$_SERVER["DOCUMENT_ROOT"] = dirname(__DIR__);
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define("BX_PUBLIC_MODE", 1);
define("PUBLIC_AJAX_MODE", true);
@ini_set('memory_limit', '2048M');
@set_time_limit(0);
@ignore_user_abort(true);

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;

global $APPLICATION;
if (is_object($APPLICATION))
{
	$APPLICATION->RestartBuffer();
}

header('Content-Type: application/json; charset=utf-8');

function mf_makeFileArray(string $relPath): ?array
{
	$relPath = trim($relPath);
	if ($relPath === '' || $relPath[0] !== '/')
	{
		return null;
	}
	$abs = $_SERVER["DOCUMENT_ROOT"] . $relPath;
	if (!is_file($abs) || filesize($abs) <= 0)
	{
		return null;
	}
	return CFile::MakeFileArray($abs);
}

function mf_ensureMorePhotoProperty(int $iblockId): bool
{
	$existing = CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => 'MORE_PHOTO'])->Fetch();
	if ($existing)
	{
		return true;
	}

	$ibp = new CIBlockProperty();
	$id = $ibp->Add([
		'IBLOCK_ID' => $iblockId,
		'ACTIVE' => 'Y',
		'NAME' => 'Дополнительные фотографии',
		'CODE' => 'MORE_PHOTO',
		'PROPERTY_TYPE' => 'F',
		'MULTIPLE' => 'Y',
		'WITH_DESCRIPTION' => 'N',
		'SORT' => 200,
		'FILTRABLE' => 'N',
	]);
	return (bool)$id;
}

try
{
	Loader::includeModule("iblock");

	$raw = file_get_contents("php://input");
	$data = json_decode($raw ?: '', true);
	if (!is_array($data))
	{
		throw new RuntimeException("Invalid JSON body");
	}

	$iblockId = (int)($data['iblock_id'] ?? 4);
	if ($iblockId <= 0)
	{
		throw new RuntimeException("iblock_id is required");
	}

	$updated = [
		'sections' => 0,
		'elements' => 0,
		'more_photos' => 0,
	];
	$errors = [];

	// Update sections pictures
	$sections = $data['sections'] ?? [];
	if (is_array($sections))
	{
		$bs = new CIBlockSection();
		foreach ($sections as $s)
		{
			$id = (int)($s['id'] ?? 0);
			$pic = (string)($s['picture'] ?? '');
			if ($id <= 0 || $pic === '') continue;

			$file = mf_makeFileArray($pic);
			if (!$file)
			{
				$errors[] = ['type' => 'section', 'id' => $id, 'error' => 'file_not_found', 'path' => $pic];
				continue;
			}
			if (!$bs->Update($id, ['PICTURE' => $file]))
			{
				$errors[] = ['type' => 'section', 'id' => $id, 'error' => $bs->LAST_ERROR];
				continue;
			}
			$updated['sections']++;
		}
	}

	// Ensure MORE_PHOTO exists (we want to attach gallery images)
	$hasMorePhoto = mf_ensureMorePhotoProperty($iblockId);

	// Update elements pictures
	$elements = $data['elements'] ?? [];
	if (is_array($elements))
	{
		$el = new CIBlockElement();
		foreach ($elements as $e)
		{
			$code = trim((string)($e['code'] ?? ''));
			if ($code === '') continue;

			$existing = CIBlockElement::GetList(
				[],
				['IBLOCK_ID' => $iblockId, '=CODE' => $code],
				false,
				false,
				['ID']
			)->Fetch();
			if (!$existing)
			{
				$errors[] = ['type' => 'element', 'code' => $code, 'error' => 'not_found'];
				continue;
			}
			$id = (int)$existing['ID'];

			$fields = [];
			$prev = mf_makeFileArray((string)($e['preview'] ?? ''));
			$det = mf_makeFileArray((string)($e['detail'] ?? ''));
			if ($prev) $fields['PREVIEW_PICTURE'] = $prev;
			if ($det) $fields['DETAIL_PICTURE'] = $det;

			if (!empty($fields))
			{
				if (!$el->Update($id, $fields))
				{
					$errors[] = ['type' => 'element', 'code' => $code, 'id' => $id, 'error' => $el->LAST_ERROR];
					continue;
				}
			}

			// More photos
			$more = $e['more_photos'] ?? [];
			if ($hasMorePhoto && is_array($more) && !empty($more))
			{
				$list = [];
				foreach ($more as $p)
				{
					$f = mf_makeFileArray((string)$p);
					if ($f) $list[] = $f;
				}
				if (!empty($list))
				{
					CIBlockElement::SetPropertyValuesEx($id, $iblockId, ['MORE_PHOTO' => $list]);
					$updated['more_photos'] += count($list);
				}
			}

			$updated['elements']++;
		}
	}

	echo json_encode([
		'ok' => true,
		'updated' => $updated,
		'errors' => $errors,
		'has_more_photo' => $hasMorePhoto,
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
catch (Throwable $e)
{
	http_response_code(500);
	echo json_encode([
		'error' => true,
		'message' => $e->getMessage(),
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

