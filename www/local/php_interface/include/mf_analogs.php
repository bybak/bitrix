<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * Product analogs storage.
 *
 * Stores undirected analog relation as a pair (P1_ID < P2_ID) in HL table `mf_product_analogs`.
 * This avoids having to create two rows for A<->B and makes uniqueness simple.
 *
 * Additionally stores per-product directed metadata in HL table `mf_product_analogs_meta`:
 * (PRODUCT_ID -> ANALOG_ID): stock/price/images from supplier feeds, etc.
 */

if (!function_exists('mf_analogs_ensure_hl_physical_table'))
{
	/**
	 * HL-запись в b_hlblock есть, а физической таблицы в MySQL нет → 1146 при выгрузке каталога.
	 */
	function mf_analogs_ensure_hl_physical_table(array $hl, string $tableName): void
	{
		$tableName = trim($tableName);
		if ($tableName === '' || !class_exists(Application::class))
		{
			return;
		}
		$conn = Application::getConnection();
		if ($conn->isTableExists($tableName))
		{
			return;
		}
		if (!class_exists(\Bitrix\Highloadblock\HighloadBlockTable::class))
		{
			return;
		}
		$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hl);
		if (method_exists($entity, 'createDbTable'))
		{
			$entity->createDbTable();
		}
	}
}

if (!function_exists('mf_analogs_ensure_hl'))
{
	/**
	 * @return array{HL_ID:int,TABLE:string,ENTITY_ID:string,DATA_CLASS:string}
	 */
	function mf_analogs_ensure_hl(): array
	{
		static $cached = null;
		if (is_array($cached))
		{
			return $cached;
		}

		if (!class_exists(Loader::class) || !Loader::includeModule('highloadblock'))
		{
			throw new \RuntimeException('Module highloadblock is not available.');
		}

		$tableName = 'mf_product_analogs';
		$optKey = 'mf_hl_' . $tableName . '_installed';

		$hl = \Bitrix\Highloadblock\HighloadBlockTable::getList([
			'filter' => ['=TABLE_NAME' => $tableName],
			'select' => ['ID', 'NAME', 'TABLE_NAME'],
			'limit' => 1,
		])->fetch();

		if (!$hl)
		{
			$res = \Bitrix\Highloadblock\HighloadBlockTable::add([
				'NAME' => 'MfProductAnalogs',
				'TABLE_NAME' => $tableName,
			]);
			if (!$res->isSuccess())
			{
				throw new \RuntimeException('Failed to create HL block: ' . implode('; ', $res->getErrorMessages()));
			}
			$hl = \Bitrix\Highloadblock\HighloadBlockTable::getById((int)$res->getId())->fetch();
		}

		$hlId = (int)($hl['ID'] ?? 0);
		if ($hlId <= 0)
		{
			throw new \RuntimeException('HL block ID is empty.');
		}

		mf_analogs_ensure_hl_physical_table($hl, $tableName);

		$entityId = 'HLBLOCK_' . $hlId;

		// Ensure user fields once (idempotent enough for our use).
		if (class_exists(Option::class))
		{
			try
			{
				if (Option::get('main', $optKey, 'N') !== 'Y')
				{
					mf_analogs_ensure_user_fields($entityId);
					mf_analogs_ensure_indexes($tableName);
					Option::set('main', $optKey, 'Y');
				}
			}
			catch (\Throwable $e)
			{
				// still proceed; fields may already exist, option may be missing, etc.
			}
		}
		else
		{
			mf_analogs_ensure_user_fields($entityId);
			mf_analogs_ensure_indexes($tableName);
		}

		$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hl);
		$dataClass = $entity->getDataClass();

		$cached = [
			'HL_ID' => $hlId,
			'TABLE' => $tableName,
			'ENTITY_ID' => $entityId,
			'DATA_CLASS' => $dataClass,
		];

		return $cached;
	}
}

