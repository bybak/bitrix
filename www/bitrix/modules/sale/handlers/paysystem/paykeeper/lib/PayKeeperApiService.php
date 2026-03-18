<?php

class PayKeeperApiService
{
    protected static $pkUrl;
    protected static $imsUrl;
    protected static $serviceId;
    protected static $username;
    protected static $password;
    protected static $token;
    protected static $logging = false;

    public function __construct($pkUrl, $pkUsername, $pkPassword, $serviceId = '', $imsUrl = '')
    {
        self::$imsUrl = $imsUrl;
        self::$serviceId = $serviceId;

        if (!preg_match('#^https?://#i', $pkUrl)) {
            $pkUrl = 'https://' . $pkUrl;
        }

        self::$pkUrl = $pkUrl;
        self::$username = $pkUsername;
        self::$password = $pkPassword;

        if (!$serviceId && !$imsUrl) {
            self::$token = self::getAuthToken();
        } else {
            self::$token = self::getAuthImsToken();
        }
    }

    public static function setLogging($enabled)
    {
        self::$logging = (bool)$enabled;
    }

    public static function getLogging()
    {
        return self::$logging;
    }

    /**
     * Универсальный метод отправки HTTP запросов
     *
     * @param string $url URL запроса
     * @param array $options Опции запроса (headers, method и т.д.)
     * @return array Тело ответа или false при ошибке
     */
    private static function Send($url, $options = array())
    {
        if (function_exists('curl_init')) {
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_FAILONERROR, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_VERBOSE, true);

            if (isset($options['http']['method'])) {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $options['http']['method']);
            }

