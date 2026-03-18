<?php

use \Bitrix\Sale\Order;
use \Bitrix\Sale\PaySystem;
use \Bitrix\Main\Web\HttpClient;
use \Bitrix\Main\Web\Json;
use \Bitrix\Main\Event;
use \Bitrix\Main\EventResult;
use \Bitrix\Main\EventManager;
use \Bitrix\Main\Loader;
use \Bitrix\Main\Context;

use \Bitrix\Main\Application;
use \Bitrix\Main\Entity;

use \Bitrix\Sale\Internals\PaymentTable;
use \Bitrix\Sale\Internals\PayableItemTable;

EventManager::getInstance()->addEventHandler(
    'sale',
    'OnSaleStatusOrderChange',
    [__NAMESPACE__ . '\PaykeeperEventHandler','OnSaleStatusOrderSaved']
);

include_once __DIR__ . '/lib/Utils.php';
include_once __DIR__ . '/lib/PayKeeperApiService.php';
require_once __DIR__ . '/lib/PayKeeperPayment.php';
require_once __DIR__ . '/lib/PayKeeperCart.php';

/**
 * Class PaykeeperEventHandler
 * Handles events and actions related to Paykeeper integration.
 */
class PaykeeperEventHandler
{
    private static $logging = false;
    private static $errorData = [];
    private static $paramsLog = [];
    private static $paySystemId;
    private static $personTypeId;
    private static $paykeeperUrl;

