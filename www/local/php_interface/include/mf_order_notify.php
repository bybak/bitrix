<?php
declare(strict_types=1);

namespace Mf\OrderNotify;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Mail\Mail;
use Bitrix\Sale\BasketItemBase;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;
use Bitrix\Sale\Shipment;

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

		$toRaw = getenv('MF_ORDER_NOTIFY_EMAIL');
		$to = ($toRaw !== false && trim((string)$toRaw) !== '')
			? trim((string)$toRaw)
			: 'andrey@motor-force.ru';

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

		$from = trim((string)getenv('MF_SMTP_FROM'));
		if ($from === '')
		{
			$from = trim((string)Option::get('main', 'email_from', ''));
		}
		if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL))
		{
			Debug::writeToFile(
				date('c') . ' order notify: нет валидного From (MF_SMTP_FROM / main.email_from)',
				'',
				'mf_order_notify.log'
			);

			return;
		}

		$body = BodyBuilder::build($order);
		$acc = trim((string)$order->getField('ACCOUNT_NUMBER'));
		$subBase = 'Новый заказ #' . ($acc !== '' ? $acc : (string)$orderId);
		$subject = self::sanitizeSubject($subBase);

		$header = ['From' => $from];
		$params = [
			'TO' => $to,
			'SUBJECT' => $subject,
			'BODY' => $body,
			'CHARSET' => 'UTF-8',
			'CONTENT_TYPE' => 'text',
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
}

final class BodyBuilder
{
	public static function build(Order $order): string
	{
		$lines = [];
		$oid = (int)$order->getId();
		$cur = (string)$order->getCurrency();
		$acc = trim((string)$order->getField('ACCOUNT_NUMBER'));

		$lines[] = 'Новый заказ на сайте';
		$lines[] = 'Дата: ' . date('Y-m-d H:i:s');
		$lines[] = '---';
		$lines[] = 'ID заказа: ' . $oid;
		if ($acc !== '')
		{
			$lines[] = 'Номер заказа: ' . $acc;
		}
		$lines[] = 'Статус: ' . (string)$order->getField('STATUS_ID');
		$lines[] = 'Отменён: ' . ((string)$order->getField('CANCELED') === 'Y' ? 'да' : 'нет');
		$lines[] = 'Сумма: ' . self::money((float)$order->getPrice(), $cur);
		$lines[] = 'Оплачен: ' . ($order->isPaid() ? 'да' : 'нет');

		$uid = (int)$order->getUserId();
		$lines[] = '---';
		$lines[] = 'Покупатель (user ID): ' . ($uid > 0 ? (string)$uid : '—');
		if ($uid > 0 && class_exists(\CUser::class))
		{
			$u = \CUser::GetByID($uid)->Fetch();
			if (is_array($u))
			{
				if (!empty($u['EMAIL']))
				{
					$lines[] = 'E-mail (профиль): ' . (string)$u['EMAIL'];
				}
				$name = trim((string)($u['NAME'] ?? '') . ' ' . (string)($u['LAST_NAME'] ?? ''));
				if ($name !== '')
				{
					$lines[] = 'ФИО (профиль): ' . $name;
				}
				if (!empty($u['LOGIN']))
				{
					$lines[] = 'Логин: ' . (string)$u['LOGIN'];
				}
			}
		}

		$lines[] = '---';
		$lines[] = 'Свойства заказа:';
		$pc = $order->getPropertyCollection();
		foreach ($pc as $prop)
		{
			$code = (string)$prop->getField('CODE');
			$name = (string)$prop->getField('NAME');
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
			$lines[] = ($name !== '' ? $name : $code) . ': ' . $val;
		}

		$lines[] = '---';
		$lines[] = 'Корзина:';
		$basket = $order->getBasket();
		if ($basket)
		{
			$n = 0;
			foreach ($basket as $item)
			{
				if (!$item instanceof BasketItemBase)
				{
					continue;
				}
				$n++;
				$pid = (int)$item->getProductId();
				$qty = (float)$item->getQuantity();
				$nameIt = (string)$item->getField('NAME');
				$lines[] = $n . ') ' . $nameIt;
				$lines[] = '   product_id=' . $pid . ', qty=' . self::qtyStr($qty) . ', позиция=' . self::money((float)$item->getPrice(), $cur);
				$lines[] = '   сумма строки=' . self::money((float)$item->getFinalPrice(), $cur);
				$props = $item->getPropertyCollection();
				foreach ($props as $p)
				{
					$c = (string)$p->getField('CODE');
					$v = $p->getField('VALUE');
					if (is_array($v))
					{
						$v = implode(',', $v);
					}
					$v = trim((string)$v);
					if ($v === '' || $c === '')
					{
						continue;
					}
					$lines[] = '   [' . $c . '] ' . $v;
				}
			}
			if ($n === 0)
			{
				$lines[] = '(нет позиций)';
			}
		}
		else
		{
			$lines[] = '(корзина недоступна)';
		}

		$lines[] = '---';
		$lines[] = 'Доставка:';
		$hasShip = false;
		foreach ($order->getShipmentCollection() as $shipment)
		{
			if (!$shipment instanceof Shipment || $shipment->isSystem())
			{
				continue;
			}
			$hasShip = true;
			$dname = trim((string)$shipment->getField('DELIVERY_NAME'));
			$lines[] = ($dname !== '' ? $dname : 'Доставка') . ': ' . self::money((float)$shipment->getField('PRICE_DELIVERY'), $cur);
			$cSh = (string)$shipment->getField('CANCELED');
			$lines[] = '  Отгрузка #' . (int)$shipment->getId() . ', отменена=' . ($cSh === 'Y' ? 'да' : 'нет');
		}
		if (!$hasShip)
		{
			$lines[] = '(нет отгрузок)';
		}

		$lines[] = '---';
		$lines[] = 'Оплата:';
		$hasPay = false;
		foreach ($order->getPaymentCollection() as $payment)
		{
			if (!$payment instanceof Payment)
			{
				continue;
			}
			$hasPay = true;
			$payName = '';
			$ps = $payment->getPaySystem();
			if ($ps)
			{
				$payName = trim((string)$ps->getField('NAME'));
			}
			$lines[] = ($payName !== '' ? $payName : 'Оплата') . ': ' . self::money((float)$payment->getField('SUM'), $cur);
			$lines[] = '  Оплачено: ' . ($payment->isPaid() ? 'да' : 'нет');
		}
		if (!$hasPay)
		{
			$lines[] = '(нет платежей)';
		}

		$lines[] = '---';
		$lines[] = 'Комментарий покупателя: ' . (trim((string)$order->getField('USER_DESCRIPTION')) !== '' ? trim((string)$order->getField('USER_DESCRIPTION')) : '—');

		return implode("\n", $lines) . "\n";
	}

	private static function money(float $v, string $currency): string
	{
		if (class_exists(\CCurrencyLang::class))
		{
			$s = \CCurrencyLang::CurrencyFormat($v, $currency);
			if (is_string($s) && $s !== '')
			{
				return $s;
			}
		}

		return number_format($v, 2, '.', ' ') . ' ' . $currency;
	}

	private static function qtyStr(float $q): string
	{
		if (abs($q - (float)(int)$q) < 1e-9)
		{
			return (string)(int)$q;
		}

		return (string)$q;
	}
}
