<?php
declare(strict_types=1);

namespace Mf\OrderMail;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Loader;
use Bitrix\Main\Mail\Mail;
use Bitrix\Sale\BasketItemBase;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;
use Bitrix\Sale\Shipment;

final class Bootstrap
{
	private static bool $inited = false;
	private const MAIL_TEMPLATES_VERSION = '3';

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

		self::ensureMailTemplates();

		$em = \Bitrix\Main\EventManager::getInstance();
		$em->addEventHandler('sale', 'OnOrderNewSendEmail', [Handlers::class, 'onOrderNewSendEmail']);
		$em->addEventHandler('sale', 'OnOrderStatusSendEmail', [Handlers::class, 'onOrderStatusSendEmail']);
		$em->addEventHandler('main', 'OnBeforeEventSend', [Handlers::class, 'onBeforeEventSend']);
	}

	private static function ensureMailTemplates(): void
	{
		if (!class_exists(\CEventMessage::class))
		{
			return;
		}

		try
		{
			if (Option::get('main', 'mf_order_mail_templates_version', '') === self::MAIL_TEMPLATES_VERSION)
			{
				return;
			}
		}
		catch (\Throwable $e)
		{
			// ignore and continue
		}

		$templates = [
			'SALE_NEW_ORDER' => [
				'SUBJECT' => 'Заказ: #MF_ORDER_DISPLAY# на motor-force.ru',
				'MESSAGE' => '#MF_ORDER_MAIL_BODY#',
			],
			'SALE_STATUS_CHANGED_N' => [
				'SUBJECT' => 'Статус заказа №#MF_ORDER_DISPLAY#',
				'MESSAGE' => '#MF_ORDER_MAIL_BODY#',
			],
			'SALE_STATUS_CHANGED_F' => [
				'SUBJECT' => 'Статус заказа №#MF_ORDER_DISPLAY#',
				'MESSAGE' => '#MF_ORDER_MAIL_BODY#',
			],
			'SALE_STATUS_CHANGED_P' => [
				'SUBJECT' => 'Статус заказа №#MF_ORDER_DISPLAY#',
				'MESSAGE' => '#MF_ORDER_MAIL_BODY#',
			],
		];

		foreach ($templates as $eventName => $data)
		{
			$res = \CEventMessage::GetList('id', 'asc', ['EVENT_NAME' => $eventName]);
			while ($row = $res->Fetch())
			{
				$id = (int)($row['ID'] ?? 0);
				if ($id <= 0)
				{
					continue;
				}
				$em = new \CEventMessage();
				$em->Update($id, [
					'SUBJECT' => $data['SUBJECT'],
					'MESSAGE' => $data['MESSAGE'],
					'BODY_TYPE' => 'html',
					'ACTIVE' => 'Y',
				]);
			}
		}

		try
		{
			Option::set('main', 'mf_order_mail_templates_version', self::MAIL_TEMPLATES_VERSION);
		}
		catch (\Throwable $e)
		{
			// ignore
		}
	}
}

final class Handlers
{
	/** @var array<int, bool> */
	private static array $sentNewOrderMail = [];

	public static function onOrderNewSendEmail(int $orderId, &$eventName, &$fields): bool
	{
		unset($eventName);

		if ($orderId <= 0)
		{
			return true;
		}

		if (isset(self::$sentNewOrderMail[$orderId]))
		{
			return false;
		}

		try
		{
			$order = self::loadOrder($orderId);
			if (!$order)
			{
				self::log('new order mail: order not loaded id=' . $orderId);

				return true;
			}

			$display = Renderer::orderDisplayNumber($order);
			$body = Renderer::renderNewOrder($order);
			$fields['MF_ORDER_DISPLAY'] = $display;
			$fields['MF_ORDER_MAIL_BODY'] = $body;
			$fields['ORDER_LIST'] = '';

			$email = self::customerEmail($order);
			if ($email === '')
			{
				$email = trim((string)($fields['EMAIL'] ?? ''));
			}
			if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))
			{
				self::log('new order mail: invalid customer email orderId=' . $orderId);

				return true;
			}

			if (!class_exists(Mail::class))
			{
				return true;
			}

			$sent = Mail::send([
				'TO' => $email,
				'SUBJECT' => self::sanitizeSubject('Заказ: ' . $display . ' на motor-force.ru'),
				'BODY' => $body,
				'CHARSET' => 'UTF-8',
				'CONTENT_TYPE' => 'html',
				'HEADER' => self::clientMailHeader('SALE_NEW_ORDER'),
			]);

			if ($sent)
			{
				self::$sentNewOrderMail[$orderId] = true;
				self::log('new order mail: sent orderId=' . $orderId . ' to=' . $email);

				return false;
			}

			self::log('new order mail: Mail::send failed orderId=' . $orderId . ', fallback to CEvent');
		}
		catch (\Throwable $e)
		{
			self::log('new order mail: ' . $e->getMessage());
		}

		return true;
	}

	public static function onOrderStatusSendEmail(int $orderId, &$eventName, &$fields, $statusId): bool
	{
		unset($orderId, $eventName, $fields, $statusId);

		// Стандартные письма Bitrix при смене STATUS_ID отключены — уведомления только по MF-статусам из 1С.
		return false;
	}

	public static function onBeforeEventSend(array &$fields, array &$eventMessage, $context, array &$result): bool
	{
		unset($context, $result);

		$eventName = (string)($eventMessage['EVENT_NAME'] ?? '');
		if (str_starts_with($eventName, 'SALE_STATUS_CHANGED'))
		{
			return false;
		}
		if ($eventName === 'SALE_ORDER_CANCEL')
		{
			return false;
		}
		if (!in_array($eventName, self::mailEventNames(), true))
		{
			return true;
		}

		$clientFrom = function_exists('mf_mail_default_from_client')
			? mf_mail_default_from_client()
			: '';
		if ($clientFrom !== '')
		{
			$fields['SALE_EMAIL'] = $clientFrom;
			$fields['=From'] = function_exists('mf_mail_default_from_client_header')
				? mf_mail_default_from_client_header()
				: $clientFrom;
			$fields['=Reply-To'] = $clientFrom;
			$fields['=X-MF-SMTP-Profile'] = 'andrey';
		}
		$fields['=X-EVENT-NAME'] = $eventName;

		$body = trim((string)($fields['MF_ORDER_MAIL_BODY'] ?? ''));
		if ($body === '')
		{
			$fallbackOrderId = (int)($fields['ORDER_REAL_ID'] ?? 0);
			if ($fallbackOrderId > 0)
			{
				$order = self::loadOrder($fallbackOrderId);
				if ($order)
				{
					$display = Renderer::orderDisplayNumber($order);
					$body = Renderer::renderNewOrder($order);
					$fields['MF_ORDER_DISPLAY'] = $display;
					$fields['MF_ORDER_MAIL_BODY'] = $body;
					$fields['ORDER_LIST'] = '';
				}
			}
		}
		if ($body === '')
		{
			return true;
		}

		$eventMessage['BODY_TYPE'] = 'html';
		$eventMessage['SITE_TEMPLATE_ID'] = '';
		$eventMessage['MESSAGE'] = $body;
		unset($eventMessage['MESSAGE_PHP']);

		return true;
	}

	/** @return list<string> */
	private static function mailEventNames(): array
	{
		return [
			'SALE_NEW_ORDER',
		];
	}

	public static function loadOrderPublic(int $orderId): ?Order
	{
		return self::loadOrder($orderId);
	}

	private static function loadOrder(int $orderId): ?Order
	{
		if ($orderId <= 0 || !Loader::includeModule('sale'))
		{
			return null;
		}

		$order = Order::load($orderId);

		return $order instanceof Order ? $order : null;
	}

	/** @return array<string, string> */
	private static function clientMailHeader(string $eventName): array
	{
		$header = [
			'X-EVENT-NAME' => $eventName,
		];
		$from = function_exists('mf_mail_default_from_client')
			? mf_mail_default_from_client()
			: '';
		if ($from !== '')
		{
			$header['From'] = function_exists('mf_mail_default_from_client_header')
				? mf_mail_default_from_client_header()
				: $from;
			$header['Reply-To'] = $from;
			$header['X-MF-SMTP-Profile'] = 'andrey';
		}

		return $header;
	}

	private static function customerEmail(Order $order): string
	{
		try
		{
			$pc = $order->getPropertyCollection();
			if ($pc !== null)
			{
				$prop = $pc->getUserEmail();
				if ($prop !== null)
				{
					$email = trim((string)$prop->getValue());
					if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
					{
						return $email;
					}
				}
			}
		}
		catch (\Throwable $e)
		{
		}

		$uid = (int)$order->getUserId();
		if ($uid > 0 && class_exists(\CUser::class))
		{
			$user = \CUser::GetByID($uid)->Fetch();
			if (is_array($user))
			{
				$email = trim((string)($user['EMAIL'] ?? ''));
				if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
				{
					return $email;
				}
			}
		}

		return '';
	}

	private static function sanitizeSubject(string $subject): string
	{
		$subject = preg_replace('~[\r\n]+~u', ' ', $subject) ?? $subject;

		return trim(preg_replace('~\s+~u', ' ', $subject) ?? $subject);
	}

	private static function log(string $message): void
	{
		if (class_exists(\Bitrix\Main\Diag\Debug::class))
		{
			\Bitrix\Main\Diag\Debug::writeToFile(date('c') . ' mf_order_mail: ' . $message, '', 'mf_order_mail.log');
		}
	}
}

