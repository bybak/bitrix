<?php

define('LOG_FILE', realpath(dirname(dirname(__FILE__))) . "/logs/paykeeper.log");

class Utils
{
    const log_file = LOG_FILE;
    public static $serviceInitName = 'paykeeper_payment_system';
    private static $actionFName = 'paykeeper';
    public static $textHiddenForLog = '--hidden-for-log--';
    public static $version = 155;

    /**
     * Initialize database
     */
    public static function initDB()
    {
        require_once __DIR__ . '/db/VersionsTable.php';
        require_once __DIR__ . '/db/OrdersTable.php';
        require_once __DIR__ . '/db/CardTokensTable.php';

        VersionsTable::createTable();
        OrdersTable::createTable();
        CardTokensTable::createTable();

        if (VersionsTable::migrateNeed()) {
            VersionsTable::initVersion();
        }
    }

    /**
     * Logs specified parameters to a log file with a timestamp.
     *
     * This method creates a log entry with the provided parameters and includes a timestamp.
     * If the log file exists and is less than 7 MB in size, the new log entry is prepended to the existing content.
     *
     * @param array $log_params An associative array of parameters to log, where each key is a parameter name and the value is its corresponding data.
     *
     * @return void
     */
    public static function paykeeperLoggerPre($log_params)
    {
        $logText = '--------------START_' . date("Y-m-d_H:i:s") . '--------------' . "\n";
        foreach ($log_params as $key => $value) {
            $logText .= "$key: " . print_r($value,true) . "\n";
        }
        $logText .= "\n\n\n";
        $file = self::log_file;
        if (file_exists($file)) {
            $logSize = filesize($file);
            if ($logSize < (7 * 1024 * 1024)) {
                $logText .= file_get_contents($file);
            }
        }
        file_put_contents($file, $logText);
    }

    /**
     * Logs specified parameters to a log file with a timestamp.
     * If the log file exists and is smaller than 7 MB, the new log entry is prepended to the existing content.
     *
     * @param $logParams
     * @param $isLoggingEnabled
     * @return void
     */
    public static function paykeeperLogger($logParams, $isLoggingEnabled = true)
    {
        if ($isLoggingEnabled) {
            $logText = '--------------START_' . date("Y-m-d_H:i:s") . '--------------' . "\n";

            $processedParams = self::maskSensitiveDataInArray($logParams);

            foreach ($processedParams as $key => $value) {
                $logText .= "$key: " . print_r($value, true) . "\n";
            }

            $logText .= "\n\n";
            file_put_contents(self::log_file, $logText, FILE_APPEND);
        }
    }

    /**
     * Проверяет, является ли строка валидным JSON
     *
     * @param string $string Строка для проверки
     * @return bool
     */
    private static function isJson($string)
    {
        if (!is_string($string) || trim($string) === '') {
            return false;
        }

        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Проверяет, является ли строка query-строкой (с параметрами через &)
     *
     * @param string $string Строка для проверки
     * @return bool
     */
    private static function isQueryString($string)
    {
        if (!is_string($string) || trim($string) === '') {
            return false;
        }

        return strpos($string, '=') !== false && (strpos($string, '&') !== false || strpos($string, '%') !== false);
    }

    /**
     * Парсит query-строку в массив
     *
     * @param string $queryString Query-строка
     * @return array
     */
    private static function parseQueryString($queryString)
    {
        $result = [];
        parse_str($queryString, $result);
        return $result;
    }

    /**
     * Рекурсивно маскирует чувствительные данные в массиве
     *
     * @param $data
     * @return array Обработанный массив
     */
    private static function maskSensitiveDataInArray($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => &$value) {
                if (is_array($value)) {
                    $value = self::maskSensitiveDataInArray($value);
                } elseif (is_string($value)) {
                    // Обработка заголовков Authorization
                    if (stripos($key, 'authorization') !== false || stripos($value, 'authorization') !== false) {
                        $value = self::maskAuthorizationHeader($value);
                    }
                    // Обработка JSON
                    elseif (self::isJson($value)) {
                        $decoded = json_decode($value, true);
                        if (is_array($decoded)) {
                            $value = self::maskSensitiveDataInArray($decoded);
                        }
                    }
                    // Обработка query-строк
                    elseif (self::isQueryString($value)) {
                        $parsed = self::parseQueryString($value);
                        if (is_array($parsed) && count($parsed) > 0) {
                            $value = self::maskSensitiveDataInArray($parsed);
                        }
                    }
                    // Маскировка чувствительных полей
                    elseif (in_array($key, ['key', 'token', 'bank_id', 'password', 'secret']) && !empty($value)) {
                        $value = substr($value, 0, 5) . self::$textHiddenForLog;
                    }
                }
            }
        } elseif (is_string($data)) {
            // Обработка заголовков Authorization
            if (stripos($data, 'authorization') !== false) {
                $data = self::maskAuthorizationHeader($data);
            }
            // Обработка JSON
            elseif (self::isJson($data)) {
                $decoded = json_decode($data, true);
                if (is_array($decoded)) {
                    $data = self::maskSensitiveDataInArray($decoded);
                }
            }
            // Обработка query-строк
            elseif (self::isQueryString($data)) {
                $parsed = self::parseQueryString($data);
                if (is_array($parsed) && count($parsed) > 0) {
                    $data = self::maskSensitiveDataInArray($parsed);
                }
            }
        }

