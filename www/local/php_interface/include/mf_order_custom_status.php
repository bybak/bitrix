<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sale\Order;

/**
 * Кастомные статусы заказа (заказ / оплата / доставка) из 1С.
 * Хранение: HL-блок mf_order_custom_status, одна строка на ORDER_ID.
 */

if (!function_exists('mf_order_custom_status_labels'))
{
	/**
	 * @return array{
	 *   order: array<string,string>,
	 *   payment: array<string,string>,
	 *   shipment: array<string,string>
	 * }
	 */
	function mf_order_custom_status_labels(): array
	{
		return [
			'order' => [
				'in_progress' => 'В работе',
				'completed' => 'Завершен',
				'cancelled' => 'Отменен',
			],
			'payment' => [
				'not_paid' => 'Не оплачен',
				'partially_paid' => 'Частично оплачен',
				'paid' => 'Оплачен',
			],
			'shipment' => [
				'not_shipped' => 'Не отгружен',
				'partially_shipped' => 'Частично отгружен',
				'shipped' => 'Отгружен',
			],
		];
	}
}

if (!function_exists('mf_order_custom_status_normalize'))
{
	/**
	 * Принимает код (in_progress) или русскую подпись (В работе).
	 *
	 * @param 'order'|'payment'|'shipment' $group
	 */
	function mf_order_custom_status_normalize(string $group, ?string $value): ?string
	{
		$value = trim((string)$value);
		if ($value === '')
		{
			return null;
		}

		$labels = mf_order_custom_status_labels();
		$map = $labels[$group] ?? null;
		if (!is_array($map))
		{
			return null;
		}

		$mfAliases = [
			'order' => [
				'IN_WORK' => 'in_progress',
				'DONE' => 'completed',
				'CANCELED' => 'cancelled',
				'CANCELLED' => 'cancelled',
			],
			'payment' => [
				'NOT_PAID' => 'not_paid',
				'PARTIAL_PAID' => 'partially_paid',
				'PAID' => 'paid',
			],
			'shipment' => [
				'NOT_SHIPPED' => 'not_shipped',
				'PARTIAL_SHIPPED' => 'partially_shipped',
				'SHIPPED' => 'shipped',
			],
		];
		$upperValue = strtoupper($value);
		if (isset($mfAliases[$group][$upperValue]))
		{
			return $mfAliases[$group][$upperValue];
		}

		if (isset($map[$value]))
		{
			return $value;
		}

		$valueLower = mb_strtolower($value);
		foreach ($map as $code => $label)
		{
			if ($code === $valueLower || mb_strtolower($label) === $valueLower)
			{
				return $code;
			}
		}

		return null;
	}
}

if (!function_exists('mf_order_custom_status_label'))
{
	function mf_order_custom_status_label(string $group, ?string $code): string
	{
		$code = trim((string)$code);
		if ($code === '')
		{
			return '';
		}

		$labels = mf_order_custom_status_labels();

		return (string)($labels[$group][$code] ?? $code);
	}
}

if (!function_exists('mf_order_custom_status_ensure_hl_physical_table'))
{
	function mf_order_custom_status_ensure_hl_physical_table(array $hl, string $tableName): void
	{
		$tableName = trim($tableName);
		if ($tableName === '' || !class_exists(Application::class))
		{
			return;
		}

		$conn = Application::getConnection();
		if ($conn->isTableExists($tableName))
		{
			return;
		}

		if (!class_exists(\Bitrix\Highloadblock\HighloadBlockTable::class))
		{
			return;
		}

		$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hl);
		if (method_exists($entity, 'createDbTable'))
		{
			$entity->createDbTable();
		}
	}
}