if (!function_exists('mf_analogs_ensure_hl_meta'))
{
	/**
	 * @return array{HL_ID:int,TABLE:string,ENTITY_ID:string,DATA_CLASS:string}
	 */
	function mf_analogs_ensure_hl_meta(): array
	{
		static $cached = null;
		if (is_array($cached))
		{
			return $cached;
		}

		if (!class_exists(Loader::class) || !Loader::includeModule('highloadblock'))
		{
			throw new \RuntimeException('Module highloadblock is not available.');
		}

		$tableName = 'mf_product_analogs_meta';
		$optKey = 'mf_hl_' . $tableName . '_installed';

		$hl = \Bitrix\Highloadblock\HighloadBlockTable::getList([
			'filter' => ['=TABLE_NAME' => $tableName],
			'select' => ['ID', 'NAME', 'TABLE_NAME'],
			'limit' => 1,
		])->fetch();

		if (!$hl)
		{
			$res = \Bitrix\Highloadblock\HighloadBlockTable::add([
				'NAME' => 'MfProductAnalogsMeta',
				'TABLE_NAME' => $tableName,
			]);
			if (!$res->isSuccess())
			{
				throw new \RuntimeException('Failed to create HL block: ' . implode('; ', $res->getErrorMessages()));
			}
			$hl = \Bitrix\Highloadblock\HighloadBlockTable::getById((int)$res->getId())->fetch();
		}

		$hlId = (int)($hl['ID'] ?? 0);
		if ($hlId <= 0)
		{
			throw new \RuntimeException('HL block ID is empty.');
		}

		mf_analogs_ensure_hl_physical_table($hl, $tableName);

		$entityId = 'HLBLOCK_' . $hlId;

		if (class_exists(Option::class))
		{
			try
			{
				if (Option::get('main', $optKey, 'N') !== 'Y')
				{
					mf_analogs_meta_ensure_user_fields($entityId);
					mf_analogs_meta_ensure_indexes($tableName);
					Option::set('main', $optKey, 'Y');
				}
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}
		else
		{
			mf_analogs_meta_ensure_user_fields($entityId);
			mf_analogs_meta_ensure_indexes($tableName);
		}

		$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hl);
		$dataClass = $entity->getDataClass();

		$cached = [
			'HL_ID' => $hlId,
			'TABLE' => $tableName,
			'ENTITY_ID' => $entityId,
			'DATA_CLASS' => $dataClass,
		];

		return $cached;
	}
}

if (!function_exists('mf_analogs_ensure_user_fields'))
{
	function mf_analogs_ensure_user_fields(string $entityId): void
	{
		if (!class_exists(\CUserTypeEntity::class))
		{
			throw new \RuntimeException('CUserTypeEntity is not available.');
		}

		$fields = [
			'UF_P1_ID' => [
				'USER_TYPE_ID' => 'integer',
				'MANDATORY' => 'Y',
				'EDIT_FORM_LABEL' => ['ru' => 'Товар 1 (ID)'],
				'LIST_COLUMN_LABEL' => ['ru' => 'Товар 1 (ID)'],
			],
			'UF_P2_ID' => [
				'USER_TYPE_ID' => 'integer',
				'MANDATORY' => 'Y',
				'EDIT_FORM_LABEL' => ['ru' => 'Товар 2 (ID)'],
				'LIST_COLUMN_LABEL' => ['ru' => 'Товар 2 (ID)'],
			],
			'UF_SORT' => [
				'USER_TYPE_ID' => 'integer',
				'MANDATORY' => 'N',
				'EDIT_FORM_LABEL' => ['ru' => 'Сортировка'],
				'LIST_COLUMN_LABEL' => ['ru' => 'Сортировка'],
			],
			'UF_SOURCE' => [
				'USER_TYPE_ID' => 'string',
				'MANDATORY' => 'N',
				'EDIT_FORM_LABEL' => ['ru' => 'Источник'],
				'LIST_COLUMN_LABEL' => ['ru' => 'Источник'],
			],
			'UF_CREATED_AT' => [
				'USER_TYPE_ID' => 'datetime',
				'MANDATORY' => 'N',
				'EDIT_FORM_LABEL' => ['ru' => 'Создано'],
				'LIST_COLUMN_LABEL' => ['ru' => 'Создано'],
			],
		];

		foreach ($fields as $fieldName => $cfg)
		{
			$existing = \CUserTypeEntity::GetList([], [
				'ENTITY_ID' => $entityId,
				'FIELD_NAME' => $fieldName,
			])->Fetch();

			if ($existing && (int)($existing['ID'] ?? 0) > 0)
			{
				continue;
			}

			$ute = new \CUserTypeEntity();
			$id = (int)$ute->Add([
				'ENTITY_ID' => $entityId,
				'FIELD_NAME' => $fieldName,
				'USER_TYPE_ID' => $cfg['USER_TYPE_ID'],
				'SORT' => 100,
				'MULTIPLE' => 'N',
				'MANDATORY' => $cfg['MANDATORY'],
				'SHOW_FILTER' => 'I',
				'SHOW_IN_LIST' => 'Y',
				'EDIT_IN_LIST' => 'Y',
				'IS_SEARCHABLE' => 'N',
				'EDIT_FORM_LABEL' => $cfg['EDIT_FORM_LABEL'],
				'LIST_COLUMN_LABEL' => $cfg['LIST_COLUMN_LABEL'],
				'LIST_FILTER_LABEL' => $cfg['LIST_COLUMN_LABEL'],
			]);

			if ($id <= 0)
			{
				throw new \RuntimeException('Failed to create UF field ' . $fieldName);
			}
		}
	}
}

