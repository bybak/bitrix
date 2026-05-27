<?php
declare(strict_types=1);

if (!function_exists('mf_catalog_listing_in_stock'))
{
	function mf_catalog_listing_in_stock(int $productId): bool
	{
		$productId = (int)$productId;
		if ($productId <= 0 || !function_exists('mf_catalog_product_store_amounts'))
		{
			return false;
		}

		foreach (mf_catalog_product_store_amounts($productId) as $amount)
		{
			if ((float)$amount > 1e-9)
			{
				return true;
			}
		}

		return false;
	}
}

if (!function_exists('mf_catalog_listing_has_display_price'))
{
	/**
	 * Как на карточке каталога: цена показывается только при наличии на складе.
	 */
	function mf_catalog_listing_has_display_price(int $productId): bool
	{
		if (!mf_catalog_listing_in_stock($productId) || !function_exists('mf_catalog_listing_display_price'))
		{
			return false;
		}

		$price = mf_catalog_listing_display_price($productId);

		return $price !== null && (float)$price > 0;
	}
}

if (!function_exists('mf_catalog_listing_sort_meta'))
{
	/**
	 * @param array<string, mixed> $item
	 *
	 * @return array{in_stock:bool,has_price:bool,price:float,name:string,sort:int}
	 */
	function mf_catalog_listing_sort_meta(int $productId, array $item = []): array
	{
		$inStock = mf_catalog_listing_in_stock($productId);
		$hasPrice = mf_catalog_listing_has_display_price($productId);
		$price = PHP_FLOAT_MAX;
		if ($hasPrice && function_exists('mf_catalog_listing_display_price'))
		{
			$p = mf_catalog_listing_display_price($productId);
			if ($p !== null && (float)$p > 0)
			{
				$price = (float)$p;
			}
		}

		return [
			'in_stock' => $inStock,
			'has_price' => $hasPrice,
			'price' => $price,
			'name' => trim((string)($item['NAME'] ?? '')),
			'sort' => (int)($item['SORT'] ?? 500),
		];
	}
}

if (!function_exists('mf_catalog_listing_compare_items'))
{
	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	function mf_catalog_listing_compare_items(array $a, array $b, string $mode = 'default'): int
	{
		$pidA = (int)($a['ID'] ?? 0);
		$pidB = (int)($b['ID'] ?? 0);
		$metaA = mf_catalog_listing_sort_meta($pidA, $a);
		$metaB = mf_catalog_listing_sort_meta($pidB, $b);

		// 1) В наличии, затем «Под заказ» — для любого режима сортировки.
		if ($metaA['in_stock'] !== $metaB['in_stock'])
		{
			return $metaA['in_stock'] ? -1 : 1;
		}

		switch ($mode)
		{
			case 'price_asc':
			case 'price_desc':
				// 2) Внутри группы: сначала с ценой, «Запросить цену» — в конце группы.
				if ($metaA['has_price'] !== $metaB['has_price'])
				{
					return $metaA['has_price'] ? -1 : 1;
				}
				if ($metaA['has_price'] && $metaB['has_price'])
				{
					$cmp = $mode === 'price_desc'
						? ($metaB['price'] <=> $metaA['price'])
						: ($metaA['price'] <=> $metaB['price']);
					if ($cmp !== 0)
					{
						return $cmp;
					}
				}
				$cmp = strcasecmp($metaA['name'], $metaB['name']);
				if ($cmp !== 0)
				{
					return $cmp;
				}
				break;

			case 'name_desc':
				$cmp = strcasecmp($metaB['name'], $metaA['name']);
				if ($cmp !== 0)
				{
					return $cmp;
				}
				break;

			case 'name_asc':
				$cmp = strcasecmp($metaA['name'], $metaB['name']);
				if ($cmp !== 0)
				{
					return $cmp;
				}
				break;

			case 'default':
			default:
				if ($metaA['sort'] !== $metaB['sort'])
				{
					return $metaA['sort'] <=> $metaB['sort'];
				}
				$cmp = strcasecmp($metaA['name'], $metaB['name']);
				if ($cmp !== 0)
				{
					return $cmp;
				}
				break;
		}

		return $pidA <=> $pidB;
	}
}

