<?php
declare(strict_types=1);

namespace Mf\Card2Card;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Loader;
use Bitrix\Main\Mail\Event as MailEvent;
use Bitrix\Sale\Order;

final class Config
{
	public static function isEnabled(): bool
	{
		$env = getenv('MF_C2C_ENABLED');
		if ($env !== false)
		{
			return in_array(strtolower(trim((string)$env)), ['1', 'true', 'yes', 'y', 'on'], true);
		}
		return Option::get('mf.c2c', 'enabled', 'Y') === 'Y';
	}

	/**
	 * Comma-separated list of pay system action IDs (from b_sale_pay_system_action.ID).
	 * Example: "10"
	 *
	 * @return int[]
	 */
	public static function paySystemActionIds(): array
	{
		$env = getenv('MF_C2C_PAYSYSTEM_ACTION_IDS');
		$val = ($env !== false) ? trim((string)$env) : trim((string)Option::get('mf.c2c', 'paysystem_action_ids', ''));
		if ($val !== '')
		{
			$ids = [];
			foreach (preg_split('~\\s*,\\s*~', $val) as $part)
			{
				$id = (int)$part;
				if ($id > 0) $ids[] = $id;
			}
			return array_values(array_unique($ids));
		}

		// Fallback auto-detect by name in your DB.
		try
		{
			$conn = Application::getConnection();
			$rows = $conn->query(
				"SELECT ID
				 FROM b_sale_pay_system_action
				 WHERE ACTIVE='Y'
				   AND (NAME LIKE '%карта%карту%' OR NAME LIKE '%карты%на%карту%')
				 ORDER BY SORT, ID"
			)->fetchAll();
			$ids = [];
			foreach ($rows as $r)
			{
				$id = (int)($r['ID'] ?? 0);
				if ($id > 0) $ids[] = $id;
			}
			return array_values(array_unique($ids));
		}
		catch (\Throwable $e)
		{
			return [];
		}
	}

	public static function emailFrom(): string
	{
		$env = getenv('MF_C2C_EMAIL_FROM');
		if ($env !== false && trim((string)$env) !== '')
		{
			return trim((string)$env);
		}
		return trim((string)Option::get('mf.c2c', 'email_from', ''));
	}

	/**
	 * Optional: force sending to this address (useful for tests).
	 */
	public static function emailToOverride(): string
	{
		$env = getenv('MF_C2C_EMAIL_TO');
		if ($env !== false && trim((string)$env) !== '')
		{
			return trim((string)$env);
		}
		return trim((string)Option::get('mf.c2c', 'email_to', ''));
	}

	public static function bcc(): string
	{
		$env = getenv('MF_C2C_BCC');
		if ($env !== false && trim((string)$env) !== '')
		{
			return trim((string)$env);
		}
		return trim((string)Option::get('mf.c2c', 'bcc', ''));
	}
}

final class Db
{
	public const TABLE = 'mf_c2c_mail_log';

	public static function ensureSchema(): void
	{
		$conn = Application::getConnection();
		$helper = $conn->getSqlHelper();

		if ($conn->isTableExists(self::TABLE))
		{
			return;
		}

		$conn->queryExecute(
			"CREATE TABLE " . $helper->quote(self::TABLE) . " (
				ORDER_ID INT NOT NULL,
				PAY_SYSTEM_ACTION_ID INT NULL,
				EMAIL_TO VARCHAR(255) NULL,
				SENT CHAR(1) NOT NULL DEFAULT 'N',
				SENT_AT DATETIME NULL,
				ERROR_TEXT TEXT NULL,
				UPDATED_AT DATETIME NULL,
				PRIMARY KEY (ORDER_ID)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);
	}

	public static function wasSent(int $orderId): bool
	{
		$orderId = (int)$orderId;
		if ($orderId <= 0) return false;

		$conn = Application::getConnection();
		$helper = $conn->getSqlHelper();
		$row = $conn->query(
			"SELECT SENT FROM " . $helper->quote(self::TABLE) . " WHERE ORDER_ID=" . $orderId . " LIMIT 1"
		)->fetch();
		return is_array($row) && ((string)($row['SENT'] ?? 'N') === 'Y');
	}