final class CustomStatusNotifier
{
	/** @var array<string, array{group:string,title:string}> */
	private const FIELD_MAP = [
		'ORDER_STATUS' => ['group' => 'order', 'title' => 'Статус заказа'],
		'PAYMENT_STATUS' => ['group' => 'payment', 'title' => 'Статус оплаты'],
		'SHIPMENT_STATUS' => ['group' => 'shipment', 'title' => 'Статус доставки'],
	];

	/** @var list<string> */
	private const ADMIN_NOTIFY_FIELDS = ['ORDER_STATUS', 'PAYMENT_STATUS'];

	public static function notify(int $orderId, ?array $before, array $after): void
	{
		if ($orderId <= 0 || self::isDisabled())
		{
			return;
		}

		$changes = self::collectChanges($before, $after);
		if ($changes === [])
		{
			return;
		}

		static $sent = [];
		$changes = array_values(array_filter(
			$changes,
			static function (array $change) use ($orderId, &$sent): bool {
				$key = $orderId
					. ':' . (string)($change['field'] ?? '')
					. ':' . (string)($change['old'] ?? '')
					. '>' . (string)($change['new'] ?? '');
				if (isset($sent[$key]))
				{
					return false;
				}
				$sent[$key] = true;

				return true;
			}
		));
		if ($changes === [])
		{
			return;
		}

		try
		{
			$order = Handlers::loadOrderPublic($orderId);
			if (!$order)
			{
				self::log('custom status mail: order not found id=' . $orderId);

				return;
			}

			self::sendCustomerNotification($order, $orderId, $changes);

			$adminChanges = self::filterAdminChanges($changes);
			if ($adminChanges !== [])
			{
				self::sendAdminNotification($order, $orderId, $adminChanges);
			}
		}
		catch (\Throwable $e)
		{
			self::log('custom status mail: ' . $e->getMessage());
		}
	}

	/** @param list<array{field:string,title:string,old:string,new:string}> $changes */
	private static function sendCustomerNotification(Order $order, int $orderId, array $changes): void
	{
		$email = self::customerEmail($order);
		if ($email === '')
		{
			self::log('custom status mail: empty customer email orderId=' . $orderId);

			return;
		}

		$display = Renderer::orderDisplayNumber($order);
		$subject = count($changes) === 1
			? self::sanitizeSubject('Заказ №' . $display . ': ' . (string)$changes[0]['title'])
			: self::sanitizeSubject('Заказ №' . $display . ': обновление статусов');
		$body = Renderer::renderMfCustomStatusChanges($order, $changes);

		$params = [
			'TO' => $email,
			'SUBJECT' => $subject,
			'BODY' => $body,
			'CHARSET' => 'UTF-8',
			'CONTENT_TYPE' => 'html',
			'HEADER' => self::clientMailHeader(),
		];

		if (!class_exists(Mail::class) || !Mail::send($params))
		{
			self::log('custom status mail: Mail::send failed orderId=' . $orderId . ' to=client');
		}
	}

	/** @param list<array{field:string,title:string,old:string,new:string}> $adminChanges */
	private static function sendAdminNotification(Order $order, int $orderId, array $adminChanges): void
	{
		$recipients = self::adminRecipientsForOrder($order);
		if ($recipients === [])
		{
			self::log('custom status mail: no admin recipients after customer filter orderId=' . $orderId);

			return;
		}

		$display = Renderer::orderDisplayNumber($order);
		$subject = count($adminChanges) === 1
			? self::sanitizeSubject('Заказ №' . $display . ': ' . (string)$adminChanges[0]['title'])
			: self::sanitizeSubject('Заказ №' . $display . ': обновление статусов');
		$body = Renderer::renderAdminMfCustomStatusChanges($order, $adminChanges);

		$header = self::adminMailHeader($order);
		$params = [
			'TO' => implode(', ', $recipients),
			'SUBJECT' => $subject,
			'BODY' => $body,
			'CHARSET' => 'UTF-8',
			'CONTENT_TYPE' => 'html',
			'HEADER' => $header,
		];

		if (!class_exists(Mail::class) || !Mail::send($params))
		{
			self::log('custom status mail: Mail::send failed orderId=' . $orderId . ' to=admin');
		}
	}

	/**
	 * @param list<array{field:string,title:string,old:string,new:string}> $changes
	 * @return list<array{field:string,title:string,old:string,new:string}>
	 */
	private static function filterAdminChanges(array $changes): array
	{
		$result = [];
		foreach ($changes as $change)
		{
			$field = trim((string)($change['field'] ?? ''));
			if ($field === '' || !in_array($field, self::ADMIN_NOTIFY_FIELDS, true))
			{
				continue;
			}
			$result[] = $change;
		}

		return $result;
	}

	/** @return list<array{field:string,title:string,old:string,new:string}> */
	private static function collectChanges(?array $before, array $after): array
	{
		$changes = [];
		foreach (self::FIELD_MAP as $key => $meta)
		{
			$beforeCode = is_array($before) ? trim((string)($before[$key] ?? '')) : '';
			$afterCode = trim((string)($after[$key] ?? ''));
			if ($beforeCode === '' || $afterCode === '' || $beforeCode === $afterCode)
			{
				continue;
			}

			$changes[] = [
				'field' => $key,
				'title' => (string)$meta['title'],
				'old' => self::statusLabel($meta['group'], $before, $key, $beforeCode),
				'new' => self::statusLabel($meta['group'], $after, $key, $afterCode),
			];
		}

		return $changes;
	}

	/** @param 'order'|'payment'|'shipment' $group */
	private static function statusLabel(string $group, ?array $row, string $key, string $code): string
	{
		$labelKey = $key . '_LABEL';
		$label = is_array($row) ? trim((string)($row[$labelKey] ?? '')) : '';
		if ($label !== '')
		{
			return $label;
		}
		if (function_exists('mf_order_custom_status_label'))
		{
			return mf_order_custom_status_label($group, $code);
		}

		return $code;
	}

	private static function isDisabled(): bool
	{
		$env = getenv('MF_ORDER_CUSTOM_STATUS_MAIL');
		if ($env !== false)
		{
			return in_array(strtolower(trim((string)$env)), ['0', 'false', 'off', 'no'], true);
		}

		return false;
	}

	/** @return array<string, string> */
	private static function clientMailHeader(): array
	{
		$header = [];
		$from = function_exists('mf_mail_default_from_client')
			? mf_mail_default_from_client()
			: '';
		if ($from !== '')
		{
			$header['From'] = function_exists('mf_mail_default_from_client_header')
				? mf_mail_default_from_client_header()
				: $from;
			$header['Reply-To'] = $from;
			$header['X-MF-SMTP-Profile'] = 'andrey';
		}

		return $header;
	}

