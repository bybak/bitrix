<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Fuser;

if (!function_exists('mf_cart_partial_site_id'))
{
	function mf_cart_partial_site_id(): string
	{
		$siteId = defined('SITE_ID') ? (string)SITE_ID : '';
		if ($siteId === '' && class_exists(\Bitrix\Main\Context::class))
		{
			try
			{
				$siteId = (string)\Bitrix\Main\Context::getCurrent()->getSite();
			}
			catch (\Throwable $e)
			{
				$siteId = '';
			}
		}

		return $siteId !== '' ? $siteId : 's1';
	}
}

/**
 * Откладывает невыбранные позиции, выбранные остаются доступны для заказа.
 *
 * @param int[] $selectedIds ID строк b_sale_basket
 */
if (!function_exists('mf_cart_partial_apply_delay'))
{
	function mf_cart_partial_apply_delay(array $selectedIds): array
	{
		$result = ['ok' => false, 'error' => '', 'delayed_ids' => []];

		if (!Loader::includeModule('sale'))
		{
			$result['error'] = 'Модуль sale недоступен';

			return $result;
		}

		$selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedIds), static function (int $id): bool {
			return $id > 0;
		})));
		if ($selectedIds === [])
		{
			$result['error'] = 'Не выбрано ни одной позиции';

			return $result;
		}

		$siteId = mf_cart_partial_site_id();
		$fUserId = Fuser::getId(true);
		$basket = Basket::loadItemsForFUser($fUserId, $siteId);

		$activeIds = [];
		foreach ($basket as $item)
		{
			$id = (int)$item->getId();
			if ($id <= 0 || $item->isDelay() || !$item->canBuy())
			{
				continue;
			}
			$activeIds[] = $id;
		}
		sort($activeIds);

		foreach ($selectedIds as $sid)
		{
			if (!in_array($sid, $activeIds, true))
			{
				$result['error'] = 'Выбраны позиции, которых нет в корзине. Обновите страницу.';

				return $result;
			}
		}

		$delayedIds = [];

		foreach ($basket as $item)
		{
			$id = (int)$item->getId();
			if ($id <= 0 || !in_array($id, $activeIds, true))
			{
				continue;
			}

			if (in_array($id, $selectedIds, true))
			{
				$item->setField('DELAY', 'N');
			}
			else
			{
				$item->setField('DELAY', 'Y');
				$delayedIds[] = $id;
			}
		}

		$r = $basket->save();
		if (!$r->isSuccess())
		{
			$result['error'] = implode('; ', $r->getErrorMessages());

			return $result;
		}

		if (session_id() === '')
		{
			@session_start();
		}
		$_SESSION['mf_partial_delayed_ids'] = $delayedIds;

		$result['ok'] = true;
		$result['delayed_ids'] = $delayedIds;

		return $result;
	}
}

if (!function_exists('mf_cart_partial_restore_delayed'))
{
	/**
	 * Возвращает отложенные ранее для частичного заказа позиции в активную корзину.
	 */
	function mf_cart_partial_restore_delayed(): void
	{
		if (session_id() === '')
		{
			@session_start();
		}
		$ids = $_SESSION['mf_partial_delayed_ids'] ?? [];
		if (!is_array($ids) || $ids === [])
		{
			return;
		}
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
			return $id > 0;
		})));
		if ($ids === [] || !Loader::includeModule('sale'))
		{
			unset($_SESSION['mf_partial_delayed_ids']);

			return;
		}

		$siteId = mf_cart_partial_site_id();
		$fUserId = Fuser::getId(true);
		$basket = Basket::loadItemsForFUser($fUserId, $siteId);

		foreach ($basket as $item)
		{
			$id = (int)$item->getId();
			if ($id > 0 && in_array($id, $ids, true))
			{
				$item->setField('DELAY', 'N');
			}
		}
		$basket->save();
		unset($_SESSION['mf_partial_delayed_ids']);
	}
}

if (!function_exists('mf_cart_partial_on_order_saved'))
{
	function mf_cart_partial_on_order_saved(\Bitrix\Main\Event $event): void
	{
		if (defined('ADMIN_SECTION') && ADMIN_SECTION === true)
		{
			return;
		}
		mf_cart_partial_restore_delayed();
	}
}

if (!function_exists('mf_cart_partial_try_restore_from_request'))
{
	/**
	 * /personal/cart/?mf_restore_partial=Y — вернуть отложенные при частичном оформлении.
	 */
	function mf_cart_partial_try_restore_from_request(): void
	{
		if (PHP_SAPI === 'cli' || (defined('ADMIN_SECTION') && ADMIN_SECTION === true))
		{
			return;
		}
		if (empty($_GET['mf_restore_partial']) || (string)$_GET['mf_restore_partial'] !== 'Y')
		{
			return;
		}
		$path = (string)($_SERVER['REQUEST_URI'] ?? '');
		if ($path === '' || stripos($path, '/personal/cart') === false)
		{
			return;
		}
		mf_cart_partial_restore_delayed();
		if (function_exists('LocalRedirect'))
		{
			LocalRedirect('/personal/cart/');
		}
		else
		{
			header('Location: /personal/cart/', true, 302);
			exit;
		}
	}
}
