<?php
declare(strict_types=1);

/**
 * Отмена заказа на сайте:
 * - MF-статус «Отменен» сразу в HL
 * - push в 1С (HTTP order_cancelled)
 * - без стандартного SALE_ORDER_CANCEL (письма — через MF CustomStatusNotifier)
 */

use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Sale\Order;

if (!function_exists('mf_order_cancel_push_enabled'))
{
	function mf_order_cancel_push_enabled(): bool
	{
		if (!class_exists(\Bitrix\Main\Config\Option::class))
		{
			return false;
		}

		return \Bitrix\Main\Config\Option::get('main', 'mf_order_cancel_push_1c', 'N') === 'Y';
	}
}

if (!function_exists('mf_order_cancel_log'))
{
	function mf_order_cancel_log(string $message): void
	{
		if (function_exists('mf_1c_import_log'))
		{
			mf_1c_import_log('ORDER CANCEL: ' . $message);
			return;
		}
		if (class_exists(\Bitrix\Main\Diag\Debug::class))
		{
			\Bitrix\Main\Diag\Debug::writeToFile(date('c') . ' ' . $message, '', 'mf_order_cancel.log');
		}
	}
}

if (!function_exists('mf_order_cancel_display_number'))
{
	function mf_order_cancel_display_number(Order $order): string
	{
		$orderId = (int)$order->getId();
		$uid = (int)$order->getUserId();
		$account = trim((string)$order->getField('ACCOUNT_NUMBER'));
		if ($account === '')
		{
			return (string)$orderId;
		}
		if (function_exists('mf_order_account_number_for_display'))
		{
			return mf_order_account_number_for_display($uid, $account);
		}

		return $uid > 0 ? ($uid . '-' . $account) : $account;
	}
}

if (!function_exists('mf_order_cancel_push_to_1c'))
{
	function mf_order_cancel_push_to_1c(Order $order, string $reason = 'Отказ покупателя'): void
	{
		if (!mf_order_cancel_push_enabled())
		{
			mf_order_cancel_log('1C push disabled (mf_order_cancel_push_1c!=Y) order_id=' . (int)$order->getId());
			return;
		}
		if (!class_exists(\Mf\Unf\Config::class))
		{
			mf_order_cancel_log('UNF Config missing, skip 1C push order_id=' . (int)$order->getId());
			return;
		}
		if (!\Mf\Unf\Config::isEnabled())
		{
			mf_order_cancel_log('UNF disabled, skip 1C push order_id=' . (int)$order->getId());
			return;
		}

		$endpoint = \Mf\Unf\Config::paidEndpoint();
		if ($endpoint === '')
		{
			$endpoint = \Mf\Unf\Config::endpoint();
		}
		if ($endpoint === '')
		{
			mf_order_cancel_log('UNF endpoint empty order_id=' . (int)$order->getId());
			return;
		}

		$externalId = mf_order_cancel_display_number($order);
		$payload = [
			'meta' => [
				'event' => 'order_cancelled',
				'source' => 'bitrix',
				'sent_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
			],
			'order' => [
				'external_id' => $externalId,
				'bitrix_order_id' => (int)$order->getId(),
				'status_id' => (string)$order->getField('STATUS_ID'),
				'canceled' => true,
			],
			'cancel' => [
				'reason' => $reason,
			],
		];

		$client = new HttpClient([
			'redirect' => false,
			'timeout' => \Mf\Unf\Config::timeoutSeconds(),
			'disableSslVerification' => false,
		]);
		$client->setHeader('Content-Type', 'application/json; charset=utf-8');
		$client->setHeader('Accept', 'application/json, text/plain, */*');

		$token = \Mf\Unf\Config::token();
		$basicUser = \Mf\Unf\Config::basicUser();
		$basicPass = \Mf\Unf\Config::basicPass();
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

		$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($body))
		{
			mf_order_cancel_log('json_encode failed order_id=' . (int)$order->getId());
			return;
		}

		try
		{
			$response = $client->post($endpoint, $body);
			$http = (int)$client->getStatus();
			mf_order_cancel_log(
				'1C push order_id=' . (int)$order->getId()
				. ' external_id=' . $externalId
				. ' http=' . $http
				. ' body=' . mb_substr((string)$response, 0, 500)
			);
		}
		catch (\Throwable $e)
		{
			mf_order_cancel_log('1C push error order_id=' . (int)$order->getId() . ' ' . $e->getMessage());
		}
	}
}

