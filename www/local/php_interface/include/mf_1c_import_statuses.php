<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\StatusTable;
use Bitrix\Sale\Order;

$mf1cExchangeDebugInclude = __DIR__ . '/mf_1c_exchange_debug.php';
if (is_file($mf1cExchangeDebugInclude))
{
	require_once $mf1cExchangeDebugInclude;
}

if (!function_exists('mf_1c_import_xml_bool'))
{
	function mf_1c_import_xml_bool(?string $value): ?bool
	{
		$value = mb_strtolower(trim((string)$value));
		if ($value === '')
		{
			return null;
		}

		if (in_array($value, ['true', '1', 'y', 'yes', 'да'], true))
		{
			return true;
		}

		if (in_array($value, ['false', '0', 'n', 'no', 'нет'], true))
		{
			return false;
		}

		return null;
	}
}

if (!function_exists('mf_1c_import_order_number_candidates'))
{
	/**
	 * Варианты сопоставления номера из 1С с заказом Bitrix.
	 *
	 * UNF может присылать:
	 * - s1291   -> заказ 291   (префикс сайта s1 + лишняя ведущая 1)
	 * - s11234  -> заказ 1234  (s1 + 1 + номер)
	 * - s1234   -> заказ 1234  (s1 + номер без лишней 1)
	 * - s112345 -> заказ 12345
	 *
	 * @return string[]
	 */
	function mf_1c_import_order_number_candidates(string $xmlNumber): array
	{
		$xmlNumber = trim($xmlNumber);
		if ($xmlNumber === '')
		{
			return [];
		}

		$digits = preg_replace('/\D+/', '', $xmlNumber);
		$tailDigits = '';
		// Префикс сайта s1 (UNF), не жадный s(\d+), иначе s1291 превращается в s129 + 1
		if (preg_match('/^s1(.+)$/iu', $xmlNumber, $matches))
		{
			$tailDigits = preg_replace('/\D+/', '', trim((string)$matches[1]));
		}

		$ordered = [];
		$seen = [];

		$push = static function (string $value) use (&$ordered, &$seen): void {
			$value = trim($value);
			if ($value === '' || isset($seen[$value]))
			{
				return;
			}
			$seen[$value] = true;
			$ordered[] = $value;
		};

		// UNF: s1 + «1» + номер (s1291, s11234, s112345) — номер = хвост после s1
		if ($tailDigits !== '' && $digits !== '' && $digits === '1' . $tailDigits)
		{
			$push($tailDigits);
		}

		if ($digits !== '')
		{
			$push($digits);
		}

		if ($tailDigits !== '')
		{
			$push($tailDigits);
		}

		if ($digits !== '' && preg_match('/^s\d/i', $xmlNumber) && strlen($digits) > 3)
		{
			$push(substr($digits, 1));
		}

		$push($xmlNumber);

		return $ordered;
	}
}

if (!function_exists('mf_1c_import_resolve_order_number'))
{
	/**
	 * Основной кандидат для лога.
	 */
	function mf_1c_import_resolve_order_number(string $xmlNumber): string
	{
		$candidates = mf_1c_import_order_number_candidates($xmlNumber);

		return $candidates[0] ?? '';
	}
}

if (!function_exists('mf_1c_import_collect_requisites'))
{
	function mf_1c_import_collect_requisites(DOMXPath $xpath, DOMNode $contextNode): array
	{
		$result = [];
		$nodes = $xpath->query('cml:ЗначенияРеквизитов/cml:ЗначениеРеквизита', $contextNode);
		if (!$nodes)
		{
			return $result;
		}

		foreach ($nodes as $reqNode)
		{
			$nameNode = $xpath->query('cml:Наименование', $reqNode)->item(0);
			$valueNode = $xpath->query('cml:Значение', $reqNode)->item(0);
			$name = $nameNode ? trim($nameNode->nodeValue) : '';
			if ($name === '')
			{
				continue;
			}
			$result[$name] = $valueNode ? trim($valueNode->nodeValue) : '';
		}

		return $result;
	}
}

