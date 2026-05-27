<?php
declare(strict_types=1);

namespace Mf\OrderMail;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Sale\BasketItemBase;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;
use Bitrix\Sale\Shipment;

final class Bootstrap
{
	private static bool $inited = false;
	private const MAIL_TEMPLATES_VERSION = '2';

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
				'SUBJECT' => 'Заказ: №#MF_ORDER_DISPLAY#',
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
	public static function onOrderNewSendEmail(int $orderId, &$eventName, &$fields): void
	{
		try
		{
			$order = self::loadOrder($orderId);
			if (!$order)
			{
				return;
			}

			$display = Renderer::orderDisplayNumber($order);
			$fields['MF_ORDER_DISPLAY'] = $display;
			$fields['MF_ORDER_MAIL_BODY'] = Renderer::renderNewOrder($order);
			$fields['ORDER_LIST'] = '';
		}
		catch (\Throwable $e)
		{
			self::log($e->getMessage());
		}
	}

	public static function onOrderStatusSendEmail(int $orderId, &$eventName, &$fields, $statusId): bool
	{
		try
		{
			if (self::shouldSuppressStatusEmail((string)$statusId))
			{
				return false;
			}

			$order = self::loadOrder($orderId);
			if (!$order)
			{
				return true;
			}

			$display = Renderer::orderDisplayNumber($order);
			$fields['MF_ORDER_DISPLAY'] = $display;
			$fields['MF_ORDER_MAIL_BODY'] = Renderer::renderStatusChange(
				$order,
				(string)($fields['ORDER_STATUS'] ?? ''),
				(string)($fields['ORDER_DESCRIPTION'] ?? '')
			);
		}
		catch (\Throwable $e)
		{
			self::log($e->getMessage());
		}

		return true;
	}

	public static function onBeforeEventSend(array &$fields, array &$eventMessage, $context, array &$result): void
	{
		unset($context, $result);

		$eventName = (string)($eventMessage['EVENT_NAME'] ?? '');
		if (!in_array($eventName, self::mailEventNames(), true))
		{
			return;
		}

		$clientFrom = function_exists('mf_mail_default_from_client')
			? mf_mail_default_from_client()
			: '';
		if ($clientFrom !== '')
		{
			$fields['=From'] = $clientFrom;
			$fields['=Reply-To'] = $clientFrom;
		}

		$body = trim((string)($fields['MF_ORDER_MAIL_BODY'] ?? ''));
		if ($body === '')
		{
			return;
		}

		$eventMessage['BODY_TYPE'] = 'html';
		$eventMessage['SITE_TEMPLATE_ID'] = '';
		$eventMessage['MESSAGE'] = $body;
		unset($eventMessage['MESSAGE_PHP']);
	}

	/** @return list<string> */
	private static function mailEventNames(): array
	{
		return [
			'SALE_NEW_ORDER',
			'SALE_STATUS_CHANGED_N',
			'SALE_STATUS_CHANGED_F',
			'SALE_STATUS_CHANGED_P',
		];
	}