if (!function_exists('mf_order_custom_status_ensure_hl'))
{
	/**
	 * @return array{HL_ID:int,TABLE:string,ENTITY_ID:string,DATA_CLASS:string}
	 */
	function mf_order_custom_status_ensure_hl(): array
	{
		static $cached = null;
		if (is_array($cached))
		{
			return $cached;
		}

		if (!class_exists(Loader::class) || !Loader::includeModule('highloadblock'))
		{
			throw new \RuntimeException('Module highloadblock is not available.');
		}

		$tableName = 'mf_order_custom_status';
		$optKey = 'mf_hl_' . $tableName . '_installed';

		$hl = \Bitrix\Highloadblock\HighloadBlockTable::getList([
			'filter' => ['=TABLE_NAME' => $tableName],
			'select' => ['ID', 'NAME', 'TABLE_NAME'],
			'limit' => 1,
		])->fetch();

		if (!$hl)
		{
			$res = \Bitrix\Highloadblock\HighloadBlockTable::add([
				'NAME' => 'MfOrderCustomStatus',
				'TABLE_NAME' => $tableName,
			]);
			if (!$res->isSuccess())
			{
				throw new \RuntimeException('Failed to create HL block: ' . implode('; ', $res->getErrorMessages()));
			}
			$hl = \Bitrix\Highloadblock\HighloadBlockTable::getById((int)$res->getId())->fetch();
		}

		$hlId = (int)($hl['ID'] ?? 0);
		if ($hlId <= 0)
		{
			throw new \RuntimeException('HL block ID is empty.');
		}

		mf_order_custom_status_ensure_hl_physical_table($hl, $tableName);

		$entityId = 'HLBLOCK_' . $hlId;

		if (class_exists(Option::class))
		{
			try
			{
				if (Option::get('main', $optKey, 'N') !== 'Y')
				{
					mf_order_custom_status_ensure_user_fields($entityId);
					mf_order_custom_status_ensure_indexes($tableName);
					Option::set('main', $optKey, 'Y');
				}
			}
			catch (\Throwable $e)
			{
				// fields/indexes may already exist
			}
		}
		else
		{
			mf_order_custom_status_ensure_user_fields($entityId);
			mf_order_custom_status_ensure_indexes($tableName);
		}

		// Добавляет новые UF-поля при обновлении схемы (идемпотентно).
		mf_order_custom_status_ensure_user_fields($entityId);

		$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hl);
		$dataClass = $entity->getDataClass();

		$cached = [
			'HL_ID' => $hlId,
			'TABLE' => $tableName,
			'ENTITY_ID' => $entityId,
			'DATA_CLASS' => $dataClass,
		];

		return $cached;
	}
}

if (!function_exists('mf_order_custom_status_ensure_user_fields'))
{
	function mf_order_custom_status_ensure_user_fields(string $entityId): void
	{
		if (!class_exists(\CUserTypeEntity::class))
		{
			throw new \RuntimeException('CUserTypeEntity is not available.');
		}

		$fields = [
			'UF_ORDER_ID' => [
				'USER_TYPE_ID' => 'integer',
				'MANDATORY' => 'Y',
				'EDIT_FORM_LABEL' => ['ru' => 'ID заказа'],
				'LIST_COLUMN_LABEL' => ['ru' => 'ORDER_ID'],
			],
			'UF_ORDER_STATUS' => [
				'USER_TYPE_ID' => 'string',
				'MANDATORY' => 'N',
				'EDIT_FORM_LABEL' => ['ru' => 'Статус заказа'],
				'LIST_COLUMN_LABEL' => ['ru' => 'ORDER_STATUS'],
			],
			'UF_PAYMENT_STATUS' => [
				'USER_TYPE_ID' => 'string',
				'MANDATORY' => 'N',
				'EDIT_FORM_LABEL' => ['ru' => 'Статус оплаты'],
				'LIST_COLUMN_LABEL' => ['ru' => 'PAYMENT_STATUS'],
			],
			'UF_SHIPMENT_STATUS' => [
				'USER_TYPE_ID' => 'string',
				'MANDATORY' => 'N',
				'EDIT_FORM_LABEL' => ['ru' => 'Статус доставки'],
				'LIST_COLUMN_LABEL' => ['ru' => 'SHIPMENT_STATUS'],
			],
			'UF_IS_CANCELED' => [
				'USER_TYPE_ID' => 'boolean',
				'MANDATORY' => 'N',
				'EDIT_FORM_LABEL' => ['ru' => 'Отменён (1С)'],
				'LIST_COLUMN_LABEL' => ['ru' => 'IS_CANCELED'],
			],
			'UF_CANCEL_REASON' => [
				'USER_TYPE_ID' => 'string',
				'MANDATORY' => 'N',
				'EDIT_FORM_LABEL' => ['ru' => 'Причина отмены'],
				'LIST_COLUMN_LABEL' => ['ru' => 'CANCEL_REASON'],
			],
			'UF_COMPLETION_VARIANT' => [
				'USER_TYPE_ID' => 'string',
				'MANDATORY' => 'N',
				'EDIT_FORM_LABEL' => ['ru' => 'Вариант завершения (1С)'],
				'LIST_COLUMN_LABEL' => ['ru' => 'COMPLETION_VARIANT'],
			],
			'UF_COMPLETION_COMMENT' => [
				'USER_TYPE_ID' => 'string',
				'MANDATORY' => 'N',
				'EDIT_FORM_LABEL' => ['ru' => 'Комментарий завершения (1С)'],
				'LIST_COLUMN_LABEL' => ['ru' => 'COMPLETION_COMMENT'],
			],
			'UF_UPDATED_AT' => [
				'USER_TYPE_ID' => 'datetime',
				'MANDATORY' => 'N',
				'EDIT_FORM_LABEL' => ['ru' => 'Обновлено'],
				'LIST_COLUMN_LABEL' => ['ru' => 'UPDATED_AT'],
			],
		];

		foreach ($fields as $fieldName => $cfg)
		{
			$existing = \CUserTypeEntity::GetList([], [
				'ENTITY_ID' => $entityId,
				'FIELD_NAME' => $fieldName,
			])->Fetch();

			if ($existing && (int)($existing['ID'] ?? 0) > 0)
			{
				continue;
			}

			$ute = new \CUserTypeEntity();
			$id = (int)$ute->Add([
				'ENTITY_ID' => $entityId,
				'FIELD_NAME' => $fieldName,
				'USER_TYPE_ID' => $cfg['USER_TYPE_ID'],
				'SORT' => 100,
				'MULTIPLE' => 'N',
				'MANDATORY' => $cfg['MANDATORY'],
				'SHOW_FILTER' => 'I',
				'SHOW_IN_LIST' => 'Y',
				'EDIT_IN_LIST' => 'Y',
				'IS_SEARCHABLE' => 'N',
				'EDIT_FORM_LABEL' => $cfg['EDIT_FORM_LABEL'],
				'LIST_COLUMN_LABEL' => $cfg['LIST_COLUMN_LABEL'],
				'LIST_FILTER_LABEL' => $cfg['LIST_COLUMN_LABEL'],
			]);

			if ($id <= 0)
			{
				throw new \RuntimeException('Failed to create UF field ' . $fieldName);
			}
		}
	}
}

