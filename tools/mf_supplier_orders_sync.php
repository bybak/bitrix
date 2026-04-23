<?php
/**
 * Локальная обёртка: запуск из корня репозитория (рядом с www):
 *   php tools/mf_supplier_orders_sync.php
 *   php tools/mf_supplier_orders_sync.php --dry-run
 *
 * В Docker:
 *   php /var/www/html/tools/mf_supplier_orders_sync.php
 */
$inner = __DIR__ . '/../www/tools/mf_supplier_orders_sync.php';
if (!is_file($inner))
{
	fwrite(STDERR, "Не найден: {$inner}\n");
	exit(1);
}
require $inner;
