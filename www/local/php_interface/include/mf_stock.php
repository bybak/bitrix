<?php
declare(strict_types=1);

namespace Mf\Stock;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Loader;
use Bitrix\Main\EventResult;
use Bitrix\Sale\BasketItemBase;
use Bitrix\Sale\Order;
use Bitrix\Sale\ResultError;

final class Config
{
	public static function isEnabled(): bool
	{
		$env = getenv('MF_STOCK_ENABLED');
		if ($env !== false)
		{
			return in_array(strtolower(trim((string)$env)), ['1', 'true', 'yes', 'y', 'on'], true);
		}
		// default enabled (requested behavior)
		return Option::get('mf.stock', 'enabled', 'Y') === 'Y';
	}
}

final class Db
{
	public const TABLE = 'mf_store_stock_deduct';

	public static function ensureSchema(): void
	{
		$conn = Application::getConnection();
		$helper = $conn->getSqlHelper();

		if ($conn->isTableExists(self::TABLE))
		{
			return;
		}

		// Keep it minimal: one row per order with JSON snapshot.
		$conn->queryExecute(
			"CREATE TABLE " . $helper->quote(self::TABLE) . " (
				ORDER_ID INT NOT NULL,
				DEDUCTED CHAR(1) NOT NULL DEFAULT 'N',
				RESTORED CHAR(1) NOT NULL DEFAULT 'N',
				DATA_JSON LONGTEXT NULL,
				DEDUCTED_AT DATETIME NULL,
				RESTORED_AT DATETIME NULL,
				UPDATED_AT DATETIME NULL,
				PRIMARY KEY (ORDER_ID)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);
	}

	/**
	 * @return array{ORDER_ID:int,DEDUCTED:string,RESTORED:string,DATA_JSON:?string}|null
	 */
	public static function getRow(int $orderId): ?array
	{
		$conn = Application::getConnection();
		$helper = $conn->getSqlHelper();
		$orderId = (int)$orderId;
		if ($orderId <= 0)
		{
			return null;
		}
		$sql = "SELECT ORDER_ID, DEDUCTED, RESTORED, DATA_JSON
				FROM " . $helper->quote(self::TABLE) . "
				WHERE ORDER_ID=" . $orderId . " LIMIT 1";
		$row = $conn->query($sql)->fetch();
		return is_array($row) ? $row : null;
	}

	/**
	 * @param array<int, array{product_id:int,store_id:int,qty:float}> $lines
	 */
	public static function markDeducted(int $orderId, array $lines): void
	{
		$conn = Application::getConnection();
		$helper = $conn->getSqlHelper();
		$now = $helper->getCurrentDateTimeFunction();

		$json = json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($json))
		{
			$json = '[]';
		}

		$conn->queryExecute(
			"INSERT INTO " . $helper->quote(self::TABLE) . " (ORDER_ID, DEDUCTED, RESTORED, DATA_JSON, DEDUCTED_AT, UPDATED_AT)
			 VALUES (" . (int)$orderId . ", 'Y', 'N', '" . $helper->forSql($json) . "', " . $now . ", " . $now . ")
			 ON DUPLICATE KEY UPDATE
				DEDUCTED='Y',
				RESTORED='N',
				DATA_JSON=VALUES(DATA_JSON),
				DEDUCTED_AT=VALUES(DEDUCTED_AT),
				UPDATED_AT=VALUES(UPDATED_AT)"
		);
	}

	/**
	 * @param array<int, array{product_id:int,store_id:int,qty:float}> $lines
	 */
	public static function markRestored(int $orderId, array $lines): void
	{
		$conn = Application::getConnection();
		$helper = $conn->getSqlHelper();
		$now = $helper->getCurrentDateTimeFunction();

		$json = json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($json))
		{
			$json = '[]';
		}

		$conn->queryExecute(
			"INSERT INTO " . $helper->quote(self::TABLE) . " (ORDER_ID, DEDUCTED, RESTORED, DATA_JSON, RESTORED_AT, UPDATED_AT)
			 VALUES (" . (int)$orderId . ", 'N', 'Y', '" . $helper->forSql($json) . "', " . $now . ", " . $now . ")
			 ON DUPLICATE KEY UPDATE
				DEDUCTED='N',
				RESTORED='Y',
				DATA_JSON=VALUES(DATA_JSON),
				RESTORED_AT=VALUES(RESTORED_AT),
				UPDATED_AT=VALUES(UPDATED_AT)"
		);
	}
}

final class Bootstrap
{
	private static bool $inited = false;

	public static function init(): void
	{
		if (self::$inited)
		{
			return;
		}
		self::$inited = true;

		if (!class_exists(Loader::class))
		{
			return;
		}
		if (!Loader::includeModule('sale') || !Loader::includeModule('catalog'))
		{
			return;
		}

		try
		{
			Db::ensureSchema();
		}
		catch (\Throwable $e)
		{
			self::log('Failed to ensure schema', ['e' => $e->getMessage()]);
		}

		\Bitrix\Main\EventManager::getInstance()->addEventHandler(
			'sale',
			'OnSaleOrderSaved',
			[Handlers::class, 'onSaleOrderSaved']
		);

		\Bitrix\Main\EventManager::getInstance()->addEventHandler(
			'sale',
			'OnSaleOrderBeforeSaved',
			[Handlers::class, 'onSaleOrderBeforeSaved']
		);
	}