if (!function_exists('mf_order_custom_status_ensure_indexes'))
{
	function mf_order_custom_status_ensure_indexes(string $tableName): void
	{
		$conn = Application::getConnection();
		$indexes = [
			'UX_MF_ORDER_CUSTOM_STATUS_ORDER_ID' => "CREATE UNIQUE INDEX `UX_MF_ORDER_CUSTOM_STATUS_ORDER_ID` ON `" . $tableName . "` (`UF_ORDER_ID`)",
			'IX_MF_ORDER_CUSTOM_STATUS_UPDATED' => "CREATE INDEX `IX_MF_ORDER_CUSTOM_STATUS_UPDATED` ON `" . $tableName . "` (`UF_UPDATED_AT`)",
		];

		foreach ($indexes as $sql)
		{
			try
			{
				$conn->queryExecute($sql);
			}
			catch (\Throwable $e)
			{
				// index may already exist
			}
		}
	}
}

if (!function_exists('mf_order_custom_status_format_row'))
{
	function mf_order_custom_status_format_row(array $row): array
	{
		$orderStatus = trim((string)($row['UF_ORDER_STATUS'] ?? ''));
		$paymentStatus = trim((string)($row['UF_PAYMENT_STATUS'] ?? ''));
		$shipmentStatus = trim((string)($row['UF_SHIPMENT_STATUS'] ?? ''));

		$updatedAt = $row['UF_UPDATED_AT'] ?? null;
		if ($updatedAt instanceof DateTime)
		{
			$updatedAt = $updatedAt->format('Y-m-d H:i:s');
		}
		else
		{
			$updatedAt = trim((string)$updatedAt);
		}

		$isCanceled = ($row['UF_IS_CANCELED'] ?? false) === true
			|| ($row['UF_IS_CANCELED'] ?? '') === '1'
			|| (string)($row['UF_IS_CANCELED'] ?? '') === 'Y';

		return [
			'ID' => (int)($row['ID'] ?? 0),
			'ORDER_ID' => (int)($row['UF_ORDER_ID'] ?? 0),
			'ORDER_STATUS' => $orderStatus,
			'ORDER_STATUS_LABEL' => mf_order_custom_status_label('order', $orderStatus),
			'PAYMENT_STATUS' => $paymentStatus,
			'PAYMENT_STATUS_LABEL' => mf_order_custom_status_label('payment', $paymentStatus),
			'SHIPMENT_STATUS' => $shipmentStatus,
			'SHIPMENT_STATUS_LABEL' => mf_order_custom_status_label('shipment', $shipmentStatus),
			'IS_CANCELED' => $isCanceled || $orderStatus === 'cancelled',
			'CANCEL_REASON' => trim((string)($row['UF_CANCEL_REASON'] ?? '')),
			'COMPLETION_VARIANT' => trim((string)($row['UF_COMPLETION_VARIANT'] ?? '')),
			'COMPLETION_COMMENT' => trim((string)($row['UF_COMPLETION_COMMENT'] ?? '')),
			'UPDATED_AT' => $updatedAt,
		];
	}
}