    /**
     * Event handler for the OnSaleStatusOrderSaved event.
     *
     * @param $event
     * @return EventResult
     */
    public static function OnSaleStatusOrderSaved($event)
    {
        $order = $event->getParameter("ENTITY");

        self::$paySystemId = $order->getField('PAY_SYSTEM_ID');
        self::$personTypeId = $order->getField('PERSON_TYPE_ID');

        // Получаем ID платежа РК
        $paymentCollection = $order->getPaymentCollection();
        $payment = null;
        foreach ($paymentCollection as $paymentElement) {
            if ($paymentElement->getField('PAY_SYSTEM_ID') != self::$paySystemId
                || !Utils::isThisSystemID(self::$paySystemId))
            {
                continue;
            }
            if ($paymentElement->getField('PS_STATUS') !== 'N') {
                $payment = $paymentElement;
                break;
            }
        }

        if (!$payment) {
            Utils::paykeeperLogger([
                'TITLE' => 'EVENT_STOP',
                'MESSAGE' => __METHOD__ . ':' . __LINE__ . ' Payment not found'
            ]);
            return self::getSuccessEventResult();
        }

        $orderAmount = $order->getPrice();
        $paymentAmount = $payment->getField('PS_SUM');
//        $paymentAmount = $payment->getSum();
        $order_status_id = $order->getField('STATUS_ID');

        Utils::paykeeperLogger([
            'TITLE' => 'EVENT_START',
            'MESSAGE' => __METHOD__ . ':' . __LINE__,
            'ORDER_ID' => $order->getId(),
            'PAYMENT_ID' => $payment->getId(),
            'PAY_SYSTEM_ID' => self::$paySystemId,
            'PERSON_TYPE_ID' => self::$personTypeId,
            'ORDER_SUM' => $orderAmount,
            'PS_INVOICE_ID' => $payment->getField('PS_INVOICE_ID'),
            'PS_SUM' => $payment->getField('PS_SUM'),
            'PS_STATUS' => $payment->getField('PS_STATUS'),
            'PAYMENT_SUM' => $payment->getSum(),
            'ORDER_IS_PAID' => $order->isPaid() ? 'Y' : 'N',
            'ORDER_STATUS' => $order_status_id,
        ]);

        $paykeeperLogin = self::getBusVal('PAYKEEPER_LK_LOGIN');
        $paykeeperPassword = self::getBusVal('PAYKEEPER_LK_PASSWORD');
        self::$paykeeperUrl = Utils::clearPaykeeperUrl(self::getBusVal('PAYKEEPER_FORM_URL'));
        self::$logging = (self::getBusVal('PAYKEEPER_LOGGING') === 'Y');

        if (!self::$paykeeperUrl || !$paykeeperLogin || !$paykeeperPassword) {
            Utils::paykeeperLogger([
                'TITLE' => 'EVENT_STOP',
                'MESSAGE' => 'Не хватает данных для запуска события',
            ]);
            return self::getSuccessEventResult();
        }

        $paykeeper_hold_funds_enable = self::getBusVal('PAYKEEPER_HOLD_FUNDS_ENABLE') === 'Y';
        $paykeeper_print_final_receipt = self::getBusVal('PAYKEEPER_PRINT_FINAL_RECEIPT') === 'Y';
        $paykeeper_charge_part_enable = self::getBusVal('PAYKEEPER_CHARGE_PART_ENABLE') === 'Y';
        $paykeeper_cof_next_enable = self::getBusVal('PAYKEEPER_ENABLE_COF_NEXT') === 'Y';

        $paykeeper_hold_debit_after_status = self::getBusVal('PAYKEEPER_HOLD_DEBIT_AFTER_STATUS');
        $paykeeper_hold_cancel_after_status = self::getBusVal('PAYKEEPER_HOLD_CANCEL_AFTER_STATUS');
        $paykeeper_print_after_status = self::getBusVal('PAYKEEPER_PRINT_AFTER_STATUS');

        $chargeSuccessAfterStatus = self::getBusVal('PAYKEEPER_CHARGE_SUCCESS_AFTER_STATUS');
        $chargeFailAfterStatus = self::getBusVal('PAYKEEPER_CHARGE_FAIL_AFTER_STATUS');
        $thresholdChargePart = self::getBusVal('PAYKEEPER_THRESHOLD_CHARGE_PART');

        $paykeeper_is_receipt = $paykeeper_print_final_receipt
            && $paykeeper_print_after_status == $order_status_id;
        $paykeeper_is_hold_debit = $paykeeper_hold_funds_enable && !$paykeeper_charge_part_enable
            && $paykeeper_hold_debit_after_status == $order_status_id;
        $paykeeper_is_hold_cancel = $paykeeper_hold_funds_enable
            && $paykeeper_hold_cancel_after_status == $order_status_id;
        $paykeeper_is_charge_part = $paykeeper_hold_funds_enable && $paykeeper_charge_part_enable
            && $paykeeper_hold_debit_after_status === $order_status_id;

        if (!$paykeeper_is_receipt
            && !$paykeeper_is_hold_debit
            && !$paykeeper_is_hold_cancel
            && !$paykeeper_is_charge_part)
        {
            Utils::paykeeperLogger([
                'TITLE' => 'EVENT_STOP',
                'MESSAGE' => 'Нет условий для запуска события',
            ]);
            return self::getSuccessEventResult();
        }

        if (!$chargeSuccessAfterStatus || !$chargeFailAfterStatus) {
            $infoLog = 'Необходимо настроить статусы заказа в модуле!';
            Utils::paykeeperLogger([
                'TITLE' => 'EVENT_STOP',
                'MESSAGE' => $infoLog
            ], self::$logging);
            self::$errorData[] = [
                'event_code' => 'STATUSES',
                'log_name' => 'ПРОВЕРКА СТАТУСОВ',
                'log_info' => $infoLog
            ];
            foreach (self::$errorData as $error) {
                \CAdminNotify::Add([
                    'TAG' => "PAYKEEPER_{$order->getId()}_{$error['event_code']}",
                    'MESSAGE' => "{$error['log_name']} PAYKEEPER. {$error['log_info']}",
                    'NOTIFY_TYPE' => 'E'
                ]);
            }
            return self::getSuccessEventResult();
        }

        if (!self::checkStatusesInDB([$chargeSuccessAfterStatus, $chargeFailAfterStatus])) {
            $infoLog = 'Необходимо настроить статусы заказа в магазине!';
            Utils::paykeeperLogger([
                'TITLE' => 'EVENT_STOP',
                'MESSAGE' => $infoLog
            ], self::$logging);
            self::$errorData[] = [
                'event_code' => 'STATUSES',
                'log_name' => 'ПРОВЕРКА СТАТУСОВ',
                'log_info' => $infoLog
            ];
            foreach (self::$errorData as $error) {
                \CAdminNotify::Add([
                    'TAG' => "PAYKEEPER_{$order->getId()}_{$error['event_code']}",
                    'MESSAGE' => "{$error['log_name']} PAYKEEPER. {$error['log_info']}",
                    'NOTIFY_TYPE' => 'E'
                ]);
            }
            return self::getSuccessEventResult();
        }

        Utils::initDB();

        if ($orderAmount == $paymentAmount
            && ( !$order->isPaid() || ($payment->getField('PS_STATUS') === 'Y' && !$paykeeper_is_receipt)) )
        {
            try {
                $orderTable = OrdersTable::getByBitrixid($order->getId());
            } catch (\Exception $e) {
                Utils::paykeeperLogger([
                    'TITLE' => 'EVENT_STOP',
                    'MESSAGE' => 'ERROR_GET_ORDERSTABLE',
                    'ERROR' => $e->getMessage()
                ], self::$logging);
            }

            if (isset($orderTable['PAYFINISHED'])
                && $orderTable['PAYFINISHED'] === 'N'
                && ($paykeeper_is_hold_debit || $paykeeper_is_charge_part)
                && $payment->getField('PS_STATUS_CODE') === 'RECURRENT')
            {
                $order->setField('STATUS_ID', $chargeSuccessAfterStatus);

                $resultSaveOrder = $order->save();

                if (!$resultSaveOrder->isSuccess()) {
                    // Если операция сохранения заказа не удалась
                    $errors = $resultSaveOrder->getErrors(); // Получаем массив ошибок
                    $text_error = 'Ошибка сохранения заказа';
                    foreach ($errors as $error) {
                        $text_error .= PHP_EOL . $error->getMessage();
                    }
                    echo $text_error;

                    Utils::paykeeperLogger([
                        'TITLE' => 'EVENT_STOP',
                        'ERROR' => $text_error
                    ], self::$logging);

                    return self::getSuccessEventResult();
                }

                Utils::paykeeperLogger([
                    'TITLE' => 'ORDER_SAVED',
                    'MESSAGE' => "Статус заказа успешно изменён на $chargeSuccessAfterStatus",
                ], self::$logging);

                $orderData = [
                    'BX_ORDER_ID' => $order->getId(),
                    'PAYFINISHED' => 'Y'
                ];
                try {
                    OrdersTable::addOrUpdate($orderData);
                } catch (\Exception $e) {
                    Utils::paykeeperLogger([
                        'TITLE' => 'EVENT_STOP',
                        'MESSAGE' => 'ERROR_ADD_OR_UPDATE_ORDERSTABLE',
                        'ERROR' => $e->getMessage()
                    ], self::$logging);
                }
                return self::getSuccessEventResult();
            }

            Utils::paykeeperLogger([
                'TITLE' => 'ORDER_SAVED',
                'MESSAGE' => 'Заказ не оплачен, либо оплата завершена. Выполнение события отклонено.',
            ]);
            return self::getSuccessEventResult();
        }

        $orderId = $order->getId();
        $userId = $order->getUserId();
        $invoiceId = $payment->getField('PS_INVOICE_ID');

        /** @var \Sale\Handlers\PaySystem\PayKeeperHandler $service */
        $service = PaySystem\Manager::getObjectById(self::$paySystemId);

        // Hold charge (classic)
        if ($paykeeper_is_hold_debit) {
            $resultConfirm = $service->confirm($payment);
            self::$paramsLog[] = [
                'SEVERITY' => 'INFO',
                'AUDIT_TYPE_ID' => 'Списание средств по ранее проведённой авторизации PayKeeper',
                'MODULE_ID' => 'sale',
                'ITEM_ID' => "API запрос /change/payment/capture/",
                'DESCRIPTION' => "Заказ $orderId. Списание средств!"
            ];
            if (!$resultConfirm->isSuccess()) {
                self::$errorData[] = [
                    'event_code' => 'CAPTURE',
                    'event_name' => 'onHoldDebitPaykeeper',
                    'log_name' => 'СПИСАНИЕ СРЕДСТВ',
                    'log_info' => implode(', ', $resultConfirm->getErrorMessages())
                ];
            } else {
                try {
                    OrdersTable::addOrUpdate([
                        'BX_ORDER_ID' => $orderId,
                        'PAYFINISHED' => 'Y'
                    ]);
                } catch (\Exception $e) {
                    Utils::paykeeperLogger([
                        'TITLE' => 'ERROR_ADD_OR_UPDATE_ORDERSTABLE',
                        'ERROR' => $e->getMessage()
                    ], self::$logging);
                }
            }
        }

        // Payment hold operations (partial refund, full capture, or cancellation with recurring charge)
        if ($paykeeper_is_charge_part) {
            // Определяем сценарий списания
            $isCOFNext = CardTokensTable::getCardToken($order->getUserId())
                && $paykeeper_cof_next_enable
                && $payment->getField('PS_STATUS') !== 'H';
            $actionPayment = self::determineActionPayment($isCOFNext, $orderAmount, $paymentAmount);

            Utils::paykeeperLogger([
                'TITLE' => 'INIT_ACTION_PAYMENT',
                'ACTION_PAYMENT' => $actionPayment
            ], self::$logging);

            switch ($actionPayment) {
                case 'COF_PARTIAL_REFUND':
                    $refundableSum = $paymentAmount - $orderAmount;
                    $resultRefund = $service->refund($payment, $refundableSum);
                    self::$paramsLog[] = [
                        'SEVERITY' => 'INFO',
                        'AUDIT_TYPE_ID' => 'Частичный возврат средств на ранее привязанную карту PayKeeper',
                        'MODULE_ID' => 'sale',
                        'ITEM_ID' => 'API запрос /change/payment/reverse/',
                        'DESCRIPTION' => "Заказ $orderId. Частичный возврат средств!",
                    ];

                    if (!$resultRefund->isSuccess()) {
                        self::$errorData[] = [
                            'event_code' => 'PARTREFUND',
                            'event_name' => 'onCOFPartReversePaykeeper',
                            'log_name' => 'ЧАСТИЧНЫЙ ВОЗВРАТ СРЕДСТВ',
                            'log_info' => implode(', ', $resultRefund->getErrorMessages())
                        ];
                        $order = Order::load($payment->getOrderId());
                        $order->setFieldNoDemand('STATUS_ID', $chargeFailAfterStatus);
                        $order->save();
                    } else {
                        $order = Order::load($payment->getOrderId());
                        $order->setFieldNoDemand('STATUS_ID', $chargeSuccessAfterStatus);
                        $order->save();

                        try {
                            OrdersTable::addOrUpdate([
                                'BX_ORDER_ID' => $orderId,
                                'PAYFINISHED' => 'Y'
                            ]);
                        } catch (\Exception $e) {
                            Utils::paykeeperLogger([
                                'TITLE' => 'ERROR_ADD_OR_UPDATE_ORDERSTABLE',
                                'ERROR' => $e->getMessage()
                            ], self::$logging);
                        }
                    }
                    break;

                case 'COF_NO_ACTION':
                    $order = Order::load($payment->getOrderId());
                    $order->setFieldNoDemand('STATUS_ID', $chargeSuccessAfterStatus);
                    $order->save();
                    break;

                case 'COF_CANCEL_WITH_RECURRING':
                    $thresholdChargePartSum = $paymentAmount ? intval((($orderAmount - $paymentAmount) / $paymentAmount) * 100) : 0;
                    $PayKeeperApiService = new PayKeeperApiService(self::$paykeeperUrl, $paykeeperLogin, $paykeeperPassword);
                    $PayKeeperApiService::setLogging(self::$logging);
                    $resultInfoPayment = $PayKeeperApiService::getInfoPayment($invoiceId);
                    $resultInfoPayment = $resultInfoPayment[0] ?? $resultInfoPayment;
                    $paymentStatus = $resultInfoPayment['status'] ?? '';

                    if ($paymentStatus !== 'refunding'
                        && $paymentStatus !== 'refunded'
                        && $paymentStatus !== 'partially_refunded')
                    {
                        $resultCancel = $service->cancel($payment);
                        self::$paramsLog[] = [
                            'SEVERITY' => 'INFO',
                            'AUDIT_TYPE_ID' => 'Полный возврат средств на ранее привязанную карту PayKeeper',
                            'MODULE_ID' => 'sale',
                            'ITEM_ID' => 'API запрос /change/payment/reverse/',
                            'DESCRIPTION' => "Заказ $orderId. Возврат средств PayKeeper"
                        ];
                    } else {
                        $resultCancel = new PaySystem\ServiceResult();
                    }

                    if (!$resultCancel->isSuccess()) {
                        self::$errorData[] = [
                            'event_code' => 'REVERSE',
                            'event_name' => 'onCOFReversePaykeeper',
                            'log_name' => 'ВОЗВРАТ СРЕДСТВ',
                            'log_info' => implode(', ', $resultCancel->getErrorMessages())
                        ];
                        $order = Order::load($payment->getOrderId());
                        $order->setFieldNoDemand('STATUS_ID', $chargeFailAfterStatus);
                        $order->save();
                    } else if ($thresholdChargePart == 0 || $thresholdChargePartSum <= $thresholdChargePart) {
                        $bankId = $payment->getField('PS_RECURRING_TOKEN');
                        if (!$bankId) {
                            $bankId = CardTokensTable::getCardToken($userId);
                            if ($bankId) {
                                $payment->setField('PS_RECURRING_TOKEN', $bankId);
                                $payment->save();
                            }
                        }
                        if (!$bankId) {
                            $errorMessage = "Заказ $orderId. Привязанная карта не найдена.";

                            Utils::paykeeperLogger([
                                'RECURRENT_ERROR' => $errorMessage
                            ], self::$logging);

                            $order = Order::load($payment->getOrderId());
                            $order->setFieldNoDemand('STATUS_ID', $chargeFailAfterStatus);
                            $order->save();

                            self::$errorData[] = [
                                'event_code' => 'BINDING',
                                'event_name' => 'onCOFBindingPaykeeper',
                                'log_name' => 'БЕЗАКЦЕПТНОЕ СПИСАНИЕ',
                                'log_info' => $errorMessage
                            ];
                        } else {
                            sleep(15);
                            try {
                                OrdersTable::addOrUpdate([
                                    'BX_ORDER_ID' => $orderId,
                                    'PAYFINISHED' => 'Y'
                                ]);
                            } catch (\Exception $e) {
                                Utils::paykeeperLogger([
                                    'TITLE' => 'ERROR_ADD_OR_UPDATE_ORDERSTABLE',
                                    'ERROR' => $e->getMessage()
                                ], self::$logging);
                            }
                            $resultRecurrent = $service->repeatRecurrent($payment);
                            self::$paramsLog[] = [
                                'SEVERITY' => 'INFO',
                                'AUDIT_TYPE_ID' => 'Безакцептное списание средств PayKeeper',
                                'MODULE_ID' => 'sale',
                                'ITEM_ID' => 'API запрос /change/binding/execute/',
                                'DESCRIPTION' => "Заказ $orderId. Безакцептное списание средств PayKeeper"
                            ];
                            if (!$resultRecurrent->isSuccess()) {
                                self::$errorData[] = [
                                    'event_code' => 'BINDING',
                                    'event_name' => 'onCOFBindingPaykeeper',
                                    'log_name' => 'БЕЗАКЦЕПТНОЕ СПИСАНИЕ',
                                    'log_info' => implode(', ', $resultRecurrent->getErrorMessages())
                                ];
                            }
                        }
                    }
                    break;

                case 'HOLD_PARTIAL_REFUND': // Сумма заказа < суммы холда: частичный возврат со списанием
                    $refundableSum = $paymentAmount - $orderAmount;
                    $resultRefund = $service->refund($payment, $refundableSum);
                    self::$paramsLog[] = [
                        'SEVERITY' => 'INFO',
                        'AUDIT_TYPE_ID' => 'Частичный возврат со списанием средств по ранее проведённой авторизации PayKeeper',
                        'MODULE_ID' => 'sale',
                        'ITEM_ID' => 'API запрос /change/payment/reverse/',
                        'DESCRIPTION' => "Заказ $orderId. Частичный возврат со списанием средств!",
                    ];

                    if (!$resultRefund->isSuccess()) {
                        self::$errorData[] = [
                            'event_code' => 'PARTCAPTURE',
                            'event_name' => 'onHoldPartDebitPaykeeper',
                            'log_name' => 'ЧАСТИЧНОЕ СПИСАНИЕ СРЕДСТВ',
                            'log_info' => implode(', ', $resultRefund->getErrorMessages())
                        ];
                        $order = Order::load($payment->getOrderId());
                        $order->setFieldNoDemand('STATUS_ID', $chargeFailAfterStatus);
                        $order->save();
                    } else {
                        $order = Order::load($payment->getOrderId());
                        $order->setFieldNoDemand('STATUS_ID', $chargeSuccessAfterStatus);
                        $order->save();

                        try {
                            OrdersTable::addOrUpdate([
                                'BX_ORDER_ID' => $orderId,
                                'PAYFINISHED' => 'Y',
                                'CONFIRMED' => 'Y'
                            ]);
                        } catch (\Exception $e) {
                            Utils::paykeeperLogger([
                                'TITLE' => 'ERROR_ADD_OR_UPDATE_ORDERSTABLE',
                                'ERROR' => $e->getMessage()
                            ], self::$logging);
                        }
                    }
                    break;

                case 'HOLD_FULL_CAPTURE': // Сумма заказа = сумме холда: полное списание по холду
                    $resultConfirm = $service->confirm($payment);
                    self::$paramsLog[] = [
                        'SEVERITY' => 'INFO',
                        'AUDIT_TYPE_ID' => 'Списание средств по ранее проведённой авторизации PayKeeper',
                        'MODULE_ID' => 'sale',
                        'ITEM_ID' => 'API запрос /change/payment/capture/',
                        'DESCRIPTION' => "Заказ $orderId. Списание средств!"
                    ];
                    if (!$resultConfirm->isSuccess()) {
                        self::$errorData[] = [
                            'event_code' => 'CAPTURE',
                            'event_name' => 'onHoldDebitPaykeeper',
                            'log_name' => 'СПИСАНИЕ СРЕДСТВ',
                            'log_info' => implode(', ', $resultConfirm->getErrorMessages())
                        ];
                    }
                    break;

                case 'HOLD_CANCEL_WITH_RECURRING': // Сумма заказа > суммы холда
                    $thresholdChargePartSum = $paymentAmount ? intval((($orderAmount - $paymentAmount) / $paymentAmount) * 100) : 0;
                    $PayKeeperApiService = new PayKeeperApiService(self::$paykeeperUrl, $paykeeperLogin, $paykeeperPassword);
                    $PayKeeperApiService::setLogging(self::$logging);
                    $resultInfoPayment = $PayKeeperApiService::getInfoPayment($invoiceId);
                    $resultInfoPayment = $resultInfoPayment[0] ?? $resultInfoPayment;
                    $paymentStatus = $resultInfoPayment['status'] ?? '';

                    if ($paymentStatus !== 'refunding'
                        && $paymentStatus !== 'refunded'
                        && $paymentStatus !== 'partially_refunded')
                    {
                        $resultCancel = $service->cancel($payment);
                        self::$paramsLog[] = [
                            'SEVERITY' => 'INFO',
                            'AUDIT_TYPE_ID' => 'Отмена авторизации средств PayKeeper',
                            'MODULE_ID' => 'sale',
                            'ITEM_ID' => 'API запрос /change/payment/reverse/',
                            'DESCRIPTION' => "Заказ $orderId. Отмена авторизации средств PayKeeper"
                        ];
                    } else {
                        $resultCancel = new PaySystem\ServiceResult();
                    }
                    if (!$resultCancel->isSuccess()) {
                        self::$errorData[] = [
                            'event_code' => 'REVERSE',
                            'event_name' => 'onHoldCancelPaykeeper',
                            'log_name' => 'ОТМЕНА АВТОРИЗАЦИИ',
                            'log_info' => implode(', ', $resultCancel->getErrorMessages())
                        ];
                        $order = Order::load($payment->getOrderId());
                        $order->setFieldNoDemand('STATUS_ID', $chargeFailAfterStatus);
                        $order->save();
                    } else if ($thresholdChargePart == 0 || $thresholdChargePartSum <= $thresholdChargePart) {
                        $bankId = $payment->getField('PS_RECURRING_TOKEN');
                        if (!$bankId) {
                            $bankId = CardTokensTable::getCardToken($userId);
                            if ($bankId) {
                                $payment->setField('PS_RECURRING_TOKEN', $bankId);
                                $payment->save();
                            }
                        }
                        if (!$bankId) {
                            $errorMessage = "Заказ $orderId. Привязанная карта не найдена.";

                            Utils::paykeeperLogger([
                                'RECURRENT_ERROR' => $errorMessage
                            ], self::$logging);

                            $order = Order::load($payment->getOrderId());
                            $order->setFieldNoDemand('STATUS_ID', $chargeFailAfterStatus);
                            $order->save();

                            self::$errorData[] = [
                                'event_code' => 'BINDING',
                                'event_name' => 'onHoldBindingPaykeeper',
                                'log_name' => 'БЕЗАКЦЕПТНОЕ СПИСАНИЕ',
                                'log_info' => $errorMessage
                            ];
                        } else {
                            sleep(15);
                            try {
                                OrdersTable::addOrUpdate([
                                    'BX_ORDER_ID' => $orderId,
                                    'PAYFINISHED' => 'Y'
                                ]);
                            } catch (\Exception $e) {
                                Utils::paykeeperLogger([
                                    'TITLE' => 'ERROR_ADD_OR_UPDATE_ORDERSTABLE',
                                    'ERROR' => $e->getMessage()
                                ], self::$logging);
                            }
                            $resultRecurrent = $service->repeatRecurrent($payment);
                            self::$paramsLog[] = [
                                'SEVERITY' => 'INFO',
                                'AUDIT_TYPE_ID' => 'Безакцептное списание средств PayKeeper',
                                'MODULE_ID' => 'sale',
                                'ITEM_ID' => 'API запрос /change/binding/execute/',
                                'DESCRIPTION' => "Заказ $orderId. Безакцептное списание средств PayKeeper"
                            ];
                            if (!$resultRecurrent->isSuccess()) {
                                self::$errorData[] = [
                                    'event_code' => 'BINDING',
                                    'event_name' => 'onHoldBindingPaykeeper',
                                    'log_name' => 'БЕЗАКЦЕПТНОЕ СПИСАНИЕ',
                                    'log_info' => implode(', ', $resultRecurrent->getErrorMessages())
                                ];
                            }
                        }
                    }
                    break;
            }
        }

        // Event Print Receipt
        if ($paykeeper_is_receipt) {
            $PayKeeperApiService = new PayKeeperApiService(self::$paykeeperUrl, $paykeeperLogin, $paykeeperPassword);
            self::generateFinalReceipt($invoiceId, $payment, $PayKeeperApiService);
        }

        // Event Hold Cancel
        if ($paykeeper_is_hold_cancel) {
            $resultCancel = $service->cancel($payment);
            if (!$resultCancel->isSuccess()) {
                self::$errorData[] = [
                    'event_code' => 'REVERSE',
                    'event_name' => 'onHoldCancelPaykeeper',
                    'log_name' => 'ОТМЕНА АВТОРИЗАЦИИ',
                    'log_info' => implode(', ', $resultCancel->getErrorMessages())
                ];
            }
            self::$paramsLog[] = [
                'SEVERITY' => 'INFO',
                'AUDIT_TYPE_ID' => 'Отмена авторизации средств PayKeeper',
                'MODULE_ID' => 'sale',
                'ITEM_ID' => 'API запрос /change/payment/reverse/',
                'DESCRIPTION' => "Заказ $orderId. Отмена авторизации средств PayKeeper"
            ];
        }

        // Добавляем запись в журнал событий об API-запросе
        if (!empty(self::$paramsLog)) {
            foreach (self::$paramsLog as $log) {
                \CEventLog::Add($log);
            }
        }

        // Добавляем всплывающее уведомление в административной панели
        if (!empty(self::$errorData)) {
            foreach (self::$errorData as $error) {
                self::triggerPaykeeperEventError($error['log_info'], $orderId, $error['event_name']);

                \CAdminNotify::Add([
                    'TAG' => "PAYKEEPER_{$orderId}_{$error['event_code']}",
                    'MESSAGE' => "{$error['log_name']} PAYKEEPER. {$error['log_info']}",
                    'NOTIFY_TYPE' => 'E'
                ]);
            }
        }

        return self::getSuccessEventResult();
    }