	public static function log(string $message, array $context = []): void
	{
		if (!empty($context))
		{
			$message .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		Debug::writeToFile($message, '', 'mf_stock.log');
	}
}

final class Stock
{
	/**
	 * @return array<int, array{product_id:int,store_id:int,qty:float}>
	 */
	public static function orderLines(Order $order): array
	{
		$lines = [];
		$basket = $order->getBasket();
		if (!$basket)
		{
			return $lines;
		}

		foreach ($basket as $item)
		{
			if (!$item instanceof BasketItemBase)
			{
				continue;
			}

			$productId = (int)$item->getProductId();
			$qty = (float)$item->getQuantity();
			if ($productId <= 0 || $qty <= 0)
			{
				continue;
			}

			$storeId = (int)(self::basketProp($item, 'MF_STORE_ID') ?? 0);
			if ($storeId <= 0 && function_exists('mf_min_price_from_available_stores'))
			{
				/** @var array{0:mixed,1:int} $best */
				$best = \mf_min_price_from_available_stores($productId);
				$storeId = (int)($best[1] ?? 0);
			}

			if ($storeId <= 0)
			{
				continue;
			}

			$lines[] = [
				'product_id' => $productId,
				'store_id' => $storeId,
				'qty' => $qty,
			];
		}

		// Merge duplicates (same product+store).
		$merged = [];
		foreach ($lines as $l)
		{
			$key = (int)$l['product_id'] . ':' . (int)$l['store_id'];
			if (!isset($merged[$key]))
			{
				$merged[$key] = $l;
				continue;
			}
			$merged[$key]['qty'] = (float)$merged[$key]['qty'] + (float)$l['qty'];
		}

		return array_values($merged);
	}

	public static function deduct(array $lines): bool
	{
		$conn = Application::getConnection();

		$conn->startTransaction();
		try
		{
			foreach ($lines as $l)
			{
				$productId = (int)($l['product_id'] ?? 0);
				$storeId = (int)($l['store_id'] ?? 0);
				$qty = (float)($l['qty'] ?? 0);
				if ($productId <= 0 || $storeId <= 0 || $qty <= 0)
				{
					continue;
				}

				// Atomic: don't allow negative stock.
				$sql = "UPDATE b_catalog_store_product
						SET AMOUNT = AMOUNT - " . (float)$qty . "
						WHERE PRODUCT_ID = " . (int)$productId . "
						  AND STORE_ID = " . (int)$storeId . "
						  AND AMOUNT >= " . (float)$qty;
				$conn->queryExecute($sql);

				$affected = (int)$conn->getAffectedRowsCount();
				if ($affected <= 0)
				{
					throw new \RuntimeException('Not enough stock for product_id=' . $productId . ', store_id=' . $storeId);
				}
			}
			$conn->commitTransaction();
			return true;
		}
		catch (\Throwable $e)
		{
			$conn->rollbackTransaction();
			Bootstrap::log('Stock deduct failed', ['e' => $e->getMessage()]);
			return false;
		}
	}

	public static function restore(array $lines): bool
	{
		$conn = Application::getConnection();

		$conn->startTransaction();
		try
		{
			foreach ($lines as $l)
			{
				$productId = (int)($l['product_id'] ?? 0);
				$storeId = (int)($l['store_id'] ?? 0);
				$qty = (float)($l['qty'] ?? 0);
				if ($productId <= 0 || $storeId <= 0 || $qty <= 0)
				{
					continue;
				}

				$sql = "UPDATE b_catalog_store_product
						SET AMOUNT = AMOUNT + " . (float)$qty . "
						WHERE PRODUCT_ID = " . (int)$productId . "
						  AND STORE_ID = " . (int)$storeId;
				$conn->queryExecute($sql);
			}
			$conn->commitTransaction();
			return true;
		}
		catch (\Throwable $e)
		{
			$conn->rollbackTransaction();
			Bootstrap::log('Stock restore failed', ['e' => $e->getMessage()]);
			return false;
		}
	}

	private static function basketProp(BasketItemBase $item, string $code): ?string
	{
		$code = (string)$code;
		$pc = $item->getPropertyCollection();
		foreach ($pc as $p)
		{
			if ((string)$p->getField('CODE') === $code)
			{
				$v = $p->getField('VALUE');
				if (is_array($v))
				{
					$v = reset($v);
				}
				$v = trim((string)$v);
				return $v !== '' ? $v : null;
			}
		}
		return null;
	}