if (!function_exists('mf_order_handle_cancelled'))
{
	function mf_order_handle_cancelled(Order $order, string $reason = 'Отказ покупателя'): void
	{
		$orderId = (int)$order->getId();
		if ($orderId <= 0)
		{
			return;
		}

		static $handled = [];
		if (!empty($handled[$orderId]))
		{
			mf_order_cancel_log('duplicate handler skip order_id=' . $orderId);
			return;
		}
		$handled[$orderId] = true;

		if (function_exists('mf_order_custom_status_get') && function_exists('mf_order_custom_status_is_cancelled'))
		{
			$before = mf_order_custom_status_get($orderId);
			if (mf_order_custom_status_is_cancelled($before) && mf_order_is_cancelled_on_site($order))
			{
				mf_order_cancel_log('already cancelled in HL order_id=' . $orderId);
				mf_order_cancel_push_to_1c($order, $reason);
				return;
			}
		}

		if (function_exists('mf_order_custom_status_set'))
		{
			try
			{
				mf_order_custom_status_set($orderId, [
					'ORDER_STATUS' => 'CANCELED',
					'IS_CANCELED' => true,
					'CANCEL_REASON' => $reason,
					'PAYMENT_STATUS' => 'not_paid',
				]);
				mf_order_cancel_log('HL status cancelled order_id=' . $orderId);
			}
			catch (\Throwable $e)
			{
				mf_order_cancel_log('HL status error order_id=' . $orderId . ' ' . $e->getMessage());
			}
		}

		mf_order_cancel_touch_1c_export($orderId);

		mf_order_cancel_push_to_1c($order, $reason);
	}
}

if (!function_exists('mf_order_cancel_touch_1c_export'))
{
	/**
	 * Сброс VERSION_1C — чтобы отменённый заказ снова попал в выгрузку CommerceML.
	 */
	function mf_order_cancel_touch_1c_export(int $orderId): void
	{
		if ($orderId <= 0 || !Loader::includeModule('sale'))
		{
			return;
		}

		try
		{
			$version = 'mf-cancel-' . gmdate('YmdHis') . '-' . $orderId;
			if (class_exists(\Bitrix\Sale\Internals\OrderTable::class))
			{
				\Bitrix\Sale\Internals\OrderTable::update($orderId, [
					'VERSION_1C' => $version,
				]);
				mf_order_cancel_log('VERSION_1C touched order_id=' . $orderId);
			}
		}
		catch (\Throwable $e)
		{
			mf_order_cancel_log('VERSION_1C touch error order_id=' . $orderId . ' ' . $e->getMessage());
		}
	}
}

if (!function_exists('mf_order_cancel_on_sale_order_saved'))
{
	function mf_order_cancel_on_sale_order_saved(Event $event): void
	{
		if (!Loader::includeModule('sale'))
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

		if (!mf_order_is_cancelled_on_site($order))
		{
			return;
		}

		$orderId = (int)$order->getId();
		if ($orderId <= 0)
		{
			return;
		}

		if (function_exists('mf_order_custom_status_get') && function_exists('mf_order_custom_status_is_cancelled'))
		{
			$current = mf_order_custom_status_get($orderId);
			if (mf_order_custom_status_is_cancelled($current))
			{
				return;
			}
		}

		$values = $event->getParameter('VALUES');
		$wasCanceled = false;
		if (is_array($values))
		{
			$wasCanceled = (string)($values['CANCELED'] ?? 'N') === 'Y'
				|| (string)($values['STATUS_ID'] ?? '') === 'C';
		}
		if ($wasCanceled)
		{
			return;
		}

		$reason = 'Отказ покупателя';
		if (is_array($values))
		{
			$fromReason = trim((string)($values['REASON_CANCELED'] ?? ''));
			if ($fromReason !== '')
			{
				$reason = $fromReason;
			}
		}
		if ($reason === 'Отказ покупателя')
		{
			$fromOrder = trim((string)$order->getField('REASON_CANCELED'));
			if ($fromOrder !== '')
			{
				$reason = $fromOrder;
			}
		}

		mf_order_handle_cancelled($order, $reason);
	}
}

if (!function_exists('mf_order_cancel_bootstrap'))
{
	function mf_order_cancel_bootstrap(): void
	{
		static $done = false;
		if ($done)
		{
			return;
		}
		$done = true;

		if (!Loader::includeModule('sale'))
		{
			return;
		}

		\Bitrix\Main\EventManager::getInstance()->addEventHandler(
			'sale',
			'OnSaleOrderSaved',
			'mf_order_cancel_on_sale_order_saved'
		);
	}
}