	public static function markSent(int $orderId, int $paySystemActionId, string $emailTo): void
	{
		$conn = Application::getConnection();
		$helper = $conn->getSqlHelper();
		$now = $helper->getCurrentDateTimeFunction();

		$conn->queryExecute(
			"INSERT INTO " . $helper->quote(self::TABLE) . " (ORDER_ID, PAY_SYSTEM_ACTION_ID, EMAIL_TO, SENT, SENT_AT, UPDATED_AT)
			 VALUES (" . (int)$orderId . ", " . (int)$paySystemActionId . ", '" . $helper->forSql($emailTo) . "', 'Y', " . $now . ", " . $now . ")
			 ON DUPLICATE KEY UPDATE
				PAY_SYSTEM_ACTION_ID=VALUES(PAY_SYSTEM_ACTION_ID),
				EMAIL_TO=VALUES(EMAIL_TO),
				SENT='Y',
				SENT_AT=VALUES(SENT_AT),
				ERROR_TEXT=NULL,
				UPDATED_AT=VALUES(UPDATED_AT)"
		);
	}

	public static function markError(int $orderId, int $paySystemActionId, string $emailTo, string $errorText): void
	{
		$conn = Application::getConnection();
		$helper = $conn->getSqlHelper();
		$now = $helper->getCurrentDateTimeFunction();

		$conn->queryExecute(
			"INSERT INTO " . $helper->quote(self::TABLE) . " (ORDER_ID, PAY_SYSTEM_ACTION_ID, EMAIL_TO, SENT, ERROR_TEXT, UPDATED_AT)
			 VALUES (" . (int)$orderId . ", " . (int)$paySystemActionId . ", '" . $helper->forSql($emailTo) . "', 'N', '" . $helper->forSql($errorText) . "', " . $now . ")
			 ON DUPLICATE KEY UPDATE
				PAY_SYSTEM_ACTION_ID=VALUES(PAY_SYSTEM_ACTION_ID),
				EMAIL_TO=VALUES(EMAIL_TO),
				SENT='N',
				ERROR_TEXT=VALUES(ERROR_TEXT),
				UPDATED_AT=VALUES(UPDATED_AT)"
		);
	}
}

final class Installer
{
	public const EVENT_NAME = 'MF_CARD2CARD_INSTRUCTIONS';

