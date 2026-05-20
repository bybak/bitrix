<?php

declare(strict_types=1);

/**
 * Обёртка: корень сайта = каталог www (document root), не local/site.
 */
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);

require $_SERVER['DOCUMENT_ROOT'] . '/local/site/tools/mf_supplier_orders_sync.php';
