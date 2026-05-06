<?php

return array (
  'cache_flags' => 
  array (
    'value' => 
    array (
      'config_options' => 3600.0,
    ),
    'readonly' => false,
  ),
  'cookies' => 
  array (
    'value' => 
    array (
      'secure' => false,
      'http_only' => true,
    ),
    'readonly' => false,
  ),
  'exception_handling' => 
  array (
    'value' => 
    array (
      'debug' => false,
      'handled_errors_types' => 4437,
      'exception_errors_types' => 4437,
      'ignore_silence' => false,
      'assertion_throws_exception' => true,
      'assertion_error_type' => 256,
      'log' => NULL,
    ),
    'readonly' => false,
  ),
  'connections' => 
  array (
    'value' => 
    array (
      'default' => 
      array (
        'host' => 'mysql:3306',
        'database' => 'bitrix_motor_force',
        'login' => 'root',
        'password' => 'levampass',
        'options' => 2.0,
        'className' => '\\Bitrix\\Main\\DB\\MysqliConnection',
      ),
    ),
    'readonly' => true,
  ),
  'crypto' => 
  array (
    'value' => 
    array (
      'crypto_key' => 'a4b1b374add13404f46b9b1b92276751',
    ),
    'readonly' => true,
  ),
  'messenger' => 
  array (
    'value' => 
    array (
      'run_mode' => NULL,
      'brokers' => 
      array (
        'default' => 
        array (
          'type' => 'db',
          'params' => 
          array (
            'table' => 'Bitrix\\Main\\Messenger\\Internals\\Storage\\Db\\Model\\MessengerMessageTable',
          ),
        ),
      ),
      'queues' => 
      array (
      ),
    ),
    'readonly' => true,
  ),
  'cache' =>
  array (
    'value' =>
    array (
      'type' => 'redis',
      'redis' =>
      array (
        'host' => (static function () {
          $h = getenv('BITRIX_REDIS_HOST');
          return ($h !== false && $h !== '') ? $h : 'redis';
        })(),
        'port' => (static function () {
          $p = getenv('BITRIX_REDIS_PORT');
          return ($p !== false && $p !== '') ? (int) $p : 6379;
        })(),
      ),
    ),
    'readonly' => false,
  ),
);