if (!function_exists('mf_analogs_ensure_indexes'))
{
	function mf_analogs_ensure_indexes(string $tableName): void
	{
		$conn = Application::getConnection();

		// Make sure uniqueness is enforced (avoid duplicates).
		// Ignore errors if indexes already exist.
		$indexes = [
			'UX_MF_ANALOGS_P1_P2' => "CREATE UNIQUE INDEX `UX_MF_ANALOGS_P1_P2` ON `" . $tableName . "` (`UF_P1_ID`, `UF_P2_ID`)",
			'IX_MF_ANALOGS_P1' => "CREATE INDEX `IX_MF_ANALOGS_P1` ON `" . $tableName . "` (`UF_P1_ID`)",
			'IX_MF_ANALOGS_P2' => "CREATE INDEX `IX_MF_ANALOGS_P2` ON `" . $tableName . "` (`UF_P2_ID`)",
		];
		foreach ($indexes as $name => $sql)
		{
			try
			{
				$conn->queryExecute($sql);
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}
	}
}

if (!function_exists('mf_analogs_meta_ensure_user_fields'))
{
	function mf_analogs_meta_ensure_user_fields(string $entityId): void
	{
		if (!class_exists(\CUserTypeEntity::class))
		{
			throw new \RuntimeException('CUserTypeEntity is not available.');
		}

		$fields = [
			'UF_PRODUCT_ID' => [
				'USER_TYPE_ID' => 'integer',
				'MANDATORY' => 'Y',
				'EDIT_FORM_LABEL' => ['ru' => 'Товар (ID)'],
				'LIST_COLUMN_LABEL' => ['ru' => 'Товар (ID)'],
			],
			'UF_ANALOG_ID' => [
				'USER_TYPE_ID' => 'integer',
				'MANDATORY' => 'Y',
				'EDIT_FORM_LABEL' => ['ru' => 'Аналог (ID)'],
				'LIST_COLUMN_LABEL' => ['ru' => 'Аналог (ID)'],
			],
			'UF_STOCK' => [
				'USER_TYPE_ID' => 'double',
				'MANDATORY' => 'N',
				'EDIT_FORM_LABEL' => ['ru' => 'Остаток'],
				'LIST_COLUMN_LABEL' => ['ru' => 'Остаток'],
			],
			'UF_PRICE' => [
				'USER_TYPE_ID' => 'double',
				'MANDATORY' => 'N',
				'EDIT_FORM_LABEL' => ['ru' => 'Цена'],
				'LIST_COLUMN_LABEL' => ['ru' => 'Цена'],
			],
			'UF_CURRENCY' => [
				'USER_TYPE_ID' => 'string',
				'MANDATORY' => 'N',
				'EDIT_FORM_LABEL' => ['ru' => 'Валюта'],
				'LIST_COLUMN_LABEL' => ['ru' => 'Валюта'],
			],
			'UF_IMAGES' => [
				'USER_TYPE_ID' => 'string',
				'MANDATORY' => 'N',
				'EDIT_FORM_LABEL' => ['ru' => 'Картинки (CSV)'],
				'LIST_COLUMN_LABEL' => ['ru' => 'Картинки'],
			],
			'UF_SOURCE' => [
				'USER_TYPE_ID' => 'string',
				'MANDATORY' => 'N',
				'EDIT_FORM_LABEL' => ['ru' => 'Источник'],
				'LIST_COLUMN_LABEL' => ['ru' => 'Источник'],
			],
			'UF_UPDATED_AT' => [
				'USER_TYPE_ID' => 'datetime',
				'MANDATORY' => 'N',
				'EDIT_FORM_LABEL' => ['ru' => 'Обновлено'],
				'LIST_COLUMN_LABEL' => ['ru' => 'Обновлено'],
			],
		];

		foreach ($fields as $fieldName => $cfg)
		{
			$existing = \CUserTypeEntity::GetList([], [
				'ENTITY_ID' => $entityId,
				'FIELD_NAME' => $fieldName,
			])->Fetch();

			if ($existing && (int)($existing['ID'] ?? 0) > 0)
			{
				continue;
			}

			$ute = new \CUserTypeEntity();
			$id = (int)$ute->Add([
				'ENTITY_ID' => $entityId,
				'FIELD_NAME' => $fieldName,
				'USER_TYPE_ID' => $cfg['USER_TYPE_ID'],
				'SORT' => 100,
				'MULTIPLE' => 'N',
				'MANDATORY' => $cfg['MANDATORY'],
				'SHOW_FILTER' => 'I',
				'SHOW_IN_LIST' => 'Y',
				'EDIT_IN_LIST' => 'Y',
				'IS_SEARCHABLE' => 'N',
				'EDIT_FORM_LABEL' => $cfg['EDIT_FORM_LABEL'],
				'LIST_COLUMN_LABEL' => $cfg['LIST_COLUMN_LABEL'],
				'LIST_FILTER_LABEL' => $cfg['LIST_COLUMN_LABEL'],
			]);

			if ($id <= 0)
			{
				throw new \RuntimeException('Failed to create UF field ' . $fieldName);
			}
		}
	}
}

if (!function_exists('mf_analogs_meta_ensure_indexes'))
{
	function mf_analogs_meta_ensure_indexes(string $tableName): void
	{
		$conn = Application::getConnection();
		$indexes = [
			'UX_MF_ANALOGS_META' => "CREATE UNIQUE INDEX `UX_MF_ANALOGS_META` ON `" . $tableName . "` (`UF_PRODUCT_ID`, `UF_ANALOG_ID`)",
			'IX_MF_ANALOGS_META_PRODUCT' => "CREATE INDEX `IX_MF_ANALOGS_META_PRODUCT` ON `" . $tableName . "` (`UF_PRODUCT_ID`)",
		];
		foreach ($indexes as $sql)
		{
			try
			{
				$conn->queryExecute($sql);
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}
	}
}

if (!function_exists('mf_analogs_replace_for_product'))
{
	/**
	 * @param int[] $analogIds
	 */
	function mf_analogs_replace_for_product(int $productId, array $analogIds, string $source = 'admin'): array
	{
		$meta = mf_analogs_ensure_hl();
		$table = (string)$meta['TABLE'];

		$productId = (int)$productId;
		$analogIds = array_values(array_unique(array_map('intval', $analogIds)));
		$analogIds = array_values(array_filter($analogIds, static fn($x) => $x > 0 && $x !== $productId));

		$conn = Application::getConnection();
		$sqlHelper = $conn->getSqlHelper();

		$conn->queryExecute("DELETE FROM `" . $table . "` WHERE `UF_P1_ID`=" . $productId . " OR `UF_P2_ID`=" . $productId);

		return mf_analogs_merge_for_product($productId, $analogIds, $source);
	}
}

if (!function_exists('mf_analogs_merge_for_product'))
{
	/**
	 * @param int[] $analogIds
	 */
	function mf_analogs_merge_for_product(int $productId, array $analogIds, string $source = 'admin'): array
	{
		$meta = mf_analogs_ensure_hl();
		$table = (string)$meta['TABLE'];

		$productId = (int)$productId;
		$analogIds = array_values(array_unique(array_map('intval', $analogIds)));
		$analogIds = array_values(array_filter($analogIds, static fn($x) => $x > 0 && $x !== $productId));

		$conn = Application::getConnection();
		$sqlHelper = $conn->getSqlHelper();

		$createdAt = (new \Bitrix\Main\Type\DateTime())->format('Y-m-d H:i:s');
		$createdAtSql = "'" . $sqlHelper->forSql($createdAt) . "'";
		$sourceSql = "'" . $sqlHelper->forSql($source) . "'";

		$inserted = 0;
		foreach ($analogIds as $aid)
		{
			$p1 = min($productId, (int)$aid);
			$p2 = max($productId, (int)$aid);
			if ($p1 <= 0 || $p2 <= 0 || $p1 === $p2)
			{
				continue;
			}

			// With unique index, this will avoid duplicates.
			$sql = "INSERT IGNORE INTO `" . $table . "` (`UF_P1_ID`,`UF_P2_ID`,`UF_SORT`,`UF_SOURCE`,`UF_CREATED_AT`) VALUES (" .
				$p1 . "," . $p2 . ",500," . $sourceSql . "," . $createdAtSql . ")";
			$conn->queryExecute($sql);
			$inserted++;
		}

		return [
			'inserted_attempted' => $inserted,
			'analogs_count' => count($analogIds),
		];
	}
}

if (!function_exists('mf_analogs_has_positive_catalog_stock'))
{
	/**
	 * Есть ли физический остаток > 0 хотя бы на одном складе (сумма по кластеру карточек).
	 */
	function mf_analogs_has_positive_catalog_stock(int $productId): bool
	{
		$productId = (int)$productId;
		if ($productId <= 0)
		{
			return false;
		}
		if (!class_exists(\CCatalogStoreProduct::class))
		{
			return false;
		}
		try
		{
			Loader::includeModule('catalog');
		}
		catch (\Throwable $e)
		{
			return false;
		}

		$clusterIds = function_exists('mf_catalog_product_cluster_ids')
			? mf_catalog_product_cluster_ids($productId)
			: [$productId];
		$sum = 0.0;
		foreach ($clusterIds as $cid)
		{
			$cid = (int)$cid;
			if ($cid <= 0)
			{
				continue;
			}
			$rs = \CCatalogStoreProduct::GetList(
				[],
				['PRODUCT_ID' => $cid],
				false,
				false,
				['AMOUNT']
			);
			while ($r = $rs->Fetch())
			{
				$sum += (float)($r['AMOUNT'] ?? 0);
			}
		}

		return $sum > 1e-9;
	}
}

if (!function_exists('mf_analogs_sort_ids_stock_priority'))
{
	/**
	 * Сначала ID с остатком на складах, затем без остатка. Порядок внутри групп = как в $ids.
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	function mf_analogs_sort_ids_stock_priority(array $ids): array
	{
		$ids = array_values(array_unique(array_map('intval', $ids)));
		$ids = array_values(array_filter($ids, static fn($x) => $x > 0));
		if ($ids === [])
		{
			return [];
		}

		$stockMap = [];
		if (count($ids) > 1 && function_exists('mf_catalog_batch_products_have_stock'))
		{
			$stockMap = mf_catalog_batch_products_have_stock($ids);
		}

		$in = [];
		$out = [];
		foreach ($ids as $id)
		{
			$has = array_key_exists($id, $stockMap)
				? (bool)$stockMap[$id]
				: mf_analogs_has_positive_catalog_stock($id);
			if ($has)
			{
				$in[] = $id;
			}
			else
			{
				$out[] = $id;
			}
		}

		return array_merge($in, $out);
	}
}

if (!function_exists('mf_analogs_ids_for_product'))
{
	/**
	 * Returns analog product IDs for a given product ID.
	 *
	 * @return int[]
	 */
	function mf_analogs_ids_for_product(int $productId, int $limit = 24): array
	{
		$productId = (int)$productId;
		if ($productId <= 0)
		{
			return [];
		}

		$meta = mf_analogs_ensure_hl();
		$table = (string)$meta['TABLE'];

		$limit = max(1, min(200, (int)$limit));

		$conn = Application::getConnection();
		$sqlHelper = $conn->getSqlHelper();

		$res = $conn->query("
			SELECT `UF_P1_ID`, `UF_P2_ID`
			FROM `" . $table . "`
			WHERE `UF_P1_ID` = " . $productId . " OR `UF_P2_ID` = " . $productId . "
			ORDER BY `UF_SORT` ASC, `ID` ASC
			LIMIT " . $limit . "
		");

		$out = [];
		while ($r = $res->fetch())
		{
			$p1 = (int)($r['UF_P1_ID'] ?? 0);
			$p2 = (int)($r['UF_P2_ID'] ?? 0);
			$other = ($p1 === $productId ? $p2 : $p1);
			if ($other > 0 && $other !== $productId)
			{
				$out[$other] = true;
			}
		}
		$ids = array_map('intval', array_keys($out));
		if (function_exists('mf_analogs_sort_ids_stock_priority'))
		{
			$ids = mf_analogs_sort_ids_stock_priority($ids);
		}

		return $ids;
	}
}

if (!function_exists('mf_analogs_related_ids_for_products'))
{
	/**
	 * Пакетная загрузка связанных аналогов для нескольких товаров (для страницы поиска).
	 *
	 * @param int[] $productIds
	 * @return array<int, int[]> productId => analogIds
	 */
	function mf_analogs_related_ids_for_products(array $productIds, int $limitPerProduct = 12): array
	{
		$productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
		$limitPerProduct = max(1, min(24, (int)$limitPerProduct));
		$result = [];
		foreach ($productIds as $pid)
		{
			$result[$pid] = [];
		}
		if ($productIds === [])
		{
			return $result;
		}

		$direct = [];
		foreach ($productIds as $pid)
		{
			$direct[$pid] = [];
		}

		try
		{
			$hl = mf_analogs_ensure_hl();
			$table = (string)$hl['TABLE'];
			$conn = Application::getConnection();
			$in = implode(',', $productIds);
			$res = $conn->query("
				SELECT `UF_P1_ID`, `UF_P2_ID`
				FROM `" . $table . "`
				WHERE `UF_P1_ID` IN (" . $in . ") OR `UF_P2_ID` IN (" . $in . ")
			");
			while ($r = $res->fetch())
			{
				$p1 = (int)($r['UF_P1_ID'] ?? 0);
				$p2 = (int)($r['UF_P2_ID'] ?? 0);
				if (isset($direct[$p1]) && $p2 > 0 && $p2 !== $p1)
				{
					$direct[$p1][$p2] = true;
				}
				if (isset($direct[$p2]) && $p1 > 0 && $p1 !== $p2)
				{
					$direct[$p2][$p1] = true;
				}
			}
		}
		catch (\Throwable $e)
		{
			// fallback ниже
		}

		$related = [];
		foreach ($productIds as $pid)
		{
			$related[$pid] = $direct[$pid] ?? [];
		}

		try
		{
			$meta = mf_analogs_ensure_hl_meta();
			$metaTable = (string)$meta['TABLE'];
			$conn = Application::getConnection();
			$in = implode(',', $productIds);

			$originalIds = [];
			$origRes = $conn->query("
				SELECT DISTINCT `UF_PRODUCT_ID`, `UF_ANALOG_ID`
				FROM `" . $metaTable . "`
				WHERE `UF_ANALOG_ID` IN (" . $in . ")
				LIMIT 500
			");
			while ($r = $origRes->fetch())
			{
				$oid = (int)($r['UF_PRODUCT_ID'] ?? 0);
				$aid = (int)($r['UF_ANALOG_ID'] ?? 0);
				if ($oid > 0 && $aid > 0 && isset($related[$aid]))
				{
					$related[$aid][$oid] = true;
					$originalIds[$oid] = true;
				}
			}

			if ($originalIds !== [])
			{
				$inOrig = implode(',', array_map('intval', array_keys($originalIds)));
				$sibRes = $conn->query("
					SELECT `UF_PRODUCT_ID`, `UF_ANALOG_ID`
					FROM `" . $metaTable . "`
					WHERE `UF_PRODUCT_ID` IN (" . $inOrig . ")
					LIMIT 1000
				");
				$oidToSearchPids = [];
				foreach ($productIds as $pid)
				{
					foreach (array_keys($related[$pid] ?? []) as $oid)
					{
						$oid = (int)$oid;
						if ($oid > 0)
						{
							$oidToSearchPids[$oid][$pid] = true;
						}
					}
				}
				while ($r = $sibRes->fetch())
				{
					$oid = (int)($r['UF_PRODUCT_ID'] ?? 0);
					$sib = (int)($r['UF_ANALOG_ID'] ?? 0);
					if ($oid <= 0 || $sib <= 0)
					{
						continue;
					}
					foreach (array_keys($oidToSearchPids[$oid] ?? []) as $pid)
					{
						if ($sib !== $pid)
						{
							$related[$pid][$sib] = true;
						}
					}
				}
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}

		$allCandidateIds = [];
		$stockMap = [];
		foreach ($productIds as $pid)
		{
			$ids = array_map('intval', array_keys($related[$pid] ?? []));
			$ids = array_values(array_filter($ids, static fn($x) => $x > 0 && $x !== $pid));
			foreach ($ids as $id)
			{
				$allCandidateIds[$id] = true;
			}
		}
		if ($allCandidateIds !== [] && function_exists('mf_catalog_batch_products_have_stock'))
		{
			$stockMap = mf_catalog_batch_products_have_stock(array_keys($allCandidateIds));
		}

		foreach ($productIds as $pid)
		{
			$ids = array_map('intval', array_keys($related[$pid] ?? []));
			$ids = array_values(array_filter($ids, static fn($x) => $x > 0 && $x !== $pid));
			if ($ids !== [] && $stockMap !== [])
			{
				$in = [];
				$out = [];
				foreach ($ids as $id)
				{
					if (!empty($stockMap[$id]))
					{
						$in[] = $id;
					}
					else
					{
						$out[] = $id;
					}
				}
				$ids = array_merge($in, $out);
			}
			elseif ($ids !== [] && function_exists('mf_analogs_sort_ids_stock_priority'))
			{
				$ids = mf_analogs_sort_ids_stock_priority($ids);
			}
			if (count($ids) > $limitPerProduct)
			{
				$ids = array_slice($ids, 0, $limitPerProduct);
			}
			$result[$pid] = $ids;
		}

		return $result;
	}
}

if (!function_exists('mf_analogs_delete_link'))
{
	function mf_analogs_delete_link(int $productId, int $analogId): void
	{
		$productId = (int)$productId;
		$analogId = (int)$analogId;
		if ($productId <= 0 || $analogId <= 0 || $productId === $analogId)
		{
			return;
		}

		$meta = mf_analogs_ensure_hl();
		$table = (string)$meta['TABLE'];

		$p1 = min($productId, $analogId);
		$p2 = max($productId, $analogId);

		$conn = Application::getConnection();
		$conn->queryExecute("DELETE FROM `" . $table . "` WHERE `UF_P1_ID`=" . $p1 . " AND `UF_P2_ID`=" . $p2);

		// Also delete directed metadata for both directions.
		try
		{
			$meta = mf_analogs_ensure_hl_meta();
			$metaTable = (string)$meta['TABLE'];
			$conn->queryExecute("DELETE FROM `" . $metaTable . "` WHERE (`UF_PRODUCT_ID`=" . $productId . " AND `UF_ANALOG_ID`=" . $analogId . ") OR (`UF_PRODUCT_ID`=" . $analogId . " AND `UF_ANALOG_ID`=" . $productId . ")");
		}
		catch (\Throwable $e)
		{
			// ignore
		}
	}
}

if (!function_exists('mf_analogs_meta_upsert'))
{
	/**
	 * Saves supplier metadata for a directed pair (product -> analog).
	 *
	 * @param string[] $images
	 */
	function mf_analogs_meta_upsert(
		int $productId,
		int $analogId,
		?float $stock,
		?float $price,
		array $images,
		string $source = 'admin',
		string $currency = 'RUB'
	): void
	{
		$productId = (int)$productId;
		$analogId = (int)$analogId;
		if ($productId <= 0 || $analogId <= 0 || $productId === $analogId)
		{
			return;
		}

		$meta = mf_analogs_ensure_hl_meta();
		$table = (string)$meta['TABLE'];
		$conn = Application::getConnection();
		$sqlHelper = $conn->getSqlHelper();

		$images = array_values(array_filter(array_map('trim', $images), static fn($s) => $s !== ''));
		$imagesCsv = implode(',', $images);

		$updatedAt = (new \Bitrix\Main\Type\DateTime())->format('Y-m-d H:i:s');
		$updatedAtSql = "'" . $sqlHelper->forSql($updatedAt) . "'";

		$stockSql = ($stock === null ? 'NULL' : (string)(float)$stock);
		$priceSql = ($price === null ? 'NULL' : (string)(float)$price);

		$sql = "
			INSERT INTO `" . $table . "`
				(`UF_PRODUCT_ID`,`UF_ANALOG_ID`,`UF_STOCK`,`UF_PRICE`,`UF_CURRENCY`,`UF_IMAGES`,`UF_SOURCE`,`UF_UPDATED_AT`)
			VALUES
				(" . $productId . "," . $analogId . "," . $stockSql . "," . $priceSql . ",'" . $sqlHelper->forSql($currency) . "','" . $sqlHelper->forSql($imagesCsv) . "','" . $sqlHelper->forSql($source) . "'," . $updatedAtSql . ")
			ON DUPLICATE KEY UPDATE
				`UF_STOCK`=VALUES(`UF_STOCK`),
				`UF_PRICE`=VALUES(`UF_PRICE`),
				`UF_CURRENCY`=VALUES(`UF_CURRENCY`),
				`UF_IMAGES`=VALUES(`UF_IMAGES`),
				`UF_SOURCE`=VALUES(`UF_SOURCE`),
				`UF_UPDATED_AT`=VALUES(`UF_UPDATED_AT`)
		";
		$conn->queryExecute($sql);
	}
}

if (!function_exists('mf_analogs_meta_map_for_product'))
{
	/**
	 * @param int[] $analogIds
	 * @return array<int, array{stock:?float,price:?float,currency:?string,images:array<int,string>,updated_at:?string,source:?string}>
	 */
	function mf_analogs_meta_map_for_product(int $productId, array $analogIds): array
	{
		$productId = (int)$productId;
		if ($productId <= 0)
		{
			return [];
		}
		$analogIds = array_values(array_unique(array_map('intval', $analogIds)));
		$analogIds = array_values(array_filter($analogIds, static fn($x) => $x > 0));
		if (empty($analogIds))
		{
			return [];
		}

		$meta = mf_analogs_ensure_hl_meta();
		$table = (string)$meta['TABLE'];
		$conn = Application::getConnection();

		$in = implode(',', array_map('intval', $analogIds));
		$res = $conn->query("
			SELECT `UF_ANALOG_ID`,`UF_STOCK`,`UF_PRICE`,`UF_CURRENCY`,`UF_IMAGES`,`UF_UPDATED_AT`,`UF_SOURCE`
			FROM `" . $table . "`
			WHERE `UF_PRODUCT_ID` = " . $productId . " AND `UF_ANALOG_ID` IN (" . $in . ")
		");

		$out = [];
		while ($r = $res->fetch())
		{
			$aid = (int)($r['UF_ANALOG_ID'] ?? 0);
			if ($aid <= 0) continue;

			$imagesCsv = (string)($r['UF_IMAGES'] ?? '');
			$images = [];
			if ($imagesCsv !== '')
			{
				$parts = preg_split('~\\s*,\\s*~', $imagesCsv) ?: [];
				foreach ($parts as $p)
				{
					$p = trim((string)$p);
					if ($p !== '') $images[] = $p;
				}
			}

			$out[$aid] = [
				'stock' => ($r['UF_STOCK'] === null ? null : (float)$r['UF_STOCK']),
				'price' => ($r['UF_PRICE'] === null ? null : (float)$r['UF_PRICE']),
				'currency' => (string)($r['UF_CURRENCY'] ?? '') ?: null,
				'images' => $images,
				'updated_at' => (string)($r['UF_UPDATED_AT'] ?? '') ?: null,
				'source' => (string)($r['UF_SOURCE'] ?? '') ?: null,
			];
		}
		return $out;
	}
}

if (!function_exists('mf_analogs_meta_images_for_product'))
{
	/**
	 * Returns external image URLs for a product from analog meta table
	 * (reverse lookup: where this product appears as ANALOG_ID).
	 *
	 * @return string[]
	 */
	function mf_analogs_meta_images_for_product(int $productId): array
	{
		$productId = (int)$productId;
		if ($productId <= 0)
		{
			return [];
		}

		try
		{
			$meta = mf_analogs_ensure_hl_meta();
		}
		catch (\Throwable $e)
		{
			return [];
		}

		$table = (string)$meta['TABLE'];
		$conn = Application::getConnection();
		if (!$conn->isTableExists($table))
		{
			return [];
		}

		try
		{
			$r = $conn->query("
				SELECT `UF_IMAGES`
				FROM `" . $table . "`
				WHERE `UF_ANALOG_ID` = " . $productId . " AND `UF_IMAGES` IS NOT NULL AND `UF_IMAGES` <> ''
				ORDER BY `UF_UPDATED_AT` DESC, `ID` DESC
				LIMIT 1
			")->fetch();
		}
		catch (\Throwable $e)
		{
			return [];
		}

		$imagesCsv = is_array($r) ? (string)($r['UF_IMAGES'] ?? '') : '';
		if ($imagesCsv === '')
		{
			return [];
		}

		$parts = preg_split('~\\s*,\\s*~', $imagesCsv) ?: [];
		$out = [];
		foreach ($parts as $p)
		{
			$p = trim((string)$p);
			if ($p !== '') $out[] = $p;
		}
		return $out;
	}
}

if (!function_exists('mf_analogs_related_ids_for_product'))
{
	/**
	 * Builds "related analogs" set for a product:
	 * - direct analog links from mf_product_analogs (undirected)
	 * - plus, for each "original" where current product is an analog (meta UF_ANALOG_ID=current):
	 *   include that original and all other analogs of that original (siblings)
	 *
	 * This gives the desired UX:
	 * - on an analog card: show original + other analogs of the same original
	 * - on an original card: behaves like direct analog list
	 *
	 * @return int[]
	 */
	function mf_analogs_related_ids_for_product(int $productId, int $limit = 48): array
	{
		$productId = (int)$productId;
		if ($productId <= 0)
		{
			return [];
		}

		$limit = max(1, min(200, (int)$limit));

		// Start with direct analog links.
		$out = [];
		if (function_exists('mf_analogs_ids_for_product'))
		{
			foreach (mf_analogs_ids_for_product($productId, $limit) as $id)
			{
				$id = (int)$id;
				if ($id > 0 && $id !== $productId) $out[$id] = true;
			}
		}

		// Add "original + siblings" via meta table if available.
		try
		{
			$meta = mf_analogs_ensure_hl_meta();
			$table = (string)$meta['TABLE'];
			$conn = Application::getConnection();

			$origRes = $conn->query("
				SELECT DISTINCT `UF_PRODUCT_ID`
				FROM `" . $table . "`
				WHERE `UF_ANALOG_ID` = " . $productId . "
				LIMIT 50
			");
			$originalIds = [];
			while ($r = $origRes->fetch())
			{
				$oid = (int)($r['UF_PRODUCT_ID'] ?? 0);
				if ($oid > 0 && $oid !== $productId) $originalIds[$oid] = true;
			}

			if (!empty($originalIds))
			{
				foreach (array_keys($originalIds) as $oid)
				{
					$out[(int)$oid] = true;
				}

				$in = implode(',', array_map('intval', array_keys($originalIds)));
				$sibRes = $conn->query("
					SELECT `UF_ANALOG_ID`
					FROM `" . $table . "`
					WHERE `UF_PRODUCT_ID` IN (" . $in . ")
					LIMIT " . $limit . "
				");
				while ($r = $sibRes->fetch())
				{
					$aid = (int)($r['UF_ANALOG_ID'] ?? 0);
					if ($aid > 0 && $aid !== $productId) $out[$aid] = true;
				}
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}

		$ids = array_map('intval', array_keys($out));
		if (function_exists('mf_analogs_sort_ids_stock_priority'))
		{
			$ids = mf_analogs_sort_ids_stock_priority($ids);
		}
		if (count($ids) > $limit)
		{
			$ids = array_slice($ids, 0, $limit);
		}

		return $ids;
	}
}

