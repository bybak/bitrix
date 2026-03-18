<?php

define("NO_KEEP_STATISTIC", true);
define("STOP_STATISTICS", true);
define("NOT_CHECK_PERMISSIONS", true);
define('BX_WITH_ON_AFTER_EPILOG', true);
define('BX_NO_ACCELERATOR_RESET', true);
define("NO_AGENT_STATISTIC", true);
define("NO_AGENT_CHECK", true);
define("PERFMON_STOP", true);

require_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Application;
use Bitrix\Main\Web\Json;
use Bitrix\Sale;
use Bitrix\Sale\PaySystem;

class PayKeeperRecurrentHandler
{
    private $request;
    private $userId;

    public function __construct()
    {
        $this->request = Application::getInstance()->getContext()->getRequest();
        global $USER;
        $this->userId = (int)$USER->GetID();
    }

    public function execute()
    {
        if (!Bitrix\Main\Loader::includeModule('sale')) {
            die(json_encode(['error' => 'Модуль sale не доступен']));
        }

        if (!$this->request->isPost()) {
            die(Json::encode(['error' => 'Неверный метод-запроса']));
        }

        if (!check_bitrix_sessid()) {
            die(Json::encode(['error' => 'Сессия устарела']));
        }

        global $USER;

        if (!$USER->IsAuthorized()) {
            die(Json::encode(['error' => 'Требуется авторизация']));
        }

        $action = $this->request->getPost('action');
        $paymentId = (int)$this->request->getPost('paymentId');
        $cardUuid = $this->request->getPost('card_uuid');

        if (!preg_match('/^[a-fA-F0-9]{32}$/', $cardUuid)) {
            die(Json::encode(['error' => 'Ошибка в параметрах']));
        }

        if ($paymentId === 0) {
            die(Json::encode(['error' => 'Платёж не идентифицирован']));
        }

        try {
            $resultPayment = Sale\Payment::getList([
                'filter' => ['ID' => $paymentId],
                'select' => ['*']
            ]);
            if (!$paymentArray = $resultPayment->fetch()) {
                die(Json::encode(['error' => 'Оплата не найдена']));
            }

            $order = Sale\Order::load($paymentArray['ORDER_ID']);

            if ((int)$order->getUserId() !== $this->userId) {
                die(Json::encode(['error' => 'Нет доступа к этому заказу']));
            }

            $payment = $order->getPaymentCollection()->getItemById($paymentId);
            if (!$payment) {
                die(Json::encode(['error' => 'Платеж не найден в заказе']));
            }

            $paySystemId = $paymentArray['PAY_SYSTEM_ID'];

        } catch (Exception $e) {
            die(Json::encode(['error' => 'Ошибка инициализации оплаты']));
        }

        require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/sale/handlers/paysystem/paykeeper/handler.php';

        /**
         * @var $paykeeperHandler
         */

        try {
            switch ($action) {
                case 'repeatRecurrent':
                    $handler = $this->getHandler($paySystemId);

                    if (!$handler instanceof \Sale\Handlers\PaySystem\PayKeeperHandler) {
                        die(Json::encode(['error' => 'Неверный обработчик платежей']));
                    }

                    $this->request->set(array(
                        'payment_id' => $paymentId,
                        'order_id' => $order->getId(),
                        'action' => 'repeat_recurrent'
                    ));

                    $bankId = CardTokensTable::getCardTokenFromHash($this->userId, $cardUuid);
                    if (!$bankId) {
                        die(Json::encode(['error' => 'Привязка не найдена']));
                    }

                    $payment->setField('PS_RECURRING_TOKEN', $bankId);
                    $payment->save();

                    $resultRecurrent = $handler->repeatRecurrent($payment);

                    if (!$resultRecurrent->isSuccess()) {
                        $errors = [];
                        foreach ($resultRecurrent->getErrors() as $error) {
                            $errors[] = $error->getMessage();
                        }
                        die(Json::encode(['error' => implode(', ', $errors)]));
                    }

                    echo Json::encode(['success' => true, 'message' => 'Повторный платеж успешно выполнен']);
                    exit;

                case 'removeCard':
                    $result = CardTokensTable::removeCard($this->userId, $cardUuid);
                    if (!$result) {
                        die(Json::encode(['error' => 'Не удалось удалить привязку. Попробуйте позднее!']));
                    }
                    echo Json::encode(['success' => true]);
                    exit;

                case 'setDefaultCard':
                    $result = CardTokensTable::setDefaultCard($this->userId, $cardUuid);
                    if (!$result) {
                        die(Json::encode(['error' => 'Не удалось сделать привязку основной. Попробуйте позднее!']));
                    }
                    echo Json::encode(['success' => true]);
                    exit;

                default:
                    die(Json::encode(['error' => 'Неизвестное действие']));
            }
        } catch (Exception $e) {
            die(Json::encode(['error' => $e->getMessage()]));
        }
    }

    private function getHandler($paySystemId)
    {
        $service = PaySystem\Manager::getObjectById($paySystemId);
        if (!$service) {
            die(Json::encode(['error' => 'Платежная система не найдена']));
        }

        $reflectionService = new ReflectionClass($service);

        $handler = null;

        if ($reflectionService->hasProperty('handler')) {
            $handlerProperty = $reflectionService->getProperty('handler');
            $handlerProperty->setAccessible(true);
            $handler = $handlerProperty->getValue($service);
        }
        elseif ($reflectionService->hasProperty('paymentSystem')) {
            $psProperty = $reflectionService->getProperty('paymentSystem');
            $psProperty->setAccessible(true);
            $paymentSystem = $psProperty->getValue($service);

            if ($paymentSystem && method_exists($paymentSystem, 'getHandler')) {
                $handler = $paymentSystem->getHandler();
            }
        }

        if (!$handler) {
            $handler = new \Sale\Handlers\PaySystem\PayKeeperHandler();

            $reflectionHandler = new ReflectionClass($handler);
            if ($reflectionHandler->hasProperty('service')) {
                $serviceProperty = $reflectionHandler->getProperty('service');
                $serviceProperty->setAccessible(true);
                $serviceProperty->setValue($handler, $service);
            }
        }

        return $handler;
    }
}

$handler = new PayKeeperRecurrentHandler();
$handler->execute();