	public static function ensureMailEventTemplates(): void
	{
		if (!class_exists(\CEventType::class) || !class_exists(\CEventMessage::class))
		{
			return;
		}

		// Ensure event type (RU only).
		$exists = false;
		$rs = \CEventType::GetList(['TYPE_ID' => self::EVENT_NAME, 'LID' => 'ru']);
		while ($r = $rs->Fetch())
		{
			$exists = true;
			break;
		}

		if (!$exists)
		{
			\CEventType::Add([
				'LID' => 'ru',
				'EVENT_NAME' => self::EVENT_NAME,
				'NAME' => 'MF: Инструкция по оплате (перевод карта→карта)',
				'DESCRIPTION' =>
					"#EMAIL_TO# - Email получателя\n" .
					"#EMAIL_FROM# - Email отправителя\n" .
					"#BCC# - BCC\n" .
					"#ORDER_ID# - ID заказа\n" .
					"#ORDER_NUMBER# - Номер заказа\n" .
					"#ORDER_DATE# - Дата заказа\n" .
					"#PAY_SYSTEM_NAME# - Способ оплаты\n" .
					"#PAYMENT_SUM# - Сумма к оплате\n",
			]);
		}

		// Ensure at least one active template exists.
		$haveMessage = false;
		$rsM = \CEventMessage::GetList('id', 'asc', ['EVENT_NAME' => self::EVENT_NAME, 'ACTIVE' => 'Y']);
		while ($rsM->Fetch())
		{
			$haveMessage = true;
			break;
		}
		if ($haveMessage)
		{
			return;
		}

		$siteIds = [];
		try
		{
			if (class_exists(\Bitrix\Main\SiteTable::class))
			{
				$r = \Bitrix\Main\SiteTable::getList(['select' => ['LID']]);
				while ($s = $r->fetch())
				{
					$lid = (string)($s['LID'] ?? '');
					if ($lid !== '') $siteIds[] = $lid;
				}
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}
		if (!$siteIds)
		{
			$siteIds = ['s1'];
		}

		$em = new \CEventMessage();
		$em->Add([
			'ACTIVE' => 'Y',
			'EVENT_NAME' => self::EVENT_NAME,
			'LID' => $siteIds,
			'EMAIL_FROM' => '#EMAIL_FROM#',
			'EMAIL_TO' => '#EMAIL_TO#',
			'BCC' => '#BCC#',
			'SUBJECT' => 'Оплата заказа №#ORDER_NUMBER# (перевод карта→карта)',
			'BODY_TYPE' => 'html',
			'MESSAGE' => implode("\n", [
				'<p>Здравствуйте!</p>',
				'<p>Вы выбрали способ оплаты <b>#PAY_SYSTEM_NAME#</b>.</p>',
				'<p>Пожалуйста, переведите сумму <b>#PAYMENT_SUM#</b> по реквизитам ниже.</p>',
				'<p><b>Комментарий к переводу:</b> Заказ №#ORDER_NUMBER#</p>',
				'<hr>',
				'<p><b>Реквизиты:</b><br>',
				'Укажите реквизиты прямо в этом шаблоне письма (Админка → Почтовые события).</p>',
				'<hr>',
				'<p>Если у вас возникли вопросы — ответьте на это письмо.</p>',
			]),
		]);
	}
}

final class TemplateRenderer
{
	public static function isCard2CardPaySystemId(int $paySystemActionId): bool
	{
		return $paySystemActionId > 0 && in_array($paySystemActionId, Config::paySystemActionIds(), true);
	}

	public static function renderForOrder(Order $order, ?\Bitrix\Sale\Payment $payment = null): string
	{
		$siteId = trim((string)$order->getSiteId());
		if ($siteId === '')
		{
			$siteId = defined('SITE_ID') ? (string)SITE_ID : 's1';
		}

		$html = self::render(self::buildFields($order, $payment), $siteId);

		return self::adaptForOrderPage($html);
	}

	/**
	 * Убирает из HTML фразы, уместные только в письме (страница завершения заказа / оплаты).
	 */
	private static function adaptForOrderPage(string $html): string
	{
		if ($html === '')
		{
			return '';
		}

		$html = preg_replace('~<p\b[^>]*>\s*Здравствуйте!\s*</p>~iu', '', $html) ?? $html;
		$html = preg_replace('~<p\b[^>]*>\s*Если у вас возникли вопросы[^<]*</p>~iu', '', $html) ?? $html;
		$html = preg_replace('~(?:\s*<hr\b[^>]*>\s*)+$~iu', '', $html) ?? $html;

		return trim($html);
	}