        return $data;
    }

    /**
     * Маскирует данные в заголовке Authorization
     *
     * @param string $header Заголовок Authorization
     * @return string Маскированный заголовок
     */
    private static function maskAuthorizationHeader($header)
    {
        if (preg_match('/Authorization:\s*Basic\s+([^\s]+)/i', $header, $matches)) {
            $authData = $matches[1];
            $maskedAuth = substr($authData, 0, 5) . self::$textHiddenForLog;
            $header = str_replace($authData, $maskedAuth, $header);
        }

        return $header;
    }

    /**
     * Types of calculation
     * @param $value
     * @param $type
     * @return string
     */
    public static function getValueTypeParamCart($value, $type)
    {
        return [
            'item_type' => [
                1 => 'goods',
                2 => 'service',
                3 => 'work',
                4 => 'excise',
                5 => 'ip',
                6 => 'payment',
                7 => 'agent',
                16 => 'goods_coded'
            ],
            'payment_type' => [
                1 => 'prepay',
                2 => 'part_prepay',
                3 => 'advance',
                4 => 'full'
            ],
            'measure' => [
                1 => 'pcs',
                2 => 'g',
                3 => 'kg',
                7 => 'm',
                12 => 'l'
            ],
            'vat' => [
                1 => 'none',
                2 => 'vat0',
                7 => 'vat5',
                8 => 'vat7',
                3 => 'vat10',
                4 => 'vat22',
                9 => 'vat105',
                10 => 'vat107',
                5 => 'vat110',
                6 => 'vat122'
            ]
        ][$type][$value];
    }

    /**
     * @return string
     */
    public static function getItemTypeParamCart($value)
    {
        return self::getValueTypeParamCart($value, 'item_type');
    }

    public static function getPaymentTypeParamCart($value)
    {
        return self::getValueTypeParamCart($value, 'payment_type');
    }

    /**
     * @param $tax_rate
     * @param $zero_value_as_none - if variable is set, then when tax_rate is zero, tax is equal to none
     * @param $convert_tax_rate - converts tax rate
     * @return string
     */
    public static function getVat($tax_rate, $zero_value_as_none = true, $convert_tax_rate = false)
    {
        $roundedRate = (int)round(floatval($tax_rate));

        if ($roundedRate == 0) {
            return $zero_value_as_none ? 'none' : 'vat0';
        }

        if ($roundedRate > 0 && $roundedRate <= 100) {
            if ($convert_tax_rate) {
                return 'vat' . (100 + $roundedRate);
            } else {
                return 'vat' . $roundedRate;
            }
        }

        return 'none';
    }

    /**
     * @return int
     */
    public static function getThisSystemID()
    {
        $paysystem = \Bitrix\Sale\Internals\PaySystemActionTable::getList([
            'order' => [],
            'filter' => [ 'ACTIVE' => 'Y', 'ACTION_FILE' => self::$actionFName ]
        ])->fetch();
        if (!$paysystem) {
            return 0;
        }
        return intval($paysystem['ID']);
    }

    public static function isThisSystemID($paySystemId)
    {
        $service = \Bitrix\Sale\PaySystem\Manager::getObjectById($paySystemId);
        return ($service && $service->getField('ACTION_FILE') === self::$actionFName);
    }

    /**
     * Чистка PAYKEEPER FORM URL от URI для API запросов
     *
     * @param $url
     * @return string
     */
    public static function clearPaykeeperUrl($url)
    {
        foreach (['/create', '/order'] as $uri) {
            if ($pos = strrpos($url, $uri)) {
                $url = substr($url, 0, $pos);
                break;
            }
        }
        return rtrim($url, '/');
    }
}