if (!function_exists('mf_order_custom_status_apply_payment_truth'))
{
	/**
	 * HL мог получить «paid» из 1С без фактической оплаты в Bitrix — подменяем при чтении.
	 */
	function mf_order_custom_status_apply_payment_truth(array $status): array
	{
		$orderId = (int)($status['ORDER_ID'] ?? 0);
		if ($orderId <= 0 || ($status['PAYMENT_STATUS'] ?? '') !== 'paid')
		{
			return $status;
		}

		if (mf_order_custom_status_order_has_bitrix_payment_by_id($orderId))
		{
			return $status;
		}

		$status['PAYMENT_STATUS'] = 'not_paid';
		$status['PAYMENT_STATUS_LABEL'] = mf_order_custom_status_label('payment', 'not_paid');

		return $status;
	}
}

if (!function_exists('mf_order_custom_status_get'))
{
	function mf_order_custom_status_get(int $orderId): ?array
	{
		if ($orderId <= 0)
		{
			return null;
		}

		$hl = mf_order_custom_status_ensure_hl();
		$dataClass = $hl['DATA_CLASS'];

		$row = $dataClass::getList([
			'filter' => ['=UF_ORDER_ID' => $orderId],
			'select' => ['*'],
			'limit' => 1,
		])->fetch();

		if (!is_array($row))
		{
			return null;
		}

		return mf_order_custom_status_apply_payment_truth(mf_order_custom_status_format_row($row));
	}
}

if (!function_exists('mf_order_custom_status_get_bulk'))
{
	/**
	 * @param int[] $orderIds
	 * @return array<int, array>
	 */
	function mf_order_custom_status_get_bulk(array $orderIds): array
	{
		$orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
		if ($orderIds === [])
		{
			return [];
		}

		$hl = mf_order_custom_status_ensure_hl();
		$dataClass = $hl['DATA_CLASS'];
		$result = [];

		$rs = $dataClass::getList([
			'filter' => ['@UF_ORDER_ID' => $orderIds],
			'select' => ['*'],
		]);
		while ($row = $rs->fetch())
		{
			if (!is_array($row))
			{
				continue;
			}
			$formatted = mf_order_custom_status_apply_payment_truth(mf_order_custom_status_format_row($row));
			$result[(int)$formatted['ORDER_ID']] = $formatted;
		}

		return $result;
	}
}