	/**
	 * @return array<string, string>
	 */
	public static function buildFields(Order $order, ?\Bitrix\Sale\Payment $payment = null): array
	{
		$orderId = (int)$order->getId();
		$paymentSystemId = 0;
		$paymentSystemName = '';
		$paymentSum = (float)$order->getPrice();

		if ($payment instanceof \Bitrix\Sale\Payment)
		{
			$paymentSystemId = (int)$payment->getPaymentSystemId();
			$paymentSystemName = trim((string)$payment->getPaymentSystemName());
			$paymentSum = (float)$payment->getSum();
		}
		else
		{
			$pc = $order->getPaymentCollection();
			if ($pc)
			{
				foreach ($pc as $p)
				{
					if (!$p instanceof \Bitrix\Sale\Payment)
					{
						continue;
					}
					$psid = (int)$p->getPaymentSystemId();
					if ($psid > 0)
					{
						$payment = $p;
						$paymentSystemId = $psid;
						$paymentSystemName = trim((string)$p->getPaymentSystemName());
						$paymentSum = (float)$p->getSum();
						break;
					}
				}
			}
		}

		unset($paymentSystemId);

		$orderNumber = trim((string)$order->getField('ACCOUNT_NUMBER'));
		if ($orderNumber === '')
		{
			$orderNumber = (string)$orderId;
		}
		if (function_exists('mf_order_account_number_for_display'))
		{
			$display = mf_order_account_number_for_display((int)$order->getUserId(), (string)$order->getField('ACCOUNT_NUMBER'));
			if ($display !== '')
			{
				$orderNumber = $display;
			}
		}

		$emailFrom = Config::emailFrom();
		if ($emailFrom === '')
		{
			$emailFrom = trim((string)Option::get('main', 'email_from', ''));
		}

		$emailTo = '';
		$props = $order->getPropertyCollection();
		if ($props && method_exists($props, 'getUserEmail'))
		{
			$ep = $props->getUserEmail();
			if ($ep)
			{
				$emailTo = trim((string)$ep->getValue());
			}
		}

		$orderDate = '';
		$dateInsert = $order->getDateInsert();
		if ($dateInsert instanceof \Bitrix\Main\Type\DateTime)
		{
			$orderDate = $dateInsert->format('d.m.Y H:i:s');
		}
		elseif ($dateInsert !== null)
		{
			$orderDate = (string)$dateInsert;
		}

		$paymentSumRounded = function_exists('mf_round_price') ? mf_round_price($paymentSum) : (float)ceil($paymentSum);
		$paymentSumDecimals = ((int)round(abs($paymentSumRounded) * 10) % 10 === 0) ? 0 : 1;

		return [
			'EMAIL_TO' => $emailTo,
			'EMAIL_FROM' => $emailFrom,
			'BCC' => Config::bcc(),
			'ORDER_ID' => (string)$orderId,
			'ORDER_NUMBER' => $orderNumber,
			'ORDER_DATE' => $orderDate,
			'PAY_SYSTEM_NAME' => ($paymentSystemName !== '' ? $paymentSystemName : 'С карты на карту'),
			'PAYMENT_SUM' => number_format($paymentSumRounded, $paymentSumDecimals, '.', ' ') . ' RUB',
		];
	}

	/**
	 * @param array<string, string> $fields
	 */
	public static function render(array $fields, string $siteId = ''): string
	{
		Installer::ensureMailEventTemplates();

		if ($siteId === '')
		{
			$siteId = defined('SITE_ID') ? (string)SITE_ID : 's1';
		}

		$message = self::loadMessageTemplate($siteId);
		if ($message === '')
		{
			return '';
		}

		if (!class_exists(\CEvent::class))
		{
			return '';
		}

		return (string)\CEvent::ReplaceTemplate($message, $fields, false);
	}

	private static function loadMessageTemplate(string $siteId): string
	{
		if (!class_exists(\CEventMessage::class))
		{
			return '';
		}

		$filter = [
			'EVENT_NAME' => Installer::EVENT_NAME,
			'ACTIVE' => 'Y',
		];
		if ($siteId !== '')
		{
			$filter['SITE_ID'] = $siteId;
		}

		$rs = \CEventMessage::GetList('id', 'asc', $filter);
		while ($row = $rs->Fetch())
		{
			$message = trim((string)($row['MESSAGE'] ?? ''));
			if ($message !== '')
			{
				return $message;
			}
		}

		if ($siteId !== '')
		{
			return self::loadMessageTemplate('');
		}

		return '';
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

		if (!class_exists(Loader::class) || !Loader::includeModule('sale'))
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

		try
		{
			Installer::ensureMailEventTemplates();
		}
		catch (\Throwable $e)
		{
			self::log('Failed to ensure mail event templates', ['e' => $e->getMessage()]);
		}

		\Bitrix\Main\EventManager::getInstance()->addEventHandler(
			'sale',
			'OnSaleOrderSaved',
			[Handlers::class, 'onSaleOrderSaved']
		);
	}