if (!function_exists('mf_catalog_sort_section_items'))
{
	/**
	 * @param array<int, array<string, mixed>> $items
	 */
	function mf_catalog_sort_section_items(array &$items, string $mode = 'default'): void
	{
		if (count($items) < 2)
		{
			return;
		}

		$mode = trim($mode);
		if (!in_array($mode, ['default', 'name_asc', 'name_desc', 'price_asc', 'price_desc'], true))
		{
			$mode = 'default';
		}

		if (function_exists('mf_catalog_warm_products'))
		{
			$ids = [];
			foreach ($items as $item)
			{
				$id = (int)($item['ID'] ?? 0);
				if ($id > 0)
				{
					$ids[] = $id;
				}
			}
			if ($ids !== [])
			{
				mf_catalog_warm_products($ids);
			}
		}

		usort(
			$items,
			static function (array $a, array $b) use ($mode): int {
				return mf_catalog_listing_compare_items($a, $b, $mode);
			}
		);
	}
}

if (!function_exists('mf_catalog_section_sorted_product_ids'))
{
	/**
	 * Полный порядок ID товаров раздела для catalog.section (CUSTOM_ELEMENT_SORT).
	 *
	 * @param array<string, mixed> $extraFilter
	 *
	 * @return list<int>
	 */
	function mf_catalog_section_sorted_product_ids(
		int $sectionId,
		int $iblockId,
		array $extraFilter,
		string $mode = 'default'
	): array
	{
		$sectionId = (int)$sectionId;
		$iblockId = (int)$iblockId;
		if ($sectionId <= 0 || $iblockId <= 0)
		{
			return [];
		}

		$mode = trim($mode);
		if (!in_array($mode, ['default', 'name_asc', 'name_desc', 'price_asc', 'price_desc'], true))
		{
			$mode = 'default';
		}

		$cacheVersion = '3';
		$cacheKey = md5(json_encode([$cacheVersion, $sectionId, $iblockId, $extraFilter, $mode], JSON_UNESCAPED_UNICODE));
		$cacheDir = '/mf_catalog_sort';
		$cacheTtl = 600;

		if (class_exists(\Bitrix\Main\Data\Cache::class))
		{
			$cache = \Bitrix\Main\Data\Cache::createInstance();
			if ($cache->initCache($cacheTtl, $cacheKey, $cacheDir))
			{
				$vars = $cache->getVars();

				return is_array($vars) ? array_values(array_map('intval', $vars)) : [];
			}
		}

		if (!class_exists(\CIBlockElement::class))
		{
			return [];
		}

		$filter = array_merge(
			[
				'IBLOCK_ID' => $iblockId,
				'SECTION_ID' => $sectionId,
				'INCLUDE_SUBSECTIONS' => 'Y',
				'ACTIVE' => 'Y',
				'ACTIVE_DATE' => 'Y',
			],
			$extraFilter
		);

		$items = [];
		$rs = \CIBlockElement::GetList(
			['ID' => 'ASC'],
			$filter,
			false,
			false,
			['ID', 'NAME', 'SORT']
		);
		while ($row = $rs->Fetch())
		{
			$id = (int)($row['ID'] ?? 0);
			if ($id <= 0)
			{
				continue;
			}
			$items[] = [
				'ID' => $id,
				'NAME' => (string)($row['NAME'] ?? ''),
				'SORT' => (int)($row['SORT'] ?? 500),
			];
		}

		if ($items === [])
		{
			return [];
		}

		mf_catalog_sort_section_items($items, $mode);
		$ids = array_values(array_map(static fn(array $item): int => (int)$item['ID'], $items));

		if (isset($cache) && $cache->startDataCache())
		{
			$cache->endDataCache($ids);
		}

		return $ids;
	}
}