if (!function_exists('mf_1c_import_sum_products_qty'))
{
	function mf_1c_import_sum_products_qty(DOMXPath $xpath, DOMNode $contextNode): float
	{
		$qty = 0.0;
		$nodes = $xpath->query('cml:Товары/cml:Товар', $contextNode);
		if (!$nodes)
		{
			return $qty;
		}

		foreach ($nodes as $productNode)
		{
			$qtyNode = $xpath->query('cml:Количество', $productNode)->item(0);
			if ($qtyNode)
			{
				$qty += (float)str_replace(',', '.', trim($qtyNode->nodeValue));
			}
		}

		return $qty;
	}
}

if (!function_exists('mf_1c_import_parse_document'))
{
	function mf_1c_import_parse_document(DOMXPath $xpath, DOMNode $docNode): ?array
	{
		$numberNode = $xpath->query('cml:Номер', $docNode)->item(0);
		$xmlNumber = $numberNode ? trim($numberNode->nodeValue) : '';
		if ($xmlNumber === '')
		{
			return null;
		}

		$mainReqs = mf_1c_import_collect_requisites($xpath, $docNode);
		$statusId = strtoupper(trim((string)($mainReqs['Статус заказа ИД'] ?? '')));

		$paid = mf_1c_import_xml_bool($mainReqs['Оплачен'] ?? null);
		$shipped = mf_1c_import_xml_bool($mainReqs['Отгружен'] ?? null);
		$paymentSum = 0.0;
		$shippedQty = mf_1c_import_sum_products_qty($xpath, $docNode);

		$subNodes = $xpath->query('cml:ПодчиненныеДокументы/cml:ПодчиненныйДокумент', $docNode);
		if ($subNodes)
		{
			foreach ($subNodes as $subNode)
			{
				$subReqs = mf_1c_import_collect_requisites($xpath, $subNode);
				$subPaid = mf_1c_import_xml_bool($subReqs['Оплачен'] ?? null);
				$subShipped = mf_1c_import_xml_bool($subReqs['Отгружен'] ?? null);

				if ($subPaid === true)
				{
					$paid = true;
				}
				elseif ($subPaid === false && $paid === null)
				{
					$paid = false;
				}

				if ($subShipped === true)
				{
					$shipped = true;
				}
				elseif ($subShipped === false && $shipped === null)
				{
					$shipped = false;
				}

				$operationNode = $xpath->query('cml:ХозОперация', $subNode)->item(0);
				$operation = $operationNode ? trim($operationNode->nodeValue) : '';
				$sumNode = $xpath->query('cml:Сумма', $subNode)->item(0);
				$sum = $sumNode ? (float)str_replace(',', '.', trim($sumNode->nodeValue)) : 0.0;

				if ($sum > 0 && preg_match('/(выплат|оплат)/iu', $operation))
				{
					$paymentSum += $sum;
				}

				if (preg_match('/отпуск/iu', $operation))
				{
					$shippedQty += mf_1c_import_sum_products_qty($xpath, $subNode);
				}
			}
		}

		$candidates = mf_1c_import_order_number_candidates($xmlNumber);

		return [
			'xml_number' => $xmlNumber,
			'order_candidates' => $candidates,
			'resolved_number' => $candidates[0] ?? '',
			'status_id' => $statusId,
			'paid' => $paid,
			'shipped' => $shipped,
			'payment_sum' => $paymentSum,
			'shipped_qty' => $shippedQty,
		];
	}
}