    /**
     * Инициирование генерации чека окончательного расчёта
     *
     * @param $invoiceId
     * @param $order
     * @param PayKeeperApiService $PayKeeperApiService
     * @return void
     */
    private static function generateFinalReceipt($invoiceId, $payment, $PayKeeperApiService)
    {
        $order = $payment->getCollection()->getOrder();
        $orderId = $order->getId();
        // Создаем новую группу свойств и свойство для чека окончательного расчета, если их нет
        $propertyCode = 'PAYKEEPER_RECEIPT_ID';
        $propertyName = 'Номер чека окончательного расчёта PayKeeper';
        self::createOrderPropertyForAllPersonTypes($propertyName, $propertyCode);

        /** @var \Bitrix\Sale\PropertyValueCollection $propertyCollection */
        $propertyCollection = $order->getPropertyCollection();
        $property = $propertyCollection->getItemByOrderPropertyCode($propertyCode);

        if ($property && !$property->getValue()) {
            $PayKeeperApiService::setLogging(self::$logging);
            $resultReceiptPayment = $PayKeeperApiService::receiptPayment($invoiceId);
            $resultReceiptPayment = $resultReceiptPayment[0] ?? $resultReceiptPayment;
            $errorFlag = false;
            if (isset($resultReceiptPayment['receipt_id'])) {
                $receiptId = $resultReceiptPayment['receipt_id'];
                $infoLog = "Заказ $orderId. ID чека $receiptId";

                // Записываем значение номера чека
                $property->setField('VALUE', $receiptId);
                $order->save();

                try {
                    OrdersTable::addOrUpdate([
                        'BX_ORDER_ID' => $orderId,
                        'PK_RECEIPT_ID' => $receiptId
                    ]);
                } catch (\Exception $e) {
                    Utils::paykeeperLogger([
                        'TITLE' => 'ERROR_ADD_OR_UPDATE_ORDERSTABLE',
                        'ERROR' => $e->getMessage()
                    ], self::$logging);
                }

            } else if (isset($resultReceiptPayment['msg'])) {
                $errorFlag = true;
                $infoLog = "Заказ $orderId. Ошибка: {$resultReceiptPayment['msg']}";
            } else {
                $errorFlag = true;
                $infoLog = "Заказ $orderId. Неизвестная ошибка!";
            }

            if ($errorFlag) {
                self::$errorData[] = [
                    'event_code' => 'RECEIPT',
                    'event_name' => 'onPrintCheckPaykeeper',
                    'log_name' => 'ГЕНЕРАЦИЯ ЧЕКА',
                    'log_info' => $infoLog
                ];
            }

            self::$paramsLog[] = [
                'SEVERITY' => 'INFO',
                'AUDIT_TYPE_ID' => 'Генерация чека окончательного расчёта PayKeeper',
                'MODULE_ID' => 'sale',
                'ITEM_ID' => 'API запрос /change/payment/post-sale-receipt/',
                'DESCRIPTION' => $infoLog,
            ];
        } else {
            Utils::paykeeperLogger([
                'TITLE' => 'PAYMENT_RECEIPT',
                'MESSAGE' => 'Receipt is already',
                'RECEIPT_ID' => $property->getValue()
            ], self::$logging);
        }
    }

