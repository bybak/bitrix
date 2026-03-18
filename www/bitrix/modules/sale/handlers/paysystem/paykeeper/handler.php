<?php

namespace Sale\Handlers\PaySystem;

use Bitrix\Sale;
use Bitrix\Catalog;
use Bitrix\Catalog\Mysql\CCatalogVat;
use Bitrix\Main\Application;
use Bitrix\Main\Web\Uri;
use Bitrix\Main\Error;
use Bitrix\Main\Request;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sale\PaySystem;
use Bitrix\Sale\Payment;
use Bitrix\Sale\PriceMaths;
use Bitrix\Sale\Shipment;
use Bitrix\Main\Web;

use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\Product\SystemField\MarkingCodeGroup;

use Utils;
use PayKeeperApiService;
use PayKeeperPayment;
use PayKeeperCart;
use VersionsTable;
use OrdersTable;
use CardTokensTable;

include_once __DIR__ . '/lib/Utils.php';
require_once __DIR__ . '/lib/PayKeeperApiService.php';
require_once __DIR__ . '/lib/PayKeeperPayment.php';
require_once __DIR__ . '/lib/PayKeeperCart.php';
require_once __DIR__ . '/lib/db/VersionsTable.php';
require_once __DIR__ . '/lib/db/OrdersTable.php';
require_once __DIR__ . '/lib/db/CardTokensTable.php';