            if (isset($options['http']['header'])) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, explode("\r\n", $options['http']['header']));
            }

            if (isset($options['http']['content'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $options['http']['content']);
            }

            $response = curl_exec($ch);

            if ($response === false) {
                $response = curl_error($ch);
            }

            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);;
            curl_close($ch);
            $type = 'CURL';
        } else {
            $context = stream_context_create($options);
            $response = file_get_contents($url, false, $context);
            $httpStatus = 0;
            if (isset($http_response_header[0])) {
                preg_match('{HTTP\/\S*\s(\d{3})}', $http_response_header[0], $match);
                $httpStatus = isset($match[1]) ? (int)$match[1] : 0;
            }
            $type = 'FILE_GET_CONTENTS';
        }

        Utils::paykeeperLogger([
            'POINT' => __METHOD__ . ':' . __LINE__,
            'TYPE' => $type,
            'URL' => $url,
            'REQUEST' => $options,
            'RESPONSE' => $response,
            'STATUS' => $httpStatus
        ], self::$logging);

        return ['status' => $httpStatus, 'response' => $response];
    }

    /**
     * Получение JWT токена для авторизации в IMS
     *
     * @return string|null Возвращает JWT токен или null в случае ошибки
     */
    public static function getAuthImsToken()
    {
        try {
            $options = array(
                'http' => array(
                    'header' => "Authorization: Basic " . base64_encode(self::$username . ":" . self::$password) . "\r\n" .
                        "Content-Type: application/x-www-form-urlencoded\r\n",
                    'method' => 'GET',
                    'ignore_errors' => true
                )
            );

            $url = self::$pkUrl . "/info/settings/service-token?service=" . urlencode(self::$serviceId) . "&user=" . urlencode(self::$username);
            $response = self::Send($url, $options);

            if ($response['response'] === false) {
                return null;
            }

            $data = json_decode($response['response'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            if (!isset($data['result']) || $data['result'] !== 'success' || empty($data['token'])) {
                return null;
            }

            return $data['token'];

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Получение токена для авторизации в PayKeeper
     *
     * @return array|mixed
     */
    public static function getAuthToken()
    {
        try {
            $options = array(
                'http' => array(
                    'header' => "Authorization: Basic " . base64_encode(self::$username . ":" . self::$password) . "\r\n" .
                        "Content-Type: application/x-www-form-urlencoded\r\n",
                    'method' => 'GET',
                    'ignore_errors' => true
                )
            );

            $response = self::Send(self::$pkUrl . "/info/settings/token/", $options);

            if ($response['response'] === false) {
                return self::ResultError('Empty response', $response['status']);
            }

            $data = json_decode($response['response'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return self::ResultError('Ошибка JSON-формата ответа.', $response['status']);
            }

            if (empty($data['token'])) {
                return self::ResultError('Не удалось сделать запрос токена.', $response['status']);
            }

            return $data['token'];

        } catch (\Exception $e) {
            return self::ResultError('Error Exception ' . $e->getMessage());
        }
    }

    /**
     * Выполнение авторизованного запроса к API IMS
     *
     * @param string $method HTTP метод (GET, POST, etc)
     * @param string $endpoint API endpoint
     * @param array $data Данные для запроса
     * @return array|null Ответ API или null при ошибке
     */
    public static function makeImsRequest($method, $endpoint, $data = array())
    {
        $token = self::$token;
        if (!$token) {
            throw new RuntimeException('PayKeeperApiService makeImsRequest: Unable to get auth token');
        }

        try {
            $options = array(
                'http' => array(
                    'header' => "Authorization: Bearer " . $token . "\r\n" .
                        "Accept: application/json\r\n" .
                        "Content-Type: application/json\r\n",
                    'method' => strtoupper($method),
                    'ignore_errors' => true
                )
            );

            if ($method === 'POST' || $method === 'PATCH') {
                $options['http']['content'] = json_encode($data);
            }

            $response = self::Send(self::$imsUrl . $endpoint, $options);

            if ($response['response'] === false) {
                return null;
            }

            $array = json_decode($response['response'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            return $array;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Запросы API PayKeeper
     *
     * @param string $method
     * @param string $endpoint
     * @param array $data
     * @return array|null
     */
    public static function makeRequest($method, $endpoint, $data = [])
    {
        $token = self::$token;
        if (!$token) {
            return self::ResultError('Unable to get auth token');
        }

        try {
            if (strtolower($method) === 'post') {
                $data['token'] = $token;
            }

            $headers = array(
                'Authorization: Basic ' . base64_encode(self::$username . ':' . self::$password),
                'Accept: application/json'
            );

            if (strtolower($method) === 'post') {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }

            $options = array(
                'http' => array(
                    'header' => implode("\r\n", $headers),
                    'method' => strtoupper($method),
                    'ignore_errors' => true
                )
            );

            if (strtolower($method) === 'post') {
                $options['http']['content'] = http_build_query($data);
            }

            if (strtolower($method) === 'get') {
                $endpoint = $endpoint . '?' . http_build_query($data);
            }

            $response = self::Send(self::$pkUrl . $endpoint, $options);

            if ($response['response'] === false) {
                return self::ResultError('Пустой ответ.', $response['status']);
            }

            $array = json_decode($response['response'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return self::ResultError('Ошибка JSON-формата ответа.', $response['status']);
            }

            return $array;
        } catch (\Exception $e) {
            return self::ResultError('Исключение запроса: ' . $e->getMessage());
        }
    }

    public static function addProduct($product)
    {
        $requestData = array(
            'product' => array(
                'name' => $product['name'],
                'price' => $product['price'],
                'item_type' => $product['item_type'],
                'tax' => $product['tax'],
                'sku' => $product['sku'],
                'description' => $product['description'],
                'barcode' => $product['barcode'],
                'item_code_is_mandatory' => $product['item_code_is_mandatory'],
                'tru_code' => $product['tru_code'],
            )
        );
        return self::makeImsRequest('post', '/products', $requestData);
    }

    public static function getProduct($productId)
    {
        return self::makeImsRequest('get', "/product/" . $productId);
    }

    public static function getProducts($startFrom = 0, $limit = 100)
    {
        $requestData = array(
            'limit' => $limit,
            'start_from' => $startFrom
        );
        return self::makeImsRequest('get', '/products', $requestData);
    }

    public static function updateProduct($product)
    {
        $allowedKeys = array(
            'name', 'price', 'item_type', 'tax', 'sku',
            'description', 'barcode', 'item_code_is_mandatory', 'tru_code'
        );

        $requestData = array(
            'product' => array_intersect_key($product, array_flip($allowedKeys))
        );

        return self::makeImsRequest('patch', "/product/" . $product['id'], $requestData);
    }

    public static function searchProduct($name)
    {
        return self::makeImsRequest('get', "/products/search/", array(
            'search_string' => $name
        ));
    }

    public static function deleteProduct($productId)
    {
        return self::makeImsRequest('delete', "/product/" . $productId);
    }

    /**
     * Create invoice https://docs.paykeeper.ru/dokumentatsiya-json-api/scheta/#3.4
     *
     * @param array $data
     * @return array|null
     */
    public static function createInvoice($data)
    {
        $requestData = array(
            'pay_amount' => $data['pay_amount']
        );
        $fields = ['clientid', 'orderid', 'service_name', 'client_email', 'client_phone', 'expiry'];
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $requestData[$field] = $data[$field];
            }
        }
        return self::makeRequest('post', '/change/invoice/preview/', $requestData);
    }

    /**
     * Get data invoice https://docs.paykeeper.ru/dokumentatsiya-json-api/scheta/#3.1
     *
     * @param int $invoice_id
     * @return array|null
     */
    public static function checkStatusInvoice($invoice_id)
    {
        return self::makeRequest('get', '/info/invoice/byid/', array(
            'id' => $invoice_id
        ));
    }

    /**
     * Send invoice from email https://docs.paykeeper.ru/dokumentatsiya-json-api/scheta/#3.5
     *
     * @param int $invoice_id
     * @return array|null
     */
    public static function sendInvoice($invoice_id)
    {
        return self::makeRequest('post', '/change/invoice/send/', array(
            'id' => $invoice_id
        ));
    }

    public static function capturePayment($id)
    {
        $requestData = [
            'id' => $id
        ];
        return self::makeRequest('post', '/change/payment/capture/', $requestData);
    }

    public static function reversePayment($data)
    {
        $requestData = array(
            'id' => $data['id'],
            'amount' => $data['amount'],
            'partial' => isset($data['partial']) ? $data['partial'] : false
        );
        if (isset($data['sbp_refund_to'])) {
            $requestData['sbp_refund_to'] = $data['sbp_refund_to'];
        }
        $requestData['refund_cart'] = isset($data['refund_cart']) ? json_encode($data['refund_cart']) : json_encode([]);
        return self::makeRequest('post', '/change/payment/reverse/', $requestData);
    }

    /**
     * Info payment
     * @see https://docs.paykeeper.ru/dokumentatsiya-json-api/platezhi/#2.3
     * @param $id
     * @return array|null
     */
    public static function getInfoPayment($paymentId)
    {
        $requestData = [
            'id' => $paymentId
        ];
        return self::makeRequest('get', '/info/payments/byid/', $requestData);
    }

    public static function receiptPayment($id)
    {
        $requestData = [
            'id' => $id
        ];
        return self::makeRequest('post', '/change/payment/post-sale-receipt/', $requestData);
    }

    /**
     * Безакцептное списание по имени связки
     * https://docs.paykeeper.ru/dokumentatsiya-json-api/privyazka-karty/#9.6
     *
     * @param array $data bank_id, orderid, sum, clientid, service_name, client_email, client_phone, cart, pstype, sign
     * @return array|null
     */
    public static function executeBinding($data)
    {
        $requestData = [
            'bank_id' => $data['bank_id'],
            'orderid' => $data['orderid'],
            'sum' => $data['sum']
        ];
        $fields = ['clientid', 'service_name', 'client_email', 'client_phone', 'cart', 'pstype', 'sign'];
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                if ($field === 'cart') {
                    $requestData['cart'] = json_encode($data[$field]);
                } else {
                    $requestData[$field] = $data[$field];
                }
            }
        }
        return self::makeRequest('post', '/change/binding/execute/', $requestData);
    }


    /**
     * Формат ошибок
     *
     * @param $msg
     * @param $code
     * @return array
     */
    private static function ResultError($msg, $code = 0)
    {
        return [
            'result' => false,
            'msg' => $msg,
            'code' => $code
        ];
    }
}