if (!function_exists('mf_order_custom_status_set'))
{
	/**
	 * Создаёт или обновляет статусы заказа (upsert по ORDER_ID).
	 *
	 * @param array{
	 *   ORDER_STATUS?:string|null,
	 *   PAYMENT_STATUS?:string|null,
	 *   SHIPMENT_STATUS?:string|null,
	 *   IS_CANCELED?:bool|null,
	 *   CANCEL_REASON?:string|null,
	 *   COMPLETION_VARIANT?:string|null,
	 *   COMPLETION_COMMENT?:string|null,
	 *   UPDATED_AT?:string|DateTime|null
	 * } $statuses
	 */
	function mf_order_custom_status_set(int $orderId, array $statuses): array
	{
		if ($orderId <= 0)
		{
			throw new \InvalidArgumentException('ORDER_ID must be positive.');
		}

		$trustPaymentFrom1c = !empty($statuses['TRUST_PAYMENT_FROM_1C']);
		unset($statuses['TRUST_PAYMENT_FROM_1C']);

		$hl = mf_order_custom_status_ensure_hl();
		$dataClass = $hl['DATA_CLASS'];

		$fields = [];
		$errors = [];

		if (array_key_exists('ORDER_STATUS', $statuses))
		{
			$code = mf_order_custom_status_normalize('order', $statuses['ORDER_STATUS']);
			if ($statuses['ORDER_STATUS'] !== null && trim((string)$statuses['ORDER_STATUS']) !== '' && $code === null)
			{
				$errors[] = 'Unknown ORDER_STATUS: ' . (string)$statuses['ORDER_STATUS'];
			}
			else
			{
				$fields['UF_ORDER_STATUS'] = $code;
			}
		}

		if (array_key_exists('PAYMENT_STATUS', $statuses))
		{
			$code = mf_order_custom_status_normalize('payment', $statuses['PAYMENT_STATUS']);
			if ($statuses['PAYMENT_STATUS'] !== null && trim((string)$statuses['PAYMENT_STATUS']) !== '' && $code === null)
			{
				$errors[] = 'Unknown PAYMENT_STATUS: ' . (string)$statuses['PAYMENT_STATUS'];
			}
			else
			{
				if (
					$code === 'paid'
					&& !$trustPaymentFrom1c
					&& !mf_order_custom_status_order_has_bitrix_payment_by_id($orderId)
				)
				{
					$code = 'not_paid';
				}
				$fields['UF_PAYMENT_STATUS'] = $code;
			}
		}

		if (array_key_exists('SHIPMENT_STATUS', $statuses))
		{
			$code = mf_order_custom_status_normalize('shipment', $statuses['SHIPMENT_STATUS']);
			if ($statuses['SHIPMENT_STATUS'] !== null && trim((string)$statuses['SHIPMENT_STATUS']) !== '' && $code === null)
			{
				$errors[] = 'Unknown SHIPMENT_STATUS: ' . (string)$statuses['SHIPMENT_STATUS'];
			}
			else
			{
				$fields['UF_SHIPMENT_STATUS'] = $code;
			}
		}

		if (array_key_exists('IS_CANCELED', $statuses))
		{
			$fields['UF_IS_CANCELED'] = !empty($statuses['IS_CANCELED']) ? 1 : 0;
		}

		foreach ([
			'CANCEL_REASON' => 'UF_CANCEL_REASON',
			'COMPLETION_VARIANT' => 'UF_COMPLETION_VARIANT',
			'COMPLETION_COMMENT' => 'UF_COMPLETION_COMMENT',
		] as $payloadKey => $ufKey)
		{
			if (!array_key_exists($payloadKey, $statuses))
			{
				continue;
			}
			$value = $statuses[$payloadKey];
			$fields[$ufKey] = ($value === null || trim((string)$value) === '') ? '' : trim((string)$value);
		}

		if ($errors !== [])
		{
			throw new \InvalidArgumentException(implode('; ', $errors));
		}

		if ($fields === [])
		{
			throw new \InvalidArgumentException('No fields to save.');
		}

		if (array_key_exists('UPDATED_AT', $statuses) && $statuses['UPDATED_AT'] !== null && $statuses['UPDATED_AT'] !== '')
		{
			$updatedAt = $statuses['UPDATED_AT'];
			if (!$updatedAt instanceof DateTime)
			{
				$updatedAt = new DateTime((string)$updatedAt);
			}
			$fields['UF_UPDATED_AT'] = $updatedAt;
		}
		else
		{
			$fields['UF_UPDATED_AT'] = new DateTime();
		}

		$before = mf_order_custom_status_get($orderId);

		$existing = $dataClass::getList([
			'filter' => ['=UF_ORDER_ID' => $orderId],
			'select' => ['ID'],
			'limit' => 1,
		])->fetch();

		if (is_array($existing) && (int)($existing['ID'] ?? 0) > 0)
		{
			$res = $dataClass::update((int)$existing['ID'], $fields);
		}
		else
		{
			$fields['UF_ORDER_ID'] = $orderId;
			$res = $dataClass::add($fields);
		}

		if (!$res->isSuccess())
		{
			throw new \RuntimeException('Failed to save order custom status: ' . implode('; ', $res->getErrorMessages()));
		}

		$result = mf_order_custom_status_get($orderId);
		if (!is_array($result))
		{
			throw new \RuntimeException('Order custom status was saved but cannot be read back.');
		}

		if (class_exists(\Mf\OrderMail\CustomStatusNotifier::class))
		{
			\Mf\OrderMail\CustomStatusNotifier::notify($orderId, $before, $result);
		}

		return $result;
	}
}