	public static function log(string $message, array $context = []): void
	{
		if (!empty($context))
		{
			$message .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		Debug::writeToFile($message, '', 'mf_c2c.log');
	}
}

final class Sender
{
	public static function handleOrder(Order $order, bool $isNew): void
	{
		if (!Config::isEnabled())
		{
			return;
		}
		if (!$isNew)
		{
			return;
		}
		if ((string)$order->getField('CANCELED') === 'Y')
		{
			return;
		}

		$orderId = (int)$order->getId();
		if ($orderId <= 0)
		{
			return;
		}

		try
		{
			Db::ensureSchema();
		}
		catch (\Throwable $e)
		{
			Bootstrap::log('Schema check failed', ['e' => $e->getMessage()]);
			return;
		}

		if (Db::wasSent($orderId))
		{
			return;
		}

		$targetPaySystemIds = Config::paySystemActionIds();
		if (empty($targetPaySystemIds))
		{
			return;
		}

		$payment = null;
		$paymentSystemId = 0;

		$pc = $order->getPaymentCollection();
		if ($pc)
		{
			foreach ($pc as $p)
			{
				if (!$p instanceof \Bitrix\Sale\Payment)
				{
					continue;
				}
				$psid = (int)$p->getPaymentSystemId();
				if ($psid > 0)
				{
					$payment = $p;
					$paymentSystemId = $psid;
					break;
				}
			}
		}

		if ($paymentSystemId <= 0 || !in_array($paymentSystemId, $targetPaySystemIds, true))
		{
			return;
		}

		$emailTo = '';
		$props = $order->getPropertyCollection();
		if ($props && method_exists($props, 'getUserEmail'))
		{
			$ep = $props->getUserEmail();
			if ($ep)
			{
				$emailTo = trim((string)$ep->getValue());
			}
		}
		$emailToOverride = Config::emailToOverride();
		if ($emailToOverride !== '')
		{
			$emailTo = $emailToOverride;
		}

		if ($emailTo === '')
		{
			Db::markError($orderId, $paymentSystemId, '', 'Empty customer email');
			return;
		}

		$siteId = (string)$order->getSiteId();
		if ($siteId === '')
		{
			$siteId = SITE_ID;
		}

		try
		{
			Installer::ensureMailEventTemplates();

			$payload = [
				'EVENT_NAME' => Installer::EVENT_NAME,
				'LID' => $siteId,
				'C_FIELDS' => TemplateRenderer::buildFields($order, $payment),
			];
			$payload['C_FIELDS']['EMAIL_TO'] = $emailTo;

			// Important:
			// In many Bitrix setups emails are queued into b_event and require cron/agents to actually send.
			// For this payment method we need immediate delivery.
			$res = method_exists(MailEvent::class, 'sendImmediate')
				? MailEvent::sendImmediate($payload)
				: MailEvent::send($payload);

			$ok = true;
			$errText = '';
			if (is_object($res) && method_exists($res, 'isSuccess'))
			{
				$ok = (bool)$res->isSuccess();
				if (!$ok && method_exists($res, 'getErrorMessages'))
				{
					$errText = implode('; ', (array)$res->getErrorMessages());
				}
			}
			elseif ($res === false || $res === null || $res === 0 || $res === '0' || $res === 'F')
			{
				$ok = false;
				$errText = 'MailEvent send failed';
			}

			if (!$ok)
			{
				Db::markError($orderId, $paymentSystemId, $emailTo, $errText !== '' ? $errText : 'MailEvent send failed');
				return;
			}

			Db::markSent($orderId, $paymentSystemId, $emailTo);
		}
		catch (\Throwable $e)
		{
			Db::markError($orderId, $paymentSystemId, $emailTo, $e->getMessage());
			Bootstrap::log('Send failed', ['orderId' => $orderId, 'e' => $e->getMessage()]);
		}
	}
}

final class Handlers
{
	/**
	 * sale:OnSaleOrderSaved
	 */
	public static function onSaleOrderSaved(\Bitrix\Main\Event $event): void
	{
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
		Sender::handleOrder($order, $isNew);
	}
}

