<?php
/**
 * Локальная обёртка:
 *   php tools/mf_catalog_uniq_key_duplicates.php --iblock-id=4
 *
 * В Docker / на сервере:
 *   php /var/www/html/tools/mf_catalog_uniq_key_duplicates.php --export=/tmp/uniq_dup.csv
 */
$inner = __DIR__ . '/../www/tools/mf_catalog_uniq_key_duplicates.php';
if (!is_file($inner))
{
	fwrite(STDERR, "Не найден: {$inner}\n");
	exit(1);
}
require $inner;