class PayKeeperHandler
    extends PaySystem\ServiceHandler
    implements
    PaySystem\IRefund,
    PaySystem\IHold,
    PaySystem\IPartialHold,
    PaySystem\IRecurring
{
    private $convert_full_tax_rate = false;
    private $delivery_name_fixed = '';
    private $tru_code_name = '';
    private $basket_item_type = 'goods';
    private $basket_payment_type = 'prepay';
    private $delivery_item_type = 'service';
    private $delivery_payment_type = 'prepay';
    private $basket_measure = 'pcs';
    private $discount = false;
    private $vat = 'none';
    private $vat_priority = false;
    private $vat_delivery = 'none';
    private $vat_delivery_priority = false;
    private static $logging;
    private static $pkObj;

    /**
     * @param Payment $payment
     * @param Request|null $request
     * @return PaySystem\ServiceResult
     */
    public function initiatePay(Payment $payment, Request $request = null)
    {
        self::$pkObj = new PayKeeperPayment();
        $paykeeper_url = $this->getBusinessValue($payment, 'PAYKEEPER_FORM_URL');

        $paykeeper_url = rtrim($paykeeper_url, '/');
        if (strpos($paykeeper_url, '/create') === false && strpos($paykeeper_url, '/order') === false) {
            $paykeeper_url = $paykeeper_url . '/create';
        }

        $secret = $this->getBusinessValue($payment, 'PAYKEEPER_SECRET');
        $service_name = $payment->getField('ID') . '|' . Utils::$serviceInitName;
        $ordernum =  $this->getBusinessValue($payment, 'PAYKEEPER_ORDER_NUMBER');
        $orderid = (strpos($paykeeper_url, '/create')) ? $ordernum : $ordernum . '|' . $service_name;
        $order = Sale\Order::load($payment->getOrderId());
        self::$pkObj->setOrderTotal(floatval($this->getBusinessValue($payment, 'PAYKEEPER_ORDER_AMOUNT')));
        $propertyCollection = $order->getPropertyCollection();
        $clientid = is_null($propertyCollection->getPayerName()) ? '' : $propertyCollection->getPayerName()->getValue();
        $client_email = is_null($propertyCollection->getUserEmail()) ? '' : $propertyCollection->getUserEmail()->getValue();
        $client_phone = is_null($propertyCollection->getPhone()) ? '' : $propertyCollection->getPhone()->getValue();

        //set order parameters
        self::$pkObj->setOrderParams(
            self::$pkObj->getOrderTotal(),  // sum
            $clientid,                       // clientid
            $orderid,                        // orderid
            $client_email,                   // client_email
            $client_phone,                   // client_phone
            $service_name,                   // service_name
            $paykeeper_url,                  // payment form url
            $secret                          // secret key
        );

        self::$logging = $this->getBusinessValue($payment, 'PAYKEEPER_LOGGING') === 'Y';

        if ($this->getBusinessValue($payment, 'PAYKEEPER_CART') === 'Y'
            && $order->getPrice() == self::$pkObj->getOrderTotal())
        {
            // Receipt Options
            if ($this->getBusinessValue($payment, 'PAYKEEPER_USE_FIXED_NAME_DELIVERY') === 'Y'
                && !empty($this->getBusinessValue($payment, 'PAYKEEPER_FIXED_NAME_DELIVERY'))
            ){
                $this->delivery_name_fixed = $this->getBusinessValue($payment, 'PAYKEEPER_FIXED_NAME_DELIVERY');
            }
            if ($this->getBusinessValue($payment, 'PAYKEEPER_TRU_CODE_USE') === 'Y'
                && !empty($this->getBusinessValue($payment, 'PAYKEEPER_TRU_CODE_NAME'))
            ){
                $this->tru_code_name = $this->getBusinessValue($payment, 'PAYKEEPER_TRU_CODE_NAME');
            }
            $this->convert_full_tax_rate = $this->getBusinessValue($payment, 'PAYKEEPER_CONVERT_FULL_RATES') === 'Y';
            $this->discount = $this->getBusinessValue($payment, 'PAYKEEPER_DISCOUNT') === 'Y';
            $this->basket_item_type = Utils::getValueTypeParamCart(
                $this->getBusinessValue($payment, 'PAYKEEPER_BASKET_ITEM_TYPE'), 'item_type');
            $this->basket_payment_type = Utils::getValueTypeParamCart(
                $this->getBusinessValue($payment, 'PAYKEEPER_BASKET_PAYMENT_TYPE'), 'payment_type');
            $this->delivery_item_type = Utils::getValueTypeParamCart(
                $this->getBusinessValue($payment, 'PAYKEEPER_DELIVERY_ITEM_TYPE'), 'item_type');
            $this->delivery_payment_type = Utils::getValueTypeParamCart(
                $this->getBusinessValue($payment, 'PAYKEEPER_DELIVERY_PAYMENT_TYPE'), 'payment_type');
            $this->basket_measure = Utils::getValueTypeParamCart(
                $this->getBusinessValue($payment, 'PAYKEEPER_BASKET_MEASURE'), 'measure');
            $this->vat = Utils::getValueTypeParamCart($this->getBusinessValue($payment, 'PAYKEEPER_VAT'), 'vat');
            $this->vat_priority = $this->getBusinessValue($payment, 'PAYKEEPER_VAT_PRIORITY') === 'Y';
            $this->vat_delivery = Utils::getValueTypeParamCart(
                $this->getBusinessValue($payment, 'PAYKEEPER_VAT_DELIVERY'), 'vat');
            $this->vat_delivery_priority = $this->getBusinessValue($payment, 'PAYKEEPER_VAT_DELIVERY_PRIORITY') === 'Y';
            self::$pkObj->fiscal_cart = $this->_cart($order);
        } else {
            self::$pkObj->fiscal_cart = [];
        }

        $params = array(
            'form_url' => self::$pkObj->getOrderParams('form_url'),
            'sum' => self::$pkObj->getOrderTotal(),
            'orderid' => self::$pkObj->getOrderParams('orderid'),
            'clientid' => self::$pkObj->getOrderParams('clientid'),
            'client_email' => self::$pkObj->getOrderParams('client_email'),
            'client_phone' => self::$pkObj->getOrderParams('client_phone'),
            'service_name' => self::$pkObj->getOrderParams('service_name'),
            'phone' => self::$pkObj->getOrderParams('client_phone'),
            'cart' => self::$pkObj->getFiscalCartEncoded()
        );

        if ($this->getBusinessValue($payment, 'PAYKEEPER_PSTYPE') != '') {
            $params['pstype'] = $this->getBusinessValue($payment, 'PAYKEEPER_PSTYPE');
        }

        if ($this->getBusinessValue($payment, 'PAYKEEPER_SBP') === 'Y') {
            $params['sbp'] = true;
        }

        $params['is_recurrent'] = $this->getBusinessValue($payment, 'PAYKEEPER_CHARGE_PART_ENABLE') === 'Y'
            || $this->getBusinessValue($payment, 'PAYKEEPER_RECURRING_ENABLE_COF') === 'Y';

        Utils::initDB();

        $request = Application::getInstance()->getContext()->getRequest();
        $uriString = $request->getRequestUri();
        $uri = new Uri($uriString);

        if ($this->getBusinessValue($payment, 'PAYKEEPER_REDIRECT_TO_ORDER') === 'Y'
            && $this->getBusinessValue($payment, 'PAYKEEPER_ORDER_PAGE'))
        {
            $params['user_result_callback'] = str_replace(
                [ '#order_id#' ],
                [ $payment->getOrderId() ],
                $this->getBusinessValue($payment, 'PAYKEEPER_ORDER_PAGE')
            );
        }

        // If the language is not English, default to Russian.
        $pf_lang = LANGUAGE_ID;
        if ($pf_lang !== 'en' && $pf_lang !== 'ru') {
            Loc::setCurrentLang('ru');
            $pf_lang = 'ru';
        }

        $params['contacts'] = false;
        $params['detail_page_message'] = Loc::getMessage('SALE_HPS_PAYKEEPER_BUTTON_REDIRECT');
        $params['redirect'] = true;

        $isNoCheckoutPage = !$request->get('ORDER_ID');
        $isPublicLink = strpos($uri->GetLocator(), 'access=') !== false;
        $isOrderPage = strpos($uri->GetLocator(), "/personal/orders/" . urlencode(urlencode($ordernum))) !== false;
        $formRedirectDisabled = $this->getBusinessValue($payment, 'PAYKEEPER_FORM_REDIRECT') !== 'Y';

        if ($isOrderPage || $isPublicLink || $formRedirectDisabled || $isNoCheckoutPage) {
            // If the payment page is accessed via a public link
            if ($isPublicLink) {
                $params['contacts'] = $this->getBusinessValue($payment, 'PAYKEEPER_ORDER_CONTACTS') === 'Y';
                $params['detail_page_message'] = Loc::getMessage('SALE_HPS_PAYKEEPER_BUTTON_PERSONAL');
            } else {
                $params['detail_page_message'] = Loc::getMessage('SALE_HPS_PAYKEEPER_BUTTON_PRESS');
            }
            $params['redirect'] = false;
        }

        $params['pay_button'] = Loc::getMessage('SALE_HPS_PAYKEEPER_BUTTON');

        if (LANG_CHARSET != 'UTF-8' && $pf_lang == 'ru') {
            $params['detail_page_message'] = iconv('UTF-8', LANG_CHARSET, $params['detail_page_message']);
            $params['pay_button'] = iconv('UTF-8', LANG_CHARSET, $params['pay_button']);
        }

        if (self::$pkObj->getPaymentFormType() == 'create' || $params['is_recurrent']) { //create form
            $params['form_type'] = 'create';
            $to_hash = number_format(self::$pkObj->getOrderTotal(), 2, '.', '').
                self::$pkObj->getOrderParams('clientid')     .
                self::$pkObj->getOrderParams('orderid')      .
                self::$pkObj->getOrderParams('service_name') .
                self::$pkObj->getOrderParams('client_email') .
                self::$pkObj->getOrderParams('client_phone') .
                self::$pkObj->getOrderParams('secret_key');
            $sign = hash ('sha256' , $to_hash);
            $params['sign'] = $sign;
            $params['lang'] = $pf_lang;

            if ($params['is_recurrent']) {
                $params['msgtype'] = 'createbinding';
                $params['paymentid'] = $payment->getField('ID');
                $params['ajax_php_url'] = '/local/ajax/paykeeper/ajax.php';
                $params['ajax_js_url'] = '/local/ajax/paykeeper/ajax.js';

                if ($this->getBusinessValue($payment, 'PAYKEEPER_ENABLE_COF_NEXT') === 'Y'
                    || $this->getBusinessValue($payment, 'PAYKEEPER_RECURRING_ENABLE_COF') === 'Y')
                {

                    $params['card_list'] = CardTokensTable::getCardList($order->getUserId());
                }
            }
        } else { //order form
            $payment_parameters = array(
                'clientid' => self::$pkObj->getOrderParams('clientid'),
                'orderid' => self::$pkObj->getOrderParams('orderid'),
                'sum' => self::$pkObj->getOrderTotal(),
                'client_phone' => self::$pkObj->getOrderParams('phone'),
                'phone' => self::$pkObj->getOrderParams('phone'),
                'client_email' => self::$pkObj->getOrderParams('client_email'),
                'cart' => self::$pkObj->getFiscalCartEncoded()
            );

            $result = $this->_Send(self::$pkObj->getOrderParams('form_url'), $payment_parameters, [], 'text');
            if (isset($result['errorCode'])) {
                if (!$result['errorMessage']) {
                    $result['errorMessage'] = Loc::getMessage('SALE_HPS_PAYKEEPER_ERROR_FORM');
                }
                $form = '<p class="paykeeper__error">'."INTERNAL ERROR: {$result['errorMessage']}".'</p>';
            } else {
                $form = $result['msg'];
            }
            $params['detail_page_message'] = $form;
        }

        $this->setExtraParams($params);
        return $this->showTemplate($payment, 'template');
    }

    /**
     * Полный или частичный возврат
     *
     * @param Payment $payment
     * @param $refundableSum
     * @return PaySystem\ServiceResult
     * @throws Main\ArgumentException
     * @throws Main\ArgumentNullException
     * @throws Main\ArgumentOutOfRangeException
     * @throws Main\ArgumentTypeException
     * @throws Main\ObjectException
     */
    public function refund(Payment $payment, $refundableSum)
    {
        $result = new PaySystem\ServiceResult();
        $paykeeperId = $payment->getField('PS_INVOICE_ID');
        $order = Sale\Order::load($payment->getOrderId());
        $orderId = $order->getId();
        $paykeeperLogin = $this->getBusinessValue($payment, 'PAYKEEPER_LK_LOGIN');
        $paykeeperPassword = $this->getBusinessValue($payment, 'PAYKEEPER_LK_PASSWORD');
        self::$logging = $this->getBusinessValue($payment, 'PAYKEEPER_LOGGING') === 'Y';

        $paykeeperUrl = Utils::clearPaykeeperUrl($this->getBusinessValue($payment, 'PAYKEEPER_FORM_URL'));

        $PayKeeperApiService = new PayKeeperApiService($paykeeperUrl, $paykeeperLogin, $paykeeperPassword);
        $paymentSum = $payment->getField('PS_SUM');
        $isPartialRefund = $refundableSum < $paymentSum; // Частичный возврат
        $requestData = [
            'id' => $paykeeperId,
            'amount' => $refundableSum
        ];
        $requestData['partial'] = $isPartialRefund ? 'true': false;

        $PayKeeperApiService::setLogging(self::$logging);
        $sendResult = $PayKeeperApiService::reversePayment($requestData);
        $sendResult = $sendResult[0] ?? $sendResult;
        $errorFlag = false;
        $errorMessage = '';
        if (isset($sendResult['msg'])) {
            $errorFlag = true;
            $errorMessage = "Заказ $orderId. Ошибка: {$sendResult['msg']}";
        } else if (!isset($sendResult['result']) || $sendResult['result'] != 'success') {
            $errorFlag = true;
            $errorMessage = "Заказ $orderId. Неизвестная ошибка!";
        }

        if ($errorFlag) {
            Utils::paykeeperLogger([
                'TITLE' => $isPartialRefund ? 'REFUND_PARTIAL_PAYMENT_ERROR' : 'REFUND_FULL_PAYMENT_ERROR',
                'ERRORS' => $errorMessage
            ], self::$logging);
            $result->addError(new Error($errorMessage));
            return $result;
        }

        $result->setPsData([
            'PAID' => 'N',
            'PS_STATUS' => 'N',
            'PS_STATUS_CODE' => 'REFUNDED',
            'PS_STATUS_DESCRIPTION' => 'Payment refunded'
        ]);
        $payment->setFields($result->getPsData());
        $payment->save();
        if ($isPartialRefund) {
            // Меняем сумму оплаты
            $result->setPsData([
                'PAID' => 'Y',
                'SUM' => $paymentSum - $refundableSum,
                'PS_STATUS' => 'Y',
                'PS_SUM' => $paymentSum - $refundableSum,
                'PS_STATUS_CODE' => 'REFUNDED_PARTIAL',
                'PS_STATUS_DESCRIPTION' => 'Payment refunded partial'
            ]);
            $paymentCollection = $order->getPaymentCollection();
            $payment = $paymentCollection->getItemById($payment->getId());
            $payment->setFields($result->getPsData());
            $payment->save();

            $result->setOperationType(PaySystem\ServiceResult::MONEY_COMING);
        } else {
            // Меняем отметку об оплате заказа
            if ($order->isPaid()) {
                $order->setFieldNoDemand('PAYED', 'N');
                $order->setFieldNoDemand('DATE_PAYED', null);
            }
            $result->setOperationType(PaySystem\ServiceResult::MONEY_LEAVING);
        }
        $order->save();

        return $result;
    }

    /**
     * Полное или частичное списание ранее авторизованных средств
     *
     * @param Payment $payment
     * @param $sum
     * @return PaySystem\ServiceResult
     */
    public function confirm(Payment $payment, $sum = 0)
    {
        $paykeeperId = $payment->getField('PS_INVOICE_ID');
        $order = Sale\Order::load($payment->getOrderId());
        $orderId = $order->getId();
        $confirmSum = $sum;
        $paykeeperLogin = $this->getBusinessValue($payment, 'PAYKEEPER_LK_LOGIN');
        $paykeeperPassword = $this->getBusinessValue($payment, 'PAYKEEPER_LK_PASSWORD');
        self::$logging = $this->getBusinessValue($payment, 'PAYKEEPER_LOGGING') === 'Y';

        $paykeeperUrl = Utils::clearPaykeeperUrl($this->getBusinessValue($payment, 'PAYKEEPER_FORM_URL'));

        $paymentSum = $payment->getField('PS_SUM');

        // Сумма заказа меньше суммы холда
        if ($confirmSum > 0 && $confirmSum < $paymentSum) {
            $refundAmount = $paymentSum - $confirmSum;
            $result = $this->refund($payment, $refundAmount);
            if (!$result->isSuccess()) {
                return $result;
            }
            return $result;
        }

        // Сумма заказа больше суммы холда
        if ($confirmSum > 0 && $confirmSum > $paymentSum) {
            $result = $this->cancel($payment);
            if ($result->isSuccess()) {
                sleep(15);
                $result = $this->repeatRecurrent($payment);
            }
            return $result;
        }

        $result = new PaySystem\ServiceResult();
        $PayKeeperApiService = new PayKeeperApiService($paykeeperUrl, $paykeeperLogin, $paykeeperPassword);
        $PayKeeperApiService::setLogging(self::$logging);
        $sendResult = $PayKeeperApiService::capturePayment($paykeeperId);
        $sendResult = $sendResult[0] ?? $sendResult;
        $errorMessage = '';
        if (isset($sendResult['msg'])) {
            $errorMessage = "Заказ $orderId. Ошибка: {$sendResult['msg']}";
        } else if (!isset($sendResult['result']) || $sendResult['result'] != 'success') {
            $errorMessage = "Заказ $orderId. Неизвестная ошибка!";
        }
        if ($errorMessage) {
            Utils::paykeeperLogger([
                'TITLE' => 'CONFIRM_FULL_PAYMENT_ERROR',
                'ERRORS' => $errorMessage
            ]);
            $chargeFailAfterStatus = $this->getBusinessValue($payment, 'PAYKEEPER_CHARGE_FAIL_AFTER_STATUS');
            if ($chargeFailAfterStatus) {
                $order->setFieldNoDemand('STATUS_ID', $chargeFailAfterStatus);
                $order->save();
            }
            $result->addError(new Error($errorMessage));
            return $result;
        }

        $result->setPsData([
            'PS_STATUS' => 'Y',
            'PS_STATUS_CODE' => 'CONFIRMED',
            'PS_STATUS_DESCRIPTION' => 'Payment confirmed',
            'PS_SUM' => $paymentSum
        ]);
        $payment->setFields($result->getPsData());
        $payment->save();

        $result->setOperationType(PaySystem\ServiceResult::MONEY_COMING);

        $chargeSuccessAfterStatus = $this->getBusinessValue($payment, 'PAYKEEPER_CHARGE_SUCCESS_AFTER_STATUS');
        if ($chargeSuccessAfterStatus) {
            $order->setFieldNoDemand('STATUS_ID', $chargeSuccessAfterStatus);
        }
        $order->save();

        try {
            OrdersTable::addOrUpdate([
                'BX_ORDER_ID' => $orderId,
                'CONFIRMED' => 'Y'
            ]);
        } catch (\Exception $e) {
            Utils::paykeeperLogger([
                'TITLE' => 'ERROR_ADD_OR_UPDATE_ORDERSTABLE',
                'ERROR' => $e->getMessage()
            ], self::$logging);
        }

        return $result;
    }

    /**
     * Отмена авторизации холдированных средств
     *
     * @param Payment $payment
     * @return PaySystem\ServiceResult
     */
    public function cancel(Payment $payment)
    {
        $result = new PaySystem\ServiceResult();
        $psStatus = $payment->getField('PS_STATUS_CODE');
        if ($psStatus === 'CANCELLED') {
            Utils::paykeeperLogger([
                'TITLE' => 'PAYMENT_ALREADY_CANCELLED',
                'PS_STATUS' => $payment->getField('PS_STATUS')
            ], self::$logging);
            return $result;
        }
        $paykeeperId = $payment->getField('PS_INVOICE_ID');
        $order = Sale\Order::load($payment->getOrderId());
        $orderId = $order->getId();
        $paykeeperLogin = $this->getBusinessValue($payment, 'PAYKEEPER_LK_LOGIN');
        $paykeeperPassword = $this->getBusinessValue($payment, 'PAYKEEPER_LK_PASSWORD');
        self::$logging = $this->getBusinessValue($payment, 'PAYKEEPER_LOGGING') === 'Y';

        $paykeeperUrl = Utils::clearPaykeeperUrl($this->getBusinessValue($payment, 'PAYKEEPER_FORM_URL'));

        $PayKeeperApiService = new PayKeeperApiService($paykeeperUrl, $paykeeperLogin, $paykeeperPassword);
        $requestData = [
            'id' => $paykeeperId,
            'amount' => $payment->getField('PS_SUM'),
            'partial' => false
        ];

        $PayKeeperApiService::setLogging(self::$logging);
        $sendResult = $PayKeeperApiService::reversePayment($requestData);
        $sendResult = $sendResult[0] ?? $sendResult;
        $errorFlag = false;
        $infoLog = '';
        if (isset($sendResult['msg'])) {
            $errorFlag = true;
            $infoLog = "Заказ $orderId. Ошибка: {$sendResult['msg']}";
        } else if (!isset($sendResult['result']) || $sendResult['result'] != 'success') {
            $errorFlag = true;
            $infoLog = "Заказ $orderId. Неизвестная ошибка!";
        }

        if ($errorFlag) {
            Utils::paykeeperLogger([
                'TITLE' => 'CANCEL_PAYMENT_ERROR',
                'ERRORS' => $infoLog
            ], self::$logging);
            $result->addError(new Error($infoLog));
            return $result;
        }

        $payment->setPaid("N");
        $result->setPsData([
//            'PS_STATUS' => 'N',
            'PS_STATUS' => 'C',
            'PS_STATUS_CODE' => 'CANCELLED',
            'PS_STATUS_DESCRIPTION' => 'Payment cancelled'
        ]);
        $payment->setFields($result->getPsData());
        $payment->save();

        // Меняем отметку об оплате заказа
        if ($order->isPaid()) {
            $order->setFieldNoDemand('PAYED', 'N');
            $order->setFieldNoDemand('DATE_PAYED', null);
        }
        $order->save();

        $result->setOperationType(PaySystem\ServiceResult::MONEY_LEAVING);
        return $result;
    }

    /**
     * Безакцептное списание по привязанной карте
     *
     * @param Payment $payment
     * @param Request|null $request
     * @return PaySystem\ServiceResult
     */
    public function repeatRecurrent(Payment $payment, Request $request = null): PaySystem\ServiceResult
    {
        $result = new PaySystem\ServiceResult();
        if (!self::$logging) {
            self::$logging = $this->getBusinessValue($payment, 'PAYKEEPER_LOGGING') === 'Y';
        }
        $order = Sale\Order::load($payment->getOrderId());
        $orderId = $order->getId();
        $bankId = $payment->getField('PS_RECURRING_TOKEN');
        $orderSum = $order->getPrice();

        // Создаём новую оплату
        $paymentCollection = $order->getPaymentCollection();
        $newPayment = $paymentCollection->createItem();
        $newPayment->setFields([
            'PAY_SYSTEM_ID' => $payment->getPaymentSystemId(),
            'PAY_SYSTEM_NAME' => $payment->getPaymentSystemName(),
            'SUM' => $orderSum,
            'CURRENCY' => 'RUB', // обязательный параметр со значением RUB
        ]);
        $newPayment->setPaid('N');
        $saveResult = $newPayment->save();
        if (!$saveResult->isSuccess()) {
            $errorMessage = "Заказ $orderId. Ошибка создания платежа: " . implode(', ', $saveResult->getErrorMessages());
            Utils::paykeeperLogger([
                'PAYMENT_ADD_ERROR' => $errorMessage
            ], self::$logging);

            $chargeFailAfterStatus = $this->getBusinessValue($payment, 'PAYKEEPER_CHARGE_FAIL_AFTER_STATUS');
            if ($chargeFailAfterStatus) {
                $order->setFieldNoDemand('STATUS_ID', $chargeFailAfterStatus);
                $order->save();
            }

            $result->addError(
                PaySystem\Error::create($errorMessage)
            );
            return $result;
        }

        $propertyCollection = $order->getPropertyCollection();
        $serviceName = $newPayment->getId() . '|' . Utils::$serviceInitName;
        $orderNum =  $this->getBusinessValue($payment, 'PAYKEEPER_ORDER_NUMBER');
        $paykeeperUrl = $this->getBusinessValue($payment, 'PAYKEEPER_FORM_URL');
        $clientId = is_null($propertyCollection->getPayerName()) ? '' : $propertyCollection->getPayerName()->getValue();
        $clientEmail = is_null($propertyCollection->getUserEmail()) ? '' : $propertyCollection->getUserEmail()->getValue();
        $clientPhone = is_null($propertyCollection->getPhone()) ? '' : $propertyCollection->getPhone()->getValue();
        $orderNumber = (strpos($paykeeperUrl, '/create')) ? $orderNum : $orderNum . '|' . $serviceName;
        self::$pkObj = new PayKeeperPayment();
        self::$pkObj->setOrderTotal(floatval($orderSum));
        self::$pkObj->setOrderParams(
            self::$pkObj->getOrderTotal(),
            $clientId,
            $orderNumber,
            $clientEmail,
            $clientPhone,
            $serviceName,
            $paykeeperUrl,
            $this->getBusinessValue($payment, 'PAYKEEPER_SECRET')
        );

        $requestData = [
            'bank_id' => $bankId,
            'orderid' => $orderNumber,
            'sum' => $orderSum,

            'clientid' => $clientId,
            'client_email' => $clientEmail,
            'client_phone' => $clientPhone,
            'service_name' => $serviceName
        ];

        if ($this->getBusinessValue($payment, 'PAYKEEPER_CART') === 'Y') {
            $PayKeeperCart = (new PayKeeperCart(self::$pkObj))
                ->setConvertFullTaxRate($this->getBusinessValue($payment, 'PAYKEEPER_CONVERT_FULL_RATES') === 'Y')
                ->setDiscount($this->getBusinessValue($payment, 'PAYKEEPER_DISCOUNT') === 'Y')
                ->setBasketItemType(Utils::getItemTypeParamCart(
                    $this->getBusinessValue($payment, 'PAYKEEPER_BASKET_ITEM_TYPE')))
                ->setBasketPaymentType(Utils::getValueTypeParamCart(
                    $this->getBusinessValue($payment, 'PAYKEEPER_BASKET_PAYMENT_TYPE'), 'payment_type'))
                ->setDeliveryItemType(Utils::getItemTypeParamCart(
                    $this->getBusinessValue($payment, 'PAYKEEPER_DELIVERY_ITEM_TYPE')))
                ->setDeliveryPaymentType(Utils::getValueTypeParamCart(
                    $this->getBusinessValue($payment, 'PAYKEEPER_DELIVERY_PAYMENT_TYPE'), 'payment_type'))
                ->setBasketMeasure(Utils::getValueTypeParamCart(
                    $this->getBusinessValue($payment, 'PAYKEEPER_BASKET_MEASURE'), 'measure'))
                ->setVat(Utils::getValueTypeParamCart($this->getBusinessValue($payment, 'PAYKEEPER_VAT'), 'vat'))
                ->setVatPriority($this->getBusinessValue($payment, 'PAYKEEPER_VAT_PRIORITY') === 'Y')
                ->setVatDelivery(Utils::getValueTypeParamCart(
                    $this->getBusinessValue($payment, 'PAYKEEPER_VAT_DELIVERY'), 'vat'))
                ->setVatDeliveryPriority($this->getBusinessValue($payment, 'PAYKEEPER_VAT_DELIVERY_PRIORITY') === 'Y');
            if ($this->getBusinessValue($payment, 'PAYKEEPER_USE_FIXED_NAME_DELIVERY') === 'Y'
                && !empty($this->getBusinessValue($payment, 'PAYKEEPER_FIXED_NAME_DELIVERY')))
            {
                $PayKeeperCart->setDeliveryNameFixed($this->getBusinessValue($payment, 'PAYKEEPER_FIXED_NAME_DELIVERY'));
            }
            if ($this->getBusinessValue($payment, 'PAYKEEPER_TRU_CODE_USE') === 'Y'
                && !empty($this->getBusinessValue($payment, 'PAYKEEPER_TRU_CODE_NAME')))
            {
                $PayKeeperCart->setTruCodeName($this->getBusinessValue($payment, 'PAYKEEPER_TRU_CODE_NAME'));
            }
            $requestData['cart'] = $PayKeeperCart->processOrderCart($order);
        }

        $paykeeperLogin = $this->getBusinessValue($payment, 'PAYKEEPER_LK_LOGIN');
        $paykeeperPassword = $this->getBusinessValue($payment, 'PAYKEEPER_LK_PASSWORD');
        $paykeeperUrl = Utils::clearPaykeeperUrl($this->getBusinessValue($payment, 'PAYKEEPER_FORM_URL'));

        $PayKeeperApiService = new PayKeeperApiService($paykeeperUrl, $paykeeperLogin, $paykeeperPassword);
        $PayKeeperApiService::setLogging(self::$logging);
        $sendResult = $PayKeeperApiService::executeBinding($requestData);
        $sendResult = $sendResult[0] ?? $sendResult;
        $errorMessage = '';
        if (isset($sendResult['msg'])) {
            $errorMessage = "Заказ $orderId. Ошибка: {$sendResult['msg']}";
        } else if (!isset($sendResult['result'])
            || $sendResult['result'] != 'success'
            || !isset($sendResult['payment_id']))
        {
            $errorMessage = "Заказ $orderId. Неизвестная ошибка!";
        }
        if ($errorMessage) {
            Utils::paykeeperLogger([
                'TITLE' => 'PAYMENT_EXECUTE_BINDING_ERROR',
                'ERRORS' => $errorMessage
            ]);

            $paymentCollection = $order->getPaymentCollection();
            foreach ($paymentCollection as $paymentItem) {
                $currentPaymentId = $paymentItem->getId();
                if ($newPayment->getId() === $currentPaymentId) {
                    $deleteResult = $paymentItem->delete();
                    if (!$deleteResult->isSuccess()) {
                        $errorMessage = "Заказ $orderId. Ошибка удаления неудачного платежа $currentPaymentId: "
                            . implode(', ', $deleteResult->getErrorMessages());
                        Utils::paykeeperLogger([
                            'PAYMENT_DELETE_ERROR' => $errorMessage
                        ], self::$logging);
                    } else {
                        $order = $paymentItem->getCollection()->getOrder();
                    }
                }
            }

            $chargeFailAfterStatus = $this->getBusinessValue($payment, 'PAYKEEPER_CHARGE_FAIL_AFTER_STATUS');
            if ($chargeFailAfterStatus) {
                $order->setFieldNoDemand('STATUS_ID', $chargeFailAfterStatus);
            }
            $order->save();

            $result->addError(new Error($errorMessage));
            return $result;
        }
        $payment_id = $sendResult['payment_id'];
        $psData = [
            'PS_INVOICE_ID' => $payment_id,
            'PS_SUM' => $orderSum,
            'PS_CURRENCY' => '',
            'PS_STATUS' => 'Y',
            'PS_STATUS_DESCRIPTION' => 'Payment recurrent',
            'PS_STATUS_MESSAGE' => "PayKeeper Payment ID: $payment_id",
            'PS_RECURRING_TOKEN' => $bankId
        ];
        if ($payment->getField('PS_STATUS') === 'N' || $payment->getField('PS_STATUS') === 'H') {
            $psData['PS_STATUS_CODE'] = 'RECURRING';
        } else {
            $psData['PS_STATUS_CODE'] = 'RECURRENT';
        }
        $result->setPsData($psData);
        $newPayment->setFields($result->getPsData());
        $newPayment->setPaid("Y");
        $newPayment->save();

        $paymentCollection = $order->getPaymentCollection();
        foreach ($paymentCollection as $paymentItem) {
            $currentPaymentId = $paymentItem->getId();
            if (!$paymentItem->getField('PS_STATUS')
                || $paymentItem->getField('PS_STATUS') === 'N'
                || $paymentItem->getField('PS_STATUS') === 'C')
            {
                $deleteResult = $paymentItem->delete();
                if (!$deleteResult->isSuccess()) {
                    $errorMessage = "Заказ $orderId. Ошибка удаления платежа $currentPaymentId: "
                        . implode(', ', $deleteResult->getErrorMessages());
                    Utils::paykeeperLogger([
                        'PAYMENT_DELETE_ERROR' => $errorMessage
                    ], self::$logging);
                } else {
                    $order = $paymentItem->getCollection()->getOrder();
                }
            }
        }

        $chargeSuccessAfterStatus = $this->getBusinessValue($newPayment, 'PAYKEEPER_CHARGE_SUCCESS_AFTER_STATUS');
        if ($chargeSuccessAfterStatus) {
            $order->setFieldNoDemand('STATUS_ID', $chargeSuccessAfterStatus);
        }
        $order->save();

        return $result;
    }

    /**
     * Отмена привязки карты
     *
     * @param Payment $payment
     * @param Request|null $request
     * @return PaySystem\ServiceResult
     */
    public function cancelRecurrent(Payment $payment, Request $request = null): PaySystem\ServiceResult
    {
        $payment->setFields([
            'PS_RECURRING_TOKEN' => ''
        ]);
        return $payment->save();
    }

    /**
     * Признак привязанной карты
     *
     * @param Payment $payment
     * @return bool
     */
    public function isRecurring(Payment $payment): bool
    {
        return $payment->getField('PS_RECURRING_TOKEN');
    }

    /**
     * @param $order
     * @return array
     */
    private function _cart($order)
    {
        /* BASKET */
        $basket = $order->getBasket();
        $last_index = 0;

        $markingCodes = $this->getMarkingCodesFromShipments($order);

        foreach ($basket as $item) {
            $name = $item->getField('NAME');
            $quantity = $item->getField('QUANTITY');

            // For correctPrecision()
            if ($quantity == 1 && self::$pkObj->single_item_index < 0) {
                self::$pkObj->single_item_index = $last_index;
            }
            if ($quantity > 1 && self::$pkObj->more_then_one_item_index < 0) {
                self::$pkObj->more_then_one_item_index = $last_index;
            }

            $price = (float) $item->getField('PRICE');
            $vat_rate = $item->getField('VAT_RATE');
            if ($this->vat_priority && $this->vat) {
                $vat = $this->vat;
            } else if (!is_null($vat_rate)) {
                $vat = Utils::getVat($vat_rate * 100, false, $this->convert_full_tax_rate);
            } else {
                $vat = $this->vat ?: null;
            }

            self::$pkObj->updateFiscalCart(
                self::$pkObj->getPaymentFormType(),
                $name,
                $price,
                $quantity,
                0,
                $vat
            );
            self::$pkObj->fiscal_cart[$last_index]['item_type'] = $this->basket_item_type;
            self::$pkObj->fiscal_cart[$last_index]['payment_type'] = $this->basket_payment_type;
            self::$pkObj->fiscal_cart[$last_index]['measure'] = $this->basket_measure;

            // Ловим код свойства торгового предложения, если он задан
            if ($this->tru_code_name !== ''){
                // Проверяем, есть ли параметр в товаре
                // Если торговое предложение
                $productInfo = \CCatalogSKU::GetProductInfo($item->getField('PRODUCT_ID'));
                if (!$productInfo) {
                    // Если обычный товар
                    $productInfo = \CIBlockElement::GetByID($item->getField('PRODUCT_ID'))->GetNext();
                }
                if (is_array($productInfo)) {
                    $productProperty = \CIBlockElement::GetProperty($productInfo['IBLOCK_ID'], $productInfo['ID'], array(), array('CODE' => $this->tru_code_name));
                    if (($arProperty = $productProperty->GetNext()) && $arProperty['VALUE']) {
                        self::$pkObj->fiscal_cart[$last_index]['tru_code'] = $arProperty['VALUE'];
                    }
                }

                // Проверяем, есть ли параметр в торговом предложении (приоритет)
                $basketPropertyCollection = $item->getPropertyCollection();
                foreach ($basketPropertyCollection as $propertyItem) {
                    if ($propertyItem->getField('CODE') == $this->tru_code_name && $propertyItem->getField('VALUE')) {
                        self::$pkObj->fiscal_cart[$last_index]['tru_code'] = $propertyItem->getField('VALUE');
                        break;
                    }
                }
            }

            // Ловим код маркировки
            $productId = $item->getProductId();
            if (!empty($markingCodes[$productId])) {
                self::$pkObj->fiscal_cart[$last_index]['item_code'] = $markingCodes[$productId][0];
                self::$pkObj->fiscal_cart[$last_index]['item_type'] = Utils::getValueTypeParamCart(16, 'item_type');
            }

            $last_index++;
        }

        /* DELIVERY */
        if ($order->getDeliveryPrice() > 0) {
            $dbSaleDelivery = Sale\Delivery\Services\Manager::getById($order->getField('DELIVERY_ID'));
            $delivery_name = $this->delivery_name_fixed !== '' ? $this->delivery_name_fixed : $dbSaleDelivery['NAME'];
            $delivery_price = floatval($order->getDeliveryPrice());
            self::$pkObj->setShippingPrice($delivery_price);

            if (!self::$pkObj->checkDeliveryIncluded(self::$pkObj->getShippingPrice(), $delivery_name)) {
                $vatDelivery = \CCatalogVat::GetByID($dbSaleDelivery['VAT_ID'])->Fetch();
                if ($vatDelivery) {
                    if ($this->vat_delivery_priority && $this->vat_delivery) {
                        $delivery_vat = $this->vat_delivery;
                    } else if (!is_null($vatDelivery['RATE'])) {
                        $delivery_vat = Utils::getVat($vatDelivery['RATE'], false, $this->convert_full_tax_rate);
                    } else {
                        $delivery_vat = $this->vat_delivery ? $this->vat_delivery : null;
                    }
                } else {
                    $delivery_vat = $this->vat_delivery ? $this->vat_delivery : null;
                }
                self::$pkObj->setUseDelivery();
                self::$pkObj->updateFiscalCart(
                    self::$pkObj->getPaymentFormType(),
                    $delivery_name,
                    $delivery_price,
                    1,
                    0,
                    $delivery_vat
                );
                self::$pkObj->delivery_index = $last_index;
                self::$pkObj->fiscal_cart[$last_index]['item_type'] = $this->delivery_item_type;
                self::$pkObj->fiscal_cart[$last_index]['payment_type'] = $this->delivery_payment_type;
            }
        }

        /* DISCOUNTS */
        self::$pkObj->setDiscounts($this->discount || (floatval($order->getSumPaid()) > 0));

        // Correct possible difference between order sum and fiscal cart sum
        self::$pkObj->correctPrecision();

        // Encode fiscal cart to utf-8 for json_encode
        return array_map(function($item) {
            return array_map(function($value) {
                $enc = mb_detect_encoding($value, 'ASCII, UTF-8, windows-1251', true);
                return ($enc == 'UTF-8') ? $value : iconv($enc, 'UTF-8', $value);
            }, $item);
        }, self::$pkObj->getFiscalCart());
    }

    /**
     * Собираем коды маркировки из отгрузок
     *
     * @param $order
     * @return array
     */
    private function getMarkingCodesFromShipments($order): array
    {
        $markingCodes = [];

        try {
            foreach ($order->getShipmentCollection() as $shipment) {
                if ($shipment->isSystem()) {
                    continue;
                }

                foreach ($shipment->getShipmentItemCollection() as $shipmentItem) {
                    $productId = $shipmentItem->getBasketItem()->getProductId();

                    foreach ($shipmentItem->getShipmentItemStoreCollection() as $storeBarcode) {
                        if ($code = $storeBarcode->getField('MARKING_CODE')) {
                            $markingCodes[$productId][] = $code;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Utils::paykeeperLogger([
                'TITLE' => 'ERROR_MARKING_CODE',
                'ERROR' => $e->getMessage(),
            ], self::$logging);
        }

        return $markingCodes;
    }

    /**
     * @return array
     */
    public static function getIndicativeFields()
    {
        return array('id', 'sum', 'clientid', 'orderid', 'key');
    }
    /**
     * @param Request $request
     * @param $paySystemId
     * @return bool
     */
    static protected function isMyResponseExtended(Request $request, $paySystemId)
    {
        $service_name = '';
        $servicename_split = self::_getParseServiceName($request->get('orderid'));
        if (!$servicename_split) {
            $service_name = $request->get('service_name');
            $servicename_split = self::_getParseServiceName($service_name);
        }
        $service_name = is_array($servicename_split) ? end($servicename_split) : $service_name;
        if ($service_name != Utils::$serviceInitName) {
            echo 'The service_name is incorrect!';
            return false;
        }

        $payment_id = self::_getRequestPaymentId($request);
        if (!$payment_id) {
            echo 'Payment ID not found';
            return false;
        }

        $dbRes = Payment::getList([
            'filter' => ['ID' => $payment_id],
            'select' => ['*'] // Минимально необходимые поля
        ]);
        if (!$paymentData = $dbRes->fetch()) {
            echo 'Payment not found';
            return false;
        }

        $order = Sale\Order::load($paymentData['ORDER_ID']);
        if(!$order) {
            echo 'Order not found';
            return false;
        }

        if ($request->get('action') == 'paykeeper_cancel') {
            $order->setField('COMMENTS', 'CANCELLED');
            $order->save();
            echo 'Order was cancelled.';
            return false;
        }

        $paymentIds = $order->getPaymentSystemId();
        return in_array($paySystemId, $paymentIds);
    }
    /**
     * @param Request $request
     * @return mixed
     */
    public function getPaymentIdFromRequest(Request $request)
    {
        $payment_id = self::_getRequestPaymentId($request);
        if (!$payment_id) {
            echo 'Payment ID is empty';
            return null;
        }
        $dbRes = Payment::getList([
            'filter' => ['ID' => $payment_id],
            'select' => ['*'] // Минимально необходимые поля
        ]);
        if (!$paymentData = $dbRes->fetch()) {
            echo 'Payment data not found';
            return null;
        }
        return $paymentData['ID'];
    }

    /**
     * @param Payment $payment
     * @param Request $request
     * @return PaySystem\ServiceResult
     */
    public function processRequest(Payment $payment, Request $request)
    {
        $order = $payment->getCollection()->getOrder();
        if (!$order) {
            $order = Sale\Order::load($payment->getOrderId());
        }
        $bus_data = [
            'pay_system_id' => $payment->getField('PAY_SYSTEM_ID'),
            'person_type_id' => $order->getField('PERSON_TYPE_ID')
        ];

        self::$logging = self::_getBusVal('PAYKEEPER_LOGGING', $bus_data) === 'Y';

        Utils::paykeeperLogger([
            'TITLE' => 'PAYMENT_NOTIFICATION',
            'URL' => '/bitrix/tools/sale_ps_result.php',
            'METHOD' => 'NOTIFICATION',
            'RESPONSE' => $request->getPostList()->toArray()
        ], self::$logging);

        $result = new PaySystem\ServiceResult();

        $payment_id = (int) $request->get('id');
        $clientid = $request->get('clientid');
        $sum = (float) $request->get('sum');
        $ordernum = $request->get('orderid');
        $sign = $request->get('key');
        $bank_id = $request->get('bank_id') ?: null;
        $batch_date = $request->get('batch_date') ?: null;
        $payFinished = false;
        $confirmed = false;

        $sum_format = number_format($sum, 2, '.', '');
        if ($sum_format !== number_format($payment->getSum(), 2, '.', '')) {
            echo 'The sums are not equal!';
            return $result;
        }

        $secret = self::_getBusVal('PAYKEEPER_SECRET', $bus_data);
        $hash = md5($payment_id . $sum_format . $clientid . $ordernum . $secret);
        if (!hash_equals($hash, $sign)) {
            echo 'Hash mismatch';
            return $result;
        }

        try {
            $orderTable = OrdersTable::getByBitrixid($order->getId());
            $payFinished = isset($orderTable['PAYFINISHED']) && $orderTable['PAYFINISHED'] === 'Y';
            $confirmed = isset($orderTable['CONFIRMED']) && $orderTable['CONFIRMED'] === 'Y';
        } catch (\Exception $e) {
            Utils::paykeeperLogger([
                'TITLE' => 'ERROR_GET_ORDERSTABLE',
                'ERROR' => $e->getMessage()
            ], self::$logging);
        }
        $orderData = [
            'BX_USER_ID' => $order->getUserId(),
            'BX_ORDER_ID' => $payment->getOrderId(),
            'BX_PAYMENT_ID' => $payment->getId(),
            'PK_PAYMENT_ID' => $payment_id,
            'PK_SUM' => $sum_format,
            'PK_CLIENT_ID' => $clientid,

            'SUM' => $order->getPrice(),
            'PAYMENT_SCHEME' => '1',
            'STATUS' => 'PAYED'
        ];

        if ($bank_id) {
            $orderData['PK_BANK_ID'] = $bank_id;
        }

        if (self::_getBusVal('PAYKEEPER_HOLD_FUNDS_ENABLE', $bus_data) === 'Y') {
            $orderData['PAYMENT_SCHEME'] = '2';
        }

        $orderData['PAYFINISHED'] = $payFinished ? 'Y' : 'N';
        $orderData['CONFIRMED'] = $confirmed ? 'Y' : 'N';


        $mapping = [
            'service_name' => 'PK_SERVICE_NAME',
            'client_email' => 'PK_CLIENT_EMAIL',
            'client_phone' => 'PK_CLIENT_PHONE',
            'ps_id' => 'PK_PS_ID',
            'batch_date' => 'PK_BATCH_DATE',
            'fop_receipt_key' => 'PK_FOP_RECEIPT_KEY',
            'bank_payer_id' => 'PK_BANK_PAYER_ID',
            'card_number' => 'PK_CARD_NUMBER',
            'card_holder' => 'PK_CARD_HOLDER',
            'card_expiry' => 'PK_CARD_EXPIRY',
            'bank_operation_datetime' => 'PK_BANK_OPERATION_DATETIME'
        ];
        foreach ($mapping as $requestKey => $fieldName) {
            if ($request->get($requestKey)) {
                $orderData[$fieldName] = $request->get($requestKey);
            }
        }
        try {
            OrdersTable::addOrUpdate($orderData);
        } catch (\Exception $e) {
            Utils::paykeeperLogger([
                'TITLE' => 'ERROR_ADD_OR_UPDATE_ORDERSTABLE',
                'ERROR' => $e->getMessage()
            ], self::$logging);
        }

        if ($bank_id) {
            $cardTokensData = [
                'BX_USER_ID' => $order->getUserId(),
                'PK_BANK_ID' => $bank_id,
            ];
            $mapping = [
                'card_number' => 'PK_CARD_NUMBER',
                'card_expiry' => 'PK_CARD_EXPIRY',
            ];
            foreach ($mapping as $requestKey => $fieldName) {
                if ($request->get($requestKey)) {
                    $cardTokensData[$fieldName] = $request->get($requestKey);
                }
            }
            try {
                CardTokensTable::addOrUpdate($cardTokensData);
            } catch (\Exception $e) {
                Utils::paykeeperLogger([
                    'TITLE' => 'ERROR_ADD_OR_UPDATE_CARDTOKENSTABLE',
                    'ERROR' => $e->getMessage()
                ], self::$logging);
            }
        }

        $fieldsPs = [
            'PS_INVOICE_ID' => $payment_id,
            'PS_STATUS_MESSAGE' => "PayKeeper Payment ID: $payment_id",
            'PS_SUM' => $sum,
            'PS_CURRENCY' => ''
        ];
        if ($bank_id) {
            $fieldsPs['PS_RECURRING_TOKEN'] = $bank_id;
        }
        $psStatusCode = $payment->getField('PS_STATUS_CODE');
        if ($psStatusCode === 'RECURRENT' || $psStatusCode === 'RECURRING') {
            $fieldsPs['PS_STATUS'] = 'Y';
            $fieldsPs['PS_STATUS_CODE'] = 'RECURRENT';
            $fieldsPs['PS_STATUS_DESCRIPTION'] = 'Payment CIT accepted';
            if ($psStatusCode === 'RECURRING') {
                $fieldsPs['PS_STATUS_DESCRIPTION'] = 'Payment MIT accepted after authorized';
            }
        } else if ($batch_date) {
            $fieldsPs['PS_STATUS'] = 'H';
            $fieldsPs['PS_STATUS_CODE'] = 'HOLD';
            $fieldsPs['PS_STATUS_DESCRIPTION'] = 'Payment authorized';
            if ($bank_id) {
                $fieldsPs['PS_STATUS_DESCRIPTION'] = 'Payment authorized with card linking';
            }
        } else {
            $fieldsPs['PS_STATUS'] = 'Y';
            $fieldsPs['PS_STATUS_CODE'] = 'SUCCESS';
            $fieldsPs['PS_STATUS_DESCRIPTION'] = 'Payment accepted';
        }
        $result->setOperationType(PaySystem\ServiceResult::MONEY_COMING);
        $result->setPsData($fieldsPs);

        if (!$result->isSuccess()) {
            echo 'Error set paysystem data';
            return $result;
        }

        if (self::_getBusVal('PAYKEEPER_HOLD_FUNDS_ENABLE', $bus_data) === 'Y') {
            if ($payFinished || $psStatusCode === 'RECURRING') {
                $status_id_after_payment = self::_getBusVal('PAYKEEPER_CHARGE_SUCCESS_AFTER_STATUS', $bus_data);
            } else {
                $status_id_after_payment = self::_getBusVal('PAYKEEPER_HOLD_FUNDS_AFTER_STATUS', $bus_data);
            }
        } else {
            $status_id_after_payment = self::_getBusVal('PAYKEEPER_STATUS_AFTER_PAYMENT', $bus_data);
        }

        if (!$status_id_after_payment) {
            echo 'Error change order status. Status is empty.';
            return $result;
        }

        $arr_status = array();
        $dbStatusList = \CSaleStatus::GetList(
            ['SORT' => 'ASC'],
            ['LID' => LANGUAGE_ID],
            false,
            false,
            ['ID', 'NAME', 'SORT']
        );
        while ($itemStatus = $dbStatusList->GetNext()) {
            $arr_status[$itemStatus['ID']] = '[' . $itemStatus['ID'] . '] ' . $itemStatus['NAME'];
        }
        if (!array_key_exists($status_id_after_payment, $arr_status)) {
            echo 'Error change order status. Status not found.';
            return $result;
        }
        // Меняем статус заказа, если он корректный и суммы совпадают
        $order->setField('STATUS_ID', $status_id_after_payment);

        $result_s = $order->save();

        if (!$result_s->isSuccess()) {
            // Если операция сохранения заказа не удалась
            $errors = $result_s->getErrors(); // Получаем массив ошибок
            $text_error = 'Error save order';
            foreach ($errors as $error) {
                $text_error .= PHP_EOL . $error->getMessage();
            }
            echo $text_error;

            Utils::paykeeperLogger([
                'TITLE' => 'PAYMENT_NOTIFICATION',
                'URL' => '/bitrix/tools/sale_ps_result.php',
                'METHOD' => 'SAVE_ORDER',
                'RESPONSE' => $text_error
            ], self::$logging);

            return $result;
        }

        // Разрешаем несистемную отгрузку, если опция отмечена
        if (self::_getBusVal('PAYKEEPER_SHIPMENT', $bus_data) === 'Y') {
            $shipmentCollection = $order->getShipmentCollection();
            foreach ($shipmentCollection as $shipment){
                if (!$shipment->isSystem()) {
                    $shipment->allowDelivery();
                }
            }
        }

        echo 'OK ' . md5($payment_id . $secret);
        return $result;
    }

    /**
     * @return array
     */
    public function getCurrencyList()
    {
        return array('RUB');
    }

    /**
     * @param Request $request
     * @return Sale\Order
     */
    private static function _getOrderFromRequest(Request $request)
    {
        $ordernum = $request->get('orderid');
        $ordernum_split = self::_getParseServiceName($ordernum);
        if (is_array($ordernum_split)) {
            $ordernum = $ordernum_split[0];
        }
        $order = is_numeric($ordernum) ? Sale\Order::load($ordernum) : false;
        if (!$order) {
            $order = Sale\Order::loadByAccountNumber($ordernum);
        }
        return $order;
    }

    /**
     * Sends a request to the specified URL using cURL or HttpClient depending on availability.
     *
     * @param string $url The URL to send the request to.
     * @param array $data The data to send with the request.
     * @param array $headers Optional. Additional headers to include in the request. Default is an empty array.
     * @param string $datatype Optional. The format of the data, such as 'json'. Default is 'json'.
     * @return array|mixed
     */
    private function _Send($url, $data, $headers = [], $datatype = 'json')
    {

        global $APPLICATION;

        if (mb_strtoupper(SITE_CHARSET) != 'UTF-8') {
            $data = $APPLICATION->ConvertCharsetArray($data, 'windows-1251', 'UTF-8');
        }

        if(function_exists('curl_init')) {
            $response = $this->_SendCurl($url, $data, $headers, $datatype);
        } else {
            $response = $this->_SendHttpClient($url, $data, $headers, $datatype);
        }

        return $response;
    }

    /**
     * Sends an HTTP request using the HttpClient class.
     *
     * @param string $url The URL to send the request to.
     * @param array $data The data to send with the request. Can be null for GET requests.
     * @param array $headers Additional headers to include in the request.
     * @param string $datatype The format of the response data, e.g., 'json'. Default is 'json'.
     * @return array
     */
    private function _SendHttpClient($url, $data, $headers, $datatype)
    {
        global $APPLICATION;

        $httpClient = new Web\HttpClient();
        $httpClient->setCharset('utf-8');
        $httpClient->disableSslVerification();
        foreach ($headers as $param => $value) {
            $httpClient->setHeader($param, $value);
        }
        if ($data) {
            $httpClient->post($url, $data);
        } else {
            $httpClient->get($url);
        }

        $response =  $httpClient->getResult();
        if ($datatype == 'json' && $this->_isJson($response)) {
            $response =  Web\Json::decode($response);
        } else if (empty($httpClient->getError())) {
            $response = array(
                'status' => 'success',
                'msg' => $response
            );
        } else {
            $response = array(
                'errorCode' => 999,
                'errorMessage' => 'Server not available',
            );
        }

        if (mb_strtoupper(SITE_CHARSET) != 'UTF-8') {
            $APPLICATION->ConvertCharsetArray($response, 'UTF-8', 'windows-1251');
        }

        return $response;
    }

    /**
     * Sends an HTTP request using cURL.
     *
     * @param string $url The URL to send the request to.
     * @param array $data The data to send with the request. Can be null for GET requests.
     * @param array $headers Additional headers to include in the request.
     * @param string $datatype The format of the response data, such as 'json'. Default is 'json'.
     * @return array|mixed
     */
    private function _SendCurl($url, $data, $headers, $datatype)
    {
        $curl_opt = array(
            CURLOPT_VERBOSE => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER =>false,
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false
        );
        $set_headers = [];
        foreach ($headers as $param => $value) {
            $set_headers[] = "$param: $value";
        }
        if ($set_headers) {
            $curl_opt[CURLOPT_HTTPHEADER] = $set_headers;
        }
        if ($data) {
            $curl_opt[CURLOPT_POST] = true;
            $curl_opt[CURLOPT_POSTFIELDS] = http_build_query($data);
        }
        $ch = curl_init();
        curl_setopt_array($ch, $curl_opt);
        $response = curl_exec($ch);
        $error = curl_error($ch);

        if ($datatype == 'json' && $this->_isJson($response)) {
            $response = json_decode($response, true);
        } else if (empty($error)) {
            $response = array(
                'result' => 'success',
                'msg' => $response
            );
        } else {
            $response = array(
                'errorCode' => 999,
                'errorMessage' => curl_error($ch),
            );
        }
        curl_close($ch);

        return $response;
    }

    /**
     * Checks if the given data is valid JSON.
     *
     * @param string $json_data The JSON data to validate.
     * @param bool $return_data Optional. If true, returns the decoded JSON data. Default is false.
     * @return bool|mixed
     */
    private function _isJson($json_data, $return_data = false)
    {
        $data = json_decode($json_data);
        return (json_last_error() == JSON_ERROR_NONE) ? ($return_data ? $data : true) : false;
    }

    /**
     * Parses the service name from the given text.
     *
     * This method checks if the specified service name exists within the text.
     * If found, it splits the text by the '|' character and returns an array of parts.
     *
     * @param string $text The text to be parsed for the service name.
     *
     * @return array|false Returns an array of parsed text parts if the service name is found,
     *                     otherwise returns false.
     */
    private static function _getParseServiceName($text)
    {
        if (strpos($text, Utils::$serviceInitName) !== false) {
            return explode('|', $text);
        }
        return false;
    }

    /**
     * Retrieves the business parameter value for the payment system.
     *
     * @param string $param The name of the parameter whose value is being retrieved.
     * @param array $busData
     * @return mixed Returns the business parameter value if found; otherwise, the default value
     *               obtained via the `getBusinessValue()` method.
     */
    private static function _getBusVal($param, $busData)
    {
        return Sale\BusinessValue::get(
            $param,
            PaySystem\Service::PAY_SYSTEM_PREFIX . $busData['pay_system_id'],
            $busData['person_type_id']
        );
    }

    private static function _getRequestPaymentId(Request $request)
    {
        $servicename_split = self::_getParseServiceName($request->get('orderid'));
        if (!$servicename_split) {
            $servicename_split = self::_getParseServiceName($request->get('service_name'));
        }
        return is_array($servicename_split) && count($servicename_split) > 1
            ? $servicename_split[count($servicename_split) - 2]
            : 0;
    }
}