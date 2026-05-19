<?php

declare(strict_types=1);

namespace Mf\Unf;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;

final class Config
{
	public static function isEnabled(): bool
	{
		$env = getenv('MF_UNF_ENABLED');
		if ($env !== false)
		{
			return in_array(strtolower(trim((string)$env)), ['1', 'true', 'yes', 'y', 'on'], true);
		}
		return Option::get('mf.unf', 'enabled', 'N') === 'Y';
	}

	public static function endpoint(): string
	{
		$env = getenv('MF_UNF_ENDPOINT');
		if ($env !== false && trim((string)$env) !== '')
		{
			return trim((string)$env);
		}
		return trim(Option::get('mf.unf', 'endpoint', ''));
	}

	/**
	 * Endpoint for payment updates. If empty, uses the main endpoint().
	 * Payload: external_id = unf_order_number = "{USER_ID}-{ORDER_ID}" (печатный ключ и номер в УНФ; без legacy).
	 */
	public static function paidEndpoint(): string
	{
		$env = getenv('MF_UNF_PAID_ENDPOINT');
		if ($env !== false && trim((string)$env) !== '')
		{
			return trim((string)$env);
		}
		return self::endpoint();
	}

	public static function token(): string
	{
		$env = getenv('MF_UNF_TOKEN');
		if ($env !== false && trim((string)$env) !== '')
		{
			return trim((string)$env);
		}
		return trim(Option::get('mf.unf', 'token', ''));
	}

	public static function basicUser(): string
	{
		$env = getenv('MF_UNF_BASIC_USER');
		if ($env !== false && trim((string)$env) !== '')
		{
			return trim((string)$env);
		}
		return trim(Option::get('mf.unf', 'basic_user', ''));
	}

	public static function basicPass(): string
	{
		$env = getenv('MF_UNF_BASIC_PASS');
		if ($env !== false && trim((string)$env) !== '')
		{
			return trim((string)$env);
		}
		return trim(Option::get('mf.unf', 'basic_pass', ''));
	}

	public static function timeoutSeconds(): int
	{
		$env = getenv('MF_UNF_TIMEOUT');
		if ($env !== false && is_numeric($env))
		{
			return max(1, (int)$env);
		}
		return (int)Option::get('mf.unf', 'timeout', '10');
	}

	/**
	 * Default currency code for 1C (e.g. RUB).
	 * If empty, will use Bitrix order currency as-is.
	 */
	public static function currency(): string
	{
		$env = getenv('MF_UNF_CURRENCY');
		if ($env !== false && trim((string)$env) !== '')
		{
			return trim((string)$env);
		}
		return trim(Option::get('mf.unf', 'currency', ''));
	}

	/**
	 * Default operation for 1C "Заказ покупателя".
	 * In many UNF configurations this is required.
	 * Examples: "Продажа", "Заказ покупателя" (depends on handler in 1C).
	 */
	public static function operation(): string
	{
		$env = getenv('MF_UNF_OPERATION');
		if ($env !== false && trim((string)$env) !== '')
		{
			return trim((string)$env);
		}
		return trim(Option::get('mf.unf', 'operation', ''));
	}

	/**
	 * Default taxation system for the order in 1C.
	 * Examples: "ОСН", "УСН", "Патент" (depends on handler in 1C).
	 */
	public static function taxation(): string
	{
		$env = getenv('MF_UNF_TAXATION');
		if ($env !== false && trim((string)$env) !== '')
		{
			return trim((string)$env);
		}
		return trim(Option::get('mf.unf', 'taxation', ''));
	}

	/**
	 * Default currency rate and multiplicity for 1C.
	 * In UNF documents these are often mandatory even for RUB.
	 */
	public static function currencyRate(): float
	{
		$env = getenv('MF_UNF_CURRENCY_RATE');
		if ($env !== false && is_numeric($env))
		{
			$v = (float)$env;
			return $v > 0 ? $v : 1.0;
		}
		$v = (float)Option::get('mf.unf', 'currency_rate', '1');
		return $v > 0 ? $v : 1.0;
	}

	public static function currencyMultiplicity(): int
	{
		$env = getenv('MF_UNF_CURRENCY_MULTIPLICITY');
		if ($env !== false && is_numeric($env))
		{
			$v = (int)$env;
			return $v > 0 ? $v : 1;
		}
		$v = (int)Option::get('mf.unf', 'currency_multiplicity', '1');
		return $v > 0 ? $v : 1;
	}

	public static function logEnabled(): bool
	{
		$env = getenv('MF_UNF_LOG');
		if ($env !== false)
		{
			return in_array(strtolower(trim((string)$env)), ['1', 'true', 'yes', 'y', 'on'], true);
		}
		return Option::get('mf.unf', 'log', 'Y') === 'Y';
	}
}

final class Db
{
	public const TABLE = 'mf_unf_queue';