	private static function shouldSuppressStatusEmail(string $statusId): bool
	{
		$statusId = trim($statusId);
		if ($statusId === '')
		{
			return false;
		}

		if (Loader::includeModule('sale') && class_exists(\Bitrix\Sale\OrderStatus::class))
		{
			$initial = trim((string)\Bitrix\Sale\OrderStatus::getInitialStatus());
			if ($initial !== '' && $statusId === $initial)
			{
				return true;
			}
		}

		if (class_exists(\CSaleStatus::class))
		{
			$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
			$row = \CSaleStatus::GetByID($statusId, $lang);
			if (is_array($row))
			{
				$name = mb_strtolower(trim((string)($row['NAME'] ?? '')));
				if (
					str_contains($name, 'ожидается оплата')
					|| str_contains($name, 'принят, ожидается')
				)
				{
					return true;
				}
			}
		}

		return false;
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

	private static function log(string $message): void
	{
		if (class_exists(\Bitrix\Main\Diag\Debug::class))
		{
			\Bitrix\Main\Diag\Debug::writeToFile(date('c') . ' mf_order_mail: ' . $message, '', 'mf_order_mail.log');
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
					. '<td style="padding:8px 10px;border-bottom:1px solid ' . self::COLOR_BORDER . ';vertical-align:top;">' . self::esc($storeTitle !== '' ? $storeTitle : '—') . '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid ' . self::COLOR_BORDER . ';vertical-align:top;">' . $deliverySpbHtml . '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid ' . self::COLOR_BORDER . ';vertical-align:top;color:' . self::COLOR_LINK . ';">' . $nameHtml . '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid ' . self::COLOR_BORDER . ';vertical-align:top;">' . self::esc($brand !== '' ? $brand : '—') . '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid ' . self::COLOR_BORDER . ';text-align:center;white-space:nowrap;">' . self::esc(self::qtyLabel($qty)) . '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid ' . self::COLOR_BORDER . ';text-align:right;white-space:nowrap;">' . self::esc(self::moneyPlain($price, $currency)) . '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid ' . self::COLOR_BORDER . ';text-align:right;white-space:nowrap;">' . self::esc(self::moneyPlain($lineTotal, $currency)) . '</td>'
					. '</tr>';
			}
		}

		if ($rows === '')
		{
			$rows = '<tr><td colspan="7" style="padding:10px;text-align:center;color:#666;">Нет позиций</td></tr>';
		}

		$orderTotal = (float)$order->getPrice();
		$payTotal = $orderTotal > 0 ? $orderTotal : $itemsTotal;

		return self::block(
			'<table cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;font-size:14px;">'
			. '<tr style="background:' . self::COLOR_HEAD_BG . ';font-weight:bold;">'
			. '<td style="padding:8px 10px;border:1px solid ' . self::COLOR_BORDER . ';">Склад</td>'
			. '<td style="padding:8px 10px;border:1px solid ' . self::COLOR_BORDER . ';width:170px;">Доставка</td>'
			. '<td style="padding:8px 10px;border:1px solid ' . self::COLOR_BORDER . ';">Наименование</td>'
			. '<td style="padding:8px 10px;border:1px solid ' . self::COLOR_BORDER . ';">Бренд</td>'
			. '<td style="padding:8px 10px;border:1px solid ' . self::COLOR_BORDER . ';text-align:center;width:90px;">Количество</td>'
			. '<td style="padding:8px 10px;border:1px solid ' . self::COLOR_BORDER . ';text-align:right;width:100px;">Цена</td>'
			. '<td style="padding:8px 10px;border:1px solid ' . self::COLOR_BORDER . ';text-align:right;width:110px;">Стоимость</td>'
			. '</tr>'
			. $rows
			. '<tr>'
			. '<td colspan="6" style="padding:8px 10px;text-align:right;font-weight:bold;border-top:2px solid ' . self::COLOR_BORDER . ';">Итого:</td>'
			. '<td style="padding:8px 10px;text-align:right;font-weight:bold;border-top:2px solid ' . self::COLOR_BORDER . ';">'
			. self::esc(self::moneyPlain($itemsTotal, $currency)) . ' руб.'
			. '</td></tr>'
			. '<tr style="background:' . self::COLOR_TOTAL_BG . ';">'
			. '<td colspan="6" style="padding:10px;text-align:right;font-weight:bold;font-size:16px;">Всего к оплате:</td>'
			. '<td style="padding:10px;text-align:right;font-weight:bold;font-size:16px;">'
			. self::esc(self::moneyPlain($payTotal, $currency)) . ' руб.'
			. '</td></tr>'
			. '</table>'
		);
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
		unset($order);

		$payLink = self::paykeeperPayLink($payment);

		$name = $paySystemName !== '' ? $paySystemName : 'PayKeeper';
		$html = '<div style="font-size:14px;line-height:1.6;color:#333;">'
			. '<p>Вы выбрали способ оплаты <strong>' . self::esc($name) . '</strong>.</p>'
			. '<p>Пожалуйста, оплатите заказ на сумму <strong>' . self::esc($sumLabel) . '</strong>.</p>';

		if ($payLink !== '')
		{
			$html .= '<p><a href="' . self::esc($payLink) . '" style="color:' . self::COLOR_LINK . ';text-decoration:underline;font-weight:bold;">'
				. 'Перейти к оплате на PayKeeper'
				. '</a></p>';
			$html .= '<p style="font-size:13px;color:#666;">Если ссылка не открывается, скопируйте адрес:<br>'
				. self::esc($payLink)
				. '</p>';
		}
		else
		{
			$html .= '<p>Не удалось сформировать ссылку на оплату автоматически. Пожалуйста, свяжитесь с менеджером или перейдите к оплате из личного кабинета.</p>';
		}

		$html .= '</div>';

		return $html;
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

	private static function orderStatusLabel(Order $order): string
	{
		$statusId = trim((string)$order->getField('STATUS_ID'));
		if ($statusId === '')
		{
			return '';
		}

		if (Loader::includeModule('sale') && class_exists(\CSaleStatus::class))
		{
			$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
			$row = \CSaleStatus::GetByID($statusId, $lang);
			if (is_array($row))
			{
				$name = trim((string)($row['NAME'] ?? ''));
				if ($name !== '')
				{
					return $name;
				}
			}
		}

		return $statusId;
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
