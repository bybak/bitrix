<?php

namespace Sale\Handlers\PaySystem;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Sale\PaySystem;
use Bitrix\Sale\Payment;
use Bitrix\Sale\Repository\PaymentRepository;
use Bitrix\Sale\Internals\PaymentTable;

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
	private function notifyLog(string $message, array $ctx = []): void
	{
		try
		{
			$path = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/upload/mf_paykeeper_notify.log';
			$line = '[' . date('c') . '] ' . $message;
			if (!empty($ctx))
			{
				$line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			}
			$line .= "\n";
			@file_put_contents($path, $line, FILE_APPEND);
		}
		catch (\Throwable $e)
		{
			// ignore
		}
	}

	private function in(Request $request, string $name): string
	{
		$v = $request->getPost($name);
		if ($v === null || $v === '')
		{
			$v = $request->getQuery($name);
		}
		if (($v === null || $v === '') && method_exists($request, 'decodeJson') && method_exists($request, 'getJsonList'))
		{
			try
			{
				$request->decodeJson();
				$v = $request->getJsonList()->get($name);
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}

		if (is_array($v))
		{
			return '';
		}
		return (string)$v;
	}

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
		// Fiscal: for online payments we treat it as 100% prepayment.
		// PayKeeper expects these keys per item (same naming as its Bitrix module):
		// - item_type: goods/service/work/...
		// - payment_type: prepay/part_prepay/advance/full
		// - measure: pcs/kg/l/...
		$defaultPaymentType = 'prepay';
		$defaultMeasure = 'pcs';

		$basket = $order->getBasket();
		if ($basket)
		{
			foreach ($basket as $bi)
			{
				$qty = (float)$bi->getQuantity();
				if ($qty <= 0) continue;

				$price = (float)$bi->getPrice();
				if ($price < 0) $price = 0.0;
				if (function_exists('mf_round_price'))
				{
					$price = mf_round_price($price);
				}

				$name = trim((string)$bi->getField('NAME'));
				if ($name === '') $name = 'Товар';

				$sum = function_exists('mf_round_price') ? mf_round_price($price * $qty) : (float)ceil($price * $qty);
				$vatRate = $bi->getField('VAT_RATE');
				$vat = $this->vatCodeFromRate($vatRate);
				if ($vat === '') $vat = $defaultVat;

				$cart[] = [
					'name' => $name,
					'price' => $price,
					'quantity' => ($qty == (int)$qty ? (int)$qty : $qty),
					'sum' => (string)(int)$sum,
					'tax' => $vat,
					'item_type' => 'goods',
					'payment_type' => $defaultPaymentType,
					'measure' => $defaultMeasure,
				];
				$total += $sum;
			}
		}

		// Delivery as separate line (so total matches payment sum).
		$delivery = (float)$order->getDeliveryPrice();
		if ($delivery > 0)
		{
			$delivery = function_exists('mf_round_price') ? mf_round_price($delivery) : (float)ceil($delivery);
			$cart[] = [
				'name' => 'Доставка',
				'price' => $delivery,
				'quantity' => 1,
				'sum' => (string)(int)$delivery,
				'tax' => $defaultVat,
				'item_type' => 'service',
				'payment_type' => $defaultPaymentType,
				'measure' => $defaultMeasure,
			];
			$total += $delivery;
		}

		$paySum = function_exists('mf_round_price') ? mf_round_price((float)$payment->getSum()) : (float)ceil((float)$payment->getSum());
		$diff = $paySum - $total;
		if (abs($diff) >= 1 && !empty($cart))
		{
			// Fix minor rounding differences by adjusting the last line.
			$lastIdx = count($cart) - 1;
			$lastSum = (float)str_replace(',', '.', (string)($cart[$lastIdx]['sum'] ?? '0'));
			$lastSum = function_exists('mf_round_price') ? mf_round_price($lastSum + $diff) : (float)ceil($lastSum + $diff);
			$q = (float)($cart[$lastIdx]['quantity'] ?? 1);
			if ($q <= 0) $q = 1;
			$cart[$lastIdx]['sum'] = (string)(int)$lastSum;
			$cart[$lastIdx]['price'] = function_exists('mf_round_price') ? mf_round_price($lastSum / $q) : (float)ceil($lastSum / $q);
			$total += $diff;
		}

		return $cart;
	}

	public static function getIndicativeFields()
	{
		// У PayKeeper нет уникального "маркерного" поля, поэтому проверяем минимальный набор.
		return ['id', 'sum', 'key'];
	}

	/**
	 * Ссылка на оплату PayKeeper (для писем и других сценариев без показа шаблона).
	 */
	public function resolvePayLink(Payment $payment): string
	{
		$params = $this->buildInitParams($payment, false);

		return trim((string)($params['PAY_LINK'] ?? ''));
	}

	public function initiatePay(Payment $payment, ?Request $request = null)
	{
		$params = $this->buildInitParams($payment, true);
		$this->setExtraParams($params);

		return $this->showTemplate($payment, 'template');
	}

	/**
	 * @return array{PAY_LINK:string,QR_CODE:string,QR_LINK:string,ERROR:string}
	 */
	private function buildInitParams(Payment $payment, bool $withSbp): array
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
			/**
			 * External order id for PayKeeper.
			 *
			 * CRITICAL: callbacks must reliably map back to a Bitrix payment.
			 * We store generated UUID into PS_INVOICE_ID and persist it via direct update
			 * (order->save can silently fail on some installs / during initiatePay).
			 *
			 * getPaymentIdFromRequest() also supports legacy "PAYMENT_<id>" if needed.
			 */
			$orderid = trim((string)$payment->getField('PS_INVOICE_ID'));
			if ($orderid === '')
			{
				$orderid = $this->uuidV4();
				$payment->setField('PS_INVOICE_ID', $orderid);
			}

			// Persist mapping as safely as possible (direct update; order->save can fail on some installs).
			if ($paymentId > 0)
			{
				try
				{
					PaymentTable::update($paymentId, ['PS_INVOICE_ID' => $orderid]);
				}
				catch (\Throwable $e)
				{
					// ignore
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

			if ($withSbp && $useSbp)
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

		return $params;
	}

	public function processRequest(Payment $payment, Request $request)
	{
		$result = new PaySystem\ServiceResult();

		$secret = (string)$this->getBusinessValue($payment, 'PK_SECRET');
		if ($secret === '')
		{
			$this->notifyLog('processRequest: missing PK_SECRET', [
				'ip' => (string)($request->getRemoteAddress() ?? ''),
				'method' => (string)($request->getRequestMethod() ?? ''),
				'uri' => (string)($request->getRequestUri() ?? ''),
			]);
			$result->addError(new Error('PayKeeper secret (PK_SECRET) is not configured.'));
			return $result;
		}

		$paykeeperId = $this->in($request, 'id');
		$sum = $this->in($request, 'sum');
		$clientid = $this->in($request, 'clientid');
		$orderid = $this->in($request, 'orderid');
		$key = $this->in($request, 'key');

		$expected = md5($paykeeperId . $sum . $clientid . $orderid . $secret);
		if (!hash_equals($expected, $key))
		{
			$this->notifyLog('processRequest: signature mismatch', [
				'ip' => (string)($request->getRemoteAddress() ?? ''),
				'method' => (string)($request->getRequestMethod() ?? ''),
				'orderid' => $orderid,
				'id' => $paykeeperId,
				'sum' => $sum,
				'clientid_len' => strlen($clientid),
				'clientid_md5' => md5($clientid),
				'key_prefix' => substr($key, 0, 12),
				'expected_prefix' => substr($expected, 0, 12),
				'secret_len' => strlen($secret),
			]);
			$result->addError(new Error('PayKeeper signature mismatch.'));
			return $result;
		}

		// Доп. проверка суммы
		$shouldPay = (float)$payment->getSum();
		$got = (float)str_replace(',', '.', $sum);
		if ($got > 0 && abs($got - $shouldPay) > 0.01)
		{
			$this->notifyLog('processRequest: sum mismatch', [
				'ip' => (string)($request->getRemoteAddress() ?? ''),
				'orderid' => $orderid,
				'id' => $paykeeperId,
				'got' => $got,
				'should' => $shouldPay,
			]);
			$result->addError(new Error('PayKeeper sum mismatch.'));
			return $result;
		}

		$this->notifyLog('processRequest: ok', [
			'ip' => (string)($request->getRemoteAddress() ?? ''),
			'orderid' => $orderid,
			'id' => $paykeeperId,
			'sum' => $sum,
		]);

		$result->setOperationType(PaySystem\ServiceResult::MONEY_COMING);
		// IMPORTANT: Only set fields that exist on Bitrix Payment entity,
		// otherwise Payment::setFields() throws and Bitrix returns 500.
		$now = new \Bitrix\Main\Type\DateTime();
		$currency = (string)$payment->getField('CURRENCY');
		if ($currency === '')
		{
			$currency = 'RUB';
		}
		$result->setPsData([
			'PS_STATUS_CODE' => (string)$paykeeperId,
			'PS_STATUS_DESCRIPTION' => 'PayKeeper notification accepted',
			'PS_STATUS_MESSAGE' => 'orderid=' . (string)$orderid,
			'PS_SUM' => (float)str_replace(',', '.', (string)$sum),
			'PS_CURRENCY' => $currency,
			'PS_RESPONSE_DATE' => $now,
			'PAY_VOUCHER_NUM' => (string)$paykeeperId,
			'PAY_VOUCHER_DATE' => $now,
		]);

		return $result;
	}

	public function sendResponse(PaySystem\ServiceResult $result, Request $request)
	{
		// PayKeeper требует ответ: "OK <md5(id+secret)>"
		$paykeeperId = $this->in($request, 'id');
		$paymentId = (int)$this->getPaymentIdFromRequest($request);
		if ($paykeeperId === '' || $paymentId <= 0)
		{
			$this->notifyLog('sendResponse: no id/paymentId', [
				'ip' => (string)($request->getRemoteAddress() ?? ''),
				'id' => $paykeeperId,
				'paymentId' => $paymentId,
			]);
			return '';
		}

		$payment = PaymentRepository::getInstance()->getById($paymentId);
		if (!$payment)
		{
			$this->notifyLog('sendResponse: payment not found', [
				'ip' => (string)($request->getRemoteAddress() ?? ''),
				'id' => $paykeeperId,
				'paymentId' => $paymentId,
			]);
			return '';
		}
		$secret = (string)$this->getBusinessValue($payment, 'PK_SECRET');
		if ($secret === '')
		{
			$this->notifyLog('sendResponse: missing PK_SECRET', [
				'ip' => (string)($request->getRemoteAddress() ?? ''),
				'id' => $paykeeperId,
				'paymentId' => $paymentId,
			]);
			return '';
		}

		// Extra safety: confirm signature before acknowledging to PayKeeper.
		$sum = $this->in($request, 'sum');
		$clientid = $this->in($request, 'clientid');
		$orderid = $this->in($request, 'orderid');
		$key = $this->in($request, 'key');
		$expected = md5($paykeeperId . $sum . $clientid . $orderid . $secret);
		if ($sum === '' || $orderid === '' || $key === '' || !hash_equals($expected, $key))
		{
			$this->notifyLog('sendResponse: signature mismatch', [
				'ip' => (string)($request->getRemoteAddress() ?? ''),
				'paymentId' => $paymentId,
				'orderid' => $orderid,
				'id' => $paykeeperId,
				'sum' => $sum,
				'clientid_len' => strlen($clientid),
				'clientid_md5' => md5($clientid),
				'key_prefix' => substr($key, 0, 12),
				'expected_prefix' => substr($expected, 0, 12),
			]);
			return '';
		}

		$this->notifyLog('sendResponse: OK', [
			'ip' => (string)($request->getRemoteAddress() ?? ''),
			'paymentId' => $paymentId,
			'orderid' => $orderid,
			'id' => $paykeeperId,
			'sum' => $sum,
		]);

		$body = 'OK ' . md5($paykeeperId . $secret);
		// IMPORTANT: Service::processRequest ignores return value. We must output body.
		// PayKeeper подтверждает получение только по телу ответа.
		if (!headers_sent())
		{
			header('Content-Type: text/plain; charset=UTF-8');
		}
		echo $body;
		// Prevent any further output/header modifications (e.g. $APPLICATION->FinalActions()).
		// Otherwise Bitrix may throw and respond with 500 even after we echoed OK.
		if (PHP_SAPI !== 'cli')
		{
			die();
		}
		return $body;
	}

	public function getPaymentIdFromRequest(Request $request)
	{
		$orderid = $this->in($request, 'orderid');
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

