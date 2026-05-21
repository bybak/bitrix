<?php
declare(strict_types=1);

namespace Mf\OrderMail;

use Bitrix\Main\Loader;
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

		$em = \Bitrix\Main\EventManager::getInstance();
		$em->addEventHandler('sale', 'OnOrderNewSendEmail', [Handlers::class, 'onOrderNewSendEmail']);
		$em->addEventHandler('sale', 'OnOrderStatusSendEmail', [Handlers::class, 'onOrderStatusSendEmail']);
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

	public static function onOrderStatusSendEmail(int $orderId, &$eventName, &$fields, $statusId): void
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
	private const COLOR_TITLE = '#1a73b8';
	private const COLOR_SECTION = '#e65100';
	private const COLOR_LINK = '#1a73b8';
	private const COLOR_BORDER = '#dddddd';
	private const COLOR_HEAD_BG = '#f3f3f3';
	private const COLOR_TOTAL_BG = '#fff8dc';

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
		return self::renderOrderMail($order, false);
	}

	public static function renderAdminNewOrder(Order $order): string
	{
		return self::renderOrderMail($order, true);
	}

	private static function renderOrderMail(Order $order, bool $forAdmin): string
	{
		$display = self::orderDisplayNumber($order);
		$siteUrl = self::siteUrl($order);
		$siteLink = '<a href="' . self::esc($siteUrl) . '" style="color:' . self::COLOR_LINK . ';text-decoration:underline;">'
			. self::esc(self::SITE_HOST) . '</a>';
		$dateInsert = '';
		$dateObj = $order->getDateInsert();
		if ($dateObj instanceof \Bitrix\Main\Type\DateTime)
		{
			$dateInsert = $dateObj->format('d.m.Y H:i:s');
		}

		$html = [];
		$html[] = self::wrapOpen();
		$adminUrl = rtrim($siteUrl, '/') . '/bitrix/admin/sale_order_view.php?ID=' . (int)$order->getId() . '&lang=ru';

		if ($forAdmin)
		{
			$subtitle = 'Поступил новый заказ на сайте ' . $siteLink;
			if ($dateInsert !== '')
			{
				$subtitle .= '<br>Дата: ' . self::esc($dateInsert);
			}
			$subtitle .= '<br><a href="' . self::esc($adminUrl) . '" style="color:' . self::COLOR_LINK . ';">Открыть заказ в админке</a>';

			$html[] = self::block(
				'<div style="text-align:center;margin:0 0 18px 0;">'
				. '<div style="font-size:28px;font-weight:bold;color:' . self::COLOR_TITLE . ';margin:0 0 8px 0;">'
				. 'Новый заказ: №' . self::esc($display)
				. '</div>'
				. '<div style="font-size:14px;color:#333;line-height:1.5;">' . $subtitle . '</div>'
				. '</div>'
			);
		}
		else
		{
			$html[] = self::block(
				'<div style="text-align:center;margin:0 0 18px 0;">'
				. '<div style="font-size:28px;font-weight:bold;color:' . self::COLOR_TITLE . ';margin:0 0 8px 0;">'
				. 'Заказ: №' . self::esc($display)
				. '</div>'
				. '<div style="font-size:14px;color:#333;">Вы сделали заказ на сайте ' . $siteLink . '</div>'
				. '</div>'
			);
		}

		$html[] = self::sectionTitle('Информация о заказе');
		$html[] = self::basketTable($order);

		$html[] = self::sectionTitle($forAdmin ? 'Данные покупателя' : 'Ваши данные');
		$html[] = self::customerTable($order);

		if ($forAdmin)
		{
			$html[] = self::sectionTitle('Служебная информация');
			$html[] = self::adminMetaTable($order);
			$html[] = self::adminFooterBlock($adminUrl);
		}
		else
		{
			$html[] = self::footerBlock();
		}

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
				$article = self::basketItemArticle($item, $productId);

				$nameHtml = self::esc($name !== '' ? $name : '—');
				if ($article !== '')
				{
					$nameHtml .= '<br><span style="font-size:12px;color:#666;">Артикул: ' . self::esc($article) . '</span>';
				}

				$rows .= '<tr>'
					. '<td style="padding:8px 10px;border-bottom:1px solid ' . self::COLOR_BORDER . ';vertical-align:top;">' . self::esc($storeTitle !== '' ? $storeTitle : '—') . '</td>'
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
			$rows = '<tr><td colspan="6" style="padding:10px;text-align:center;color:#666;">Нет позиций</td></tr>';
		}

		$orderTotal = (float)$order->getPrice();
		$payTotal = $orderTotal > 0 ? $orderTotal : $itemsTotal;

		return self::block(
			'<table cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;font-size:14px;">'
			. '<tr style="background:' . self::COLOR_HEAD_BG . ';font-weight:bold;">'
			. '<td style="padding:8px 10px;border:1px solid ' . self::COLOR_BORDER . ';">Склад</td>'
			. '<td style="padding:8px 10px;border:1px solid ' . self::COLOR_BORDER . ';">Наименование</td>'
			. '<td style="padding:8px 10px;border:1px solid ' . self::COLOR_BORDER . ';">Бренд</td>'
			. '<td style="padding:8px 10px;border:1px solid ' . self::COLOR_BORDER . ';text-align:center;width:90px;">Количество</td>'
			. '<td style="padding:8px 10px;border:1px solid ' . self::COLOR_BORDER . ';text-align:right;width:100px;">Цена</td>'
			. '<td style="padding:8px 10px;border:1px solid ' . self::COLOR_BORDER . ';text-align:right;width:110px;">Стоимость</td>'
			. '</tr>'
			. $rows
			. '<tr>'
			. '<td colspan="5" style="padding:8px 10px;text-align:right;font-weight:bold;border-top:2px solid ' . self::COLOR_BORDER . ';">Итого:</td>'
			. '<td style="padding:8px 10px;text-align:right;font-weight:bold;border-top:2px solid ' . self::COLOR_BORDER . ';">'
			. self::esc(self::moneyPlain($itemsTotal, $currency)) . ' руб.'
			. '</td></tr>'
			. '<tr style="background:' . self::COLOR_TOTAL_BG . ';">'
			. '<td colspan="5" style="padding:10px;text-align:right;font-weight:bold;font-size:16px;">Всего к оплате:</td>'
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
		$isJur = $personTypeId === 2;

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
		$confirm = self::prop($props, 'MF_CONFIRM_CHANNEL');
		$comment = trim((string)$order->getField('USER_DESCRIPTION'));

		$rows = [
			['ФИО', $fio],
			['E-mail', self::prop($props, 'EMAIL')],
			['Контактный телефон', self::prop($props, 'PHONE')],
			['Город (Населённый пункт), Область, Край и т.д.', $city],
			['Улица, Дом, Квартира', $address],
			['Индекс', $zip],
			['Способ доставки', $deliveryName],
			['Удобный способ подтверждения заказа', $confirm],
			['Комментарий', $comment],
		];

		if ($isJur)
		{
			array_splice($rows, 1, 0, [
				['Название компании', self::prop($props, 'COMPANY')],
				['ИНН', self::prop($props, 'INN')],
			]);
		}

		$body = '';
		foreach ($rows as [$label, $value])
		{
			$value = trim((string)$value);
			if ($value === '')
			{
				continue;
			}
			$valueHtml = self::esc($value);
			if ($label === 'E-mail')
			{
				$valueHtml = '<a href="mailto:' . self::esc($value) . '" style="color:' . self::COLOR_LINK . ';">' . self::esc($value) . '</a>';
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

	private static function adminMetaTable(Order $order): string
	{
		$uid = (int)$order->getUserId();
		$profileEmail = '';
		$profileLogin = '';
		$profileName = '';
		if ($uid > 0 && class_exists(\CUser::class))
		{
			$u = \CUser::GetByID($uid)->Fetch();
			if (is_array($u))
			{
				$profileEmail = trim((string)($u['EMAIL'] ?? ''));
				$profileLogin = trim((string)($u['LOGIN'] ?? ''));
				$profileName = trim((string)($u['NAME'] ?? '') . ' ' . (string)($u['LAST_NAME'] ?? ''));
			}
		}

		$rows = [
			['ID заказа', (string)(int)$order->getId()],
			['Статус', trim((string)$order->getField('STATUS_ID'))],
			['Отменён', (string)$order->getField('CANCELED') === 'Y' ? 'да' : 'нет'],
			['Оплачен', $order->isPaid() ? 'да' : 'нет'],
			['User ID', $uid > 0 ? (string)$uid : '—'],
			['E-mail (профиль)', $profileEmail],
			['ФИО (профиль)', $profileName],
			['Логин', $profileLogin],
			['Способ оплаты', self::paySystemLabel($order)],
		];

		return self::keyValueTable($rows);
	}

	/** @param list<array{0: string, 1: string}> $rows */
	private static function keyValueTable(array $rows): string
	{
		$body = '';
		foreach ($rows as [$label, $value])
		{
			$value = trim((string)$value);
			if ($value === '' || $value === '—')
			{
				continue;
			}
			$valueHtml = self::esc($value);
			if ($label === 'E-mail (профиль)')
			{
				$valueHtml = '<a href="mailto:' . self::esc($value) . '" style="color:' . self::COLOR_LINK . ';">' . self::esc($value) . '</a>';
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

	private static function adminFooterBlock(string $adminOrderUrl): string
	{
		$parts = [
			'<div style="text-align:center;font-weight:bold;font-size:16px;margin:0 0 10px 0;">'
			. '<a href="' . self::esc($adminOrderUrl) . '" style="color:' . self::COLOR_LINK . ';text-decoration:underline;">Открыть заказ в админке</a>'
			. '</div>',
			'<div style="text-align:center;font-weight:bold;margin:0 0 6px 0;">Часы работы:</div>',
			'<div style="text-align:center;line-height:1.5;margin:0 0 14px 0;">'
			. 'Пн-Чт с 10:00 до 18:00;<br>'
			. 'Пт с 10:00 до 17:00;<br>'
			. 'Сб-Вс - Выходной.'
			. '</div>',
		];

		return self::block(implode("\n", $parts));
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
	private static function prop(array $props, string $code): string
	{
		return trim((string)($props[$code] ?? ''));
	}

	private static function deliveryLabel(Order $order): string
	{
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
			$edost = self::parseEdostFromComments((string)$order->getField('COMMENTS'));
			if ($edost !== '')
			{
				return $edost;
			}
		}

		if ($name === '' || self::isTechnicalDeliveryName($name))
		{
			$comments = trim((string)$order->getField('COMMENTS'));
			if (preg_match('~Доставка: стоимость будет рассчитана менеджером~u', $comments))
			{
				return 'Стоимость доставки будет рассчитана менеджером';
			}
		}

		return $name;
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

	private static function parseEdostFromComments(string $comments): string
	{
		$comments = trim($comments);
		if ($comments === '')
		{
			return '';
		}
		if (preg_match('~Доставка \(eDost[^:]*:\s*(.+?)\s*—\s*(.+?)\s*—\s*([0-9]+(?:[.,][0-9]+)?)\s*₽~u', $comments, $m))
		{
			$company = trim((string)$m[1]);
			$tariff = trim((string)$m[2]);
			$price = str_replace('.', ',', (string)$m[3]);
			$label = $company !== '' ? ($company . ' — ' . $tariff) : $tariff;

			return $label . ' — ' . $price . ' ₽';
		}

		return '';
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