	public static function availableAmount(int $productId, int $storeId): float
	{
		$productId = (int)$productId;
		$storeId = (int)$storeId;
		if ($productId <= 0 || $storeId <= 0)
		{
			return 0.0;
		}

		try
		{
			$conn = Application::getConnection();
			$val = $conn->queryScalar(
				"SELECT AMOUNT
				 FROM b_catalog_store_product
				 WHERE PRODUCT_ID=" . $productId . "
				   AND STORE_ID=" . $storeId
			);
			return (float)$val;
		}
		catch (\Throwable $e)
		{
			return 0.0;
		}
	}
}

final class Handlers
{
	/**
	 * sale:OnSaleOrderBeforeSaved
	 * Возвращаем EventResult::ERROR, чтобы заблокировать создание заказа при нехватке остатков.
	 */
	public static function onSaleOrderBeforeSaved(\Bitrix\Main\Event $event)
	{
		if (!Config::isEnabled())
		{
			return null;
		}

		/** @var Order|null $order */
		$order = $event->getParameter('ENTITY');
		if (!$order instanceof Order)
		{
			return null;
		}

		// Only for new orders (block checkout). Existing orders in admin should not be blocked here.
		$orderId = (int)$order->getId();
		if ($orderId > 0)
		{
			return null;
		}

		if ((string)$order->getField('CANCELED') === 'Y')
		{
			return null;
		}

		$basket = $order->getBasket();
		if (!$basket)
		{
			return null;
		}

		// Build map productId -> name for better error messages.
		$names = [];
		foreach ($basket as $item)
		{
			if ($item instanceof BasketItemBase)
			{
				$pid = (int)$item->getProductId();
				if ($pid > 0 && !isset($names[$pid]))
				{
					$names[$pid] = (string)$item->getField('NAME');
				}
			}
		}

		$lines = Stock::orderLines($order);
		if (empty($lines))
		{
			return null;
		}

		$issues = [];
		foreach ($lines as $l)
		{
			$pid = (int)($l['product_id'] ?? 0);
			$sid = (int)($l['store_id'] ?? 0);
			$need = (float)($l['qty'] ?? 0);
			if ($pid <= 0 || $sid <= 0 || $need <= 0)
			{
				continue;
			}

			$avail = Stock::availableAmount($pid, $sid);
			if ($avail + 1e-9 < $need)
			{
				$title = trim((string)($names[$pid] ?? ''));
				if ($title === '')
				{
					$title = 'Товар #' . $pid;
				}
				$issues[] = $title . ' (нужно: ' . $need . ', доступно: ' . $avail . ')';
			}
		}

		if (empty($issues))
		{
			return null;
		}

		$msg = "Недостаточно товара на складе для оформления заказа:\n- " . implode("\n- ", $issues);
		return new EventResult(
			EventResult::ERROR,
			new ResultError($msg, 'MF_STOCK_NOT_ENOUGH'),
			'sale'
		);
	}

	public static function onSaleOrderSaved(\Bitrix\Main\Event $event): void
	{
		if (!Config::isEnabled())
		{
			return;
		}

		/** @var Order|null $order */
		$order = $event->getParameter('ENTITY');
		if (!$order instanceof Order)
		{
			$order = $event->getParameter('ORDER');
		}
		if (!$order instanceof Order)
		{
			return;
		}

		$orderId = (int)$order->getId();
		if ($orderId <= 0)
		{
			return;
		}

		$isNew = (bool)$event->getParameter('IS_NEW');
		$isCanceled = (string)$order->getField('CANCELED') === 'Y';

		try
		{
			Db::ensureSchema();
		}
		catch (\Throwable $e)
		{
			Bootstrap::log('Schema check failed', ['e' => $e->getMessage()]);
			return;
		}

		$row = Db::getRow($orderId);
		$deductedAlready = is_array($row) && ($row['DEDUCTED'] ?? 'N') === 'Y' && ($row['RESTORED'] ?? 'N') !== 'Y';
		$restoredAlready = is_array($row) && ($row['RESTORED'] ?? 'N') === 'Y';

		// Desired state:
		// - on new (or uncanceled) orders: deducted
		// - on canceled orders: restored (if was deducted)
		if ($isCanceled)
		{
			if (!$deductedAlready)
			{
				return;
			}

			$lines = [];
			$raw = is_array($row) ? (string)($row['DATA_JSON'] ?? '') : '';
			if ($raw !== '')
			{
				$decoded = json_decode($raw, true);
				if (is_array($decoded))
				{
					$lines = $decoded;
				}
			}
			if (empty($lines))
			{
				$lines = Stock::orderLines($order);
			}

			if (Stock::restore($lines))
			{
				Db::markRestored($orderId, $lines);
			}

			return;
		}

		// Not canceled
		if ($deductedAlready)
		{
			return;
		}
		if (!$isNew && !$restoredAlready)
		{
			// Avoid retroactively deducting old orders unless they were explicitly restored and then uncanceled.
			return;
		}

		$lines = Stock::orderLines($order);
		if (empty($lines))
		{
			return;
		}

		if (Stock::deduct($lines))
		{
			Db::markDeducted($orderId, $lines);
		}
		else
		{
			// This should not normally happen because we validate in OnSaleOrderBeforeSaved,
			// but can happen due to concurrent checkouts.
			Bootstrap::log('Stock deduct failed after order saved (race?)', ['orderId' => $orderId, 'lines' => $lines]);
		}
	}
}

