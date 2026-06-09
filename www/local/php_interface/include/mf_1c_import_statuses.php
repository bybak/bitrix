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

$mfOrderCustomStatusInclude = __DIR__ . '/mf_order_custom_status.php';
if (is_file($mfOrderCustomStatusInclude))
{
	require_once $mfOrderCustomStatusInclude;
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
			$push('1-' . $tailDigits);
		}

		if ($digits !== '')
		{
			$push($digits);
			$trimmedDigits = ltrim($digits, '0');
			if ($trimmedDigits !== '' && $trimmedDigits !== $digits)
			{
				$push($trimmedDigits);
			}
		}

		if ($tailDigits !== '')
		{
			$push($tailDigits);
			$push('1-' . $tailDigits);
		}

		if ($digits !== '' && preg_match('/^s\d/i', $xmlNumber) && strlen($digits) > 3)
		{
			$push(substr($digits, 1));
		}

		$push($xmlNumber);

		return $ordered;
	}
}

if (!function_exists('mf_1c_import_number_1c_candidates'))
{
	/**
	 * Кандидаты из реквизита «Номер по 1С», например 1c-001295 → 295, 1-295.
	 *
	 * @return string[]
	 */
	function mf_1c_import_number_1c_candidates(string $number1c): array
	{
		$number1c = trim($number1c);
		if ($number1c === '')
		{
			return [];
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

		if (preg_match('/(\d+)$/u', $number1c, $matches))
		{
			$digits = ltrim((string)$matches[1], '0');
			if ($digits !== '')
			{
				$push($digits);
				if (strlen($digits) > 1 && $digits[0] === '1')
				{
					$tail = substr($digits, 1);
					if ($tail !== '')
					{
						$push($tail);
						$push('1-' . $tail);
					}
				}
			}
		}

		if (preg_match('/^s\d/i', $number1c))
		{
			foreach (mf_1c_import_order_number_candidates($number1c) as $candidate)
			{
				$push($candidate);
			}
		}

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

if (!function_exists('mf_1c_import_merge_all_requisites'))
{
	function mf_1c_import_merge_all_requisites(DOMXPath $xpath, DOMNode $docNode): array
	{
		$merged = mf_1c_import_collect_requisites($xpath, $docNode);
		$subNodes = $xpath->query('cml:ПодчиненныеДокументы/cml:ПодчиненныйДокумент', $docNode);
		if (!$subNodes)
		{
			return $merged;
		}

		foreach ($subNodes as $subNode)
		{
			foreach (mf_1c_import_collect_requisites($xpath, $subNode) as $name => $value)
			{
				if (($merged[$name] ?? '') === '' && $value !== '')
				{
					$merged[$name] = $value;
				}
			}
		}

		return $merged;
	}
}

if (!function_exists('mf_1c_import_normalize_mf_code'))
{
	function mf_1c_import_normalize_mf_code(string $type, ?string $code): ?string
	{
		$code = strtoupper(trim((string)$code));
		if ($code === '')
		{
			return null;
		}

		$allowed = [
			'order' => ['IN_WORK', 'DONE', 'CANCELED'],
			'payment' => ['NOT_PAID', 'PARTIAL_PAID', 'PAID'],
			'shipment' => ['NOT_SHIPPED', 'PARTIAL_SHIPPED', 'SHIPPED'],
		];

		if (!isset($allowed[$type]) || !in_array($code, $allowed[$type], true))
		{
			mf_1c_import_log('IMPORT STATUSES: unknown MF code type=' . $type . ' code=' . $code);

			return null;
		}

		return $code;
	}
}

if (!function_exists('mf_1c_import_parse_bool'))
{
	function mf_1c_import_parse_bool(mixed $value): bool
	{
		if (is_bool($value))
		{
			return $value;
		}

		$value = mb_strtolower(trim((string)$value));

		return in_array($value, ['1', 'y', 'yes', 'true', 'да'], true);
	}
}

if (!function_exists('mf_1c_import_is_cancelled_mf'))
{
	function mf_1c_import_is_cancelled_mf(
		array $mf,
		?string $statusId = null,
		?string $resolvedOrderStatus = null
	): bool
	{
		if (mf_1c_import_parse_bool($mf['is_cancelled'] ?? false))
		{
			return true;
		}

		if ((string)($mf['order_status'] ?? '') === 'CANCELED')
		{
			return true;
		}

		if ($resolvedOrderStatus === 'CANCELED')
		{
			return true;
		}

		return strtoupper(trim((string)$statusId)) === 'C';
	}
}

if (!function_exists('mf_1c_import_order_is_paid_in_bitrix'))
{
	function mf_1c_import_order_is_paid_in_bitrix(?Order $order): bool
	{
		if (!$order)
		{
			return false;
		}

		if ((string)$order->getField('PAYED') === 'Y')
		{
			return true;
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

if (!function_exists('mf_1c_import_resolve_payment_status_for_import'))
{
	/**
	 * Статус оплаты для HL: только явный КодСтатусаОплатыMF из 1С.
	 * Поле CommerceML «Оплачен» и суммы не используем — при завершении/отмене 1С часто шлёт ложный paid=true.
	 */
	function mf_1c_import_resolve_payment_status_for_import(
		array $mf,
		?Order $order,
		bool $isCancelled
	): ?string
	{
		$explicit = $mf['payment_status'] ?? null;

		if ($explicit !== null)
		{
			if ($isCancelled && $explicit === 'PAID' && !mf_1c_import_order_is_paid_in_bitrix($order))
			{
				return 'NOT_PAID';
			}

			return $explicit;
		}

		if ($isCancelled)
		{
			return mf_1c_import_order_is_paid_in_bitrix($order) ? 'PAID' : 'NOT_PAID';
		}

		return null;
	}
}

if (!function_exists('mf_1c_import_resolve_cancel_reason_text'))
{
	/**
	 * Для отображения «Причина отмены» используем КомментарийОтменыMF (1С копирует туда комментарий завершения).
	 */
	function mf_1c_import_resolve_cancel_reason_text(array $mf): string
	{
		if (empty($mf['_is_cancelled']) && !mf_1c_import_is_cancelled_mf($mf))
		{
			return '';
		}

		return trim((string)($mf['cancel_comment'] ?? ''));
	}
}

if (!function_exists('mf_1c_import_extract_mf_fields'))
{
	function mf_1c_import_extract_mf_fields(array $reqs): array
	{
		$cancelReasonCode = trim((string)($reqs['ПричинаОтменыMF'] ?? ''));
		$cancelComment = trim((string)($reqs['КомментарийОтменыMF'] ?? ''));

		return [
			'order_status' => mf_1c_import_normalize_mf_code('order', $reqs['КодСтатусаЗаказаMF'] ?? null),
			'payment_status' => mf_1c_import_normalize_mf_code('payment', $reqs['КодСтатусаОплатыMF'] ?? null),
			'shipment_status' => mf_1c_import_normalize_mf_code('shipment', $reqs['КодСтатусаДоставкиMF'] ?? null),
			'is_cancelled' => mf_1c_import_parse_bool($reqs['ОтмененMF'] ?? false),
			'completion_variant' => trim((string)($reqs['ВариантЗавершенияMF'] ?? '')),
			'completion_comment' => trim((string)($reqs['КомментарийЗавершенияMF'] ?? '')),
			'cancel_reason' => $cancelReasonCode,
			'cancel_comment' => $cancelComment,
			'raw' => [
				'СтатусЗаказаMF' => trim((string)($reqs['СтатусЗаказаMF'] ?? '')),
				'СтатусОплатыMF' => trim((string)($reqs['СтатусОплатыMF'] ?? '')),
				'СтатусДоставкиMF' => trim((string)($reqs['СтатусДоставкиMF'] ?? '')),
				'ОтмененMF' => trim((string)($reqs['ОтмененMF'] ?? '')),
				'ВариантЗавершенияMF' => trim((string)($reqs['ВариантЗавершенияMF'] ?? '')),
				'КомментарийЗавершенияMF' => trim((string)($reqs['КомментарийЗавершенияMF'] ?? '')),
				'ПричинаОтменыMF' => $cancelReasonCode,
				'КомментарийОтменыMF' => $cancelComment,
				'СуммаЗаказаMF' => trim((string)($reqs['СуммаЗаказаMF'] ?? '')),
				'СуммаОплаченоMF' => trim((string)($reqs['СуммаОплаченоMF'] ?? '')),
				'КоличествоЗаказаноMF' => trim((string)($reqs['КоличествоЗаказаноMF'] ?? '')),
				'КоличествоОтгруженоMF' => trim((string)($reqs['КоличествоОтгруженоMF'] ?? '')),
			],
		];
	}
}

if (!function_exists('mf_1c_import_format_mf_log'))
{
	function mf_1c_import_format_mf_log(array $mf): string
	{
		$parts = [
			'order=' . ($mf['order_status'] ?? ''),
			'payment=' . ($mf['payment_status'] ?? ''),
			'shipment=' . ($mf['shipment_status'] ?? ''),
			'cancel_reason=' . ($mf['cancel_reason'] ?? ''),
			'cancel_comment=' . ($mf['cancel_comment'] ?? ''),
		];

		$raw = $mf['raw'] ?? [];
		if (is_array($raw))
		{
			foreach ($raw as $name => $value)
			{
				if ($value !== '')
				{
					$parts[] = $name . '=' . $value;
				}
			}
		}

		return implode(' ', $parts);
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

		$id1cNode = $xpath->query('cml:Ид', $docNode)->item(0);
		$id1c = $id1cNode ? trim($id1cNode->nodeValue) : '';

		$allReqs = mf_1c_import_merge_all_requisites($xpath, $docNode);
		$mf = mf_1c_import_extract_mf_fields($allReqs);
		$statusId = strtoupper(trim((string)($allReqs['Статус заказа ИД'] ?? '')));

		$paid = mf_1c_import_xml_bool($allReqs['Оплачен'] ?? null);
		$shipped = mf_1c_import_xml_bool($allReqs['Отгружен'] ?? null);
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
		$number1c = trim((string)($allReqs['Номер по 1С'] ?? ''));
		if ($number1c !== '')
		{
			foreach (mf_1c_import_number_1c_candidates($number1c) as $extraCandidate)
			{
				if (!in_array($extraCandidate, $candidates, true))
				{
					$candidates[] = $extraCandidate;
				}
			}
		}

		return [
			'xml_number' => $xmlNumber,
			'id_1c' => $id1c,
			'number_1c' => $number1c,
			'order_candidates' => $candidates,
			'resolved_number' => $candidates[0] ?? '',
			'status_id' => $statusId,
			'mf' => $mf,
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
			$mf = is_array($parsed['mf'] ?? null) ? $parsed['mf'] : [];
			mf_1c_import_log(
				'IMPORT STATUSES PARSED: xml_number=' . $parsed['xml_number']
				. ' bitrix_id=' . $parsed['resolved_number']
				. ' candidates=' . implode(',', $parsed['order_candidates'] ?? [])
				. ' legacy_status_id=' . $parsed['status_id']
				. ' mf_fields=' . mf_1c_import_format_mf_log($mf)
				. ' paid=' . var_export($parsed['paid'], true)
				. ' shipped=' . var_export($parsed['shipped'], true)
			);
		}

		return $updates;
	}
}

if (!function_exists('mf_1c_import_get_order_loader'))
{
	function mf_1c_import_get_order_loader(): ?\CSaleOrderLoader
	{
		if (!Loader::includeModule('sale'))
		{
			return null;
		}

		$path = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/sale/general/order_loader.php';
		if (!class_exists('CSaleOrderLoader', false) && is_file($path))
		{
			require_once $path;
		}

		if (!class_exists('CSaleOrderLoader', false))
		{
			return null;
		}

		return new \CSaleOrderLoader();
	}
}

if (!function_exists('mf_1c_import_find_order_id_by_code'))
{
	function mf_1c_import_find_order_id_by_code(string $orderCode): ?int
	{
		$orderCode = trim($orderCode);
		if ($orderCode === '')
		{
			return null;
		}

		$loader = mf_1c_import_get_order_loader();
		if (!$loader || !method_exists($loader, 'getOrderIdByDocument'))
		{
			return null;
		}

		$orderId = $loader->getOrderIdByDocument($orderCode);
		if ($orderId === false || $orderId === null || $orderId === '')
		{
			return null;
		}

		$orderId = (int)$orderId;

		return $orderId > 0 ? $orderId : null;
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

		$id1c = trim((string)($parsed['id_1c'] ?? ''));
		if ($id1c !== '')
		{
			$row = \Bitrix\Sale\Internals\OrderTable::getList([
				'filter' => ['=ID_1C' => $id1c],
				'select' => ['ID', 'ACCOUNT_NUMBER'],
				'order' => ['ID' => 'DESC'],
				'limit' => 1,
			])->fetch();
			if (is_array($row) && (int)($row['ID'] ?? 0) > 0)
			{
				mf_1c_import_log(
					'IMPORT STATUSES MATCH: method=ID_1C value=' . $id1c
					. ' order_id=' . (int)$row['ID']
					. ' account=' . (string)($row['ACCOUNT_NUMBER'] ?? '')
				);

				return (int)$row['ID'];
			}
		}

		$lookupCodes = [];
		$xmlNumber = trim((string)($parsed['xml_number'] ?? ''));
		if ($xmlNumber !== '')
		{
			$lookupCodes[] = $xmlNumber;
		}
		foreach ($parsed['order_candidates'] ?? [] as $candidate)
		{
			$candidate = trim((string)$candidate);
			if ($candidate !== '' && !in_array($candidate, $lookupCodes, true))
			{
				$lookupCodes[] = $candidate;
			}
		}

		foreach ($lookupCodes as $orderCode)
		{
			$orderId = mf_1c_import_find_order_id_by_code($orderCode);
			if ($orderId !== null && $orderId > 0)
			{
				mf_1c_import_log(
					'IMPORT STATUSES MATCH: method=getOrderIdByDocument value=' . $orderCode
					. ' order_id=' . $orderId
				);

				return $orderId;
			}
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
			mf_1c_import_log(
				'IMPORT STATUSES LOOKUP MISS: candidates=' . implode(',', $candidates)
			);

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

if (!function_exists('mf_1c_import_detect_payment_status_from_mf_sums'))
{
	/**
	 * Суммы из 1С не считаем доказательством полной оплаты: при завершении/отмене заказа
	 * «СуммаОплаченоMF» часто дублирует сумму заказа без фактической оплаты.
	 * PAID — только при явном флаге «Оплачен» в XML.
	 */
	function mf_1c_import_detect_payment_status_from_mf_sums(?string $orderSumRaw, ?string $paidSumRaw, ?bool $paidFlag = null): ?string
	{
		$orderSumRaw = trim((string)$orderSumRaw);
		$paidSumRaw = trim((string)$paidSumRaw);
		if ($orderSumRaw === '' || $paidSumRaw === '')
		{
			return null;
		}

		$orderSum = (float)str_replace([' ', ','], ['', '.'], $orderSumRaw);
		$paidSum = (float)str_replace([' ', ','], ['', '.'], $paidSumRaw);
		if ($orderSum <= 0)
		{
			return null;
		}
		if ($paidSum <= 0)
		{
			return 'NOT_PAID';
		}
		if ($paidSum + 0.001 >= $orderSum)
		{
			return $paidFlag === true ? 'PAID' : null;
		}

		return 'PARTIAL_PAID';
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

		// Без явного «Оплачен» в XML не выставляем PAID по совпадению сумм/подчинённых документов.
		if ($paymentSum > 0 && $orderPrice > 0 && $paymentSum + 0.001 < $orderPrice)
		{
			return 'PARTIAL_PAID';
		}

		if ($paymentSum > 0 && $orderPrice <= 0)
		{
			return 'PARTIAL_PAID';
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

if (!function_exists('mf_1c_import_sync_hl_statuses'))
{
	/**
	 * Сохраняет статусы в HL mf_order_custom_status (колонки «Заказ (1С)» в админке).
	 *
	 * @return string[] список обновлённых полей HL
	 */
	function mf_1c_import_sync_hl_statuses(
		int $orderId,
		?string $orderStatus,
		?string $paymentStatus,
		?string $shipmentStatus,
		array $mf = []
	): array
	{
		if ($orderId <= 0)
		{
			return [];
		}

		if (!function_exists('mf_order_custom_status_set'))
		{
			mf_1c_import_log('IMPORT STATUSES HL SKIP: mf_order_custom_status_set is not loaded order_id=' . $orderId);

			return [];
		}

		if (function_exists('mf_order_custom_status_ensure_hl'))
		{
			try
			{
				mf_order_custom_status_ensure_hl();
			}
			catch (\Throwable $e)
			{
				mf_1c_import_log('IMPORT STATUSES HL ENSURE ERROR: ' . $e->getMessage());
			}
		}

		$payload = [];
		if ($orderStatus !== null && $orderStatus !== '')
		{
			$payload['ORDER_STATUS'] = $orderStatus;
		}
		if ($paymentStatus !== null && $paymentStatus !== '')
		{
			$payload['PAYMENT_STATUS'] = $paymentStatus;
		}
		if ($shipmentStatus !== null && $shipmentStatus !== '')
		{
			$payload['SHIPMENT_STATUS'] = $shipmentStatus;
		}

		$isCancelled = !empty($mf['_is_cancelled']);
		if (!$isCancelled)
		{
			$isCancelled = mf_1c_import_is_cancelled_mf($mf);
		}
		$payload['IS_CANCELED'] = $isCancelled;
		$payload['CANCEL_REASON'] = mf_1c_import_resolve_cancel_reason_text($mf);

		$completionVariant = trim((string)($mf['completion_variant'] ?? ''));
		if ($completionVariant !== '')
		{
			$payload['COMPLETION_VARIANT'] = $completionVariant;
		}

		$completionComment = trim((string)($mf['completion_comment'] ?? ''));
		if ($completionComment !== '')
		{
			$payload['COMPLETION_COMMENT'] = $completionComment;
		}

		if ($payload === [])
		{
			return [];
		}

		$before = function_exists('mf_order_custom_status_get')
			? mf_order_custom_status_get($orderId)
			: null;
		$changed = [];

		try
		{
			$after = mf_order_custom_status_set($orderId, $payload);
			$trackKeys = [
				'ORDER_STATUS' => 'ORDER_STATUS',
				'PAYMENT_STATUS' => 'PAYMENT_STATUS',
				'SHIPMENT_STATUS' => 'SHIPMENT_STATUS',
				'CANCEL_REASON' => 'CANCEL_REASON',
				'COMPLETION_VARIANT' => 'COMPLETION_VARIANT',
				'COMPLETION_COMMENT' => 'COMPLETION_COMMENT',
			];
			foreach ($trackKeys as $key => $afterKey)
			{
				if (!array_key_exists($key, $payload))
				{
					continue;
				}
				$beforeValue = is_array($before) ? (string)($before[$afterKey] ?? '') : '';
				$afterValue = is_array($after) ? (string)($after[$afterKey] ?? '') : '';
				if ($beforeValue !== $afterValue)
				{
					$changed[] = 'HL_' . $key . '=' . $afterValue;
				}
			}
			if (array_key_exists('IS_CANCELED', $payload) && is_array($after))
			{
				$beforeCanceled = is_array($before) && !empty($before['IS_CANCELED']);
				$afterCanceled = !empty($after['IS_CANCELED']);
				if ($beforeCanceled !== $afterCanceled)
				{
					$changed[] = 'HL_IS_CANCELED=' . ($afterCanceled ? 'Y' : 'N');
				}
			}
			if ($changed === [] && is_array($after))
			{
				foreach ($trackKeys as $key => $afterKey)
				{
					if (!array_key_exists($key, $payload))
					{
						continue;
					}
					$afterValue = (string)($after[$afterKey] ?? '');
					if ($afterValue !== '')
					{
						$changed[] = 'HL_' . $key . '=' . $afterValue;
					}
				}
			}
		}
		catch (\Throwable $e)
		{
			mf_1c_import_log(
				'IMPORT STATUSES HL ERROR: order_id=' . $orderId
				. ' error=' . $e->getMessage()
				. ' payload=' . json_encode($payload, JSON_UNESCAPED_UNICODE)
			);

			return [];
		}

		if ($changed !== [])
		{
			mf_1c_import_log(
				'IMPORT STATUSES HL OK: order_id=' . $orderId
				. ' updated=' . implode(', ', $changed)
			);
		}

		return $changed;
	}
}

if (!function_exists('mf_1c_import_resolve_status_codes'))
{
	/**
	 * @return array{order:?string,payment:?string,shipment:?string,is_cancelled:bool}
	 */
	function mf_1c_import_resolve_status_codes(array $parsed, ?Order $order = null): array
	{
		$mf = is_array($parsed['mf'] ?? null) ? $parsed['mf'] : [];
		$statusId = strtoupper(trim((string)($parsed['status_id'] ?? '')));

		$orderStatus = $mf['order_status'] ?? null;
		if ($orderStatus === null)
		{
			$orderStatus = mf_1c_import_map_order_status($statusId);
		}

		$isCancelled = mf_1c_import_is_cancelled_mf($mf, $statusId, $orderStatus);

		$orderQty = 0.0;
		if ($order)
		{
			$basket = $order->getBasket();
			if ($basket)
			{
				foreach ($basket as $basketItem)
				{
					$orderQty += (float)$basketItem->getQuantity();
				}
			}
		}
		$raw = is_array($mf['raw'] ?? null) ? $mf['raw'] : [];
		if ($orderQty <= 0 && ($raw['КоличествоЗаказаноMF'] ?? '') !== '')
		{
			$orderQty = (float)str_replace(',', '.', (string)$raw['КоличествоЗаказаноMF']);
		}

		$paymentStatus = mf_1c_import_resolve_payment_status_for_import($mf, $order, $isCancelled);

		$shipmentStatus = $mf['shipment_status'] ?? null;
		if ($shipmentStatus === null)
		{
			$shippedQty = (float)($parsed['shipped_qty'] ?? 0);
			if ($shippedQty <= 0 && ($raw['КоличествоОтгруженоMF'] ?? '') !== '')
			{
				$shippedQty = (float)str_replace(',', '.', (string)$raw['КоличествоОтгруженоMF']);
			}
			$shipmentStatus = mf_1c_import_detect_shipment_status(
				$parsed['shipped'] ?? null,
				$shippedQty,
				$orderQty
			);
		}

		return [
			'order' => $orderStatus,
			'payment' => $paymentStatus,
			'shipment' => $shipmentStatus,
			'is_cancelled' => $isCancelled,
		];
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

			try
			{
			$mf = is_array($parsed['mf'] ?? null) ? $parsed['mf'] : [];
			$orderId = mf_1c_import_find_order_id($parsed);
			$resolvedOrderId = ($orderId !== null && $orderId > 0) ? (int)$orderId : 0;
			mf_1c_import_log(
				'IMPORT STATUSES LOOKUP: xml_number=' . ($parsed['xml_number'] ?? '')
				. ' number_1c=' . ($parsed['number_1c'] ?? '')
				. ' bitrix_id=' . ($parsed['resolved_number'] ?? '')
				. ' candidates=' . implode(',', $parsed['order_candidates'] ?? [])
				. ' order_found=' . ($resolvedOrderId > 0 ? 'Y' : 'N')
				. ' order_id=' . $resolvedOrderId
				. ' mf_fields=' . mf_1c_import_format_mf_log($mf)
			);

			if ($resolvedOrderId <= 0)
			{
				mf_1c_import_log('IMPORT STATUSES APPLY: order not found for xml_number=' . ($parsed['xml_number'] ?? ''));
				continue;
			}

			$orderId = $resolvedOrderId;
			$order = Order::load($orderId);
			if (!$order)
			{
				mf_1c_import_log(
					'IMPORT STATUSES APPLY: Order::load failed id=' . $orderId
				);
				continue;
			}

			$statusCodes = mf_1c_import_resolve_status_codes($parsed, $order);
			$ufOrderStatus = $statusCodes['order'] ?? null;
			$ufPaymentStatus = $statusCodes['payment'] ?? null;
			$ufShipmentStatus = $statusCodes['shipment'] ?? null;
			$mf['_is_cancelled'] = !empty($statusCodes['is_cancelled']);
			mf_1c_import_log(
				'IMPORT STATUSES PAYMENT: order_id=' . $orderId
				. ' resolved=' . (string)$ufPaymentStatus
				. ' explicit=' . (string)($mf['payment_status'] ?? '')
				. ' cancelled=' . (!empty($mf['_is_cancelled']) ? 'Y' : 'N')
				. ' xml_paid=' . var_export($parsed['paid'] ?? null, true)
				. ' bitrix_paid=' . (mf_1c_import_order_is_paid_in_bitrix($order) ? 'Y' : 'N')
			);

			$hlChanged = mf_1c_import_sync_hl_statuses(
				$orderId,
				$ufOrderStatus,
				$ufPaymentStatus,
				$ufShipmentStatus,
				$mf
			);
			if ($hlChanged === [])
			{
				mf_1c_import_log(
					'IMPORT STATUSES HL NOOP: order_id=' . $orderId
					. ' order=' . (string)$ufOrderStatus
					. ' payment=' . (string)$ufPaymentStatus
					. ' shipment=' . (string)$ufShipmentStatus
				);
			}

			$changedFields = $hlChanged;

			$statusId = strtoupper(trim((string)($parsed['status_id'] ?? '')));
			if ($ufOrderStatus !== null)
			{
				if (mf_1c_import_set_order_uf($order, 'UF_1C_ORDER_STATUS', $ufOrderStatus))
				{
					$changedFields[] = 'UF_1C_ORDER_STATUS=' . $ufOrderStatus;
				}
			}

			if ($ufOrderStatus === 'DONE' && mf_1c_import_order_status_exists('F'))
			{
				if ((string)$order->getField('STATUS_ID') !== 'F')
				{
					$order->setField('STATUS_ID', 'F');
					$changedFields[] = 'STATUS_ID=F';
				}
			}
			elseif ($ufOrderStatus === 'CANCELED' && mf_1c_import_order_status_exists('C'))
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
			elseif ($statusId === 'F' && mf_1c_import_order_status_exists('F'))
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

			if ($ufPaymentStatus !== null)
			{
				if (mf_1c_import_set_order_uf($order, 'UF_1C_PAYMENT_STATUS', $ufPaymentStatus))
				{
					$changedFields[] = 'UF_1C_PAYMENT_STATUS=' . $ufPaymentStatus;
				}
				if (
					($mf['payment_status'] ?? null) === 'PAID'
					&& $ufPaymentStatus === 'PAID'
					&& mf_1c_import_mark_order_paid($order)
				)
				{
					$changedFields[] = 'PAYED=Y';
				}
			}

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

			$cancelReason = trim((string)($mf['cancel_reason'] ?? ''));
			if ($cancelReason !== '')
			{
				if (mf_1c_import_set_order_uf($order, 'UF_1C_CANCEL_REASON', $cancelReason))
				{
					$changedFields[] = 'UF_1C_CANCEL_REASON=' . $cancelReason;
				}
			}

			$cancelComment = mf_1c_import_resolve_cancel_reason_text($mf);
			if ($cancelComment !== '')
			{
				if (mf_1c_import_set_order_uf($order, 'UF_1C_CANCEL_COMMENT', $cancelComment))
				{
					$changedFields[] = 'UF_1C_CANCEL_COMMENT=' . $cancelComment;
				}
			}
			elseif (!empty($mf['_is_cancelled']) || mf_1c_import_is_cancelled_mf($mf, $statusId, $ufOrderStatus))
			{
				if (mf_1c_import_set_order_uf($order, 'UF_1C_CANCEL_COMMENT', ''))
				{
					$changedFields[] = 'UF_1C_CANCEL_COMMENT=';
				}
			}

			if ($changedFields === [])
			{
				mf_1c_import_log('IMPORT STATUSES APPLY: no changes for order_id=' . $orderId);
				continue;
			}

			$orderNeedsSave = false;
			foreach ($changedFields as $fieldChange)
			{
				if (!str_starts_with($fieldChange, 'HL_'))
				{
					$orderNeedsSave = true;
					break;
				}
			}

			if (!$orderNeedsSave)
			{
				mf_1c_import_log(
					'IMPORT STATUSES APPLY OK (HL only): order_id=' . $orderId
					. ' updated=' . implode(', ', $changedFields)
				);
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
			catch (\Throwable $e)
			{
				mf_1c_import_log(
					'IMPORT STATUSES APPLY DOC ERROR: xml_number=' . ($parsed['xml_number'] ?? '')
					. ' error=' . $e->getMessage()
					. ' @ ' . $e->getFile() . ':' . $e->getLine()
				);
			}
		}
	}
}

if (!function_exists('mf_1c_import_apply_file'))
{
	function mf_1c_import_apply_file(string $filePath): void
	{
		static $applied = [];
		$filePath = trim($filePath);
		if ($filePath === '' || isset($applied[$filePath]))
		{
			return;
		}

		try
		{
			if (!is_file($filePath))
			{
				mf_1c_import_log('IMPORT STATUSES APPLY FILE: not found ' . $filePath);

				return;
			}

			mf_1c_import_log('IMPORT STATUSES APPLY FILE v20260519a: start ' . $filePath);

			if (!Loader::includeModule('sale'))
			{
				mf_1c_import_log('IMPORT STATUSES APPLY FILE: sale module not loaded');

				return;
			}

			Loader::includeModule('highloadblock');

			$updates = mf_1c_import_parse_xml_file($filePath);
			if ($updates === [])
			{
				mf_1c_import_log('IMPORT STATUSES APPLY FILE: no documents in ' . basename($filePath));

				return;
			}

			mf_1c_import_log('IMPORT STATUSES APPLY FILE: documents=' . count($updates));
			mf_1c_import_apply_updates($updates);
			$applied[$filePath] = true;
			mf_1c_import_log('IMPORT STATUSES APPLY FILE: done');
		}
		catch (\Throwable $e)
		{
			mf_1c_import_log(
				'IMPORT STATUSES APPLY FILE FATAL: ' . $e->getMessage()
				. ' @ ' . $e->getFile() . ':' . $e->getLine()
			);
		}
	}
}

if (!function_exists('mf_1c_import_register_shutdown'))
{
	function mf_1c_import_register_shutdown(string $filePath): void
	{
		static $registered = [];
		$filePath = trim($filePath);
		if ($filePath === '' || isset($registered[$filePath]))
		{
			return;
		}
		$registered[$filePath] = true;

		mf_1c_import_log('IMPORT STATUSES: register shutdown xml=' . $filePath);

		register_shutdown_function(static function () use ($filePath): void {
			mf_1c_import_log('IMPORT STATUSES: shutdown fallback start');
			mf_1c_import_apply_file($filePath);
			mf_1c_import_log('IMPORT STATUSES: shutdown fallback done');
		});
	}
}
