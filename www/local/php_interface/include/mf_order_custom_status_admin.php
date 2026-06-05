<?php

declare(strict_types=1);

/**
 * Колонки кастомных статусов 1С в списке заказов админки (после «Покупатель»).
 */

if (!function_exists('mf_admin_order_list_custom_status_headers'))
{
	function mf_admin_order_list_custom_status_headers(): array
	{
		return [
			'MF_ORDER_STATUS' => [
				'id' => 'MF_ORDER_STATUS',
				'content' => 'Заказ (1С)',
				'sort' => '',
				'default' => true,
			],
			'MF_PAYMENT_STATUS' => [
				'id' => 'MF_PAYMENT_STATUS',
				'content' => 'Оплата (1С)',
				'sort' => '',
				'default' => true,
			],
			'MF_SHIPMENT_STATUS' => [
				'id' => 'MF_SHIPMENT_STATUS',
				'content' => 'Доставка (1С)',
				'sort' => '',
				'default' => true,
			],
			'MF_CANCEL_REASON' => [
				'id' => 'MF_CANCEL_REASON',
				'content' => 'Причина отмены',
				'sort' => '',
				'default' => true,
			],
		];
	}
}

if (!function_exists('mf_admin_order_list_insert_headers_after'))
{
	/**
	 * @param array<string, array> $visibleHeaders
	 * @param array<string, array> $newHeaders
	 * @return array<string, array>
	 */
	function mf_admin_order_list_insert_headers_after(array $visibleHeaders, array $newHeaders, string $afterColumnId, string $fallbackAfterColumnId = 'ID'): array
	{
		$reordered = [];
		$inserted = false;

		foreach ($visibleHeaders as $id => $header)
		{
			$reordered[$id] = $header;
			if ($id === $afterColumnId)
			{
				foreach ($newHeaders as $newId => $newHeader)
				{
					$reordered[$newId] = $newHeader;
				}
				$inserted = true;
			}
		}

		if ($inserted)
		{
			return $reordered;
		}

		$reordered = [];
		foreach ($visibleHeaders as $id => $header)
		{
			$reordered[$id] = $header;
			if ($id === $fallbackAfterColumnId)
			{
				foreach ($newHeaders as $newId => $newHeader)
				{
					$reordered[$newId] = $newHeader;
				}
			}
		}

		return $reordered;
	}
}

if (!function_exists('mf_admin_order_list_custom_status_cell'))
{
	function mf_admin_order_list_custom_status_cell(?string $label): string
	{
		$label = trim((string)$label);
		if ($label === '')
		{
			return '<span style="color:#a8a8a8;">—</span>';
		}

		return htmlspecialcharsbx($label);
	}
}

if (!function_exists('mf_admin_order_list_inject_custom_statuses'))
{
	function mf_admin_order_list_inject_custom_statuses($list): void
	{
		if (!is_object($list) || !isset($list->table_id) || (string)$list->table_id !== 'tbl_sale_order')
		{
			return;
		}

		if (!function_exists('mf_order_custom_status_get_bulk'))
		{
			return;
		}

		$newHeaders = mf_admin_order_list_custom_status_headers();
		foreach ($newHeaders as $id => $header)
		{
			$list->aHeaders[$id] = $header;
		}

		$list->aVisibleHeaders = mf_admin_order_list_insert_headers_after(
			is_array($list->aVisibleHeaders) ? $list->aVisibleHeaders : [],
			$newHeaders,
			'USER'
		);

		$orderIds = [];
		if (is_array($list->aRows))
		{
			foreach ($list->aRows as $row)
			{
				if (!is_object($row))
				{
					continue;
				}
				$orderId = (int)($row->id ?? 0);
				if ($orderId > 0)
				{
					$orderIds[] = $orderId;
				}
			}
		}

		$statusMap = [];
		try
		{
			$statusMap = mf_order_custom_status_get_bulk($orderIds);
		}
		catch (\Throwable $e)
		{
			$statusMap = [];
		}

		if (!is_array($list->aRows))
		{
			return;
		}

		foreach ($list->aRows as $row)
		{
			if (!is_object($row) || !method_exists($row, 'AddField'))
			{
				continue;
			}

			$orderId = (int)($row->id ?? 0);
			$status = $statusMap[$orderId] ?? null;

			$row->AddField(
				'MF_ORDER_STATUS',
				mf_admin_order_list_custom_status_cell(is_array($status) ? ($status['ORDER_STATUS_LABEL'] ?? '') : '')
			);
			$row->AddField(
				'MF_PAYMENT_STATUS',
				mf_admin_order_list_custom_status_cell(is_array($status) ? ($status['PAYMENT_STATUS_LABEL'] ?? '') : '')
			);
			$row->AddField(
				'MF_SHIPMENT_STATUS',
				mf_admin_order_list_custom_status_cell(is_array($status) ? ($status['SHIPMENT_STATUS_LABEL'] ?? '') : '')
			);

			$cancelReason = '';
			if (is_array($status) && function_exists('mf_order_custom_status_is_cancelled') && mf_order_custom_status_is_cancelled($status))
			{
				$cancelReason = trim((string)($status['CANCEL_REASON'] ?? ''));
			}
			$row->AddField(
				'MF_CANCEL_REASON',
				mf_admin_order_list_custom_status_cell($cancelReason)
			);
		}
	}
}

if (class_exists(\Bitrix\Main\EventManager::class))
{
	\Bitrix\Main\EventManager::getInstance()->addEventHandler(
		'main',
		'OnAdminListDisplay',
		'mf_admin_order_list_inject_custom_statuses'
	);
}