	/** @return array<string, string> */
	private static function adminMailHeader(Order $order): array
	{
		$from = function_exists('mf_mail_default_from_admin')
			? mf_mail_default_from_admin()
			: (function_exists('mf_mail_default_from') ? mf_mail_default_from() : '');
		if ($from === '')
		{
			$from = trim((string)getenv('MF_SMTP_FROM_ROBOT'));
		}
		if ($from === '' && class_exists(Option::class))
		{
			$from = trim((string)Option::get('main', 'email_from', ''));
		}

		$header = ['X-MF-SMTP-Profile' => 'robot'];
		if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL))
		{
			$header['From'] = $from;
		}

		$customerEmail = self::customerEmail($order);
		if ($customerEmail !== '')
		{
			$header['Reply-To'] = $customerEmail;
		}

		return $header;
	}

	/** @return list<string> */
	private static function adminRecipients(): array
	{
		if (function_exists('mf_mail_admin_recipient_emails'))
		{
			$emails = mf_mail_admin_recipient_emails();
			if ($emails !== [])
			{
				return $emails;
			}
		}

		$one = function_exists('mf_mail_admin_inbox')
			? mf_mail_admin_inbox()
			: trim((string)getenv('MF_ORDER_NOTIFY_EMAIL'));
		if ($one !== '' && filter_var($one, FILTER_VALIDATE_EMAIL))
		{
			return [$one];
		}

		return ['andrey@motor-force.ru'];
	}

	/** @return list<string> */
	private static function adminRecipientsForOrder(Order $order): array
	{
		$customerEmail = strtolower(trim(self::customerEmail($order)));
		$recipients = self::adminRecipients();
		if ($customerEmail === '')
		{
			return $recipients;
		}

		return array_values(array_filter(
			$recipients,
			static fn(string $email): bool => strtolower(trim($email)) !== $customerEmail
		));
	}

	private static function customerEmail(Order $order): string
	{
		try
		{
			$pc = $order->getPropertyCollection();
			if ($pc !== null)
			{
				$prop = $pc->getUserEmail();
				if ($prop !== null)
				{
					$email = trim((string)$prop->getValue());
					if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
					{
						return $email;
					}
				}
			}
		}
		catch (\Throwable $e)
		{
		}

		$uid = (int)$order->getUserId();
		if ($uid > 0 && class_exists(\CUser::class))
		{
			$user = \CUser::GetByID($uid)->Fetch();
			if (is_array($user))
			{
				$email = trim((string)($user['EMAIL'] ?? ''));
				if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
				{
					return $email;
				}
			}
		}

		return '';
	}

	private static function sanitizeSubject(string $subject): string
	{
		$subject = preg_replace('~[\r\n]+~u', ' ', $subject) ?? $subject;

		return trim(preg_replace('~\s+~u', ' ', $subject) ?? $subject);
	}

	private static function log(string $message): void
	{
		if (class_exists(Debug::class))
		{
			Debug::writeToFile(date('c') . ' mf_order_mail: ' . $message, '', 'mf_order_mail.log');
		}
	}
}

final class Renderer
{
	private const SITE_HOST = 'motor-force.ru';
	private const OFFICE_ADDRESS = 'Россия, Санкт-Петербург, ул. Салова, д. 57, к. 1, Литера Ч, 2-й этаж, офис № 1Н (Motor-Force)';
	private const COLOR_TITLE = '#1a73b8';
	private const COLOR_SECTION = '#e65100';
	private const COLOR_LINK = '#1a73b8';
	private const COLOR_BORDER = '#dddddd';
	private const COLOR_HEAD_BG = '#f3f3f3';
	private const COLOR_TOTAL_BG = '#fff8dc';
	private const DELIVERY_PRELIMINARY_NOTE = 'Стоимость доставки предварительная. Точная сумма будет рассчитана после упаковки заказа и оплачивается при получении.';
	/** Ширина таблицы заказа (px): при «Ответить» клиенты сужают блок цитаты — 100% ломает колонки. */
	private const BASKET_TABLE_WIDTH = 640;

	public static function orderDisplayNumber(Order $order): string
	{
		if (!function_exists('mf_order_account_number_for_display'))
		{
			$acc = trim((string)$order->getField('ACCOUNT_NUMBER'));

			return $acc !== '' ? $acc : (string)(int)$order->getId();
		}

		return mf_order_account_number_for_display(
			(int)$order->getUserId(),
			(string)$order->getField('ACCOUNT_NUMBER')
		);
	}

	public static function renderNewOrder(Order $order): string
	{
		return self::renderOrderMail($order);
	}

	public static function renderAdminNewOrder(Order $order): string
	{
		return self::renderNewOrder($order);
	}

	private static function renderOrderMail(Order $order): string
	{
		self::ensureMfOrderStatusDefaults($order);

		$display = self::orderDisplayNumber($order);
		$siteUrl = self::siteUrl($order);
		$siteLink = '<a href="' . self::esc($siteUrl) . '" style="color:' . self::COLOR_LINK . ';text-decoration:underline;">'
			. self::esc(self::SITE_HOST) . '</a>';

		$html = [];
		$html[] = self::wrapOpen();

		$html[] = self::block(
			'<div style="text-align:center;margin:0 0 18px 0;">'
			. '<div style="font-size:28px;font-weight:bold;color:' . self::COLOR_TITLE . ';margin:0 0 8px 0;">'
			. 'Заказ: №' . self::esc($display)
			. '</div>'
			. '<div style="font-size:14px;color:#333;">Заказ оформлен на сайте ' . $siteLink . '</div>'
			. '</div>'
		);

		$html[] = self::sectionTitle('Информация о заказе');
		$html[] = self::basketTable($order);

		$paymentInstructions = self::paymentInstructionsBlock($order);
		if ($paymentInstructions !== '')
		{
			$html[] = $paymentInstructions;
		}

		$html[] = self::sectionTitle('Данные покупателя');
		$html[] = self::customerTable($order);

		$html[] = self::footerBlock();
		$html[] = self::wrapClose();

		return implode("\n", $html);
	}

	/**
	 * @param list<array{title:string,old:string,new:string}> $changes
	 */
	public static function renderMfCustomStatusChanges(Order $order, array $changes): string
	{
		$display = self::orderDisplayNumber($order);
		$ordersUrl = rtrim(self::siteUrl($order), '/') . '/personal/orders/';

		$html = [];
		$html[] = self::wrapOpen();
		$html[] = self::block(
			'<div style="text-align:center;margin:0 0 18px 0;">'
			. '<div style="font-size:24px;font-weight:bold;color:' . self::COLOR_TITLE . ';margin:0 0 8px 0;">'
			. 'Заказ №' . self::esc($display)
			. '</div>'
			. '<div style="font-size:14px;color:#333;">Обновление статуса заказа</div>'
			. '</div>'
		);

		$lines = [];
		foreach ($changes as $change)
		{
			$title = trim((string)($change['title'] ?? ''));
			$old = trim((string)($change['old'] ?? ''));
			$new = trim((string)($change['new'] ?? ''));
			if ($title === '' || $new === '')
			{
				continue;
			}
			$line = '<strong>' . self::esc($title) . ':</strong> ' . self::esc($new);
			if ($old !== '' && $old !== $new)
			{
				$line = '<strong>' . self::esc($title) . ':</strong> '
					. self::esc($old) . ' → <strong>' . self::esc($new) . '</strong>';
			}
			$lines[] = $line;
		}

		if ($lines === [])
		{
			$lines[] = 'Статус заказа обновлён.';
		}

		$lines[] = 'Подробности в <a href="' . self::esc($ordersUrl) . '" style="color:' . self::COLOR_LINK . ';">личном кабинете</a>.';

		$html[] = self::block(
			'<div style="font-size:14px;line-height:1.7;color:#333;">' . implode('<br>', $lines) . '</div>'
		);
		$html[] = self::footerBlock(false);
		$html[] = self::wrapClose();

		return implode("\n", $html);
	}