if (!function_exists('mf_order_custom_status_is_cancelled'))
{
	function mf_order_custom_status_is_cancelled(?array $mfStatus): bool
	{
		if (!is_array($mfStatus))
		{
			return false;
		}

		if (!empty($mfStatus['IS_CANCELED']))
		{
			return true;
		}

		return (string)($mfStatus['ORDER_STATUS'] ?? '') === 'cancelled';
	}
}

if (!function_exists('mf_order_custom_status_order_has_bitrix_payment_by_id'))
{
	function mf_order_custom_status_order_has_bitrix_payment_by_id(int $orderId): bool
	{
		if ($orderId <= 0 || !Loader::includeModule('sale'))
		{
			return false;
		}

		$order = Order::load($orderId);
		if (!$order)
		{
			return false;
		}

		$paymentCollection = $order->getPaymentCollection();
		if (!$paymentCollection)
		{
			return false;
		}

		foreach ($paymentCollection as $payment)
		{
			if ($payment && $payment->isPaid())
			{
				return true;
			}
		}

		return false;
	}
}

if (!function_exists('mf_order_custom_status_order_has_bitrix_payment'))
{
	function mf_order_custom_status_order_has_bitrix_payment(array $paymentRows): bool
	{
		foreach ($paymentRows as $payment)
		{
			if (!is_array($payment))
			{
				continue;
			}
			if (($payment['PAID'] ?? '') === 'Y')
			{
				return true;
			}
		}

		return false;
	}
}

if (!function_exists('mf_order_custom_status_display_payment_for_list'))
{
	/**
	 * Статус оплаты в карточке заказа: не показываем «Оплачен» для отменённого заказа без фактической оплаты в Bitrix.
	 *
	 * @return array{text:string,badge_class:string,has_status:bool}
	 */
	function mf_order_custom_status_display_payment_for_list(?array $mfStatus, array $paymentRows): array
	{
		$hasBitrixPayment = mf_order_custom_status_order_has_bitrix_payment($paymentRows);
		if (
			!$hasBitrixPayment
			&& is_array($mfStatus)
			&& ($mfStatus['PAYMENT_STATUS'] ?? '') === 'paid'
		)
		{
			$corrected = $mfStatus;
			$corrected['PAYMENT_STATUS'] = 'not_paid';
			$corrected['PAYMENT_STATUS_LABEL'] = '';

			return mf_order_custom_status_display_for_list($corrected, 'payment');
		}

		return mf_order_custom_status_display_for_list($mfStatus, 'payment');
	}
}

if (!function_exists('mf_order_custom_status_display_for_list'))
{
	/**
	 * Подпись и CSS-класс бейджа для карточки заказа в ЛК (только MF-поля).
	 *
	 * @param 'order'|'payment'|'shipment' $group
	 * @return array{text:string,badge_class:string,has_status:bool}
	 */
	function mf_order_custom_status_display_for_list(?array $mfStatus, string $group): array
	{
		$fieldMap = [
			'order' => ['ORDER_STATUS', 'ORDER_STATUS_LABEL'],
			'payment' => ['PAYMENT_STATUS', 'PAYMENT_STATUS_LABEL'],
			'shipment' => ['SHIPMENT_STATUS', 'SHIPMENT_STATUS_LABEL'],
		];
		$fields = $fieldMap[$group] ?? null;
		if (!is_array($fields))
		{
			return ['text' => '—', 'badge_class' => 'mf-order-badge_status', 'has_status' => false];
		}

		$code = '';
		$label = '';
		if (is_array($mfStatus))
		{
			$code = trim((string)($mfStatus[$fields[0]] ?? ''));
			$label = trim((string)($mfStatus[$fields[1]] ?? ''));
			if ($label === '' && $code !== '')
			{
				$label = mf_order_custom_status_label($group, $code);
			}
		}

		if ($label === '')
		{
			return ['text' => '—', 'badge_class' => 'mf-order-badge_status', 'has_status' => false];
		}

		$badgeClass = 'mf-order-badge_status';
		$customBadgeClass = mf_order_custom_status_badge_class($group, $code);
		if ($customBadgeClass !== '')
		{
			$badgeClass = $customBadgeClass;
		}

		if ($group !== 'order')
		{
			$badgeClass = 'mf-order-badge ' . $badgeClass;
		}

		return ['text' => $label, 'badge_class' => $badgeClass, 'has_status' => true];
	}
}

