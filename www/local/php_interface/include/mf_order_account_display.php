<?php
/**
 * Отображение номера заказа клиенту: {USER_ID}-{ACCOUNT_NUMBER}.
 * В БД ACCOUNT_NUMBER по-прежнему сквозной (как настроено в Bitrix).
 */
if (!function_exists('mf_order_account_number_for_display'))
{
	function mf_order_account_number_for_display(?int $orderUserId, string $accountNumber): string
	{
		$accountNumber = trim($accountNumber);
		if ($accountNumber === '')
		{
			return '';
		}
		$uid = (int)$orderUserId;
		if ($uid <= 0)
		{
			return $accountNumber;
		}

		return $uid . '-' . $accountNumber;
	}
}

if (!function_exists('mf_order_account_number_admin_title'))
{
	/**
	 * Заголовки в админке: префиксованный номер или внутренний ID, если ACCOUNT_NUMBER пуст.
	 */
	function mf_order_account_number_admin_title(?int $orderUserId, string $accountNumber, int $orderId): string
	{
		$accountNumber = trim($accountNumber);
		if ($accountNumber === '')
		{
			return $orderId > 0 ? (string)$orderId : '';
		}

		return mf_order_account_number_for_display($orderUserId, $accountNumber);
	}
}