	/**
	 * @param list<array{field?:string,title:string,old:string,new:string}> $changes
	 */
	public static function renderAdminMfCustomStatusChanges(Order $order, array $changes): string
	{
		$display = self::orderDisplayNumber($order);

		$html = [];
		$html[] = self::wrapOpen();
		$html[] = self::block(
			'<div style="text-align:center;margin:0 0 18px 0;">'
			. '<div style="font-size:24px;font-weight:bold;color:' . self::COLOR_TITLE . ';margin:0 0 8px 0;">'
			. 'Заказ №' . self::esc($display)
			. '</div>'
			. '<div style="font-size:14px;color:#333;">Обновление статусов из 1С</div>'
			. '</div>'
		);

		$lines = [];
		foreach ($changes as $change)
		{
			$title = trim((string)($change['title'] ?? ''));
			$old = trim((string)($change['old'] ?? ''));
			$new = trim((string)($change['new'] ?? ''));
			if ($title === '' || $new === '')
			{
				continue;
			}
			$line = '<strong>' . self::esc($title) . ':</strong> ' . self::esc($new);
			if ($old !== '' && $old !== $new)
			{
				$line = '<strong>' . self::esc($title) . ':</strong> '
					. self::esc($old) . ' → <strong>' . self::esc($new) . '</strong>';
			}
			$lines[] = $line;
		}

		if ($lines === [])
		{
			$lines[] = 'Статусы заказа обновлены.';
		}

		$html[] = self::block(
			'<div style="font-size:14px;line-height:1.7;color:#333;">' . implode('<br>', $lines) . '</div>'
		);
		$html[] = self::footerBlock(false);
		$html[] = self::wrapClose();

		return implode("\n", $html);
	}

	public static function renderStatusChange(Order $order, string $statusName, string $statusDesc): string
	{
		$display = self::orderDisplayNumber($order);
		$siteUrl = self::siteUrl($order);
		$orderUrl = rtrim($siteUrl, '/') . '/personal/order/' . rawurlencode((string)$order->getField('ACCOUNT_NUMBER')) . '/';
		$dateInsert = '';
		$dateObj = $order->getDateInsert();
		if ($dateObj instanceof \Bitrix\Main\Type\DateTime)
		{
			$dateInsert = $dateObj->format('d.m.Y H:i:s');
		}

		$html = [];
		$html[] = self::wrapOpen();
		$html[] = self::block(
			'<div style="text-align:center;margin:0 0 18px 0;">'
			. '<div style="font-size:24px;font-weight:bold;color:' . self::COLOR_TITLE . ';margin:0 0 8px 0;">'
			. 'Заказ: №' . self::esc($display)
			. '</div>'
			. '<div style="font-size:14px;color:#333;">Статус заказа изменён</div>'
			. '</div>'
		);

		$lines = [];
		if ($dateInsert !== '')
		{
			$lines[] = 'Заказ от ' . self::esc($dateInsert) . '.';
		}
		if ($statusName !== '')
		{
			$lines[] = '<strong>Новый статус:</strong> ' . self::esc($statusName);
		}
		if ($statusDesc !== '')
		{
			$lines[] = self::esc($statusDesc);
		}
		$lines[] = 'Подробности: <a href="' . self::esc($orderUrl) . '" style="color:' . self::COLOR_LINK . ';">'
			. self::esc($orderUrl) . '</a>';

		$html[] = self::block(
			'<div style="font-size:14px;line-height:1.6;color:#333;">' . implode('<br>', $lines) . '</div>'
		);
		$html[] = self::footerBlock(false);
		$html[] = self::wrapClose();

		return implode("\n", $html);
	}

	private static function basketTable(Order $order): string
	{
		$currency = (string)$order->getCurrency();
		$basket = $order->getBasket();
		$rows = '';
		$itemsTotal = 0.0;

		if ($basket)
		{
			foreach ($basket as $item)
			{
				if (!$item instanceof BasketItemBase)
				{
					continue;
				}
				$qty = (float)$item->getQuantity();
				$price = (float)$item->getPrice();
				$lineTotal = (float)$item->getFinalPrice();
				$itemsTotal += $lineTotal;

				$productId = (int)$item->getProductId();
				$name = self::basketItemDisplayName($item, $productId);
				$brand = self::basketItemBrand($item, $productId);
				$storeTitle = self::basketItemStoreTitle($item);
				$storeId = self::basketItemStoreId($item);
				$deliverySpbHtml = self::basketItemDeliverySpbHtml($storeId, $productId);
				$article = self::basketItemArticle($item, $productId);
				$productUrl = self::basketItemProductUrl($productId, $order);

				$nameText = self::esc($name !== '' ? $name : '—');
				$nameHtml = $productUrl !== ''
					? '<a href="' . self::esc($productUrl) . '" style="color:' . self::COLOR_LINK . ';text-decoration:underline;">' . $nameText . '</a>'
					: $nameText;
				if ($article !== '')
				{
					$nameHtml .= '<br><span style="font-size:12px;color:#666;">Артикул: ' . self::esc($article) . '</span>';
				}

				$rows .= '<tr>'
					. '<td' . self::basketCellAttr('store') . '>' . self::esc($storeTitle !== '' ? $storeTitle : '—') . '</td>'
					. '<td' . self::basketCellAttr('delivery') . '>' . $deliverySpbHtml . '</td>'
					. '<td' . self::basketCellAttr('name', false, 'color:' . self::COLOR_LINK . ';') . '>' . $nameHtml . '</td>'
					. '<td' . self::basketCellAttr('brand') . '>' . self::esc($brand !== '' ? $brand : '—') . '</td>'
					. '<td' . self::basketCellAttr('qty') . '>' . self::esc(self::qtyLabel($qty)) . '</td>'
					. '<td' . self::basketCellAttr('price') . '>' . self::esc(self::moneyPlain($price, $currency)) . '</td>'
					. '<td' . self::basketCellAttr('sum') . '>' . self::esc(self::moneyPlain($lineTotal, $currency)) . '</td>'
					. '</tr>';
			}
		}

		if ($rows === '')
		{
			$rows = '<tr><td colspan="7" style="padding:10px;text-align:center;color:#666;">Нет позиций</td></tr>';
		}

		$orderTotal = (float)$order->getPrice();
		$payTotal = $orderTotal > 0 ? $orderTotal : $itemsTotal;

		$w = self::BASKET_TABLE_WIDTH;
		$cellBase = self::basketCellStyleBase();
		$footLabel = 'padding:8px 6px;text-align:right;font-weight:bold;border-top:2px solid ' . self::COLOR_BORDER
			. ';' . $cellBase;
		$footSum = 'padding:8px 6px;text-align:right;font-weight:bold;border-top:2px solid ' . self::COLOR_BORDER
			. ';white-space:nowrap;' . $cellBase;

		return self::block(
			'<table cellpadding="0" cellspacing="0" border="0" width="' . $w . '" style="width:' . $w . 'px;max-width:100%;table-layout:fixed;border-collapse:collapse;font-size:14px;mso-table-lspace:0pt;mso-table-rspace:0pt;">'
			. self::basketColgroup()
			. '<tr style="background:' . self::COLOR_HEAD_BG . ';font-weight:bold;">'
			. '<td' . self::basketCellAttr('store', true) . '>Склад</td>'
			. '<td' . self::basketCellAttr('delivery', true) . '>Доставка</td>'
			. '<td' . self::basketCellAttr('name', true) . '>Наименование</td>'
			. '<td' . self::basketCellAttr('brand', true) . '>Бренд</td>'
			. '<td' . self::basketCellAttr('qty', true) . '>Кол-во</td>'
			. '<td' . self::basketCellAttr('price', true) . '>Цена</td>'
			. '<td' . self::basketCellAttr('sum', true) . '>Сумма</td>'
			. '</tr>'
			. $rows
			. '<tr>'
			. '<td colspan="6" style="' . $footLabel . '">Итого:</td>'
			. '<td style="' . $footSum . '">' . self::esc(self::moneyPlain($itemsTotal, $currency)) . ' руб.</td>'
			. '</tr>'
			. '<tr style="background:' . self::COLOR_TOTAL_BG . ';">'
			. '<td colspan="6" style="padding:10px 6px;text-align:right;font-weight:bold;font-size:16px;' . $cellBase . '">Всего к оплате:</td>'
			. '<td style="padding:10px 6px;text-align:right;font-weight:bold;font-size:16px;white-space:nowrap;' . $cellBase . '">'
			. self::esc(self::moneyPlain($payTotal, $currency)) . ' руб.'
			. '</td></tr>'
			. '</table>'
		);
	}

