<?php

namespace Sale\Handlers\PaySystem;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Sale\PaySystem;
use Bitrix\Sale\Payment;
use Bitrix\Sale\Repository\PaymentRepository;

Loc::loadMessages(__FILE__);

/**
 * PayKeeper integration:
 * - create invoice via JSON API (/info/settings/token/ -> /change/invoice/preview/)
 * - show payment link
 * - optionally generate SBP QR via /change/sbpqr/activate
 * - accept POST notifications and mark payment as paid
 */
final class mf_paykeeperHandler extends PaySystem\ServiceHandler
{
	private function uuidV4(): string
	{
		// RFC 4122 UUID v4
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
		$hex = bin2hex($data);
		return sprintf(
			'%s-%s-%s-%s-%s',
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 12, 4),
			substr($hex, 16, 4),
			substr($hex, 20, 12)
		);
	}

	private function normPhone(string $s): string
	{
		$s = trim($s);
		if ($s === '') return '';
		// keep digits and leading +
		$s = preg_replace('~(?!^)\\+|[^0-9\\+]~', '', $s) ?? $s;
		return $s;
	}

	private function vatCodeFromRate($rate): string
	{
		$r = (float)$rate;
		if ($r <= 0) return 'none';
		if (abs($r - 0.1) < 0.001) return 'vat10';
		if (abs($r - 0.2) < 0.001) return 'vat20';
		return 'none';
	}

	private function buildCartForPaykeeper(Payment $payment, string $defaultVat = 'none'): array
	{
		$order = $payment->getCollection()->getOrder();
		$cart = [];
		$total = 0.0;

		$basket = $order->getBasket();
		if ($basket)
		{
			foreach ($basket as $bi)
			{
				$qty = (float)$bi->getQuantity();
				if ($qty <= 0) continue;

				$price = (float)$bi->getPrice();
				if ($price < 0) $price = 0.0;

				$name = trim((string)$bi->getField('NAME'));
				if ($name === '') $name = 'Товар';

				$sum = round($price * $qty, 2);
				$vatRate = $bi->getField('VAT_RATE');
				$vat = $this->vatCodeFromRate($vatRate);
				if ($vat === '') $vat = $defaultVat;

				$cart[] = [
					'name' => $name,
					'price' => round($price, 2),
					'quantity' => ($qty == (int)$qty ? (int)$qty : $qty),
					'sum' => rtrim(rtrim(number_format($sum, 2, '.', ''), '0'), '.'),
					'tax' => $vat,
				];
				$total += $sum;
			}
		}

		// Delivery as separate line (so total matches payment sum).
		$delivery = (float)$order->getDeliveryPrice();
		if ($delivery > 0)
		{
			$delivery = round($delivery, 2);
			$cart[] = [
				'name' => 'Доставка',
				'price' => $delivery,
				'quantity' => 1,
				'sum' => rtrim(rtrim(number_format($delivery, 2, '.', ''), '0'), '.'),
				'tax' => $defaultVat,
			];
			$total += $delivery;
		}

		$paySum = round((float)$payment->getSum(), 2);
		$diff = round($paySum - $total, 2);
		if (abs($diff) >= 0.01 && !empty($cart))
		{
			// Fix minor rounding differences by adjusting the last line.
			$lastIdx = count($cart) - 1;
			$lastSum = (float)str_replace(',', '.', (string)($cart[$lastIdx]['sum'] ?? '0'));
			$lastSum = round($lastSum + $diff, 2);
			$q = (float)($cart[$lastIdx]['quantity'] ?? 1);
			if ($q <= 0) $q = 1;
			$cart[$lastIdx]['sum'] = rtrim(rtrim(number_format($lastSum, 2, '.', ''), '0'), '.');
			$cart[$lastIdx]['price'] = round($lastSum / $q, 2);
			$total += $diff;
		}

		return $cart;
	}

	public static function getIndicativeFields()
	{
		// У PayKeeper нет уникального "маркерного" поля, поэтому проверяем минимальный набор.
		return ['id', 'sum', 'key'];
	}

	public function initiatePay(Payment $payment, ?Request $request = null)
	{
		$params = [
			'PAY_LINK' => '',
			'QR_CODE' => '',
			'QR_LINK' => '',
			'ERROR' => '',
		];

		try
		{
			$pkServer = rtrim((string)$this->getBusinessValue($payment, 'PK_SERVER'), '/');
			$pkUser = (string)$this->getBusinessValue($payment, 'PK_USER');
			$pkPass = (string)$this->getBusinessValue($payment, 'PK_PASSWORD');
			$useSbp = ((string)$this->getBusinessValue($payment, 'PK_USE_SBP') !== 'N');
			$psId = trim((string)$this->getBusinessValue($payment, 'PK_PSID'));
			$ttl = (int)$this->getBusinessValue($payment, 'PK_TTL');
			$ttl = max(1, min(20, $ttl > 0 ? $ttl : 5));
			$purpose = trim((string)$this->getBusinessValue($payment, 'PK_PURPOSE'));
			if ($purpose === '')
			{
				$purpose = 'Оплата заказа';
			}

			if ($pkServer === '' || $pkUser === '' || $pkPass === '')
			{
				throw new \RuntimeException('PayKeeper не настроен (PK_SERVER/PK_USER/PK_PASSWORD).');
			}

			$order = $payment->getCollection()->getOrder();
			$orderId = (int)$order->getId();
			$paymentId = (int)$payment->getId();

			$orderIdLabel = ($orderId > 0) ? ('Заказ #' . $orderId) : 'Заказ';
			// External order id for PayKeeper: UUID (stored in PS_INVOICE_ID to map callbacks back to payment).
			$orderid = trim((string)$payment->getField('PS_INVOICE_ID'));
			if ($orderid === '')
			{
				$orderid = $this->uuidV4();
				$payment->setField('PS_INVOICE_ID', $orderid);
				// Persist mapping so sale_ps_result.php can find payment by UUID.
				try
				{
					$order->save();
				}
				catch (\Throwable $e)
				{
					// ignore: if not saved, callback mapping may fail; we'll still try.
				}
			}
			$serviceName = $purpose . ' (' . $orderIdLabel . ')';

			// Customer contacts for receipt + invoice details.
			$props = $order->getPropertyCollection();
			$email = '';
			$phone = '';
			$payerName = '';
			$profileName = '';
			try
			{
				$email = $props && $props->getUserEmail() ? (string)$props->getUserEmail()->getValue() : '';
				$phone = $props && $props->getPhone() ? (string)$props->getPhone()->getValue() : '';
				$payerName = $props && $props->getPayerName() ? (string)$props->getPayerName()->getValue() : '';
				$profileName = $props && $props->getProfileName() ? (string)$props->getProfileName()->getValue() : '';
			}
			catch (\Throwable $e)
			{
				// ignore
			}
			$email = trim($email);
			$phone = $this->normPhone((string)$phone);
			$payerName = trim($payerName);
			$profileName = trim($profileName);

			// If payer name looks like email, ignore it.
			if ($payerName !== '' && str_contains($payerName, '@'))
			{
				$payerName = '';
			}
			if ($payerName === '' && $profileName !== '' && !str_contains($profileName, '@'))
			{
				$payerName = $profileName;
			}

			// Fallbacks from user profile (if order props are empty).
			if (($email === '' || $phone === '' || $payerName === '') && class_exists(\CUser::class))
			{
				$uid = (int)$order->getUserId();
				if ($uid > 0)
				{
					$u = \CUser::GetByID($uid)->Fetch();
					if (is_array($u))
					{
						if ($email === '')
						{
							$email = trim((string)($u['EMAIL'] ?? ''));
						}
						if ($phone === '')
						{
							$phone = $this->normPhone((string)($u['PERSONAL_PHONE'] ?? ($u['WORK_PHONE'] ?? '')));
						}
						if ($payerName === '')
						{
							$fn = trim((string)($u['NAME'] ?? ''));
							$ln = trim((string)($u['LAST_NAME'] ?? ''));
							$payerName = trim($ln . ' ' . $fn);
						}
					}
				}
			}

			$firstName = '';
			$lastName = '';
			if ($payerName !== '')
			{
				$parts = preg_split('~\\s+~u', $payerName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
				if (count($parts) >= 2)
				{
					$lastName = (string)$parts[0];
					$firstName = (string)$parts[1];
				}
				elseif (count($parts) === 1)
				{
					$firstName = (string)$parts[0];
				}
			}

			$sendReceipt = ((string)$this->getBusinessValue($payment, 'PK_SEND_RECEIPT') !== 'N');
			$defaultVat = trim((string)$this->getBusinessValue($payment, 'PK_VAT'));
			if ($defaultVat === '') $defaultVat = 'none';
			$cart = $this->buildCartForPaykeeper($payment, $defaultVat);

			// PayKeeper uses clientid in its UI; prefer real name (not email).
			$clientId = $payerName !== '' ? $payerName : ($email !== '' ? $email : ($phone !== '' ? $phone : $orderid));

			// PayKeeper expects cart and extra receipt params inside service_name JSON object (see docs).
			// service_name parameter MUST contain JSON string with at least: cart, service_name.
			$serviceNamePk = json_encode([
				'cart' => json_encode($cart, JSON_UNESCAPED_UNICODE),
				'lang' => 'ru',
				'service_name' => $serviceName,
			], JSON_UNESCAPED_UNICODE);

			$client = $this->makeClient($pkUser, $pkPass);
			$token = $this->getToken($client, $pkServer);

			$invoiceId = $this->createInvoicePreview(
				$client,
				$pkServer,
				$token,
				[
					'pay_amount' => (float)$payment->getSum(),
					'orderid' => $orderid,
					'service_name' => $serviceNamePk,
					'clientid' => $clientId,
					'client_email' => $email,
					'client_phone' => $phone,
					'client_name' => $payerName,
					'first_name' => $firstName,
					'last_name' => $lastName,
					'email' => $email,
					'phone' => $phone,
					'order_number' => (string)$orderId,
					// Receipt sending is configured on PayKeeper side (cashbox). We pass contacts.
					'send_receipt' => ($sendReceipt ? 1 : 0),
				]
			);

			$params['PAY_LINK'] = $pkServer . '/bill/' . rawurlencode((string)$invoiceId) . '/';

			if ($useSbp)
			{
				$sbp = $this->activateSbpQr(
					$client,
					$pkServer,
					$token,
					[
						'sum' => (float)$payment->getSum(),
						'orderid' => $orderid,
						'service_name' => $serviceName,
						'paymentPurpose' => $serviceName,
						'ttl' => $ttl,
						'ps_id' => $psId,
					]
				);
				$params['QR_CODE'] = (string)($sbp['qr_code'] ?? '');
				$params['QR_LINK'] = (string)($sbp['qr_link'] ?? '');
			}
		}
		catch (\Throwable $e)
		{
			$params['ERROR'] = $e->getMessage();
		}

		$this->setExtraParams($params);
		return $this->showTemplate($payment, 'template');
	}

	public function processRequest(Payment $payment, Request $request)
	{
		$result = new PaySystem\ServiceResult();

		$secret = (string)$this->getBusinessValue($payment, 'PK_SECRET');
		if ($secret === '')
		{
			$result->addError(new Error('PayKeeper secret (PK_SECRET) is not configured.'));
			return $result;
		}

		$paykeeperId = (string)$request->getPost('id');
		$sum = (string)$request->getPost('sum');
		$clientid = (string)$request->getPost('clientid');
		$orderid = (string)$request->getPost('orderid');
		$key = (string)$request->getPost('key');

		$expected = md5($paykeeperId . $sum . $clientid . $orderid . $secret);
		if (!hash_equals($expected, $key))
		{
			$result->addError(new Error('PayKeeper signature mismatch.'));
			return $result;
		}

		// Доп. проверка суммы
		$shouldPay = (float)$payment->getSum();
		$got = (float)str_replace(',', '.', $sum);
		if ($got > 0 && abs($got - $shouldPay) > 0.01)
		{
			$result->addError(new Error('PayKeeper sum mismatch.'));
			return $result;
		}

		$result->setOperationType(PaySystem\ServiceResult::MONEY_COMING);
		$result->setPsData([
			'PAYKEEPER_ID' => $paykeeperId,
			'PAYKEEPER_SUM' => $sum,
			'PAYKEEPER_ORDERID' => $orderid,
		]);

		return $result;
	}

	public function sendResponse(PaySystem\ServiceResult $result, Request $request)
	{
		// PayKeeper требует ответ: "OK <md5(id+secret)>"
		$paykeeperId = (string)$request->getPost('id');
		$paymentId = (int)$this->getPaymentIdFromRequest($request);
		if ($paykeeperId === '' || $paymentId <= 0)
		{
			return '';
		}

		$payment = PaymentRepository::getInstance()->getById($paymentId);
		if (!$payment)
		{
			return '';
		}
		$secret = (string)$this->getBusinessValue($payment, 'PK_SECRET');
		if ($secret === '')
		{
			return '';
		}

		return 'OK ' . md5($paykeeperId . $secret);
	}

	public function getPaymentIdFromRequest(Request $request)
	{
		$orderid = (string)($request->getPost('orderid') ?? '');
		$orderid = trim($orderid);
		if ($orderid === '')
		{
			return 0;
		}

		// Legacy format: PAYMENT_<id>
		if (preg_match('~PAYMENT_(\\d+)~', $orderid, $m))
		{
			return (int)$m[1];
		}

		// UUID mapping: stored in PS_INVOICE_ID.
		try
		{
			$row = \Bitrix\Sale\Internals\PaymentTable::getList([
				'filter' => ['=PS_INVOICE_ID' => $orderid],
				'select' => ['ID'],
				'limit' => 1,
			])->fetch();
			return $row ? (int)$row['ID'] : 0;
		}
		catch (\Throwable $e)
		{
			return 0;
		}
	}

	public function getCurrencyList()
	{
		return ['RUB'];
	}

	private function makeClient(string $user, string $pass): HttpClient
	{
		$client = new HttpClient([
			'redirect' => true,
			'disableSslVerification' => false,
			'waitResponse' => true,
			'socketTimeout' => 15,
			'streamTimeout' => 15,
		]);
		$client->setAuthorization($user, $pass);
		$client->setHeader('Accept', 'application/json');
		$client->setHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
		return $client;
	}

	private function getToken(HttpClient $client, string $server): string
	{
		$resp = $client->get($server . '/info/settings/token/');
		$data = json_decode((string)$resp, true);
		if (!is_array($data) || empty($data['token']))
		{
			throw new \RuntimeException('PayKeeper token request failed.');
		}
		return (string)$data['token'];
	}

	private function createInvoicePreview(HttpClient $client, string $server, string $token, array $paymentData): string
	{
		$payload = http_build_query(array_merge($paymentData, ['token' => $token]));
		$resp = $client->post($server . '/change/invoice/preview/', $payload);
		$data = json_decode((string)$resp, true);
		if (!is_array($data) || empty($data['invoice_id']))
		{
			$msg = 'PayKeeper invoice preview failed.';
			if (is_array($data) && !empty($data['msg']))
			{
				$msg .= ' ' . (string)$data['msg'];
			}
			elseif (is_array($data) && !empty($data['error']))
			{
				$msg .= ' ' . (string)$data['error'];
			}
			throw new \RuntimeException($msg);
		}
		return (string)$data['invoice_id'];
	}

	private function activateSbpQr(HttpClient $client, string $server, string $token, array $data): array
	{
		$payload = [
			'token' => $token,
			'sum' => $data['sum'] ?? '',
			'paymentPurpose' => $data['paymentPurpose'] ?? '',
			'ttl' => $data['ttl'] ?? '',
			'orderid' => $data['orderid'] ?? '',
			'service_name' => $data['service_name'] ?? '',
		];
		if (!empty($data['ps_id']))
		{
			$payload['ps_id'] = $data['ps_id'];
		}
		$resp = $client->post($server . '/change/sbpqr/activate', http_build_query($payload));
		$decoded = json_decode((string)$resp, true);
		if (!is_array($decoded) || empty($decoded['qr_code']))
		{
			// Не считаем фаталом: просто не покажем QR.
			return [];
		}
		return $decoded;
	}
}