if (!function_exists('mf_1c_import_parse_xml_file'))
{
	/**
	 * @return array<int, array>
	 */
	function mf_1c_import_parse_xml_file(string $filePath): array
	{
		if (!is_file($filePath))
		{
			mf_1c_import_log('IMPORT STATUSES: file not found ' . $filePath);
			return [];
		}

		$xmlString = file_get_contents($filePath);
		if ($xmlString === false || trim($xmlString) === '')
		{
			mf_1c_import_log('IMPORT STATUSES: empty XML ' . $filePath);
			return [];
		}

		mf_1c_import_log_xml_dump($filePath, $xmlString);

		$xmlString = str_replace(
			'<Наименование>Статуса заказа ИД</Наименование>',
			'<Наименование>Статус заказа ИД</Наименование>',
			$xmlString
		);

		file_put_contents($filePath, $xmlString);

		$dom = new DOMDocument();
		if (@$dom->loadXML($xmlString) === false)
		{
			mf_1c_import_log('IMPORT STATUSES: invalid XML ' . $filePath);
			return [];
		}

		$xpath = new DOMXPath($dom);
		$xpath->registerNamespace('cml', 'urn:1C.ru:commerceml_210');

		$updates = [];
		$docNodes = $xpath->query('//cml:Документ');
		if (!$docNodes || $docNodes->length === 0)
		{
			mf_1c_import_log('IMPORT STATUSES: no documents in XML ' . basename($filePath));
			return [];
		}

		foreach ($docNodes as $docNode)
		{
			$parsed = mf_1c_import_parse_document($xpath, $docNode);
			if ($parsed === null)
			{
				continue;
			}
			$updates[] = $parsed;
			mf_1c_import_log(
				'IMPORT STATUSES PARSED: xml_number=' . $parsed['xml_number']
				. ' candidates=' . implode(',', $parsed['order_candidates'] ?? [])
				. ' resolved=' . $parsed['resolved_number']
				. ' status_id=' . $parsed['status_id']
				. ' paid=' . var_export($parsed['paid'], true)
				. ' shipped=' . var_export($parsed['shipped'], true)
				. ' payment_sum=' . $parsed['payment_sum']
				. ' shipped_qty=' . $parsed['shipped_qty']
			);
		}

		return $updates;
	}
}

if (!function_exists('mf_1c_import_find_order_id'))
{
	function mf_1c_import_find_order_id(array $parsed): ?int
	{
		if (!Loader::includeModule('sale'))
		{
			return null;
		}

		$candidates = $parsed['order_candidates'] ?? null;
		if (!is_array($candidates) || $candidates === [])
		{
			$candidates = mf_1c_import_order_number_candidates((string)($parsed['xml_number'] ?? ''));
		}

		if ($candidates === [])
		{
			return null;
		}

		$filter = ['LOGIC' => 'OR'];
		foreach ($candidates as $candidate)
		{
			$candidate = trim((string)$candidate);
			if ($candidate === '')
			{
				continue;
			}

			if (ctype_digit($candidate))
			{
				$filter[] = ['=ID' => (int)$candidate];
			}
			$filter[] = ['=ACCOUNT_NUMBER' => $candidate];
		}

		if (count($filter) === 1)
		{
			return null;
		}

		$orders = [];
		$rs = Order::getList([
			'filter' => $filter,
			'select' => ['ID', 'ACCOUNT_NUMBER'],
		]);
		while ($row = $rs->fetch())
		{
			if (!is_array($row))
			{
				continue;
			}
			$orders[(int)$row['ID']] = $row;
		}

		if ($orders === [])
		{
			return null;
		}

		if (count($orders) === 1)
		{
			return (int)array_key_first($orders);
		}

		$bestId = null;
		$bestScore = -1;
		foreach ($orders as $orderId => $row)
		{
			$accountNumber = (string)($row['ACCOUNT_NUMBER'] ?? '');
			foreach ($candidates as $priority => $candidate)
			{
				$candidate = trim((string)$candidate);
				if ($candidate === '')
				{
					continue;
				}

				$idMatch = (string)$orderId === $candidate;
				$accMatch = $accountNumber === $candidate;
				if (!$idMatch && !$accMatch)
				{
					continue;
				}

				$digitLen = strlen(preg_replace('/\D+/', '', $candidate));
				$score = ($digitLen * 1000) - $priority + ($accMatch ? 50 : 0) + ($idMatch ? 25 : 0);
				if ($score > $bestScore)
				{
					$bestScore = $score;
					$bestId = $orderId;
				}
			}
		}

		return $bestId > 0 ? $bestId : (int)array_key_first($orders);
	}
}

if (!function_exists('mf_1c_import_order_status_exists'))
{
	function mf_1c_import_order_status_exists(string $statusId): bool
	{
		$statusId = trim($statusId);
		if ($statusId === '')
		{
			return false;
		}

		$row = StatusTable::getList([
			'filter' => ['=ID' => $statusId, '=TYPE' => 'O'],
			'select' => ['ID'],
			'limit' => 1,
		])->fetch();

		return is_array($row);
	}
}