	public static function ensureSchema(): void
	{
		$conn = Application::getConnection();
		if ($conn->isTableExists(self::TABLE))
		{
			return;
		}

		// Minimal queue schema. Using TEXT for payload/response for compatibility.
		$sql = "
			CREATE TABLE `" . self::TABLE . "` (
				`ID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`ORDER_ID` INT UNSIGNED NOT NULL,
				`STATUS` VARCHAR(16) NOT NULL DEFAULT 'new',
				`ATTEMPTS` INT UNSIGNED NOT NULL DEFAULT 0,
				`NEXT_RETRY_AT` DATETIME NULL,
				`LAST_ERROR` TEXT NULL,
				`PAYLOAD_JSON` MEDIUMTEXT NOT NULL,
				`RESPONSE_HTTP_CODE` INT NULL,
				`RESPONSE_BODY` MEDIUMTEXT NULL,
				`CREATED_AT` DATETIME NOT NULL,
				`UPDATED_AT` DATETIME NOT NULL,
				`SENT_AT` DATETIME NULL,
				PRIMARY KEY (`ID`),
				UNIQUE KEY `UX_ORDER_ID` (`ORDER_ID`),
				KEY `IX_STATUS_NEXT` (`STATUS`, `NEXT_RETRY_AT`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
		";
		$conn->queryExecute($sql);
	}
}

final class DbPaid
{
	public const TABLE = 'mf_unf_paid_queue';

	public static function ensureSchema(): void
	{
		$conn = Application::getConnection();
		if ($conn->isTableExists(self::TABLE))
		{
			// Soft-migrate: add PAYLOAD_JSON for better debugging (older installs may not have it).
			try
			{
				$col = $conn->query("SHOW COLUMNS FROM `" . self::TABLE . "` LIKE 'PAYLOAD_JSON'")->fetch();
				if (!$col)
				{
					$conn->queryExecute("ALTER TABLE `" . self::TABLE . "` ADD COLUMN `PAYLOAD_JSON` MEDIUMTEXT NULL AFTER `LAST_ERROR`");
				}
			}
			catch (\Throwable $e)
			{
				// ignore (no rights / unsupported)
			}
			return;
		}

		$sql = "
			CREATE TABLE `" . self::TABLE . "` (
				`ID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`ORDER_ID` INT UNSIGNED NOT NULL,
				`PAYMENT_ID` INT UNSIGNED NULL,
				`PAY_SYSTEM_ACTION_ID` INT UNSIGNED NULL,
				`STATUS` VARCHAR(16) NOT NULL DEFAULT 'new',
				`ATTEMPTS` INT UNSIGNED NOT NULL DEFAULT 0,
				`NEXT_RETRY_AT` DATETIME NULL,
				`LAST_ERROR` TEXT NULL,
				`PAYLOAD_JSON` MEDIUMTEXT NULL,
				`RESPONSE_HTTP_CODE` INT NULL,
				`RESPONSE_BODY` MEDIUMTEXT NULL,
				`CREATED_AT` DATETIME NOT NULL,
				`UPDATED_AT` DATETIME NOT NULL,
				`SENT_AT` DATETIME NULL,
				PRIMARY KEY (`ID`),
				UNIQUE KEY `UX_ORDER_ID` (`ORDER_ID`),
				KEY `IX_STATUS_NEXT` (`STATUS`, `NEXT_RETRY_AT`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
		";
		$conn->queryExecute($sql);
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

		if (!class_exists('\\Bitrix\\Main\\Loader'))
		{
			return;
		}

		// Ensure our queue table exists (lazy safe).
		try
		{
			Db::ensureSchema();
			DbPaid::ensureSchema();
		}
		catch (\Throwable $e)
		{
			self::log('Failed to ensure queue schema: ' . $e->getMessage());
		}

		// Register event handler for new orders.
		try
		{
			if (Loader::includeModule('sale'))
			{
				\Bitrix\Main\EventManager::getInstance()->addEventHandler(
					'sale',
					'OnSaleOrderSaved',
					[Handlers::class, 'onSaleOrderSaved']
				);

				\Bitrix\Main\EventManager::getInstance()->addEventHandler(
					'sale',
					'OnSalePaymentEntitySaved',
					[Handlers::class, 'onSalePaymentEntitySaved']
				);
			}
		}
		catch (\Throwable $e)
		{
			self::log('Failed to register sale events: ' . $e->getMessage());
		}

		// Ensure agent exists (works both in hit-based agents and cron agents mode).
		try
		{
			if (class_exists('\\CAgent'))
			{
				Agent::ensureRegistered();
			}
		}
		catch (\Throwable $e)
		{
			self::log('Failed to register agent: ' . $e->getMessage());
		}
	}

	public static function log(string $message, array $context = []): void
	{
		if (!Config::logEnabled())
		{
			return;
		}
		if (!empty($context))
		{
			$message .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		Debug::writeToFile($message, '', 'mf_unf.log');
	}
}

final class Handlers
{
	/**
	 * sale:OnSaleOrderSaved
	 * @param \Bitrix\Main\Event $event
	 */
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

		$isNew = (bool)$event->getParameter('IS_NEW');
		if (!$isNew)
		{
			return;
		}

		$endpoint = Config::endpoint();
		if ($endpoint === '')
		{
			Bootstrap::log('UNF endpoint is empty; skip enqueue', ['orderId' => $order->getId()]);
			return;
		}

		try
		{
			Queue::enqueueOrder($order);
		}
		catch (\Throwable $e)
		{
			Bootstrap::log('Failed to enqueue order', ['orderId' => $order->getId(), 'e' => $e->getMessage()]);
		}
	}

	/**
	 * sale:OnSalePaymentEntitySaved
	 * Enqueue an UNF update when payment becomes PAID=Y.
	 */
	public static function onSalePaymentEntitySaved(\Bitrix\Main\Event $event): void
	{
		if (!Config::isEnabled())
		{
			return;
		}

		$entity = $event->getParameter('ENTITY');
		if (!$entity instanceof Payment)
		{
			return;
		}

		$values = $event->getParameter('VALUES');
		$wasPaid = is_array($values) && (string)($values['PAID'] ?? 'N') === 'Y';
		$isPaid = (string)$entity->getField('PAID') === 'Y';
		if (!$isPaid || $wasPaid)
		{
			return;
		}

		$psActionId = (int)$entity->getPaymentSystemId();
		if ($psActionId <= 0)
		{
			return;
		}
		if (!self::isPaykeeperAction($psActionId))
		{
			return;
		}

		$orderId = (int)$entity->getOrderId();
		if ($orderId <= 0)
		{
			return;
		}

		try
		{
			DbPaid::ensureSchema();
			QueuePaid::enqueuePaid($orderId, (int)$entity->getId(), $psActionId);
		}
		catch (\Throwable $e)
		{
			Bootstrap::log('Failed to enqueue paid update', [
				'orderId' => $orderId,
				'paymentId' => (int)$entity->getId(),
				'psActionId' => $psActionId,
				'e' => $e->getMessage(),
			]);
		}
	}

	private static function isPaykeeperAction(int $psActionId): bool
	{
		static $cache = [];
		if (isset($cache[$psActionId]))
		{
			return (bool)$cache[$psActionId];
		}

		try
		{
			$conn = Application::getConnection();
			$row = $conn->query("
				SELECT ACTION_FILE
				FROM b_sale_pay_system_action
				WHERE ID = " . (int)$psActionId . "
				LIMIT 1
			")->fetch();
			$af = is_array($row) ? trim((string)($row['ACTION_FILE'] ?? '')) : '';
			$ok = ($af === 'mf_paykeeper' || $af === 'mfpaykeeper');
			$cache[$psActionId] = $ok;
			return $ok;
		}
		catch (\Throwable $e)
		{
			$cache[$psActionId] = false;
			return false;
		}
	}
}

final class Agent
{
	public static function ensureRegistered(): void
	{
		$agentName = '\\' . __CLASS__ . '::run();';
		$res = \CAgent::GetList([], ['NAME' => $agentName]);
		if ($res && ($row = $res->Fetch()))
		{
			return;
		}

		\CAgent::AddAgent(
			$agentName,
			'main',
			'N',
			60, // every minute
			'',
			'Y',
			ConvertTimeStamp(time() + 60, 'FULL')
		);
	}

	/**
	 * Bitrix agent callback, must return next call string.
	 */
	public static function run(): string
	{
		try
		{
			Queue::process(5);
			QueuePaid::process(5);
		}
		catch (\Throwable $e)
		{
			Bootstrap::log('Agent run failed', ['e' => $e->getMessage()]);
		}
		return '\\' . __CLASS__ . '::run();';
	}
}

final class Queue
{
	public static function enqueueOrder(Order $order): void
	{
		Db::ensureSchema();

		$orderId = (int)$order->getId();
		if ($orderId <= 0)
		{
			throw new \RuntimeException('Order has no ID yet');
		}

		$payload = Payload::build($order);
		// Keep payload JSON ASCII-only (unicode escaped) to avoid charset issues on 1C side.
		$payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
		if (!is_string($payloadJson) || $payloadJson === '')
		{
			throw new \RuntimeException('Failed to encode payload JSON');
		}

		$conn = Application::getConnection();
		$now = new DateTime();
		$nowSql = $conn->getSqlHelper()->convertToDbDateTime($now);

		// Upsert by ORDER_ID (unique key).
		$sql = "
			INSERT INTO `" . Db::TABLE . "` 
				(`ORDER_ID`, `STATUS`, `ATTEMPTS`, `NEXT_RETRY_AT`, `LAST_ERROR`, `PAYLOAD_JSON`, `RESPONSE_HTTP_CODE`, `RESPONSE_BODY`, `CREATED_AT`, `UPDATED_AT`, `SENT_AT`)
			VALUES
				(" . $orderId . ", 'new', 0, NULL, NULL, '" . $conn->getSqlHelper()->forSql($payloadJson) . "', NULL, NULL, " . $nowSql . ", " . $nowSql . ", NULL)
			ON DUPLICATE KEY UPDATE
				`STATUS` = 'new',
				`PAYLOAD_JSON` = VALUES(`PAYLOAD_JSON`),
				`UPDATED_AT` = VALUES(`UPDATED_AT`),
				`LAST_ERROR` = NULL,
				`RESPONSE_HTTP_CODE` = NULL,
				`RESPONSE_BODY` = NULL,
				`NEXT_RETRY_AT` = NULL,
				`SENT_AT` = NULL
		";
		$conn->queryExecute($sql);

		Bootstrap::log('Order enqueued', ['orderId' => $orderId]);
	}

	public static function process(int $limit): void
	{
		if (!Config::isEnabled())
		{
			return;
		}
		$endpoint = Config::endpoint();
		if ($endpoint === '')
		{
			return;
		}

		Db::ensureSchema();
		$conn = Application::getConnection();
		$sqlHelper = $conn->getSqlHelper();
		$now = new DateTime();
		$nowSql = $sqlHelper->convertToDbDateTime($now);

		$limit = max(1, min(50, (int)$limit));
		$rows = $conn->query("
			SELECT *
			FROM `" . Db::TABLE . "`
			WHERE (`STATUS` IN ('new','error'))
			  AND (`NEXT_RETRY_AT` IS NULL OR `NEXT_RETRY_AT` <= " . $nowSql . ")
			ORDER BY `ID` ASC
			LIMIT " . $limit . "
		");

		while ($row = $rows->fetch())
		{
			self::sendRow($row);
		}
	}

	private static function sendRow(array $row): void
	{
		$conn = Application::getConnection();
		$sqlHelper = $conn->getSqlHelper();

		$id = (int)($row['ID'] ?? 0);
		$orderId = (int)($row['ORDER_ID'] ?? 0);
		$attempts = (int)($row['ATTEMPTS'] ?? 0);
		$payloadJson = (string)($row['PAYLOAD_JSON'] ?? '');
		// JSON формируется при постановке в очередь (OnSaleOrderSaved) — отгрузка в БД может дописаться позже.
		// Перед HTTP-отправкой пересобираем payload из актуального заказа, чтобы в 1С ушли доставка и цена.
		if ($orderId > 0)
		{
			try
			{
				if (Loader::includeModule('sale'))
				{
					$oFresh = Order::load($orderId);
					if ($oFresh instanceof Order)
					{
						$payloadFresh = Payload::build($oFresh);
						$enc = \json_encode($payloadFresh, JSON_UNESCAPED_SLASHES);
						if (\is_string($enc) && $enc !== '')
						{
							$payloadJson = $enc;
						}
					}
				}
			}
			catch (\Throwable $e)
			{
				Bootstrap::log('UNF payload refresh failed; using queued JSON', ['orderId' => $orderId, 'e' => $e->getMessage()]);
			}
		}

		$endpoint = Config::endpoint();
		$token = Config::token();
		$basicUser = Config::basicUser();
		$basicPass = Config::basicPass();
		$client = new HttpClient([
			'redirect' => false,
			'timeout' => Config::timeoutSeconds(),
			'disableSslVerification' => false,
		]);

		$headers = [
			'Content-Type' => 'application/json; charset=utf-8',
			'Accept' => 'application/json, text/plain, */*',
		];
		$client->setHeaders($headers);
		// Prefer Basic Auth if configured (1C commonly exposes HS endpoints behind Basic).
		if ($basicUser !== '')
		{
			$client->setAuthorization($basicUser, $basicPass);
			// When Basic auth is used, Authorization header is occupied.
			// If a token is configured, pass it in a separate header so 1C handler can validate it.
			if ($token !== '')
			{
				$client->setHeader('X-MF-Token', $token);
			}
		}
		elseif ($token !== '')
		{
			$client->setHeader('Authorization', 'Bearer ' . $token);
		}

		$httpCode = null;
		$respBody = null;
		$error = null;

		try
		{
			$respBody = $client->post($endpoint, $payloadJson);
			$httpCode = (int)$client->getStatus();
			if ($httpCode < 200 || $httpCode >= 300)
			{
				throw new \RuntimeException('UNF returned HTTP ' . $httpCode);
			}
			// Как у paid-очереди: 1С может ответить HTTP 200, но в теле ok=false (ошибка создания заказа).
			$appErr = QueuePaid::unfResponseCheck(is_string($respBody) ? $respBody : null);
			if ($appErr !== null)
			{
				throw new \RuntimeException($appErr);
			}
		}
		catch (\Throwable $e)
		{
			$error = $e->getMessage();
		}

		$now = new DateTime();
		$nowSql = $sqlHelper->convertToDbDateTime($now);

		if ($error === null)
		{
			$conn->queryExecute("
				UPDATE `" . Db::TABLE . "`
				SET
					`STATUS` = 'sent',
					`UPDATED_AT` = " . $nowSql . ",
					`SENT_AT` = " . $nowSql . ",
					`RESPONSE_HTTP_CODE` = " . ((int)$httpCode) . ",
					`RESPONSE_BODY` = " . ($respBody === null ? "NULL" : ("'" . $sqlHelper->forSql((string)$respBody) . "'")) . ",
					`LAST_ERROR` = NULL,
					`NEXT_RETRY_AT` = NULL
				WHERE `ID` = " . $id . "
			");
			Bootstrap::log('Order sent to UNF', ['orderId' => $orderId, 'http' => $httpCode]);
			return;
		}

		$attempts++;
		$delay = self::backoffSeconds($attempts);
		$next = (new DateTime())->add($delay);
		$nextSql = $sqlHelper->convertToDbDateTime($next);

		$conn->queryExecute("
			UPDATE `" . Db::TABLE . "`
			SET
				`STATUS` = 'error',
				`ATTEMPTS` = " . $attempts . ",
				`UPDATED_AT` = " . $nowSql . ",
				`NEXT_RETRY_AT` = " . $nextSql . ",
				`LAST_ERROR` = '" . $sqlHelper->forSql($error) . "',
				`RESPONSE_HTTP_CODE` = " . ($httpCode === null ? "NULL" : ((int)$httpCode)) . ",
				`RESPONSE_BODY` = " . ($respBody === null ? "NULL" : ("'" . $sqlHelper->forSql((string)$respBody) . "'")) . "
			WHERE `ID` = " . $id . "
		");
		Bootstrap::log('UNF send failed', ['orderId' => $orderId, 'attempts' => $attempts, 'delay' => $delay, 'error' => $error]);
	}

	private static function backoffSeconds(int $attempts): int
	{
		// 30s, 60s, 120s, ... capped at 1 hour.
		$base = 30;
		$max = 3600;
		$pow = min(16, max(0, $attempts - 1));
		$delay = $base * (2 ** $pow);
		return min($max, $delay);
	}
}

final class QueuePaid
{
	/** Проверка JSON-ответа 1С (ok / payment_result). Используется и очередью заказов, и paid. */
	public static function unfResponseCheck(?string $respBody): ?string
	{
		$raw = (string)($respBody ?? '');
		$raw = trim($raw);
		if ($raw === '')
		{
			return 'UNF returned empty body';
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded))
		{
			return 'UNF returned non-JSON body';
		}
		if (($decoded['ok'] ?? null) === false)
		{
			$err = (string)($decoded['error'] ?? 'unknown_error');
			return 'UNF response ok=false: ' . $err;
		}
		$pr = $decoded['payment_result'] ?? null;
		if (is_array($pr) && (($pr['ok'] ?? null) === false))
		{
			$err = (string)($pr['error'] ?? 'unknown_payment_error');
			return 'UNF payment_result.ok=false: ' . $err;
		}
		return null;
	}

	public static function enqueuePaid(int $orderId, int $paymentId, int $psActionId): void
	{
		DbPaid::ensureSchema();

		$orderId = (int)$orderId;
		if ($orderId <= 0)
		{
			throw new \RuntimeException('Order ID is empty');
		}

		$conn = Application::getConnection();
		$now = new DateTime();
		$nowSql = $conn->getSqlHelper()->convertToDbDateTime($now);

		$sql = "
			INSERT INTO `" . DbPaid::TABLE . "`
				(`ORDER_ID`, `PAYMENT_ID`, `PAY_SYSTEM_ACTION_ID`, `STATUS`, `ATTEMPTS`, `NEXT_RETRY_AT`, `LAST_ERROR`, `RESPONSE_HTTP_CODE`, `RESPONSE_BODY`, `CREATED_AT`, `UPDATED_AT`, `SENT_AT`)
			VALUES
				(" . $orderId . ", " . ($paymentId > 0 ? $paymentId : "NULL") . ", " . ($psActionId > 0 ? $psActionId : "NULL") . ", 'new', 0, NULL, NULL, NULL, NULL, " . $nowSql . ", " . $nowSql . ", NULL)
			ON DUPLICATE KEY UPDATE
				`STATUS` = 'new',
				`PAYMENT_ID` = VALUES(`PAYMENT_ID`),
				`PAY_SYSTEM_ACTION_ID` = VALUES(`PAY_SYSTEM_ACTION_ID`),
				`UPDATED_AT` = VALUES(`UPDATED_AT`),
				`ATTEMPTS` = 0,
				`LAST_ERROR` = NULL,
				`RESPONSE_HTTP_CODE` = NULL,
				`RESPONSE_BODY` = NULL,
				`NEXT_RETRY_AT` = NULL,
				`SENT_AT` = NULL
		";
		$conn->queryExecute($sql);

		Bootstrap::log('Paid update enqueued', ['orderId' => $orderId, 'paymentId' => $paymentId, 'psActionId' => $psActionId]);
	}

	public static function process(int $limit): void
	{
		if (!Config::isEnabled())
		{
			return;
		}
		$endpoint = Config::paidEndpoint();
		if ($endpoint === '')
		{
			return;
		}

		DbPaid::ensureSchema();
		$conn = Application::getConnection();
		$sqlHelper = $conn->getSqlHelper();
		$now = new DateTime();
		$nowSql = $sqlHelper->convertToDbDateTime($now);

		$limit = max(1, min(50, (int)$limit));
		$rows = $conn->query("
			SELECT *
			FROM `" . DbPaid::TABLE . "`
			WHERE (`STATUS` IN ('new','error'))
			  AND (`NEXT_RETRY_AT` IS NULL OR `NEXT_RETRY_AT` <= " . $nowSql . ")
			ORDER BY 
				CASE WHEN `STATUS` = 'new' THEN 0 ELSE 1 END ASC,
				`ID` ASC
			LIMIT " . $limit . "
		");

		while ($row = $rows->fetch())
		{
			self::sendRow($row);
		}
	}

	private static function sendRow(array $row): void
	{
		$conn = Application::getConnection();
		$sqlHelper = $conn->getSqlHelper();

		$id = (int)($row['ID'] ?? 0);
		$orderId = (int)($row['ORDER_ID'] ?? 0);
		$attempts = (int)($row['ATTEMPTS'] ?? 0);
		$paymentId = (int)($row['PAYMENT_ID'] ?? 0);
		$psActionId = (int)($row['PAY_SYSTEM_ACTION_ID'] ?? 0);

		if ($id <= 0 || $orderId <= 0)
		{
			return;
		}

		// Ensure order create queue is sent first (avoid "update before create").
		try
		{
			if ($conn->isTableExists(Db::TABLE))
			{
				$createRow = $conn->query("
					SELECT STATUS
					FROM `" . Db::TABLE . "`
					WHERE ORDER_ID = " . $orderId . "
					LIMIT 1
				")->fetch();
				$createStatus = is_array($createRow) ? (string)($createRow['STATUS'] ?? '') : '';
				if ($createStatus !== '' && $createStatus !== 'sent')
				{
					self::reschedule($id, $orderId, $attempts + 1, 60, 'Waiting for order create to be sent (status=' . $createStatus . ')', null, null);
					return;
				}
			}
		}
		catch (\Throwable $e)
		{
			// ignore and attempt send anyway
		}

		$endpoint = Config::paidEndpoint();
		$token = Config::token();
		$basicUser = Config::basicUser();
		$basicPass = Config::basicPass();
		$client = new HttpClient([
			'redirect' => false,
			'timeout' => Config::timeoutSeconds(),
			'disableSslVerification' => false,
		]);

		$headers = [
			'Content-Type' => 'application/json; charset=utf-8',
			'Accept' => 'application/json, text/plain, */*',
		];
		$client->setHeaders($headers);
		if ($basicUser !== '')
		{
			$client->setAuthorization($basicUser, $basicPass);
			if ($token !== '')
			{
				$client->setHeader('X-MF-Token', $token);
			}
		}
		elseif ($token !== '')
		{
			$client->setHeader('Authorization', 'Bearer ' . $token);
		}

		$httpCode = null;
		$respBody = null;
		$error = null;
		$payloadJson = null;

		try
		{
			if (!Loader::includeModule('sale'))
			{
				throw new \RuntimeException('sale module is not available');
			}
			$order = Order::load($orderId);
			if (!$order)
			{
				throw new \RuntimeException('Order not found');
			}

			$payload = Payload::build($order);
			// Keep payload compatible with 1C handler: add meta only (should be safe to ignore).
			if (isset($payload['meta']) && is_array($payload['meta']))
			{
				$payload['meta']['event'] = 'payment_paid';
				$payload['meta']['event_at'] = (new DateTime())->format(\DateTimeInterface::ATOM);
				$payload['meta']['payment_id'] = $paymentId > 0 ? $paymentId : null;
				$payload['meta']['pay_system_action_id'] = $psActionId > 0 ? $psActionId : null;
			}
			// Provide payment details so 1C can create/update settlement docs reliably.
			$paymentEntity = null;
			$pc = $order->getPaymentCollection();
			if ($pc)
			{
				if ($paymentId > 0)
				{
					$paymentEntity = $pc->getItemById($paymentId);
				}
				if (!$paymentEntity)
				{
					foreach ($pc as $p)
					{
						/** @var Payment $p */
						if ((string)$p->getField('PAID') === 'Y')
						{
							$paymentEntity = $p;
							break;
						}
					}
				}
			}
			if ($paymentEntity instanceof Payment)
			{
				$datePaid = $paymentEntity->getField('DATE_PAID');
				$datePaidAtom = null;
				try
				{
					if ($datePaid instanceof \Bitrix\Main\Type\DateTime)
					{
						$datePaidAtom = $datePaid->format(\DateTimeInterface::ATOM);
					}
				}
				catch (\Throwable $e)
				{
					$datePaidAtom = null;
				}

				$paymentInfo = [
					'bitrix_payment_id' => (int)$paymentEntity->getId(),
					'pay_system_action_id' => (int)$paymentEntity->getPaymentSystemId(),
					'pay_system_name' => (string)$paymentEntity->getPaymentSystemName(),
					'sum' => (float)$paymentEntity->getSum(),
					'currency' => (string)$paymentEntity->getField('CURRENCY'),
					'paid_at' => $datePaidAtom,
					'ps_status_code' => (string)$paymentEntity->getField('PS_STATUS_CODE'),
					'ps_invoice_id' => (string)$paymentEntity->getField('PS_INVOICE_ID'),
					'pay_voucher_num' => (string)$paymentEntity->getField('PAY_VOUCHER_NUM'),
				];

				$payload['payment_event'] = $paymentInfo;
				if (isset($payload['order']) && is_array($payload['order']) && isset($payload['order']['payment']) && is_array($payload['order']['payment']))
				{
					$payload['order']['payment'] = array_merge($payload['order']['payment'], [
						'bitrix_payment_id' => (int)$paymentEntity->getId(),
						'pay_system_action_id' => (int)$paymentEntity->getPaymentSystemId(),
						'sum' => (float)$paymentEntity->getSum(),
						'currency' => (string)$paymentEntity->getField('CURRENCY'),
						'paid_at' => $datePaidAtom,
						'ps_invoice_id' => (string)$paymentEntity->getField('PS_INVOICE_ID'),
						'pay_voucher_num' => (string)$paymentEntity->getField('PAY_VOUCHER_NUM'),
					]);
				}
			}

			$payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
			if (!is_string($payloadJson) || $payloadJson === '')
			{
				throw new \RuntimeException('Failed to encode payload JSON');
			}

			$respBody = $client->post($endpoint, $payloadJson);
			$httpCode = (int)$client->getStatus();
			if ($httpCode < 200 || $httpCode >= 300)
			{
				throw new \RuntimeException('UNF returned HTTP ' . $httpCode);
			}

			// 1C handler may respond 200 but still report application-level error.
			$appErr = self::unfResponseCheck(is_string($respBody) ? $respBody : null);
			if ($appErr !== null)
			{
				throw new \RuntimeException($appErr);
			}
		}
		catch (\Throwable $e)
		{
			$error = $e->getMessage();
		}

		if ($error === null)
		{
			$now = new DateTime();
			$nowSql = $sqlHelper->convertToDbDateTime($now);

			// Save last payload for debugging (if column exists).
			if (is_string($payloadJson) && $payloadJson !== '')
			{
				try
				{
					$conn->queryExecute("
						UPDATE `" . DbPaid::TABLE . "`
						SET `PAYLOAD_JSON` = '" . $sqlHelper->forSql($payloadJson) . "'
						WHERE `ID` = " . $id . "
					");
				}
				catch (\Throwable $e)
				{
					// ignore
				}
			}

			$conn->queryExecute("
				UPDATE `" . DbPaid::TABLE . "`
				SET
					`STATUS` = 'sent',
					`UPDATED_AT` = " . $nowSql . ",
					`SENT_AT` = " . $nowSql . ",
					`RESPONSE_HTTP_CODE` = " . ((int)$httpCode) . ",
					`RESPONSE_BODY` = " . ($respBody === null ? "NULL" : ("'" . $sqlHelper->forSql((string)$respBody) . "'")) . ",
					`LAST_ERROR` = NULL,
					`NEXT_RETRY_AT` = NULL
				WHERE `ID` = " . $id . "
			");
			Bootstrap::log('Paid update sent to UNF', ['orderId' => $orderId, 'http' => $httpCode]);
			return;
		}

		$attempts++;
		$delay = self::backoffSeconds($attempts);
		$next = (new DateTime())->add($delay);
		$nextSql = $sqlHelper->convertToDbDateTime($next);
		$now = new DateTime();
		$nowSql = $sqlHelper->convertToDbDateTime($now);

		$conn->queryExecute("
			UPDATE `" . DbPaid::TABLE . "`
			SET
				`STATUS` = 'error',
				`ATTEMPTS` = " . $attempts . ",
				`UPDATED_AT` = " . $nowSql . ",
				`NEXT_RETRY_AT` = " . $nextSql . ",
				`LAST_ERROR` = '" . $sqlHelper->forSql($error) . "',
				`RESPONSE_HTTP_CODE` = " . ($httpCode === null ? "NULL" : ((int)$httpCode)) . ",
				`RESPONSE_BODY` = " . ($respBody === null ? "NULL" : ("'" . $sqlHelper->forSql((string)$respBody) . "'")) . "
			WHERE `ID` = " . $id . "
		");

		// Save last payload for debugging (best-effort; column may not exist on older installs).
		if (is_string($payloadJson) && $payloadJson !== '')
		{
			try
			{
				$conn->queryExecute("
					UPDATE `" . DbPaid::TABLE . "`
					SET `PAYLOAD_JSON` = '" . $sqlHelper->forSql($payloadJson) . "'
					WHERE `ID` = " . $id . "
				");
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}
		Bootstrap::log('UNF paid update failed', ['orderId' => $orderId, 'attempts' => $attempts, 'delay' => $delay, 'error' => $error]);
	}

	private static function reschedule(int $id, int $orderId, int $attempts, int $delaySeconds, string $reason, ?int $httpCode, ?string $respBody): void
	{
		$conn = Application::getConnection();
		$sqlHelper = $conn->getSqlHelper();
		$now = new DateTime();
		$next = (new DateTime())->add(max(1, (int)$delaySeconds));
		$nowSql = $sqlHelper->convertToDbDateTime($now);
		$nextSql = $sqlHelper->convertToDbDateTime($next);

		$conn->queryExecute("
			UPDATE `" . DbPaid::TABLE . "`
			SET
				`STATUS` = 'error',
				`ATTEMPTS` = " . max(0, $attempts) . ",
				`UPDATED_AT` = " . $nowSql . ",
				`NEXT_RETRY_AT` = " . $nextSql . ",
				`LAST_ERROR` = '" . $sqlHelper->forSql($reason) . "',
				`RESPONSE_HTTP_CODE` = " . ($httpCode === null ? "NULL" : ((int)$httpCode)) . ",
				`RESPONSE_BODY` = " . ($respBody === null ? "NULL" : ("'" . $sqlHelper->forSql((string)$respBody) . "'")) . "
			WHERE `ID` = " . (int)$id . "
		");
		Bootstrap::log('Paid update rescheduled', ['orderId' => $orderId, 'delay' => $delaySeconds, 'reason' => $reason]);
	}

	private static function backoffSeconds(int $attempts): int
	{
		$base = 30;
		$max = 3600;
		$pow = min(16, max(0, $attempts - 1));
		$delay = $base * (2 ** $pow);
		return min($max, $delay);
	}
}

final class Payload
{
	private static function productMeta(int $productId): array
	{
		static $cache = [];
		$productId = (int)$productId;
		if ($productId <= 0)
		{
			return [
				'xml_id' => '',
				'code' => '',
				'article' => '',
				'brand' => '',
			];
		}
		if (isset($cache[$productId]))
		{
			return $cache[$productId];
		}

		$meta = [
			'xml_id' => '',
			'code' => '',
			'article' => '',
			'brand' => '',
		];

		try
		{
			if (!Loader::includeModule('iblock'))
			{
				return $cache[$productId] = $meta;
			}

			// IMPORTANT:
			// - Article is stored in PROPERTY_CML2_ARTICLE (used across the site templates).
			// - Brand is stored in PROPERTY_MF_BRAND (canonical brand name).
			$e = \CIBlockElement::GetList(
				[],
				['=ID' => $productId],
				false,
				['nTopCount' => 1],
				[
					'ID',
					'XML_ID',
					'CODE',
					'PROPERTY_CML2_ARTICLE',
					'PROPERTY_MF_BRAND',
				]
			)->Fetch();

			if (is_array($e))
			{
				$meta['xml_id'] = trim((string)($e['XML_ID'] ?? ''));
				$meta['code'] = trim((string)($e['CODE'] ?? ''));
				$meta['article'] = trim((string)($e['PROPERTY_CML2_ARTICLE_VALUE'] ?? ''));
				$meta['brand'] = trim((string)($e['PROPERTY_MF_BRAND_VALUE'] ?? ''));
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}

		return $cache[$productId] = $meta;
	}

	private static function currencyFor1c(Order $order): array
	{
		$bitrixCur = (string)$order->getCurrency();
		$cur = Config::currency();
		if ($cur === '')
		{
			$cur = $bitrixCur;
		}
		$rate = Config::currencyRate();
		$mult = Config::currencyMultiplicity();
		return [
			'code' => $cur,
			'bitrix_code' => $bitrixCur,
			'rate' => $rate,
			'multiplicity' => $mult,
		];
	}

	private static function vatPercent(?float $vatRate): int
	{
		$r = (float)($vatRate ?? 0.0);
		if ($r <= 0) return 0;
		$p = (int)round($r * 100.0, 0);
		return max(0, min(100, $p));
	}

	/**
	 * Внешний ключ заказа для УНФ и номер как на сайте: {USER_ID}-{ORDER_ID} (гость → 0-123).
	 */
	private static function orderExternalIdForUnf(Order $order): string
	{
		$uid = 0;
		try
		{
			$uid = (int)$order->getUserId();
		}
		catch (\Throwable $e)
		{
			$uid = 0;
		}
		if ($uid < 0)
		{
			$uid = 0;
		}

		return $uid . '-' . (int)$order->getId();
	}

	/**
	 * First non-empty order property by CODE candidates (Bitrix sites use different property codes).
	 */
	private static function firstNonEmptyPropValue(array $props, array $codes): string
	{
		foreach ($codes as $code)
		{
			$code = (string)$code;
			if ($code === '' || !\array_key_exists($code, $props))
			{
				continue;
			}
			$v = $props[$code];
			if ($v === null || $v === '' || $v === false)
			{
				continue;
			}
			if (\is_array($v))
			{
				$parts = [];
				foreach ($v as $item)
				{
					$s = \trim((string)$item);
					if ($s !== '')
					{
						$parts[] = $s;
					}
				}
				$v = \implode(', ', $parts);
			}
			$v = \trim((string)$v);
			if ($v !== '')
			{
				return $v;
			}
		}

		return '';
	}

	/**
	 * If property codes differ (e.g. MF_PHONE), pick any prop whose CODE looks like a phone field.
	 */
	private static function pickPhoneFromPropsLoose(array $props): string
	{
		foreach ($props as $code => $v)
		{
			$codeU = \strtoupper((string)$code);
			if ($codeU === '' || (!\str_contains($codeU, 'PHONE') && !\str_contains($codeU, 'MOBILE') && !\str_contains($codeU, 'TEL')))
			{
				continue;
			}
			$s = self::firstNonEmptyPropValue($props, [(string)$code]);
			if ($s !== '')
			{
				return $s;
			}
		}

		return '';
	}

	public static function build(Order $order): array
	{
		$orderId = (int)$order->getId();
		$siteId = (string)$order->getSiteId();

		$props = [];
		$propCollection = $order->getPropertyCollection();
		if ($propCollection)
		{
			foreach ($propCollection as $prop)
			{
				$code = (string)$prop->getField('CODE');
				if ($code === '')
				{
					continue;
				}
				$props[$code] = $prop->getValue();
			}
		}

		$checkoutHints = self::extractCheckoutSnapshot($propCollection, $props);

		$userEmail = self::firstNonEmptyPropValue($props, ['EMAIL', 'email', 'E_MAIL', 'MAIL']);
		$userPhone = self::firstNonEmptyPropValue($props, ['PHONE', 'PHONE_MOBILE', 'MOBILE', 'TEL', 'CONTACT_PHONE', 'TEL_MOBILE', 'USER_PHONE']);
		if ($userPhone === '')
		{
			$userPhone = self::pickPhoneFromPropsLoose($props);
		}
		$userName = self::firstNonEmptyPropValue($props, ['FIO', 'CONTACT_PERSON', 'NAME', 'FULL_NAME', 'BUYER_NAME']);
		$address = self::firstNonEmptyPropValue($props, ['ADDRESS', 'LOCATION', 'DELIVERY_ADDRESS', 'ADDRESS_FULL', 'ADRES', 'LOCATION_ADDRESS', 'FULL_ADDRESS']);

		$userId = (int)$order->getUserId();
		if ($userId > 0 && ($userEmail === '' || $userPhone === '' || $userName === ''))
		{
			try
			{
				$u = \CUser::GetByID($userId)->Fetch();
				if (\is_array($u))
				{
					if ($userEmail === '')
					{
						$userEmail = \trim((string)($u['EMAIL'] ?? ''));
					}
					if ($userPhone === '')
					{
						$userPhone = \trim((string)($u['PERSONAL_PHONE'] ?? ''));
						if ($userPhone === '')
						{
							$userPhone = \trim((string)($u['PERSONAL_MOBILE'] ?? ''));
						}
						if ($userPhone === '')
						{
							$userPhone = \trim((string)($u['WORK_PHONE'] ?? ''));
						}
					}
					if ($userName === '')
					{
						$userName = \trim(\trim((string)($u['NAME'] ?? '')) . ' ' . \trim((string)($u['LAST_NAME'] ?? '')));
					}
				}
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}

		$basketItems = [];
		$basket = $order->getBasket();
		if ($basket)
		{
			/** @var BasketItem $item */
			foreach ($basket->getBasketItems() as $item)
			{
				$productId = (int)$item->getProductId();
				$pm = self::productMeta($productId);
				$externalId = self::productExternalId($productId);
				$vatRate = null;
				try { $vatRate = (float)$item->getVatRate(); } catch (\Throwable $e) { $vatRate = null; }
				$vatPercent = self::vatPercent($vatRate);
				$itemWarehouse = self::basketItemWarehouseTitle($item);

				$basketItems[] = [
					'product' => [
						'bitrix_product_id' => $productId,
						'external_id' => $externalId,
						'xml_id' => $pm['xml_id'] !== '' ? $pm['xml_id'] : null,
						'code' => $pm['code'] !== '' ? $pm['code'] : null,
						'article' => $pm['article'] !== '' ? $pm['article'] : null,
						'brand' => $pm['brand'] !== '' ? $pm['brand'] : null,
						'name' => (string)$item->getField('NAME'),
					],
					'quantity' => (float)$item->getQuantity(),
					'price' => (float)$item->getPrice(),
					'currency' => (string)$order->getCurrency(),
					// VAT: keep both formats (rate 0.2 and percent 20) — 1C handlers differ.
					'vat_rate' => (float)$vatRate,
					'vat_percent' => $vatPercent,
					'nds_percent' => $vatPercent, // alias for some 1C handlers
					'warehouse_name' => $itemWarehouse !== '' ? $itemWarehouse : null,
				];
			}
		}

		$deliveryName = '';
		$deliveryPrice = (float)$order->getDeliveryPrice();
		$warehouse = null;
		$shipmentCollection = $order->getShipmentCollection();
		$emptyDeliveryId = 0;
		try
		{
			if (\class_exists(\Bitrix\Sale\Delivery\Services\Manager::class))
			{
				$emptyDeliveryId = (int)\Bitrix\Sale\Delivery\Services\Manager::getEmptyDeliveryServiceId();
			}
		}
		catch (\Throwable $e)
		{
			$emptyDeliveryId = 0;
		}

		$chosenShipment = self::pickChosenShipment($shipmentCollection, $emptyDeliveryId);

		if ($chosenShipment !== null)
		{
			$deliveryName = self::resolveDeliveryServiceName($chosenShipment);
			if ($deliveryName === '')
			{
				$didShip = 0;
				try
				{
					$didShip = (int)$chosenShipment->getDeliveryId();
				}
				catch (\Throwable $e)
				{
					$didShip = 0;
				}
				if ($didShip > 0)
				{
					$deliveryName = self::deliveryServiceHumanName($didShip);
				}
			}
			try
			{
				$deliveryPrice = (float)$chosenShipment->getField('PRICE_DELIVERY');
			}
			catch (\Throwable $e)
			{
				$deliveryPrice = (float)$order->getDeliveryPrice();
			}
			$shipmentForWarehouse = $chosenShipment;
			try
			{
				if ($shipmentForWarehouse->isSystem() && $shipmentCollection)
				{
					foreach ($shipmentCollection as $s)
					{
						if (!$s->isSystem())
						{
							$shipmentForWarehouse = $s;
							break;
						}
					}
				}
			}
			catch (\Throwable $e)
			{
				$shipmentForWarehouse = $chosenShipment;
			}
			$warehouse = self::warehouseFromShipment($shipmentForWarehouse);
		}

		$orderDeliveryId = 0;
		try
		{
			$orderDeliveryId = (int)$order->getField('DELIVERY_ID');
		}
		catch (\Throwable $e)
		{
			$orderDeliveryId = 0;
		}
		if ($orderDeliveryId <= 0)
		{
			$orderDeliveryId = (int)self::firstNonEmptyPropValue($props, ['DELIVERY_ID']);
		}
		if ($deliveryName === '' && $orderDeliveryId > 0 && $orderDeliveryId !== $emptyDeliveryId)
		{
			$deliveryName = self::deliveryServiceHumanName($orderDeliveryId);
		}
		if ($deliveryName === '' || self::isTrivialEmptyDeliveryName($deliveryName))
		{
			$hint = self::firstNonEmptyPropValue($props, [
				'MF_DELIVERY_NAME',
				'MF_DELIVERY_LABEL',
				'CHECKOUT_DELIVERY',
				'DELIVERY_SERVICE_NAME',
				'SELECTED_DELIVERY',
				'DELIVERY_TYPE',
				'DELIVERY_SERVICE',
				'SHIPMENT_NAME',
				'SPOSOB_DOSTAVKI',
				'DELIVERY_NAME_USER',
			]);
			if ($hint !== '' && !self::isTrivialEmptyDeliveryName($hint))
			{
				$deliveryName = $hint;
			}
		}
		if ($deliveryName === '' || self::isTrivialEmptyDeliveryName($deliveryName))
		{
			$snap = self::loadDeliverySnapshotFromDb($orderId, $emptyDeliveryId);
			if ($snap['name'] !== '')
			{
				$deliveryName = $snap['name'];
			}
			if ($snap['price'] > 0.0 && $deliveryPrice <= 0.0)
			{
				$deliveryPrice = $snap['price'];
			}
		}
		if ($deliveryName === '' || self::isTrivialEmptyDeliveryName($deliveryName))
		{
			$chk = \trim((string)($checkoutHints['delivery_service'] ?? ''));
			if ($chk !== '' && !self::isTrivialEmptyDeliveryName($chk))
			{
				$deliveryName = $chk;
			}
		}
		if (($deliveryName === '' || self::isTrivialEmptyDeliveryName($deliveryName)) && $orderId > 0)
		{
			try
			{
				if (Loader::includeModule('sale'))
				{
					$oReload = Order::load($orderId);
					if ($oReload instanceof Order)
					{
						$scReload = $oReload->getShipmentCollection();
						$chReload = self::pickChosenShipment($scReload, $emptyDeliveryId);
						if ($chReload !== null)
						{
							$dnR = self::resolveDeliveryServiceName($chReload);
							if ($dnR !== '' && !self::isTrivialEmptyDeliveryName($dnR))
							{
								$deliveryName = $dnR;
							}
							else
							{
								$didR = 0;
								try
								{
									$didR = (int)$chReload->getDeliveryId();
								}
								catch (\Throwable $e)
								{
									$didR = 0;
								}
								if ($didR > 0)
								{
									$dnR = self::deliveryServiceHumanName($didR);
									if ($dnR !== '' && !self::isTrivialEmptyDeliveryName($dnR))
									{
										$deliveryName = $dnR;
									}
								}
							}
							if ($deliveryPrice <= 0.0)
							{
								try
								{
									$dpR = (float)$chReload->getField('PRICE_DELIVERY');
									if ($dpR > 0.0)
									{
										$deliveryPrice = $dpR;
									}
								}
								catch (\Throwable $e)
								{
									// ignore
								}
							}
						}
					}
				}
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}
		if ($deliveryPrice <= 0.0)
		{
			try
			{
				$dp = (float)$order->getDeliveryPrice();
				if ($dp > 0.0)
				{
					$deliveryPrice = $dp;
				}
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}

		if (self::shouldReplaceDeliveryName($deliveryName))
		{
			$edostDelivery = self::parseEdostDeliveryFromOrder($order, $props);
			if ($edostDelivery['name'] !== '')
			{
				$deliveryName = $edostDelivery['name'];
				if ($edostDelivery['price'] > 0.0 && $deliveryPrice <= 0.0)
				{
					$deliveryPrice = $edostDelivery['price'];
				}
			}
		}

		$basketWarehouse = self::warehouseFromOrderBasket($order);
		if ($basketWarehouse !== null)
		{
			$warehouse = $basketWarehouse;
		}

		if (self::isTrivialEmptyDeliveryName($deliveryName))
		{
			$deliveryName = '';
		}

		$paymentName = '';
		$paymentInfo = null;
		$paymentPlan = null;
		$paymentCollection = $order->getPaymentCollection();
		if ($paymentCollection)
		{
			foreach ($paymentCollection as $payment)
			{
				$paymentName = (string)$payment->getPaymentSystemName();

				$datePaidAtom = null;
				try
				{
					$datePaid = $payment->getField('DATE_PAID');
					if ($datePaid instanceof \Bitrix\Main\Type\DateTime)
					{
						$datePaidAtom = $datePaid->format(\DateTimeInterface::ATOM);
					}
				}
				catch (\Throwable $e)
				{
					$datePaidAtom = null;
				}

				$paymentInfo = [
					'bitrix_payment_id' => (int)$payment->getId(),
					'pay_system_action_id' => (int)$payment->getPaymentSystemId(),
					'pay_system_name' => (string)$payment->getPaymentSystemName(),
					'sum' => (float)$payment->getSum(),
					'currency' => (string)$payment->getField('CURRENCY'),
					'paid' => (string)$payment->getField('PAID') === 'Y',
					'paid_at' => $datePaidAtom,
					'ps_invoice_id' => (string)$payment->getField('PS_INVOICE_ID'),
					'pay_voucher_num' => (string)$payment->getField('PAY_VOUCHER_NUM'),
				];

				// Default payment plan: full amount due at order creation time.
				$dueAt = $order->getDateInsert() ? $order->getDateInsert()->format(\DateTimeInterface::ATOM) : (new DateTime())->format(\DateTimeInterface::ATOM);
				$paymentPlan = [
					'type' => 'full_prepayment',
					'due_at' => $dueAt,
					'sum' => (float)$order->getPrice(),
					'currency' => (string)$order->getCurrency(),
				];
				break;
			}
		}

		$currency = self::currencyFor1c($order);
		$operation = Config::operation();
		$taxation = Config::taxation();

		$accountNumberRaw = '';
		try
		{
			$accountNumberRaw = trim((string)$order->getField('ACCOUNT_NUMBER'));
		}
		catch (\Throwable $e)
		{
			$accountNumberRaw = '';
		}
		$accountNumberDisplay = $accountNumberRaw;
		if ($accountNumberRaw !== '' && function_exists('mf_order_account_number_for_display'))
		{
			try
			{
				$accountNumberDisplay = mf_order_account_number_for_display((int)$order->getUserId(), $accountNumberRaw);
			}
			catch (\Throwable $e)
			{
				$accountNumberDisplay = $accountNumberRaw;
			}
		}

		// Receipt date is required in some UNF configs.
		$dateInsert = $order->getDateInsert();
		$receiptDateAtom = $dateInsert ? $dateInsert->format(\DateTimeInterface::ATOM) : (new DateTime())->format(\DateTimeInterface::ATOM);
		$receiptDateLocal = $dateInsert ? $dateInsert->format('d.m.Y H:i:s') : (new DateTime())->format('d.m.Y H:i:s');

		$unfOrderKey = self::orderExternalIdForUnf($order);

		$basketItems = self::attachWarehouseNameToBasketItems($basketItems, $warehouse);

		$userComment = '';
		try
		{
			$userComment = \trim((string)$order->getField('USER_DESCRIPTION'));
		}
		catch (\Throwable $e)
		{
			$userComment = '';
		}
		if ($userComment === '' && isset($checkoutHints['order_comment_hint']) && \is_string($checkoutHints['order_comment_hint']))
		{
			$t = \trim($checkoutHints['order_comment_hint']);
			if ($t !== '')
			{
				$userComment = $t;
			}
		}

		return [
			'meta' => [
				'source' => 'bitrix',
				'site_id' => $siteId,
				'sent_at' => (new DateTime())->format(\DateTimeInterface::ATOM),
				'schema_version' => 5,
			],
			'order' => [
				'external_id' => $unfOrderKey,
				// Явный номер для реквизита «Номер» в УНФ (= external_id, формат USER-ORDER).
				'unf_order_number' => $unfOrderKey,
				'bitrix_order_id' => $orderId,
				// Номер заказа для 1С (см. MfOrderNumberFromBitrixJson в HTTP-обработчике УНФ)
				'account_number' => $accountNumberRaw !== '' ? $accountNumberRaw : null,
				'account_number_display' => $accountNumberDisplay !== '' ? $accountNumberDisplay : null,
				'date_insert' => $order->getDateInsert() ? $order->getDateInsert()->format(\DateTimeInterface::ATOM) : null,
				'status_id' => (string)$order->getField('STATUS_ID'),
				// UNF-required fields (names duplicated for compatibility with custom HS handlers)
				'currency' => (string)$currency['code'],
				'currency_rate' => (float)$currency['rate'],
				'currency_multiplicity' => (int)$currency['multiplicity'],
				'rate' => (float)$currency['rate'],
				'multiplicity' => (int)$currency['multiplicity'],
				'operation' => $operation,
				'taxation' => $taxation,
				'receipt_date' => $receiptDateAtom,
				'receipt_date_local' => $receiptDateLocal,
				'person_type_id' => (int)$order->getPersonTypeId(),
				'person_type_name' => (function () use ($order): ?string {
					try
					{
						$ptId = (int)$order->getPersonTypeId();
						if ($ptId <= 0)
						{
							return null;
						}
						if (!class_exists(\Bitrix\Sale\Internals\PersonTypeTable::class))
						{
							return null;
						}
						$row = \Bitrix\Sale\Internals\PersonTypeTable::getRowById($ptId);
						return is_array($row) ? (string)($row['NAME'] ?? '') : null;
					}
					catch (\Throwable $e)
					{
						return null;
					}
				})(),
				'price' => (float)$order->getPrice(),
				'bitrix_currency' => (string)$order->getCurrency(),
				'warehouse' => $warehouse,
				'user_comment' => $userComment !== '' ? $userComment : null,
				// Дублирует delivery.name — часть HTTP-цепочек в 1С читает плоские ключи; для п.11 комментария в УНФ.
				'delivery_label' => ($deliveryName !== '' && !self::isTrivialEmptyDeliveryName($deliveryName)) ? $deliveryName : null,
				'delivery' => [
					'name' => $deliveryName,
					'price' => $deliveryPrice,
				],
				'checkout' => $checkoutHints,
				'payment' => [
					'name' => $paymentName,
					'paid' => (string)$order->getField('PAYED') === 'Y',
					'info' => $paymentInfo,
					'plan' => $paymentPlan,
				],
				'customer' => [
					'name' => $userName,
					'email' => $userEmail,
					'phone' => $userPhone,
					'address' => $address,
					'bitrix_user_id' => (int)$order->getUserId(),
				],
				'items' => $basketItems,
				'props' => $props,
			],
		];
	}

	private static function mbLc(string $s): string
	{
		if ($s === '')
		{
			return '';
		}
		if (!\function_exists('mb_strtolower'))
		{
			return \strtolower($s);
		}

		return \mb_strtolower($s, 'UTF-8');
	}

	/**
	 * @param \Bitrix\Sale\PropertyValue|\Bitrix\Sale\PropertyValueBase $prop
	 */
	private static function orderPropertyPlainValue($prop): string
	{
		try
		{
			$v = $prop->getValue();
		}
		catch (\Throwable $e)
		{
			return '';
		}
		if (\is_array($v))
		{
			$v = \array_filter(\array_map('strval', $v), static fn ($x) => \trim((string)$x) !== '');

			return \trim(\implode(', ', $v));
		}

		return \trim((string)$v);
	}

	/**
	 * @return array{confirm: string, city_region: string, street_house: string, zip: string, delivery_address: string, order_comment_hint: string, delivery_service: string}
	 */
	private static function extractCheckoutSnapshot($propCollection, array $props): array
	{
		$out = [
			'confirm' => '',
			'city_region' => '',
			'street_house' => '',
			'zip' => '',
			'delivery_address' => '',
			'order_comment_hint' => '',
			'delivery_service' => self::firstNonEmptyPropValue($props, [
				'MF_DELIVERY_NAME',
				'MF_DELIVERY_LABEL',
				'CHECKOUT_DELIVERY',
				'DELIVERY_SERVICE_NAME',
				'SELECTED_DELIVERY',
				'DELIVERY_TYPE',
				'DELIVERY_SERVICE',
				'SHIPMENT_NAME',
				'SPOSOB_DOSTAVKI',
				'DELIVERY_NAME_USER',
			]),
		];
		$out['confirm'] = self::firstNonEmptyPropValue($props, [
			'TELEGRAM', 'TELEGRAM_USERNAME', 'CONFIRM_METHOD', 'CONFIRMATION_METHOD', 'SPOSOB_PODTV',
			'SPOSOB_PODTVERZHDENIYA', 'UDOBNYJ_SPOSOB', 'UDOBNYY_SPOSOB', 'SMS_CONFIRM', 'HOW_TO_CONFIRM',
		]);

		if (!$propCollection)
		{
			return $out;
		}

		foreach ($propCollection as $prop)
		{
			$name = self::mbLc(\trim((string)$prop->getField('NAME')));
			if ($name === '')
			{
				continue;
			}

			$type = (string)$prop->getField('TYPE');
			$v = self::orderPropertyPlainValue($prop);
			if ($v === '')
			{
				continue;
			}
			if ($type === 'LOCATION' && \preg_match('/^\d+$/', $v))
			{
				continue;
			}

			if (\mb_strpos($name, 'адрес доставки', 0, 'UTF-8') !== false)
			{
				$out['delivery_address'] = $out['delivery_address'] ?: $v;

				continue;
			}

			if (
				\mb_strpos($name, 'достав', 0, 'UTF-8') !== false
				&& \mb_strpos($name, 'адрес', 0, 'UTF-8') === false
				&& \mb_strpos($name, 'срок', 0, 'UTF-8') === false
				&& (
					\mb_strpos($name, 'способ', 0, 'UTF-8') !== false
					|| \mb_strpos($name, 'служба', 0, 'UTF-8') !== false
					|| \mb_strpos($name, 'вариант', 0, 'UTF-8') !== false
					|| \mb_strpos($name, 'тариф', 0, 'UTF-8') !== false
					|| $name === 'доставка'
				)
			)
			{
				$out['delivery_service'] = $out['delivery_service'] ?: $v;

				continue;
			}

			if (
				\mb_strpos($name, 'подтверж', 0, 'UTF-8') !== false
				&& (
					\mb_strpos($name, 'способ', 0, 'UTF-8') !== false
					|| \mb_strpos($name, 'заказ', 0, 'UTF-8') !== false
					|| \mb_strpos($name, 'удобн', 0, 'UTF-8') !== false
				)
			)
			{
				$out['confirm'] = $out['confirm'] ?: $v;

				continue;
			}

			if (
				(\mb_strpos($name, 'населен', 0, 'UTF-8') !== false && \mb_strpos($name, 'область', 0, 'UTF-8') !== false)
				|| (\mb_strpos($name, 'город', 0, 'UTF-8') !== false && \mb_strpos($name, 'область', 0, 'UTF-8') !== false)
				|| (\mb_strpos($name, 'область', 0, 'UTF-8') !== false && \mb_strpos($name, 'край', 0, 'UTF-8') !== false)
				|| (\mb_strpos($name, 'город', 0, 'UTF-8') !== false && \mb_strpos($name, 'населен', 0, 'UTF-8') !== false)
			)
			{
				$out['city_region'] = $out['city_region'] ?: $v;

				continue;
			}

			if (
				\mb_strpos($name, 'улиц', 0, 'UTF-8') !== false
				|| \mb_strpos($name, 'квартир', 0, 'UTF-8') !== false
				|| (\mb_strpos($name, 'дом', 0, 'UTF-8') !== false && (\mb_strpos($name, 'улиц', 0, 'UTF-8') !== false || \mb_strpos($name, 'квартир', 0, 'UTF-8') !== false))
			)
			{
				$out['street_house'] = $out['street_house'] ?: $v;

				continue;
			}

			if (\mb_strpos($name, 'индекс', 0, 'UTF-8') !== false || \mb_strpos($name, 'почтов', 0, 'UTF-8') !== false)
			{
				$out['zip'] = $out['zip'] ?: $v;

				continue;
			}

			if (\mb_strpos($name, 'коммент', 0, 'UTF-8') !== false && \mb_strpos($name, 'заказ', 0, 'UTF-8') !== false)
			{
				$out['order_comment_hint'] = $out['order_comment_hint'] ?: $v;
			}
		}

		if ($out['city_region'] === '' && $out['delivery_address'] !== '')
		{
			$out['city_region'] = $out['delivery_address'];
		}

		// Индекс: свойство DELIVERY_ZIP (шаг «доставка») важнее INDEX/101000 из модулей местоположений.
		$zipDelivery = self::firstNonEmptyPropValue($props, ['DELIVERY_ZIP', 'DELIVERY_POSTAL']);
		$zipLoose = self::firstNonEmptyPropValue($props, ['ZIP', 'POSTAL_CODE', 'POSTCODE']);
		$zipIndex = self::firstNonEmptyPropValue($props, ['INDEX']);
		$out['zip'] = $zipDelivery !== '' ? $zipDelivery : ($out['zip'] !== '' ? $out['zip'] : ($zipLoose !== '' ? $zipLoose : $zipIndex));
		if ($out['zip'] === '' || $out['zip'] === '101000')
		{
			$blob = $out['delivery_address'] . ' ' . $out['city_region'];
			if ($blob !== ' ' && \preg_match('/\b([1-9]\d{5})\b/u', $blob, $m))
			{
				$out['zip'] = $m[1];
			}
		}

		return $out;
	}

	/**
	 * Отгрузка с реальной доставкой: все отгрузки заказа (в т.ч. системная), при равенстве — несистемная.
	 * Часто DELIVERY_ID в момент сохранения есть только в системной отгрузке или в поле заказа DELIVERY_ID.
	 *
	 * @param \Bitrix\Sale\ShipmentCollection|null $shipmentCollection
	 *
	 * @return \Bitrix\Sale\Shipment|null
	 */
	private static function pickChosenShipment($shipmentCollection, int $emptyDeliveryId): ?object
	{
		if (!$shipmentCollection)
		{
			return null;
		}

		$candidates = [];
		foreach ($shipmentCollection as $shipment)
		{
			$did = 0;
			try
			{
				$did = (int)$shipment->getDeliveryId();
			}
			catch (\Throwable $e)
			{
				$did = 0;
			}
			if ($did <= 0 || $did === $emptyDeliveryId)
			{
				continue;
			}
			$isSystem = false;
			try
			{
				$isSystem = (bool)$shipment->isSystem();
			}
			catch (\Throwable $e)
			{
				$isSystem = false;
			}
			$name = self::resolveDeliveryServiceName($shipment);
			if ($name === '')
			{
				$name = self::deliveryServiceHumanName($did);
			}
			$price = 0.0;
			try
			{
				$price = (float)$shipment->getField('PRICE_DELIVERY');
			}
			catch (\Throwable $e)
			{
				$price = 0.0;
			}
			$candidates[] = [
				'shipment' => $shipment,
				'name' => $name,
				'price' => $price,
				'did' => $did,
				'sys' => $isSystem,
			];
		}

		if ($candidates !== [])
		{
			\usort($candidates, static function (array $a, array $b): int {
				$sa = !empty($a['sys']) ? 1 : 0;
				$sb = !empty($b['sys']) ? 1 : 0;
				if ($sa !== $sb)
				{
					return $sa <=> $sb;
				}
				$na = $a['name'] !== '' ? 1 : 0;
				$nb = $b['name'] !== '' ? 1 : 0;
				if ($na !== $nb)
				{
					return $nb <=> $na;
				}
				if ($a['price'] != $b['price'])
				{
					return $b['price'] <=> $a['price'];
				}

				return $a['did'] <=> $b['did'];
			});

			return $candidates[0]['shipment'];
		}

		$items = null;
		try
		{
			if (\method_exists($shipmentCollection, 'getNotSystemItems'))
			{
				$items = $shipmentCollection->getNotSystemItems();
			}
		}
		catch (\Throwable $e)
		{
			$items = null;
		}
		if ($items)
		{
			foreach ($items as $shipment)
			{
				$n = self::resolveDeliveryServiceName($shipment);
				if ($n !== '')
				{
					return $shipment;
				}
			}
			foreach ($items as $shipment)
			{
				return $shipment;
			}
		}

		try
		{
			if (\method_exists($shipmentCollection, 'getSystemShipment'))
			{
				$sys = $shipmentCollection->getSystemShipment();
				if ($sys)
				{
					$did = 0;
					try
					{
						$did = (int)$sys->getDeliveryId();
					}
					catch (\Throwable $e)
					{
						$did = 0;
					}
					if ($did > 0 && $did !== $emptyDeliveryId)
					{
						return $sys;
					}
				}
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}

		return null;
	}

	/**
	 * Склад, выбранный покупателем в корзине (MF_STORE_ID), надёжнее строк отгрузки с «резервом».
	 */
	private static function warehouseFromOrderBasket(Order $order): ?array
	{
		try
		{
			$basket = $order->getBasket();
			if (!$basket)
			{
				return null;
			}
			$storeIds = [];
			foreach ($basket->getBasketItems() as $item)
			{
				$sid = self::basketItemStoreId($item);
				if ($sid > 0)
				{
					$storeIds[$sid] = true;
				}
			}
			if ($storeIds === [])
			{
				return null;
			}
			$ids = \array_keys($storeIds);
			\sort($ids);

			return self::buildStorePayloadFromId((int)$ids[0], $ids);
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}

	private static function basketItemProp($item, string $code): string
	{
		if (\function_exists('mf_basket_get_prop') && $item instanceof BasketItem)
		{
			$v = \mf_basket_get_prop($item, $code);

			return $v !== null ? \trim((string)$v) : '';
		}
		try
		{
			$pc = $item->getPropertyCollection();
			if (!$pc)
			{
				return '';
			}
			foreach ($pc as $p)
			{
				if (\trim((string)$p->getField('CODE')) !== $code)
				{
					continue;
				}
				$v = $p->getField('VALUE');
				if (\is_array($v))
				{
					$v = \reset($v);
				}

				return \trim((string)$v);
			}
		}
		catch (\Throwable $e)
		{
		}

		return '';
	}

	private static function basketItemStoreId($item): int
	{
		return (int)self::basketItemProp($item, 'MF_STORE_ID');
	}

	private static function basketItemWarehouseTitle($item): string
	{
		$title = self::basketItemProp($item, 'MF_STORE_TITLE');
		if ($title !== '')
		{
			return $title;
		}
		$sid = self::basketItemStoreId($item);
		if ($sid <= 0)
		{
			return '';
		}
		$payload = self::buildStorePayloadFromId($sid, [$sid]);

		return \trim((string)($payload['title'] ?? ''));
	}

	/**
	 * Техническая служба Bitrix (eDost идёт отдельно в COMMENTS / checkout).
	 */
	private static function isTechnicalBitrixDeliveryName(string $name): bool
	{
		$n = \trim($name);
		if ($n === '')
		{
			return false;
		}
		if (!\function_exists('mb_strtolower'))
		{
			$lc = \strtolower($n);

			return \in_array($lc, ['стандартный', 'standard'], true);
		}
		$lc = \mb_strtolower($n, 'UTF-8');

		return \in_array($lc, ['стандартный', 'standard'], true);
	}

	private static function shouldReplaceDeliveryName(string $name): bool
	{
		return $name === '' || self::isTrivialEmptyDeliveryName($name) || self::isTechnicalBitrixDeliveryName($name);
	}

	/**
	 * @return array{name: string, price: float}
	 */
	private static function parseEdostDeliveryFromComments(string $comments): array
	{
		$out = ['name' => '', 'price' => 0.0];
		$comments = \trim($comments);
		if ($comments === '')
		{
			return $out;
		}
		if (\preg_match('~Доставка \(eDost[^:]*:\s*(.+?)\s*—\s*(.+?)\s*—\s*([0-9]+(?:[.,][0-9]+)?)\s*₽\s*\(tarif_id=([^)]+)\)~u', $comments, $m))
		{
			$company = \trim((string)$m[1]);
			$tariff = \trim((string)$m[2]);
			$out['price'] = (float)\str_replace(',', '.', (string)$m[3]);
			$out['name'] = $company !== '' ? ($company . ' — ' . $tariff) : $tariff;
		}

		return $out;
	}

	/**
	 * @param array<string, string> $props
	 *
	 * @return array{name: string, price: float}
	 */
	private static function parseEdostDeliveryFromOrder(Order $order, array $props): array
	{
		$company = self::firstNonEmptyPropValue($props, ['MF_EDOST_TARIF_COMPANY']);
		$name = self::firstNonEmptyPropValue($props, ['MF_EDOST_TARIF_NAME']);
		$priceRaw = self::firstNonEmptyPropValue($props, ['MF_EDOST_TARIF_PRICE']);
		if ($company !== '' || $name !== '')
		{
			$label = $company !== '' ? ($company . ' — ' . $name) : $name;
			$price = $priceRaw !== '' ? (float)\str_replace(',', '.', $priceRaw) : 0.0;

			return ['name' => \trim($label), 'price' => $price];
		}

		try
		{
			return self::parseEdostDeliveryFromComments((string)$order->getField('COMMENTS'));
		}
		catch (\Throwable $e)
		{
			return ['name' => '', 'price' => 0.0];
		}
	}

	/**
	 * Локализованное имя пустой службы («Без доставки») — не считается выбранной доставкой.
	 */
	private static function isTrivialEmptyDeliveryName(string $name): bool
	{
		$n = \trim($name);
		if ($n === '')
		{
			return true;
		}
		if (!\function_exists('mb_strtolower'))
		{
			$lc = \strtolower($n);

			return \in_array($lc, ['без доставки', 'no delivery'], true);
		}
		$lc = \mb_strtolower($n, 'UTF-8');

		return \in_array($lc, [
			'без доставки',
			'без доставки.',
			'no delivery',
			'without delivery',
			'стандартный',
			'standard',
		], true) || \mb_strpos($lc, 'без доставки', 0, 'UTF-8') === 0;
	}

	/**
	 * Чтение b_sale_order_delivery напрямую (на случай сбоев ORM / кеша).
	 *
	 * @return array{name: string, price: float, delivery_id: int}
	 */
	private static function loadDeliverySnapshotFromDbSql(int $orderId, int $emptyDeliveryId): array
	{
		$out = ['name' => '', 'price' => 0.0, 'delivery_id' => 0];
		if ($orderId <= 0)
		{
			return $out;
		}
		try
		{
			$conn = Application::getConnection();
			// JOIN b_sale_delivery_srv: в ряде конфигов DELIVERY_NAME в отгрузке пуст, а справочник служб — источник имени (в т.ч. профили с PARENT_ID).
			$sql = 'SELECT od.`DELIVERY_ID`, od.`DELIVERY_NAME`, od.`PRICE_DELIVERY`, od.`SYSTEM`, od.`CANCELED`,'
				. ' s.`NAME` AS `SRV_NAME`, s.`PARENT_ID` AS `SRV_PARENT_ID`, sp.`NAME` AS `SRV_PARENT_NAME`'
				. ' FROM `b_sale_order_delivery` od'
				. ' LEFT JOIN `b_sale_delivery_srv` s ON s.`ID` = od.`DELIVERY_ID`'
				. ' LEFT JOIN `b_sale_delivery_srv` sp ON sp.`ID` = s.`PARENT_ID`'
				. ' WHERE od.`ORDER_ID` = ' . $orderId
				. ' AND od.`DELIVERY_ID` > 0';
			if ($emptyDeliveryId > 0)
			{
				$sql .= ' AND od.`DELIVERY_ID` <> ' . (int)$emptyDeliveryId;
			}
			$sql .= ' ORDER BY od.`SYSTEM` ASC, od.`PRICE_DELIVERY` DESC, od.`ID` DESC LIMIT 20';
			$res = $conn->query($sql);
			while ($row = $res->fetch())
			{
				if (((string)($row['CANCELED'] ?? 'N')) === 'Y')
				{
					continue;
				}
				$did = (int)($row['DELIVERY_ID'] ?? 0);
				if ($did <= 0 || ($emptyDeliveryId > 0 && $did === $emptyDeliveryId))
				{
					continue;
				}
				$dn = \trim((string)($row['DELIVERY_NAME'] ?? ''));
				$name = $dn;
				if ($name === '' || self::isTrivialEmptyDeliveryName($name))
				{
					$name = self::deliveryServiceHumanName($did);
				}
				if ($name === '' || self::isTrivialEmptyDeliveryName($name))
				{
					$name = \trim((string)($row['SRV_NAME'] ?? ''));
				}
				if ($name === '' || self::isTrivialEmptyDeliveryName($name))
				{
					$name = \trim((string)($row['SRV_PARENT_NAME'] ?? ''));
				}
				if ($name === '' || self::isTrivialEmptyDeliveryName($name))
				{
					$pid = (int)($row['SRV_PARENT_ID'] ?? 0);
					if ($pid > 0)
					{
						$name = self::deliveryServiceHumanName($pid);
					}
				}
				if ($name === '' || self::isTrivialEmptyDeliveryName($name))
				{
					continue;
				}
				$out['name'] = $name;
				$out['price'] = (float)($row['PRICE_DELIVERY'] ?? 0.0);
				$out['delivery_id'] = $did;

				return $out;
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}

		return $out;
	}

	/**
	 * Прямое чтение отгрузок из БД: при OnSaleOrderSaved объект заказа иногда ещё без актуальных DELIVERY_NAME / ID.
	 *
	 * @return array{name: string, price: float, delivery_id: int}
	 */
	private static function loadDeliverySnapshotFromDb(int $orderId, int $emptyDeliveryId): array
	{
		$out = ['name' => '', 'price' => 0.0, 'delivery_id' => 0];
		if ($orderId <= 0)
		{
			return $out;
		}
		if (!Loader::includeModule('sale'))
		{
			return $out;
		}

		$sqlOut = self::loadDeliverySnapshotFromDbSql($orderId, $emptyDeliveryId);
		if ($sqlOut['name'] !== '')
		{
			return $sqlOut;
		}

		try
		{
			if (\class_exists(\Bitrix\Sale\Internals\ShipmentTable::class))
			{
				$filter = [
					'=ORDER_ID' => $orderId,
					'>DELIVERY_ID' => 0,
				];
				if ($emptyDeliveryId > 0)
				{
					$filter['!=DELIVERY_ID'] = $emptyDeliveryId;
				}
				$res = \Bitrix\Sale\Internals\ShipmentTable::getList([
					'filter' => $filter,
					'select' => ['DELIVERY_ID', 'DELIVERY_NAME', 'PRICE_DELIVERY', 'SYSTEM', 'CANCELED'],
					'order' => ['SYSTEM' => 'ASC', 'PRICE_DELIVERY' => 'DESC', 'ID' => 'DESC'],
					'limit' => 40,
				]);
				while ($row = $res->fetch())
				{
					if (((string)($row['CANCELED'] ?? 'N')) === 'Y')
					{
						continue;
					}
					$did = (int)($row['DELIVERY_ID'] ?? 0);
					if ($did <= 0 || ($emptyDeliveryId > 0 && $did === $emptyDeliveryId))
					{
						continue;
					}
					$dn = \trim((string)($row['DELIVERY_NAME'] ?? ''));
					$name = $dn;
					if ($name === '' || self::isTrivialEmptyDeliveryName($name))
					{
						$name = self::deliveryServiceHumanName($did);
					}
					if ($name === '' || self::isTrivialEmptyDeliveryName($name))
					{
						continue;
					}
					$out['name'] = $name;
					$out['price'] = (float)($row['PRICE_DELIVERY'] ?? 0.0);
					$out['delivery_id'] = $did;

					return $out;
				}
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}

		try
		{
			if (\class_exists(\Bitrix\Sale\Internals\OrderTable::class))
			{
				$row = \Bitrix\Sale\Internals\OrderTable::getList([
					'filter' => ['=ID' => $orderId],
					'select' => ['DELIVERY_ID', 'PRICE_DELIVERY'],
					'limit' => 1,
				])->fetch();
				if (\is_array($row))
				{
					$oid = (int)($row['DELIVERY_ID'] ?? 0);
					if ($oid > 0 && ($emptyDeliveryId <= 0 || $oid !== $emptyDeliveryId))
					{
						$name = self::deliveryServiceHumanName($oid);
						if ($name !== '' && !self::isTrivialEmptyDeliveryName($name))
						{
							$out['name'] = $name;
							$out['price'] = (float)($row['PRICE_DELIVERY'] ?? 0.0);
							$out['delivery_id'] = $oid;
						}
					}
				}
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}

		return $out;
	}

	/**
	 * Человекочитаемое имя службы доставки по ID (профили, группы, автоматические службы).
	 */
	private static function deliveryServiceHumanName(int $deliveryId): string
	{
		$deliveryId = (int)$deliveryId;
		if ($deliveryId <= 0)
		{
			return '';
		}
		$result = '';
		try
		{
			if (\class_exists(\Bitrix\Sale\Delivery\Services\Manager::class))
			{
				$obj = \Bitrix\Sale\Delivery\Services\Manager::getObjectById($deliveryId);
				if ($obj !== null)
				{
					$n = '';
					if (\method_exists($obj, 'getNameWithParent'))
					{
						try
						{
							$t = $obj->getNameWithParent();
							$n = \trim(\is_string($t) ? $t : (string)$t);
						}
						catch (\Throwable $e)
						{
							$n = '';
						}
					}
					if ($n === '' && \method_exists($obj, 'getName'))
					{
						try
						{
							$t = $obj->getName();
							$n = \trim(\is_string($t) ? $t : (string)$t);
						}
						catch (\Throwable $e)
						{
							$n = '';
						}
					}
					if ($n !== '')
					{
						$result = $n;
					}
				}
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}

		if ($result === '' || self::isTrivialEmptyDeliveryName($result))
		{
			try
			{
				if (\class_exists(\Bitrix\Sale\Delivery\Services\Table::class))
				{
					$row = \Bitrix\Sale\Delivery\Services\Table::getByPrimary($deliveryId, ['select' => ['NAME']])->fetch();
					if (\is_array($row))
					{
						$result = \trim((string)($row['NAME'] ?? ''));
					}
				}
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}

		if (self::isTrivialEmptyDeliveryName($result))
		{
			return '';
		}

		return $result;
	}

	/**
	 * @param \Bitrix\Sale\Shipment $shipment
	 */
	private static function resolveDeliveryServiceName($shipment): string
	{
		$name = '';
		try
		{
			$name = \trim((string)$shipment->getField('DELIVERY_NAME'));
		}
		catch (\Throwable $e)
		{
			$name = '';
		}
		if ($name === '')
		{
			try
			{
				$name = \trim((string)$shipment->getDeliveryName());
			}
			catch (\Throwable $e)
			{
				$name = '';
			}
		}
		if ($name !== '' && !self::isTrivialEmptyDeliveryName($name))
		{
			return $name;
		}
		$id = 0;
		try
		{
			$id = (int)$shipment->getDeliveryId();
		}
		catch (\Throwable $e)
		{
			$id = 0;
		}
		if ($id <= 0)
		{
			return '';
		}

		return self::deliveryServiceHumanName($id);
	}

	/**
	 * @param array<int>|null $allIds
	 *
	 * @return array<string, mixed>|null
	 */
	private static function buildStorePayloadFromId(int $primaryStoreId, ?array $allIds = null): ?array
	{
		$primaryStoreId = (int)$primaryStoreId;
		if ($primaryStoreId <= 0)
		{
			return null;
		}
		$ids = \is_array($allIds) && $allIds !== [] ? \array_values(\array_unique(\array_map('intval', $allIds))) : [$primaryStoreId];
		\sort($ids);
		$store = [
			'bitrix_store_id' => $primaryStoreId,
			'bitrix_store_ids' => $ids,
			'is_multi' => \count($ids) > 1,
			'xml_id' => null,
			'title' => null,
			'address' => null,
		];
		try
		{
			if (Loader::includeModule('catalog') && \class_exists('\\Bitrix\\Catalog\\StoreTable'))
			{
				$row = \Bitrix\Catalog\StoreTable::getByPrimary($primaryStoreId, [
					'select' => ['ID', 'XML_ID', 'TITLE', 'ADDRESS'],
				])->fetch();
				if (\is_array($row))
				{
					$store['xml_id'] = (string)($row['XML_ID'] ?? '') ?: null;
					$store['title'] = (string)($row['TITLE'] ?? '') ?: null;
					$store['address'] = (string)($row['ADDRESS'] ?? '') ?: null;
				}
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}

		return $store;
	}

	/**
	 * Tries to extract catalog store (warehouse) from a shipment.
	 * Returns null when store control is not used / not specified.
	 */
	private static function warehouseFromShipment($shipment): ?array
	{
		try
		{
			if (\method_exists($shipment, 'getStoreId'))
			{
				try
				{
					$sidHead = (int)$shipment->getStoreId();
					if ($sidHead > 0)
					{
						return self::buildStorePayloadFromId($sidHead, [$sidHead]);
					}
				}
				catch (\Throwable $e)
				{
					// fallback: склады из строк отгрузки (часто «резерв»)
				}
			}

			$qtyByStore = [];
			$shipmentItemCollection = $shipment->getShipmentItemCollection();
			if ($shipmentItemCollection)
			{
				foreach ($shipmentItemCollection as $shipmentItem)
				{
					if (!method_exists($shipmentItem, 'getShipmentItemStoreCollection'))
					{
						continue;
					}
					$storeCollection = $shipmentItem->getShipmentItemStoreCollection();
					if (!$storeCollection)
					{
						continue;
					}
					foreach ($storeCollection as $storeItem)
					{
						if (!method_exists($storeItem, 'getStoreId'))
						{
							continue;
						}
						$sid = (int)$storeItem->getStoreId();
						if ($sid <= 0)
						{
							continue;
						}
						$qty = 0.0;
						try
						{
							$qty = (float)$storeItem->getQuantity();
						}
						catch (\Throwable $e)
						{
							try
							{
								$qty = (float)$storeItem->getField('QUANTITY');
							}
							catch (\Throwable $e2)
							{
								$qty = 0.0;
							}
						}
						$qtyByStore[$sid] = ($qtyByStore[$sid] ?? 0.0) + $qty;
					}
				}
			}

			if ($qtyByStore === [])
			{
				return null;
			}
			\arsort($qtyByStore, SORT_NUMERIC);
			$primaryStoreId = 0;
			foreach ($qtyByStore as $sid => $qty)
			{
				if ($qty > 0.0)
				{
					$primaryStoreId = (int)$sid;
					break;
				}
			}
			if ($primaryStoreId <= 0)
			{
				$primaryStoreId = (int)\array_key_first($qtyByStore);
			}
			$allIds = \array_keys($qtyByStore);
			\sort($allIds);

			return self::buildStorePayloadFromId($primaryStoreId, $allIds);
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}

	/**
	 * Подставляет в строки корзины название склада Bitrix (из отгрузки) для передачи в 1С в комментарий.
	 *
	 * @param array<int, array<string, mixed>> $basketItems
	 * @param array<string, mixed>|null        $warehouse
	 */
	private static function attachWarehouseNameToBasketItems(array $basketItems, ?array $warehouse): array
	{
		$title = '';
		if (\is_array($warehouse) && isset($warehouse['title']))
		{
			$title = \trim((string)$warehouse['title']);
		}
		if ($title === '')
		{
			return $basketItems;
		}
		foreach ($basketItems as $k => $row)
		{
			if (!\is_array($row))
			{
				continue;
			}
			$existing = \trim((string)($row['warehouse_name'] ?? ''));
			if ($existing !== '')
			{
				continue;
			}
			if ($title !== '')
			{
				$basketItems[$k]['warehouse_name'] = $title;
			}
		}

		return $basketItems;
	}

	private static function productExternalId(int $productId): string
	{
		$productId = (int)$productId;
		if ($productId <= 0)
		{
			return '';
		}

		// Prefer XML_ID from iblock element (stable key for 1C mapping).
		try
		{
			if (Loader::includeModule('iblock'))
			{
				$row = \Bitrix\Iblock\ElementTable::getByPrimary($productId, [
					'select' => ['ID', 'XML_ID'],
				])->fetch();
				$xmlId = (string)($row['XML_ID'] ?? '');
				if ($xmlId !== '')
				{
					return $xmlId;
				}
			}
		}
		catch (\Throwable $e)
		{
			// Ignore and fallback.
		}

		return 'bitrix:' . $productId;
	}
}