    /**
     * Определение сценария списания
     *
     * @param $isCOFNext
     * @param $orderAmount
     * @param $holdAmount
     * @return string
     */
    private static function determineActionPayment($isCOFNext, $orderAmount, $holdAmount)
    {
        if ($isCOFNext) {
            if ($orderAmount < $holdAmount) {
                $paymentCase = 'COF_PARTIAL_REFUND';
            } elseif ($orderAmount == $holdAmount) {
                $paymentCase = 'COF_NO_ACTION';
            } else {
                $paymentCase = 'COF_CANCEL_WITH_RECURRING';
            }
        } else {
            if ($orderAmount < $holdAmount) {
                $paymentCase = 'HOLD_PARTIAL_REFUND';
            } elseif ($orderAmount == $holdAmount) {
                $paymentCase = 'HOLD_FULL_CAPTURE';
            } else {
                $paymentCase = 'HOLD_CANCEL_WITH_RECURRING';
            }
        }
        return $paymentCase;
    }

    /**
     * Creates an order property for all person types.
     *
     * This method checks if a property with a specific code exists for each person type in the system.
     * If not, it creates the property and assigns it to a property group.
     *
     * @param string $propertyName The name of the property to be created.
     * @param string $propertyCode The unique code of the property.
     * @return void
     */
    private static function createOrderPropertyForAllPersonTypes($propertyName, $propertyCode)
    {
        // Получаем все типы плательщиков
        $personTypes = [];
        $dbPersonTypes = \CSalePersonType::GetList( ['SORT' => 'ASC'], [] );
        while ($personType = $dbPersonTypes->Fetch()) {
            $personTypes[] = $personType['ID'];
        }

        foreach ($personTypes as $personTypeId) {
            // Проверяем, существует ли уже свойство с таким кодом для данного типа плательщика
            $dbProps = \CSaleOrderProps::GetList(
                [],
                [ 'CODE' => $propertyCode, 'PERSON_TYPE_ID' => $personTypeId ]
            );

            if ($dbProps->Fetch())
                continue;

            // Создаем новую группу свойства
            $groupExists = false;
            $propsGroupId = null;
            $nameGroup = 'Генерация чека окончательного расчета';
            $groupCheck = \CSaleOrderPropsGroup::GetList( [],
                [ 'NAME' => $nameGroup, 'PERSON_TYPE_ID' => $personTypeId ]
            );

            if ($group = $groupCheck->Fetch()) {
                $groupExists = true;
                $propsGroupId = $group['ID'];
            }

            if (!$groupExists) {
                $groupFields = [
                    'NAME' => $nameGroup,
                    'PERSON_TYPE_ID' => $personTypeId,
                    'SORT' => 500
                ];
                $propsGroupId = \CSaleOrderPropsGroup::Add($groupFields);
            }

            // Создаем новое свойство
            $propertyFields = [
                'NAME' => $propertyName,
                'TYPE' => 'STRING', // Тип свойства: STRING, NUMBER, FILE, etc.
                'REQUIRED' => 'N', // Обязательное: Y или N
                'DEFAULT_VALUE' => '',
                'SORT' => 500,
                'USER_PROPS' => 'Y', // Включить в свойства пользователя: Y или N
                'IS_LOCATION' => 'N',
                'IS_LOCATION4TAX' => 'N',
                'IS_EMAIL' => 'N',
                'IS_PROFILE_NAME' => 'N',
                'IS_PAYER' => 'N',
                'IS_FILTERED' => 'N',
                'CODE' => $propertyCode,
                'ACTIVE' => 'Y',
                'UTIL' => 'Y',
                'INPUT_FIELD_LOCATION' => '',
                'PROPS_GROUP_ID' => $propsGroupId, // ID группы свойств
                'SIZE1' => 0,
                'SIZE2' => 0,
                'DESCRIPTION' => '',
                'PERSON_TYPE_ID' => $personTypeId // Тип плательщика
            ];
            \CSaleOrderProps::Add($propertyFields);
        }
    }