if (!function_exists('mf_1c_import_map_order_status'))
{
	function mf_1c_import_map_order_status(string $statusId): ?string
	{
		$statusId = strtoupper(trim($statusId));
		if ($statusId === 'F')
		{
			return 'DONE';
		}
		if ($statusId === 'C')
		{
			return 'CANCELED';
		}
		if ($statusId === 'N' || $statusId === 'P')
		{
			return 'IN_WORK';
		}

		return null;
	}
}

if (!function_exists('mf_1c_import_detect_payment_status'))
{
	function mf_1c_import_detect_payment_status(?bool $paid, float $paymentSum, float $orderPrice): ?string
	{
		if ($paid === false)
		{
			return 'NOT_PAID';
		}

		if ($paid === true)
		{
			return 'PAID';
		}

		if ($paymentSum > 0 && $orderPrice > 0 && $paymentSum < $orderPrice)
		{
			return 'PARTIAL_PAID';
		}

		if ($paymentSum > 0 && ($orderPrice <= 0 || $paymentSum >= $orderPrice))
		{
			return 'PAID';
		}

		return null;
	}
}

if (!function_exists('mf_1c_import_detect_shipment_status'))
{
	function mf_1c_import_detect_shipment_status(?bool $shipped, float $shippedQty, float $orderQty): ?string
	{
		if ($shipped === false)
		{
			return 'NOT_SHIPPED';
		}

		if ($shippedQty > 0 && $orderQty > 0 && $shippedQty < $orderQty)
		{
			return 'PARTIAL_SHIPPED';
		}

		if ($shipped === true)
		{
			return 'SHIPPED';
		}

		if ($shippedQty > 0 && ($orderQty <= 0 || $shippedQty >= $orderQty))
		{
			return 'SHIPPED';
		}

		return null;
	}
}

if (!function_exists('mf_1c_import_set_order_uf'))
{
	function mf_1c_import_set_order_uf(Order $order, string $field, ?string $value): bool
	{
		if ($value === null || $value === '')
		{
			return false;
		}

		$current = (string)$order->getField($field);
		if ($current === $value)
		{
			return false;
		}

		$order->setField($field, $value);

		return true;
	}
}

if (!function_exists('mf_1c_import_mark_order_paid'))
{
	function mf_1c_import_mark_order_paid(Order $order): bool
	{
		$changed = false;

		if ($order->getField('PAYED') !== 'Y')
		{
			$order->setField('PAYED', 'Y');
			$changed = true;
		}

		$paymentCollection = $order->getPaymentCollection();
		if ($paymentCollection)
		{
			foreach ($paymentCollection as $payment)
			{
				if ($payment && !$payment->isPaid())
				{
					$payment->setPaid('Y');
					$changed = true;
				}
			}
		}

		return $changed;
	}
}