	/** @return array<string, int> */
	private static function basketColumnWidths(): array
	{
		return [
			'store' => 60,
			'delivery' => 180,
			'name' => 166,
			'brand' => 58,
			'qty' => 54,
			'price' => 60,
			'sum' => 62,
		];
	}

	private static function basketColgroup(): string
	{
		$html = '<colgroup>';
		foreach (self::basketColumnWidths() as $px)
		{
			$html .= '<col width="' . $px . '" style="width:' . $px . 'px;">';
		}
		$html .= '</colgroup>';

		return $html;
	}

	private static function basketCellStyleBase(): string
	{
		return 'word-wrap:break-word;overflow-wrap:break-word;word-break:break-word;white-space:normal;';
	}

	private static function basketCellAttr(string $column, bool $isHeader = false, string $extraStyle = ''): string
	{
		$widths = self::basketColumnWidths();
		$px = (int)($widths[$column] ?? 64);
		$border = $isHeader
			? 'border:1px solid ' . self::COLOR_BORDER . ';'
			: 'border-bottom:1px solid ' . self::COLOR_BORDER . ';';
		$align = match ($column)
		{
			'qty' => 'text-align:center;',
			'price', 'sum' => 'text-align:right;white-space:nowrap;',
			default => '',
		};
		$columnExtra = match ($column)
		{
			'delivery' => 'font-size:12px;line-height:1.45;',
			default => '',
		};

		return ' width="' . $px . '" style="width:' . $px . 'px;max-width:' . $px . 'px;min-width:' . $px . 'px;padding:8px 6px;'
			. $border
			. 'vertical-align:top;'
			. self::basketCellStyleBase()
			. $align
			. $columnExtra
			. $extraStyle
			. '"';
	}

	private static function customerTable(Order $order): string
	{
		$props = self::orderPropsMap($order);
		$personTypeId = (int)$order->getPersonTypeId();
		$isJur = self::isJurPersonType($personTypeId);

		$fio = $isJur
			? self::firstNonEmpty(
				self::prop($props, 'CONTACT_PERSON'),
				self::prop($props, 'FIO')
			)
			: self::prop($props, 'FIO');
		if ($fio === '')
		{
			$fio = self::orderUserName($order);
		}

		$city = self::prop($props, 'DELIVERY_LOCATION_TEXT');
		if ($city === '')
		{
			$city = self::prop($props, 'CITY');
		}

		$address = self::prop($props, 'DELIVERY_ADDRESS');
		if ($address === '')
		{
			$address = self::prop($props, 'ADDRESS');
		}

		$zip = self::prop($props, 'DELIVERY_ZIP');
		if ($zip === '')
		{
			$zip = self::prop($props, 'ZIP');
		}

		$deliveryName = self::deliveryLabel($order);
		$deliveryCellHtml = self::deliveryMailCellHtml($order);
		$confirm = self::prop($props, 'MF_CONFIRM_CHANNEL');
		$comment = trim((string)$order->getField('USER_DESCRIPTION'));
		$statusName = self::orderStatusLabel($order);
		$paySystem = self::paySystemLabel($order);

		$rows = [
			['ФИО', $fio],
		];

		if ($isJur)
		{
			$rows = array_merge($rows, self::jurCompanyRows($props));
		}

		$rows = array_merge($rows, [
			['E-mail', self::prop($props, 'EMAIL')],
			['Контактный телефон', self::prop($props, 'PHONE')],
			['Город (Населённый пункт), Область, Край и т.д.', $city],
			['Улица, Дом, Квартира', $address],
			['Индекс', $zip],
			['Способ доставки', $deliveryName],
			['Статус', $statusName],
			['Способ оплаты', $paySystem],
			['Удобный способ подтверждения заказа', $confirm],
			['Комментарий', $comment],
		]);

		$body = '';
		foreach ($rows as [$label, $value])
		{
			$value = trim((string)$value);
			if ($value === '')
			{
				continue;
			}
			if ($label === 'Способ доставки' && $deliveryCellHtml !== '')
			{
				$valueHtml = $deliveryCellHtml;
			}
			elseif ($label === 'E-mail')
			{
				$valueHtml = '<a href="mailto:' . self::esc($value) . '" style="color:' . self::COLOR_LINK . ';">' . self::esc($value) . '</a>';
			}
			else
			{
				$valueHtml = self::esc($value);
			}
			$body .= '<tr>'
				. '<td style="padding:8px 10px;border-bottom:1px solid ' . self::COLOR_BORDER . ';width:45%;color:#555;vertical-align:top;">'
				. self::esc($label)
				. '</td>'
				. '<td style="padding:8px 10px;border-bottom:1px solid ' . self::COLOR_BORDER . ';vertical-align:top;">'
				. $valueHtml
				. '</td></tr>';
		}

		if ($body === '')
		{
			$body = '<tr><td colspan="2" style="padding:10px;color:#666;">Нет данных</td></tr>';
		}

		return self::block(
			'<table cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;font-size:14px;">'
			. $body
			. '</table>'
		);
	}

	private static function paymentInstructionsBlock(Order $order): string
	{
		$payment = self::primaryPayment($order);
		if (!$payment instanceof Payment)
		{
			return '';
		}

		$type = self::paymentType($payment);
		$paySystemName = self::paySystemLabel($order);
		$currency = (string)$order->getCurrency();
		$paymentSum = (float)$payment->getSum();
		if ($paymentSum <= 0)
		{
			$paymentSum = (float)$order->getPrice();
		}
		$sumLabel = self::paymentSumLabel($paymentSum, $currency);

		$content = '';
		switch ($type)
		{
			case 'card2card':
				$content = self::card2CardPaymentHtml($order, $payment);
				break;
			case 'paykeeper':
				$content = self::paykeeperPaymentHtml($order, $payment, $paySystemName, $sumLabel);
				break;
			case 'cash':
				$content = self::cashPaymentHtml($paySystemName, $sumLabel);
				break;
			case 'invoice':
				$content = self::invoicePaymentHtml($order, $paySystemName, $sumLabel);
				break;
		}

		if ($content === '')
		{
			return '';
		}

		return self::sectionTitle('Оплата')
			. self::block($content);
	}

	private static function card2CardPaymentHtml(Order $order, Payment $payment): string
	{
		if (!class_exists(\Mf\Card2Card\TemplateRenderer::class))
		{
			return '';
		}

		return \Mf\Card2Card\TemplateRenderer::renderForOrder($order, $payment);
	}

	private static function paykeeperPaymentHtml(
		Order $order,
		Payment $payment,
		string $paySystemName,
		string $sumLabel
	): string
	{
		unset($order, $payment);

		$name = $paySystemName !== '' ? $paySystemName : 'PayKeeper';

		return '<div style="font-size:14px;line-height:1.6;color:#333;">'
			. '<p>Вы выбрали способ оплаты <strong>' . self::esc($name) . '</strong>.</p>'
			. '<p>В течение нескольких минут на вашу электронную почту придёт <strong>письмо со ссылкой на оплату</strong>.</p>'
			. '<p>Сумма к оплате: <strong>' . self::esc($sumLabel) . '</strong>.</p>'
			. '<p style="font-size:13px;color:#666;">Если письмо не пришло в течение 10–15 минут, проверьте папку «Спам» или свяжитесь с менеджером магазина.</p>'
			. '</div>';
	}

