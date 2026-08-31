<?php

declare(strict_types=1);

/**
 * Внешние склады и прайсы (FEED_CODE): без отдельного хранения остатков по прайсу.
 * Остатки только в b_catalog_store_product. Здесь — реестр кодов прайсов и привязка товаров к прайсу
 * после загрузки внешних цен (для списка в админке и частичной очистки).
 */

use Bitrix\Main\Application;

if (!function_exists('mf_esf_conn'))
{
	function mf_esf_conn(): ?\Bitrix\Main\DB\Connection
	{
		if (!class_exists(Application::class))
		{
			return null;
		}
		try
		{
			return Application::getConnection();
		}
		catch (Throwable $e)
		{
			return null;
		}
	}
}

if (!function_exists('mf_esf_normalize_feed_code'))
{
	function mf_esf_normalize_feed_code(string $s): string
	{
		$s = mb_strtoupper(trim($s));
		$s = preg_replace('~[^A-Z0-9_-]+~', '', $s) ?? '';

		return mb_substr($s, 0, 64);
	}
}

if (!function_exists('mf_esf_ensure_registry_table'))
{
	/**
	 * Реестр кодов прайсов по складу (загрузка внешних цен и т.п.).
	 */
	function mf_esf_ensure_registry_table(): bool
	{
		$conn = mf_esf_conn();
		if (!$conn)
		{
			return false;
		}
		$driver = method_exists($conn, 'getType') ? (string)$conn->getType() : '';
		if ($driver !== '' && stripos($driver, 'mysql') === false)
		{
			return false;
		}

		try
		{
			$conn->queryExecute('CREATE TABLE IF NOT EXISTS mf_external_store_feed_registry (
				STORE_ID INT NOT NULL,
				FEED_CODE VARCHAR(64) NOT NULL,
				UPDATED_AT DATETIME NOT NULL,
				PRIMARY KEY (STORE_ID, FEED_CODE),
				KEY IX_STORE (STORE_ID)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
		}
		catch (Throwable $e)
		{
			return false;
		}

		return true;
	}
}

if (!function_exists('mf_esf_register_store_feed'))
{
	function mf_esf_register_store_feed(int $storeId, string $feedCode): void
	{
		$storeId = (int)$storeId;
		$feedCode = mf_esf_normalize_feed_code($feedCode);
		if ($storeId <= 0 || $feedCode === '' || !mf_esf_ensure_registry_table())
		{
			return;
		}
		$conn = mf_esf_conn();
		if (!$conn)
		{
			return;
		}
		$h = $conn->getSqlHelper();
		$fc = $h->forSql($feedCode);
		$conn->queryExecute("
			INSERT INTO mf_external_store_feed_registry (STORE_ID, FEED_CODE, UPDATED_AT)
			VALUES (" . $storeId . ", '" . $fc . "', NOW())
			ON DUPLICATE KEY UPDATE UPDATED_AT = VALUES(UPDATED_AT)
		");
	}
}

if (!function_exists('mf_esf_registry_delete_all_for_store'))
{
	function mf_esf_registry_delete_all_for_store(int $storeId): void
	{
		$storeId = (int)$storeId;
		if ($storeId <= 0 || !mf_esf_ensure_registry_table())
		{
			return;
		}
		$conn = mf_esf_conn();
		if (!$conn)
		{
			return;
		}
		$conn->queryExecute('DELETE FROM mf_external_store_feed_registry WHERE STORE_ID = ' . $storeId);
	}
}

if (!function_exists('mf_esf_registry_remove_feed'))
{
	function mf_esf_registry_remove_feed(int $storeId, string $feedCode): void
	{
		$storeId = (int)$storeId;
		$feedCode = mf_esf_normalize_feed_code($feedCode);
		if ($storeId <= 0 || $feedCode === '' || !mf_esf_ensure_registry_table())
		{
			return;
		}
		$conn = mf_esf_conn();
		if (!$conn)
		{
			return;
		}
		$h = $conn->getSqlHelper();
		$fc = $h->forSql($feedCode);
		$conn->queryExecute(
			"DELETE FROM mf_external_store_feed_registry WHERE STORE_ID = {$storeId} AND FEED_CODE = '{$fc}'"
		);
	}
}

if (!function_exists('mf_esf_ensure_price_product_table'))
{
	function mf_esf_ensure_price_product_table(): bool
	{
		$conn = mf_esf_conn();
		if (!$conn)
		{
			return false;
		}
		$driver = method_exists($conn, 'getType') ? (string)$conn->getType() : '';
		if ($driver !== '' && stripos($driver, 'mysql') === false)
		{
			return false;
		}
		try
		{
			$conn->queryExecute('CREATE TABLE IF NOT EXISTS mf_external_store_feed_price_product (
				STORE_ID INT NOT NULL,
				FEED_CODE VARCHAR(64) NOT NULL,
				PRODUCT_ID INT NOT NULL,
				UPDATED_AT DATETIME NOT NULL,
				PRIMARY KEY (STORE_ID, FEED_CODE, PRODUCT_ID),
				KEY IX_STORE_PID (STORE_ID, PRODUCT_ID),
				KEY IX_STORE_FEED (STORE_ID, FEED_CODE)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
		}
		catch (Throwable $e)
		{
			return false;
		}

		return true;
	}
}

if (!function_exists('mf_esf_touch_price_product'))
{
	function mf_esf_touch_price_product(int $storeId, string $feedCode, int $productId): void
	{
		$storeId = (int)$storeId;
		$productId = (int)$productId;
		$feedCode = mf_esf_normalize_feed_code($feedCode);
		if ($storeId <= 0 || $productId <= 0 || $feedCode === '' || !mf_esf_ensure_price_product_table())
		{
			return;
		}
		$conn = mf_esf_conn();
		if (!$conn)
		{
			return;
		}
		$h = $conn->getSqlHelper();
		$fc = $h->forSql($feedCode);
		$conn->queryExecute(
			'INSERT INTO mf_external_store_feed_price_product (STORE_ID, FEED_CODE, PRODUCT_ID, UPDATED_AT) VALUES ('
			. $storeId . ", '" . $fc . "', " . $productId . ", NOW()) "
			. 'ON DUPLICATE KEY UPDATE UPDATED_AT = VALUES(UPDATED_AT)'
		);
	}
}

if (!function_exists('mf_esf_untouch_price_product'))
{
	function mf_esf_untouch_price_product(int $storeId, string $feedCode, int $productId): void
	{
		$storeId = (int)$storeId;
		$productId = (int)$productId;
		$feedCode = mf_esf_normalize_feed_code($feedCode);
		if ($storeId <= 0 || $productId <= 0 || $feedCode === '' || !mf_esf_ensure_price_product_table())
		{
			return;
		}
		$conn = mf_esf_conn();
		if (!$conn)
		{
			return;
		}
		$h = $conn->getSqlHelper();
		$fc = $h->forSql($feedCode);
		$conn->queryExecute(
			"DELETE FROM mf_external_store_feed_price_product WHERE STORE_ID = {$storeId} AND FEED_CODE = '{$fc}' AND PRODUCT_ID = {$productId}"
		);
	}
}

if (!function_exists('mf_esf_price_touch_other_feed_exists'))
{
	function mf_esf_price_touch_other_feed_exists(int $storeId, int $productId): bool
	{
		$storeId = (int)$storeId;
		$productId = (int)$productId;
		if ($storeId <= 0 || $productId <= 0 || !mf_esf_ensure_price_product_table())
		{
			return false;
		}
		$conn = mf_esf_conn();
		if (!$conn)
		{
			return false;
		}
		$res = $conn->query(
			'SELECT 1 FROM mf_external_store_feed_price_product WHERE STORE_ID = ' . $storeId
			. ' AND PRODUCT_ID = ' . $productId . ' LIMIT 1'
		);
		if (!$res)
		{
			return false;
		}

		return (bool)$res->fetch();
	}
}

if (!function_exists('mf_esf_delete_feed_price_products_collect'))
{
	/**
	 * @return list<int>
	 */
	function mf_esf_delete_feed_price_products_collect(int $storeId, string $feedCode): array
	{
		$storeId = (int)$storeId;
		$feedCode = mf_esf_normalize_feed_code($feedCode);
		if ($storeId <= 0 || $feedCode === '' || !mf_esf_ensure_price_product_table())
		{
			return [];
		}
		$conn = mf_esf_conn();
		if (!$conn)
		{
			return [];
		}
		$h = $conn->getSqlHelper();
		$fc = $h->forSql($feedCode);
		$pids = [];
		$res = $conn->query(
			"SELECT PRODUCT_ID FROM mf_external_store_feed_price_product WHERE STORE_ID = {$storeId} AND FEED_CODE = '{$fc}'"
		);
		if ($res)
		{
			while ($r = $res->fetch())
			{
				$pid = (int)($r['PRODUCT_ID'] ?? 0);
				if ($pid > 0)
				{
					$pids[$pid] = true;
				}
			}
		}
		$conn->queryExecute(
			"DELETE FROM mf_external_store_feed_price_product WHERE STORE_ID = {$storeId} AND FEED_CODE = '{$fc}'"
		);

		return array_keys($pids);
	}
}

if (!function_exists('mf_esf_delete_all_price_product_for_store'))
{
	function mf_esf_delete_all_price_product_for_store(int $storeId): void
	{
		$storeId = (int)$storeId;
		if ($storeId <= 0 || !mf_esf_ensure_price_product_table())
		{
			return;
		}
		$conn = mf_esf_conn();
		if (!$conn)
		{
			return;
		}
		$conn->queryExecute('DELETE FROM mf_external_store_feed_price_product WHERE STORE_ID = ' . $storeId);
	}
}

if (!function_exists('mf_esf_feed_product_counts_for_store'))
{
	/**
	 * Число привязанных товаров по каждому коду прайса на складе.
	 *
	 * @return array<string, int>
	 */
	function mf_esf_feed_product_counts_for_store(int $storeId): array
	{
		$storeId = (int)$storeId;
		if ($storeId <= 0 || !mf_esf_ensure_price_product_table())
		{
			return [];
		}
		$conn = mf_esf_conn();
		if (!$conn)
		{
			return [];
		}
		$counts = [];
		try
		{
			$res = $conn->query(
				'SELECT FEED_CODE, COUNT(*) AS CNT FROM mf_external_store_feed_price_product'
				. ' WHERE STORE_ID = ' . $storeId
				. ' GROUP BY FEED_CODE'
			);
			if ($res)
			{
				while ($r = $res->fetch())
				{
					$c = trim((string)($r['FEED_CODE'] ?? ''));
					if ($c !== '')
					{
						$counts[$c] = (int)($r['CNT'] ?? 0);
					}
				}
			}
		}
		catch (Throwable $e)
		{
		}

		return $counts;
	}
}

if (!function_exists('mf_esf_list_feeds_for_store'))
{
	/**
	 * @return list<array{feed_code: string, product_count: int}>
	 */
	function mf_esf_list_feeds_for_store(int $storeId): array
	{
		$storeId = (int)$storeId;
		if ($storeId <= 0)
		{
			return [];
		}
		$conn = mf_esf_conn();
		if (!$conn)
		{
			return [];
		}
		mf_esf_ensure_registry_table();
		mf_esf_ensure_price_product_table();
		$counts = mf_esf_feed_product_counts_for_store($storeId);
		$set = [];
		try
		{
			$resR = $conn->query(
				'SELECT FEED_CODE FROM mf_external_store_feed_registry WHERE STORE_ID = ' . $storeId
			);
			if ($resR)
			{
				while ($r = $resR->fetch())
				{
					$c = trim((string)($r['FEED_CODE'] ?? ''));
					if ($c !== '')
					{
						$set[$c] = true;
					}
				}
			}
		}
		catch (Throwable $e)
		{
		}
		foreach (array_keys($counts) as $c)
		{
			$set[$c] = true;
		}
		$codes = array_keys($set);
		sort($codes, SORT_STRING);
		$out = [];
		foreach ($codes as $c)
		{
			$out[] = [
				'feed_code' => $c,
				'product_count' => (int)($counts[$c] ?? 0),
			];
		}

		return $out;
	}
}

if (!function_exists('mf_esf_list_feed_codes_for_store'))
{
	/**
	 * @return list<string>
	 */
	function mf_esf_list_feed_codes_for_store(int $storeId): array
	{
		$out = [];
		foreach (mf_esf_list_feeds_for_store($storeId) as $row)
		{
			$c = trim((string)($row['feed_code'] ?? ''));
			if ($c !== '')
			{
				$out[] = $c;
			}
		}

		return $out;
	}
}