    /**
     * Triggers an event in case of an error in the Paykeeper API request.
     *
     * This method logs the error message and triggers the custom event 'onPrintCheckPaykeeper'.
     * Additional error handling can be performed in the event handler.
     *
     * @param string $errMessage The error message received from the Paykeeper API.
     * @param string $orderId The ID of the order that encountered the error.
     * @param string $nameEvent
     * @return void
     */
    private static function triggerPaykeeperEventError($errMessage, $orderId, $nameEvent) {
        $event = new Event('paykeeper', $nameEvent, [ $errMessage, $orderId ]);
        $event->send();

        foreach ($event->getResults() as $eventResult) {
            if ($eventResult->getType() == EventResult::ERROR && self::$logging) {
                $errorParams = $eventResult->getParameters();
                $log_params = [
                    'TITLE' => 'EVENT_ERROR',
                    'EVENT NAME' => $nameEvent,
                    'METHOD' => 'EVENT',
                    'DATA' => "ORDER ID $orderId MESSAGE $errMessage"
                ];
                if (!empty($errorParams['ERROR_MESSAGE'])) {
                    $log_params['TEXT ERROR'] = $errorParams['ERROR_MESSAGE'];
                }
                Utils::paykeeperLogger($log_params, self::$logging);
            }
        }
    }

    /**
     * Returns a successful event result.
     *
     * This method creates and returns an instance of `EventResult` with a success status.
     *
     * @return \Bitrix\Main\EventResult An event result object with a status of success.
     */
    private static function getSuccessEventResult() {
        return new EventResult(EventResult::SUCCESS);
    }

    /**
     * Retrieves the business value associated with a specified parameter for a given order.
     *
     * @param string $param The name of the business parameter to retrieve.
     * @param \Bitrix\Sale\Order $order The order object containing relevant payment system and person type IDs.
     *
     * @return mixed Returns the business value associated with the specified parameter for the payment system
     *               and person type in the order.
     */
    private static function getBusVal($param)
    {
        return \Bitrix\Sale\BusinessValue::get(
            $param,
            PaySystem\Service::PAY_SYSTEM_PREFIX . self::$paySystemId,
            self::$personTypeId
        );
    }

    /**
     * @param $statuses
     * @return bool
     */
    private static function checkStatusesInDB($statuses)
    {
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
        foreach ($statuses as $status) {
            if (!array_key_exists($status, $arr_status)) {
                return false;
            }
        }
        return true;
    }
}
