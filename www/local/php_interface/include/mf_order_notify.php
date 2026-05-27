<?php
declare(strict_types=1);

namespace Mf\OrderNotify;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Mail\Mail;
use Bitrix\Sale\Order;
use Mf\OrderMail\Renderer;

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

		\Bitrix\Main\EventManager::getInstance()->addEventHandler(
			'sale',
			'OnSaleOrderSaved',
			[Handlers::class, 'onSaleOrderSaved']
		);
	}
}

final class Handlers
{
	public static function onSaleOrderSaved(Event $event): void
	{
		try
		{
			self::run($event);
		}
		catch (\Throwable $e)
		{
			Debug::writeToFile(
				date('c') . ' order notify: ' . $e->getMessage(),
				'',
				'mf_order_notify.log'
			);
		}
	}

	private static function run(Event $event): void
	{
		$envOff = getenv('MF_ORDER_NOTIFY_ENABLED');
		if ($envOff !== false && in_array(strtolower(trim((string)$envOff)), ['0', 'false', 'off', 'no'], true))
		{
			return;
		}

		$to = function_exists('mf_mail_admin_inbox')
			? mf_mail_admin_inbox()
			: trim((string)getenv('MF_ORDER_NOTIFY_EMAIL'));
		if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL))
		{
			$to = 'andrey@motor-force.ru';
		}
		if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL))
		{
			return;
		}

		if (!(bool)$event->getParameter('IS_NEW'))
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

		if ((string)$order->getField('CANCELED') === 'Y')
		{
			return;
		}

		$orderId = (int)$order->getId();
		if ($orderId <= 0)
		{
			return;
		}

		$from = function_exists('mf_mail_default_from_admin')
			? mf_mail_default_from_admin()
			: (function_exists('mf_mail_default_from') ? mf_mail_default_from() : '');
		if ($from === '')
		{
			$from = trim((string)getenv('MF_SMTP_FROM_ROBOT'));
		}
		if ($from === '')
		{
			$from = trim((string)Option::get('main', 'email_from', ''));
		}
		if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL))
		{
			Debug::writeToFile(
				date('c') . ' order notify: нет валидного From (MF_SMTP_FROM_ROBOT / main.email_from)',
				'',
				'mf_order_notify.log'
			);

			return;
		}

		$display = Renderer::orderDisplayNumber($order);
		$body = Renderer::renderAdminNewOrder($order);
		$subject = self::sanitizeSubject('Заказ: №' . $display);

		$header = ['From' => $from, 'X-MF-SMTP-Profile' => 'robot'];
		$customerEmail = self::customerEmail($order);
		if ($customerEmail !== '')
		{
			$header['Reply-To'] = $customerEmail;
		}

		$params = [
			'TO' => $to,
			'SUBJECT' => $subject,
			'BODY' => $body,
			'CHARSET' => 'UTF-8',
			'CONTENT_TYPE' => 'html',
			'HEADER' => $header,
		];

		if (!class_exists(Mail::class) || !Mail::send($params))
		{
			Debug::writeToFile(
				date('c') . ' order notify: Mail::send failed for orderId=' . $orderId,
				'',
				'mf_order_notify.log'
			);
		}
	}

	private static function sanitizeSubject(string $s): string
	{
		$s = preg_replace('~[\r\n]+~u', ' ', $s) ?? $s;

		return trim(preg_replace('~\s+~u', ' ', $s) ?? $s);
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
}