if (!function_exists('mf_1c_import_apply_updates'))
{
	function mf_1c_import_apply_updates(array $updates): void
	{
		if ($updates === [])
		{
			return;
		}

		if (!Loader::includeModule('sale'))
		{
			mf_1c_import_log('IMPORT STATUSES APPLY: sale module not loaded');
			return;
		}

		foreach ($updates as $parsed)
		{
			if (!is_array($parsed))
			{
				continue;
			}

			$orderId = mf_1c_import_find_order_id($parsed);
			mf_1c_import_log(
				'IMPORT STATUSES LOOKUP: xml_number=' . ($parsed['xml_number'] ?? '')
				. ' resolved=' . ($parsed['resolved_number'] ?? '')
				. ' order_id=' . (int)$orderId
			);

			if ($orderId <= 0)
			{
				mf_1c_import_log('IMPORT STATUSES APPLY: order not found for xml_number=' . ($parsed['xml_number'] ?? ''));
				continue;
			}

			$order = Order::load($orderId);
			if (!$order)
			{
				mf_1c_import_log('IMPORT STATUSES APPLY: Order::load failed id=' . $orderId);
				continue;
			}

			$changedFields = [];
			$orderPrice = (float)$order->getPrice();
			$orderQty = 0.0;
			$basket = $order->getBasket();
			if ($basket)
			{
				foreach ($basket as $basketItem)
				{
					$orderQty += (float)$basketItem->getQuantity();
				}
			}

			$statusId = strtoupper(trim((string)($parsed['status_id'] ?? '')));
			$ufOrderStatus = mf_1c_import_map_order_status($statusId);
			if ($ufOrderStatus !== null)
			{
				if (mf_1c_import_set_order_uf($order, 'UF_1C_ORDER_STATUS', $ufOrderStatus))
				{
					$changedFields[] = 'UF_1C_ORDER_STATUS=' . $ufOrderStatus;
				}
			}

			if ($statusId === 'F' && mf_1c_import_order_status_exists('F'))
			{
				if ((string)$order->getField('STATUS_ID') !== 'F')
				{
					$order->setField('STATUS_ID', 'F');
					$changedFields[] = 'STATUS_ID=F';
				}
			}
			elseif ($statusId === 'C' && mf_1c_import_order_status_exists('C'))
			{
				if ((string)$order->getField('STATUS_ID') !== 'C')
				{
					$order->setField('STATUS_ID', 'C');
					$changedFields[] = 'STATUS_ID=C';
				}
				if ($order->getField('CANCELED') !== 'Y')
				{
					$order->setField('CANCELED', 'Y');
					$changedFields[] = 'CANCELED=Y';
				}
			}

			$ufPaymentStatus = mf_1c_import_detect_payment_status(
				$parsed['paid'] ?? null,
				(float)($parsed['payment_sum'] ?? 0),
				$orderPrice
			);
			if ($ufPaymentStatus !== null)
			{
				if (mf_1c_import_set_order_uf($order, 'UF_1C_PAYMENT_STATUS', $ufPaymentStatus))
				{
					$changedFields[] = 'UF_1C_PAYMENT_STATUS=' . $ufPaymentStatus;
				}
				if ($ufPaymentStatus === 'PAID' && mf_1c_import_mark_order_paid($order))
				{
					$changedFields[] = 'PAYED=Y';
				}
			}

			$ufShipmentStatus = mf_1c_import_detect_shipment_status(
				$parsed['shipped'] ?? null,
				(float)($parsed['shipped_qty'] ?? 0),
				$orderQty
			);
			if ($ufShipmentStatus !== null)
			{
				if (mf_1c_import_set_order_uf($order, 'UF_1C_SHIPMENT_STATUS', $ufShipmentStatus))
				{
					$changedFields[] = 'UF_1C_SHIPMENT_STATUS=' . $ufShipmentStatus;
				}

				if (
					in_array($ufShipmentStatus, ['SHIPPED', 'PARTIAL_SHIPPED'], true)
					&& mf_1c_import_order_status_exists('DF')
					&& !in_array((string)$order->getField('STATUS_ID'), ['F', 'C'], true)
					&& (string)$order->getField('STATUS_ID') !== 'DF'
				)
				{
					$order->setField('STATUS_ID', 'DF');
					$changedFields[] = 'STATUS_ID=DF';
				}
			}

			if ($changedFields === [])
			{
				mf_1c_import_log('IMPORT STATUSES APPLY: no changes for order_id=' . $orderId);
				continue;
			}

			$result = $order->save();
			if ($result->isSuccess())
			{
				mf_1c_import_log(
					'IMPORT STATUSES APPLY OK: order_id=' . $orderId
					. ' updated=' . implode(', ', $changedFields)
				);
			}
			else
			{
				mf_1c_import_log(
					'IMPORT STATUSES APPLY ERROR: order_id=' . $orderId
					. ' errors=' . implode('; ', $result->getErrorMessages())
					. ' attempted=' . implode(', ', $changedFields)
				);
			}
		}
	}
}

if (!function_exists('mf_1c_import_register_shutdown'))
{
	function mf_1c_import_register_shutdown(string $filePath): void
	{
		$updates = mf_1c_import_parse_xml_file($filePath);
		if ($updates === [])
		{
			return;
		}

		register_shutdown_function(static function () use ($updates): void {
			mf_1c_import_apply_updates($updates);
		});
	}
}