	private static function cashPaymentHtml(string $paySystemName, string $sumLabel): string
	{
		$name = $paySystemName !== '' ? $paySystemName : 'Наличными в офисе';

		return '<div style="font-size:14px;line-height:1.6;color:#333;">'
			. '<p>Вы выбрали способ оплаты <strong>' . self::esc($name) . '</strong>.</p>'
			. '<p>Вы можете оплатить заказ на сумму <strong>' . self::esc($sumLabel) . '</strong> наличными в нашем офисе по адресу:</p>'
			. '<p><strong>' . self::esc(self::OFFICE_ADDRESS) . '</strong></p>'
			. '<p>Рекомендуем заранее уточнить наличие товара и время визита у менеджера.</p>'
			. '</div>';
	}

	private static function invoicePaymentHtml(Order $order, string $paySystemName, string $sumLabel): string
	{
		$name = $paySystemName !== '' ? $paySystemName : 'Безнал: выставление счёта (для юр. лиц)';

		return '<div style="font-size:14px;line-height:1.6;color:#333;">'
			. '<p>Вы выбрали способ оплаты <strong>' . self::esc($name) . '</strong>.</p>'
			. '<p>Сумма заказа: <strong>' . self::esc($sumLabel) . '</strong>.</p>'
			. '<p>После проверки заказа менеджером мы выставим счёт на оплату и отправим его на указанный e-mail.</p>'
			. '<p>Если потребуются дополнительные документы (КПП, юридический адрес и т.д.), менеджер свяжется с вами.</p>'
			. '</div>';
	}

	private static function paykeeperPayLink(Payment $payment): string
	{
		if (!Loader::includeModule('sale') || !class_exists(\Bitrix\Sale\PaySystem\Manager::class))
		{
			return '';
		}

		$psId = (int)$payment->getPaymentSystemId();
		if ($psId <= 0)
		{
			return '';
		}

		try
		{
			$handler = self::paykeeperHandler($psId);
			if ($handler instanceof \Sale\Handlers\PaySystem\mf_paykeeperHandler)
			{
				$link = trim($handler->resolvePayLink($payment));
				if ($link !== '' && preg_match('~^https?://~i', $link))
				{
					return $link;
				}

				if ($link === '')
				{
					self::log('paykeeper: empty PAY_LINK for paymentId=' . (int)$payment->getId());
				}
			}
		}
		catch (\Throwable $e)
		{
			self::log('paykeeper link: ' . $e->getMessage());
		}

		return '';
	}

	private static function paykeeperHandler(int $paySystemId): ?\Sale\Handlers\PaySystem\mf_paykeeperHandler
	{
		self::ensurePaykeeperHandlerLoaded();

		$service = \Bitrix\Sale\PaySystem\Manager::getObjectById($paySystemId);
		if (!$service instanceof \Bitrix\Sale\PaySystem\Service)
		{
			return null;
		}

		try
		{
			$ref = new \ReflectionClass($service);
			$prop = $ref->getProperty('handler');
			$prop->setAccessible(true);
			$handler = $prop->getValue($service);
			if ($handler instanceof \Sale\Handlers\PaySystem\mf_paykeeperHandler)
			{
				return $handler;
			}
		}
		catch (\Throwable $e)
		{
			self::log('paykeeper handler: ' . $e->getMessage());
		}

		return null;
	}

	private static function ensurePaykeeperHandlerLoaded(): void
	{
		if (class_exists(\Sale\Handlers\PaySystem\mf_paykeeperHandler::class, false))
		{
			return;
		}

		$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
		$paths = [
			$docRoot . '/local/php_interface/include/sale_payment/mfpaykeeper/handler.php',
			$docRoot . '/bitrix/php_interface/include/sale_payment/mfpaykeeper/handler.php',
		];
		foreach ($paths as $path)
		{
			if ($path !== '' && is_file($path))
			{
				require_once $path;
				break;
			}
		}
	}

	private static function paymentSumLabel(float $sum, string $currency): string
	{
		$rounded = function_exists('mf_round_price') ? mf_round_price($sum) : (float)ceil($sum);
		$decimals = ((int)round(abs($rounded) * 10) % 10 === 0) ? 0 : 1;
		$cur = strtoupper(trim($currency));
		if ($cur === '')
		{
			$cur = 'RUB';
		}

		return number_format($rounded, $decimals, '.', ' ') . ' ' . $cur;
	}

	private static function primaryPayment(Order $order): ?Payment
	{
		foreach ($order->getPaymentCollection() as $payment)
		{
			if ($payment instanceof Payment)
			{
				return $payment;
			}
		}

		return null;
	}

	private static function paymentType(Payment $payment): string
	{
		$psId = (int)$payment->getPaymentSystemId();
		if ($psId <= 0)
		{
			return '';
		}

		if (
			class_exists(\Mf\Card2Card\TemplateRenderer::class)
			&& \Mf\Card2Card\TemplateRenderer::isCard2CardPaySystemId($psId)
		)
		{
			return 'card2card';
		}

		if (function_exists('mf_checkout_invoice_pay_system_ids'))
		{
			$invoiceIds = mf_checkout_invoice_pay_system_ids();
			if (in_array($psId, $invoiceIds, true))
			{
				return 'invoice';
			}
		}

		$actionFile = self::paySystemActionFile($psId);
		if ($actionFile === 'mf_paykeeper' || $actionFile === 'mfpaykeeper')
		{
			return 'paykeeper';
		}
		if ($actionFile === 'cash')
		{
			return 'cash';
		}
		if ($actionFile === 'bill')
		{
			return 'invoice';
		}

		$name = mb_strtolower(trim((string)$payment->getPaymentSystemName()));
		if (str_contains($name, 'paykeeper') || str_contains($name, 'сбп'))
		{
			return 'paykeeper';
		}
		if (str_contains($name, 'налич') && str_contains($name, 'офис'))
		{
			return 'cash';
		}
		if (str_contains($name, 'безнал') || str_contains($name, 'счёт') || str_contains($name, 'счет'))
		{
			return 'invoice';
		}
		if (str_contains($name, 'карт') && str_contains($name, 'карт'))
		{
			return 'card2card';
		}

		return '';
	}

