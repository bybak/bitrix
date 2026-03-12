<?php

declare(strict_types=1);

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * Adds catalog product property "MF_SHOW_IN_CATALOG" (default: N).
 *
 * Goal: allow hiding products from catalog listings by default.
 * For existing products where the property is not set (empty) we keep them visible via filter logic.
 */

if (!function_exists('mf_ensure_iblock4_show_in_catalog_property'))
{
	function mf_ensure_iblock4_show_in_catalog_property(): void
	{
		if (!class_exists(Loader::class) || !Loader::includeModule('iblock'))
		{
			return;
		}

		$iblockId = 4;
		$code = 'MF_SHOW_IN_CATALOG';

		// NOTE: older Bitrix versions don't reliably support strict '=' filters here,
		// so we use plain keys to ensure the property is found correctly.
		$existing = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code])->Fetch();
		$propId = (int)($existing['ID'] ?? 0);
		if ($propId > 0)
		{
			return;
		}

		$optKey = 'mf_iblock4_show_in_catalog_property_installed';

		if ($propId <= 0)
		{
			$bp = new \CIBlockProperty();
			$propId = (int)$bp->Add([
				'IBLOCK_ID' => $iblockId,
				'NAME' => 'Показывать в каталоге',
				'ACTIVE' => 'Y',
				'SORT' => 5000,
				'CODE' => $code,
				'PROPERTY_TYPE' => 'L',
				'MULTIPLE' => 'N',
				'IS_REQUIRED' => 'N',
				'FILTRABLE' => 'Y',
				'LIST_TYPE' => 'L',
			]);
			if ($propId <= 0)
			{
				return;
			}
		}

		// Ensure enums: Y / N, default = N.
		$haveY = false;
		$haveN = false;
		$enums = [];
		$rs = \CIBlockPropertyEnum::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['PROPERTY_ID' => $propId]);
		while ($e = $rs->Fetch())
		{
			$xml = (string)($e['XML_ID'] ?? '');
			$val = (string)($e['VALUE'] ?? '');
			if ($xml === 'Y' || $val === 'Y') $haveY = true;
			if ($xml === 'N' || $val === 'N') $haveN = true;
			$enums[] = $e;
		}

		if (!$haveY || !$haveN)
		{
			$iep = new \CIBlockPropertyEnum();
			if (!$haveY)
			{
				$iep->Add([
					'PROPERTY_ID' => $propId,
					'VALUE' => 'Y',
					'XML_ID' => 'Y',
					'SORT' => 100,
					'DEF' => 'N',
				]);
			}
			if (!$haveN)
			{
				$iep->Add([
					'PROPERTY_ID' => $propId,
					'VALUE' => 'N',
					'XML_ID' => 'N',
					'SORT' => 200,
					'DEF' => 'Y',
				]);
			}
		}

		if (class_exists(Option::class))
		{
			try
			{
				Option::set('main', $optKey, 'Y');
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}
	}
}

if (!function_exists('mf_ensure_iblock4_ext_images_property'))
{
	/**
	 * Multiple string property with external image URLs.
	 * Used for analog stubs imported from supplier CSV.
	 */
	function mf_ensure_iblock4_ext_images_property(): void
	{
		if (!class_exists(Loader::class) || !Loader::includeModule('iblock'))
		{
			return;
		}

		$iblockId = 4;
		$code = 'MF_EXT_IMAGES';

		// NOTE: older Bitrix versions don't reliably support strict '=' filters here,
		// so we use plain keys to ensure the property is found correctly.
		$existing = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code])->Fetch();
		$propId = (int)($existing['ID'] ?? 0);
		if ($propId > 0)
		{
			return;
		}

		$optKey = 'mf_iblock4_ext_images_property_installed';
		if ($propId <= 0)
		{
			$bp = new \CIBlockProperty();
			$propId = (int)$bp->Add([
				'IBLOCK_ID' => $iblockId,
				'NAME' => 'Внешние картинки (URL)',
				'ACTIVE' => 'Y',
				'SORT' => 5010,
				'CODE' => $code,
				'PROPERTY_TYPE' => 'S',
				'MULTIPLE' => 'Y',
				'IS_REQUIRED' => 'N',
				'FILTRABLE' => 'N',
			]);
			if ($propId <= 0)
			{
				return;
			}
		}

		if (class_exists(Option::class))
		{
			try
			{
				Option::set('main', $optKey, 'Y');
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}
	}
}

