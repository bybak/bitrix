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

		$userEmail = (string)($props['EMAIL'] ?? '');
		$userPhone = (string)($props['PHONE'] ?? '');
		$userName = (string)($props['FIO'] ?? '');
		$address = (string)($props['ADDRESS'] ?? ($props['LOCATION'] ?? ''));

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
				];
			}
		}

		$deliveryName = '';
		$warehouse = null;
		$shipmentCollection = $order->getShipmentCollection();
		if ($shipmentCollection)
		{
			foreach ($shipmentCollection as $shipment)
			{
				if ($shipment->isSystem())
				{
					continue;
				}
				if ($deliveryName === '')
				{
					$deliveryName = (string)$shipment->getDeliveryName();
				}

				// Try to resolve warehouse (catalog store) from shipment items.
				$warehouse = $warehouse ?? self::warehouseFromShipment($shipment);
			}
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

		return [
			'meta' => [
				'source' => 'bitrix',
				'site_id' => $siteId,
				'sent_at' => (new DateTime())->format(\DateTimeInterface::ATOM),
				'schema_version' => 4,
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
				'delivery' => [
					'name' => $deliveryName,
					'price' => (float)$order->getDeliveryPrice(),
				],
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

	/**
	 * Tries to extract catalog store (warehouse) from a shipment.
	 * Returns null when store control is not used / not specified.
	 */
	private static function warehouseFromShipment($shipment): ?array
	{
		try
		{
			$storeIds = [];
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
						if ($sid > 0)
						{
							$storeIds[$sid] = true;
						}
					}
				}
			}

			$storeIds = array_keys($storeIds);
			sort($storeIds);
			if (empty($storeIds))
			{
				return null;
			}

			$primaryStoreId = (int)$storeIds[0];
			$store = [
				'bitrix_store_id' => $primaryStoreId,
				'bitrix_store_ids' => $storeIds,
				'is_multi' => count($storeIds) > 1,
				'xml_id' => null,
				'title' => null,
				'address' => null,
			];

			// Enrich with store metadata when catalog module is available.
			if (Loader::includeModule('catalog') && class_exists('\\Bitrix\\Catalog\\StoreTable'))
			{
				$row = \Bitrix\Catalog\StoreTable::getByPrimary($primaryStoreId, [
					'select' => ['ID', 'XML_ID', 'TITLE', 'ADDRESS'],
				])->fetch();
				if (is_array($row))
				{
					$store['xml_id'] = (string)($row['XML_ID'] ?? '') ?: null;
					$store['title'] = (string)($row['TITLE'] ?? '') ?: null;
					$store['address'] = (string)($row['ADDRESS'] ?? '') ?: null;
				}
			}

			return $store;
		}
		catch (\Throwable $e)
		{
			return null;
		}
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