if (!function_exists('mf_order_custom_status_badge_class'))
{
	/**
	 * CSS-модификатор бейджа для ЛК (mf-order-badge_*).
	 *
	 * @param 'order'|'payment'|'shipment' $group
	 */
	function mf_order_custom_status_badge_class(string $group, ?string $code): string
	{
		$code = trim((string)$code);
		if ($code === '')
		{
			return '';
		}

		$success = ['completed', 'paid', 'shipped'];
		$warn = ['in_progress', 'partially_paid', 'partially_shipped'];
		$alert = ['not_paid', 'not_shipped', 'cancelled'];

		if (in_array($code, $success, true))
		{
			return 'mf-order-badge_success';
		}
		if (in_array($code, $warn, true))
		{
			return 'mf-order-badge_warn';
		}
		if (in_array($code, $alert, true))
		{
			return 'mf-order-badge_alert';
		}

		return '';
	}
}

if (!function_exists('mf_order_custom_status_active_filters'))
{
	/**
	 * @return array{order:string,payment:string,shipment:string}
	 */
	function mf_order_custom_status_active_filters(): array
	{
		return [
			'order' => trim((string)($_REQUEST['mf_order_status'] ?? '')),
			'payment' => trim((string)($_REQUEST['mf_payment_status'] ?? '')),
			'shipment' => trim((string)($_REQUEST['mf_shipment_status'] ?? '')),
		];
	}
}

if (!function_exists('mf_order_custom_status_order_matches_filters'))
{
	/**
	 * @param array{order:string,payment:string,shipment:string} $filters
	 */
	function mf_order_custom_status_order_matches_filters(?array $mfStatus, array $filters): bool
	{
		$hasFilter = ($filters['order'] !== '' || $filters['payment'] !== '' || $filters['shipment'] !== '');
		if (!$hasFilter)
		{
			return true;
		}

		if (!is_array($mfStatus))
		{
			return false;
		}

		if ($filters['order'] !== '' && (string)($mfStatus['ORDER_STATUS'] ?? '') !== $filters['order'])
		{
			return false;
		}
		if ($filters['payment'] !== '' && (string)($mfStatus['PAYMENT_STATUS'] ?? '') !== $filters['payment'])
		{
			return false;
		}
		if ($filters['shipment'] !== '' && (string)($mfStatus['SHIPMENT_STATUS'] ?? '') !== $filters['shipment'])
		{
			return false;
		}

		return true;
	}
}

if (!function_exists('mf_order_custom_status_set_defaults_for_new_order'))
{
	/**
	 * Начальные MF-статусы для нового заказа (сайт / админка).
	 */
	function mf_order_custom_status_set_defaults_for_new_order(int $orderId): void
	{
		if ($orderId <= 0)
		{
			return;
		}

		if (mf_order_custom_status_get($orderId) !== null)
		{
			return;
		}

		mf_order_custom_status_set($orderId, [
			'ORDER_STATUS' => 'in_progress',
			'PAYMENT_STATUS' => 'not_paid',
			'SHIPMENT_STATUS' => 'not_shipped',
		]);
	}
}

if (!function_exists('mf_order_custom_status_on_order_saved'))
{
	/**
	 * sale:OnSaleOrderSaved — проставить MF-статусы при первом сохранении заказа.
	 */
	function mf_order_custom_status_on_order_saved(\Bitrix\Main\Event $event): void
	{
		if (!(bool)$event->getParameter('IS_NEW'))
		{
			return;
		}

		$order = $event->getParameter('ENTITY');
		if (!$order instanceof \Bitrix\Sale\Order)
		{
			$order = $event->getParameter('ORDER');
		}
		if (!$order instanceof \Bitrix\Sale\Order)
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
			mf_order_custom_status_set_defaults_for_new_order($orderId);
		}
		catch (\Throwable $e)
		{
			if (function_exists('AddMessage2Log'))
			{
				AddMessage2Log(
					'mf_order_custom_status_on_order_saved: order_id=' . $orderId . ' ' . $e->getMessage(),
					'mf_order_custom_status'
				);
			}
		}
	}
}

if (class_exists(\Bitrix\Main\EventManager::class))
{
	\Bitrix\Main\EventManager::getInstance()->addEventHandler(
		'sale',
		'OnSaleOrderSaved',
		'mf_order_custom_status_on_order_saved'
	);
}