	private static function paySystemActionFile(int $paySystemActionId): string
	{
		static $cache = [];
		if (isset($cache[$paySystemActionId]))
		{
			return $cache[$paySystemActionId];
		}

		$cache[$paySystemActionId] = '';
		if ($paySystemActionId <= 0)
		{
			return '';
		}

		try
		{
			if (Loader::includeModule('sale') && class_exists(\Bitrix\Sale\PaySystem\Manager::class))
			{
				$row = \Bitrix\Sale\PaySystem\Manager::getById($paySystemActionId);
				if (is_array($row))
				{
					$cache[$paySystemActionId] = mb_strtolower(trim((string)($row['ACTION_FILE'] ?? '')));
				}
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}

		return $cache[$paySystemActionId];
	}

	private static function log(string $message): void
	{
		if (class_exists(\Bitrix\Main\Diag\Debug::class))
		{
			\Bitrix\Main\Diag\Debug::writeToFile(date('c') . ' mf_order_mail: ' . $message, '', 'mf_order_mail.log');
		}
	}

	private static function footerBlock(bool $withProcessingNote = true): string
	{
		$parts = [
			'<div style="text-align:center;font-weight:bold;font-size:16px;margin:0 0 10px 0;">Спасибо за Ваш заказ!</div>',
		];
		if ($withProcessingNote)
		{
			$parts[] = '<div style="text-align:center;font-weight:bold;margin:0 0 16px 0;">Обработку заказа пришлем на Вашу почту (e-mail).</div>';
		}
		$parts[] = '<div style="text-align:center;font-weight:bold;margin:0 0 6px 0;">Часы работы:</div>';
		$parts[] = '<div style="text-align:center;line-height:1.5;margin:0 0 14px 0;">'
			. 'Пн-Чт с 10:00 до 18:00;<br>'
			. 'Пт с 10:00 до 17:00;<br>'
			. 'Сб-Вс - Выходной.'
			. '</div>';

		return self::block(implode("\n", $parts));
	}

	private static function sectionTitle(string $title): string
	{
		return self::block(
			'<div style="font-size:16px;font-weight:bold;color:' . self::COLOR_SECTION . ';margin:0 0 10px 0;">'
			. self::esc($title)
			. '</div>'
		);
	}

	private static function wrapOpen(): string
	{
		return '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>'
			. '<body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#222;">'
			. '<table cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td align="center" style="padding:16px 8px;">'
			. '<table cellpadding="0" cellspacing="0" border="0" width="680" style="max-width:680px;width:100%;">';
	}

	private static function wrapClose(): string
	{
		return '</table></td></tr></table></body></html>';
	}

	private static function block(string $inner): string
	{
		return '<tr><td style="padding:0 0 16px 0;">' . $inner . '</td></tr>';
	}

	/** @return array<string, string> */
	private static function orderPropsMap(Order $order): array
	{
		$skip = [
			'LOCATION' => true,
			'MF_NOMINATIM_JSON' => true,
			'MF_EDOST_TO_CITY' => true,
			'MF_EDOST_TARIF_ID' => true,
			'MF_EDOST_TARIF_COMPANY' => true,
			'MF_EDOST_TARIF_NAME' => true,
			'MF_EDOST_TARIF_PRICE' => true,
		];

		$out = [];
		$pc = $order->getPropertyCollection();
		foreach ($pc as $prop)
		{
			$code = trim((string)$prop->getField('CODE'));
			if ($code === '' || isset($skip[$code]))
			{
				continue;
			}
			$val = $prop->getValue();
			if (is_array($val))
			{
				$val = implode(', ', array_map('strval', $val));
			}
			$val = trim((string)$val);
			if ($val === '')
			{
				continue;
			}
			$out[$code] = $val;
		}

		return $out;
	}

	/** @param array<string, string> $props */
	private static function jurCompanyRows(array $props): array
	{
		return [
			['Название компании', self::prop($props, 'COMPANY')],
			['Юридический адрес', self::prop($props, 'COMPANY_ADR')],
			['Фактический адрес', self::prop($props, 'FACT_ADDRESS')],
			['ИНН', self::prop($props, 'INN')],
			['КПП', self::prop($props, 'KPP')],
			['ОГРН / ОГРНИП', self::prop($props, 'OGRN')],
			['БИК', self::prop($props, 'BIK')],
			['Расчетный счет', self::prop($props, 'RS')],
			['Корр. счет', self::prop($props, 'KS')],
			['Банковские реквизиты', self::prop($props, 'BANK_DETAILS')],
		];
	}

	private static function isJurPersonType(int $personTypeId): bool
	{
		if ($personTypeId <= 0)
		{
			return false;
		}
		if (function_exists('mf_checkout_person_type_map'))
		{
			$map = mf_checkout_person_type_map();
			$jurId = (int)($map['jur'] ?? 0);
			if ($jurId > 0)
			{
				return $personTypeId === $jurId;
			}
		}

		return $personTypeId === 2;
	}

	/** @param array<string, string> $props */
	private static function prop(array $props, string $code): string
	{
		return trim((string)($props[$code] ?? ''));
	}

	private static function deliveryLabel(Order $order): string
	{
		$parsed = self::parseEdostDeliveryData((string)$order->getField('COMMENTS'));
		if ($parsed !== null)
		{
			return (string)$parsed['label'];
		}

		$name = '';
		foreach ($order->getShipmentCollection() as $shipment)
		{
			if (!$shipment instanceof Shipment || $shipment->isSystem())
			{
				continue;
			}
			$name = trim((string)$shipment->getField('DELIVERY_NAME'));
			if ($name !== '')
			{
				break;
			}
		}

		if (self::isTechnicalDeliveryName($name))
		{
			$comments = trim((string)$order->getField('COMMENTS'));
			if (preg_match('~Доставка: стоимость будет рассчитана менеджером~u', $comments))
			{
				return 'Стоимость доставки будет рассчитана менеджером';
			}
		}

		return $name;
	}

	private static function deliveryMailCellHtml(Order $order): string
	{
		$parsed = self::parseEdostDeliveryData((string)$order->getField('COMMENTS'));
		if ($parsed === null)
		{
			return '';
		}

		$html = self::esc((string)$parsed['label']);
		if (!empty($parsed['show_note']))
		{
			$html .= '<br><span style="display:inline-block;margin-top:8px;padding:8px 10px;border-radius:6px;background:#fef3c7;border:1px solid #f59e0b;color:#b45309;font-size:13px;line-height:1.45;">'
				. self::esc(self::DELIVERY_PRELIMINARY_NOTE)
				. '</span>';
		}

		return $html;
	}

	/**
	 * @return array{label: string, tarif_id: string, show_note: bool}|null
	 */
	private static function parseEdostDeliveryData(string $comments): ?array
	{
		$comments = trim($comments);
		if ($comments === '')
		{
			return null;
		}

		if (preg_match(
			'~Доставка \(eDost[^:]*:\s*(.+?)\s*—\s*(.+?)\s*—\s*(?:([0-9]+(?:[.,][0-9]+)?)\s*₽|оплата при получении)(?:\s*(\([0-9]+(?:–[0-9]+)?\s*дн\.\)))?\s*\(tarif_id=([^)]+)\)~mu',
			$comments,
			$m
		))
		{
			$company = trim((string)$m[1]);
			$tariff = trim((string)$m[2]);
			$priceRaw = trim((string)($m[3] ?? ''));
			$daysSuffix = trim((string)($m[4] ?? ''));
			$tarifId = trim((string)($m[5] ?? ''));

			if ($tarifId === 'pickup' || mb_strtolower($company) === 'самовывоз')
			{
				$label = $tariff !== '' ? ('Самовывоз — ' . $tariff) : 'Самовывоз';
			}
			elseif ($tarifId === 'custom' || mb_strtolower($company) === 'свой вариант')
			{
				$label = $tariff !== '' ? $tariff : 'Свой вариант';
			}
			else
			{
				$label = $company !== '' ? ($company . ' — ' . $tariff) : $tariff;
			}

			if ($priceRaw !== '')
			{
				$label .= ' — ' . str_replace('.', ',', $priceRaw) . ' ₽';
			}
			if ($daysSuffix !== '')
			{
				$label .= ' ' . $daysSuffix;
			}

			$showNote = !self::isPickupTarifId($tarifId, $company);

			return [
				'label' => $label,
				'tarif_id' => $tarifId,
				'show_note' => $showNote,
			];
		}

		if (preg_match(
			'~Доставка \(eDost[^:]*:\s*(.+?)\s*—\s*(.+?)\s*—\s*([0-9]+(?:[.,][0-9]+)?)\s*₽(?:\s*(\([0-9]+(?:–[0-9]+)?\s*дн\.\)))?~u',
			$comments,
			$m
		))
		{
			$company = trim((string)$m[1]);
			$tariff = trim((string)$m[2]);
			$price = str_replace('.', ',', (string)$m[3]);
			$daysSuffix = trim((string)($m[4] ?? ''));
			$label = $company !== '' ? ($company . ' — ' . $tariff) : $tariff;
			$label .= ' — ' . $price . ' ₽';
			if ($daysSuffix !== '')
			{
				$label .= ' ' . $daysSuffix;
			}

			return [
				'label' => $label,
				'tarif_id' => '',
				'show_note' => !self::isPickupTarifId('', $company),
			];
		}

		return null;
	}

	private static function isPickupTarifId(string $tarifId, string $company = ''): bool
	{
		if ($tarifId === 'pickup')
		{
			return true;
		}
		if (function_exists('mf_checkout_is_pickup_tariff'))
		{
			return mf_checkout_is_pickup_tariff($tarifId, $company);
		}

		return mb_strtolower(trim($company)) === 'самовывоз';
	}

	private static function parseEdostDelivery(string $comments): string
	{
		$parsed = self::parseEdostDeliveryData($comments);

		return $parsed !== null ? (string)$parsed['label'] : '';
	}

	private static function isTechnicalDeliveryName(string $name): bool
	{
		$name = trim($name);
		if ($name === '')
		{
			return true;
		}
		$lower = mb_strtolower($name);

		return in_array($lower, ['стандартный', 'без доставки', 'no delivery'], true);
	}

	private static function paySystemLabel(Order $order): string
	{
		foreach ($order->getPaymentCollection() as $payment)
		{
			if (!$payment instanceof Payment)
			{
				continue;
			}
			$ps = $payment->getPaySystem();
			if ($ps)
			{
				$name = trim((string)$ps->getField('NAME'));
				if ($name !== '')
				{
					return $name;
				}
			}
		}

		return '';
	}

	private static function ensureMfOrderStatusDefaults(Order $order): void
	{
		$orderId = (int)$order->getId();
		if ($orderId <= 0 || !function_exists('mf_order_custom_status_set_defaults_for_new_order'))
		{
			return;
		}

		try
		{
			mf_order_custom_status_set_defaults_for_new_order($orderId);
		}
		catch (\Throwable $e)
		{
			self::log('ensure mf order status defaults: orderId=' . $orderId . ' ' . $e->getMessage());
		}
	}

	private static function orderStatusLabel(Order $order): string
	{
		$orderId = (int)$order->getId();
		if ($orderId > 0 && function_exists('mf_order_custom_status_get'))
		{
			try
			{
				$mfStatus = mf_order_custom_status_get($orderId);
				if (is_array($mfStatus))
				{
					$label = trim((string)($mfStatus['ORDER_STATUS_LABEL'] ?? ''));
					if ($label !== '')
					{
						return $label;
					}

					$code = trim((string)($mfStatus['ORDER_STATUS'] ?? ''));
					if ($code !== '' && function_exists('mf_order_custom_status_label'))
					{
						$label = trim((string)mf_order_custom_status_label('order', $code));
						if ($label !== '')
						{
							return $label;
						}
					}
				}
			}
			catch (\Throwable $e)
			{
				self::log('order status label from 1C: orderId=' . $orderId . ' ' . $e->getMessage());
			}
		}

		if (function_exists('mf_order_custom_status_label'))
		{
			$fallback = trim((string)mf_order_custom_status_label('order', 'in_progress'));
			if ($fallback !== '')
			{
				return $fallback;
			}
		}

		return 'В работе';
	}

	private static function basketItemProductUrl(int $productId, Order $order): string
	{
		$productId = (int)$productId;
		if ($productId <= 0)
		{
			return '';
		}

		$code = '';
		if (Loader::includeModule('iblock') && class_exists(\CIBlockElement::class))
		{
			$res = \CIBlockElement::GetList(
				[],
				['ID' => $productId],
				false,
				['nTopCount' => 1],
				['ID', 'CODE']
			);
			if ($row = $res->Fetch())
			{
				$code = trim((string)($row['CODE'] ?? ''));
			}
		}

		$base = rtrim(self::siteUrl($order), '/');
		if ($code !== '')
		{
			return $base . '/products/' . rawurlencode($code) . '/';
		}

		return $base . '/products/?ELEMENT_ID=' . $productId;
	}

	private static function basketItemProp(BasketItemBase $item, string $code): string
	{
		$code = trim($code);
		if ($code === '')
		{
			return '';
		}
		$props = $item->getPropertyCollection();
		foreach ($props as $p)
		{
			if (strcasecmp(trim((string)$p->getField('CODE')), $code) !== 0)
			{
				continue;
			}
			$v = trim((string)$p->getField('VALUE'));
			if ($v !== '')
			{
				return $v;
			}
		}

		return '';
	}

	private static function basketItemStoreId(BasketItemBase $item): int
	{
		return (int)self::basketItemProp($item, 'MF_STORE_ID');
	}

	private static function basketItemDeliverySpbHtml(int $storeId, int $productId): string
	{
		if (function_exists('mf_store_delivery_email_cell_html'))
		{
			return mf_store_delivery_email_cell_html($storeId, $productId);
		}
		if (function_exists('mf_store_delivery_spb_email_html'))
		{
			return mf_store_delivery_spb_email_html($storeId, $productId);
		}

		return '—';
	}

	private static function basketItemStoreTitle(BasketItemBase $item): string
	{
		$title = self::basketItemProp($item, 'MF_STORE_TITLE');
		if ($title !== '')
		{
			return $title;
		}

		$storeId = (int)self::basketItemProp($item, 'MF_STORE_ID');
		if ($storeId > 0 && function_exists('mf_store_row'))
		{
			$row = mf_store_row($storeId);
			if (is_array($row))
			{
				$title = trim((string)($row['TITLE'] ?? ''));
				if ($title !== '')
				{
					return $title;
				}
			}
		}

		return '';
	}

	private static function basketItemDisplayName(BasketItemBase $item, int $productId): string
	{
		if (function_exists('mf_basket_item_display_name'))
		{
			$name = mf_basket_item_display_name([
				'NAME' => (string)$item->getField('NAME'),
				'PRODUCT_ID' => $productId,
			]);
			if ($name !== '')
			{
				return $name;
			}
		}

		return trim((string)$item->getField('NAME'));
	}

	private static function basketItemBrand(BasketItemBase $item, int $productId): string
	{
		foreach (['MF_BRAND', 'BRAND', 'CML2_MANUFACTURER', 'MF_BRAND_NORM'] as $code)
		{
			$value = self::basketItemProp($item, $code);
			if ($value !== '')
			{
				return $value;
			}
		}

		if ($productId > 0 && function_exists('mf_catalog_brand_article_by_product_id'))
		{
			$meta = mf_catalog_brand_article_by_product_id($productId);

			return trim((string)($meta['brand'] ?? ''));
		}

		return '';
	}

	private static function basketItemArticle(BasketItemBase $item, int $productId): string
	{
		$props = $item->getPropertyCollection();
		foreach ($props as $p)
		{
			$code = strtoupper(trim((string)$p->getField('CODE')));
			if (in_array($code, ['CML2_ARTICLE', 'ARTNUMBER', 'ARTICLE', 'MF_ARTICLE_NORM'], true))
			{
				$v = trim((string)$p->getField('VALUE'));
				if ($v !== '')
				{
					return $v;
				}
			}
		}

		if ($productId > 0 && function_exists('mf_catalog_brand_article_by_product_id'))
		{
			$meta = mf_catalog_brand_article_by_product_id($productId);

			return trim((string)($meta['article'] ?? ''));
		}

		return '';
	}

	private static function orderUserName(Order $order): string
	{
		$uid = (int)$order->getUserId();
		if ($uid <= 0 || !class_exists(\CUser::class))
		{
			return '';
		}
		$u = \CUser::GetByID($uid)->Fetch();
		if (!is_array($u))
		{
			return '';
		}

		return trim((string)($u['NAME'] ?? '') . ' ' . (string)($u['LAST_NAME'] ?? ''));
	}

	private static function siteUrl(Order $order): string
	{
		$siteId = (string)$order->getSiteId();
		if ($siteId !== '' && class_exists(\CSite::class))
		{
			$site = \CSite::GetByID($siteId)->Fetch();
			if (is_array($site))
			{
				$serverName = trim((string)($site['SERVER_NAME'] ?? ''));
				if ($serverName !== '' && $serverName !== '_' && str_contains($serverName, '.'))
				{
					return 'https://' . $serverName;
				}
			}
		}

		return 'https://' . self::SITE_HOST;
	}

	private static function moneyPlain(float $value, string $currency): string
	{
		unset($currency);
		$rounded = function_exists('mf_round_price') ? mf_round_price($value) : (float)ceil($value);

		return number_format($rounded, 0, ',', ' ');
	}

	private static function qtyLabel(float $qty): string
	{
		if (abs($qty - (float)(int)$qty) < 1e-9)
		{
			return (int)$qty . ' шт';
		}

		return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') . ' шт';
	}

	private static function esc(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	private static function firstNonEmpty(string ...$values): string
	{
		foreach ($values as $value)
		{
			$value = trim($value);
			if ($value !== '')
			{
				return $value;
			}
		}

		return '';
	}
}
