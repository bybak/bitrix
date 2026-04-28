<?php
/**
 * Локальная обёртка: запуск из корня репозитория:
 *   php tools/mf_catalog_strip_merged_duplicate_images.php --dry-run
 *
 * На сервере (DOCUMENT_ROOT = www относительно репозитория):
 *   php /path/to/www/tools/mf_catalog_strip_merged_duplicate_images.php --apply
 */
$inner = __DIR__ . '/../www/tools/mf_catalog_strip_merged_duplicate_images.php';
if (!is_file($inner))
{
	fwrite(STDERR, "Не найден: {$inner}\n");
	exit(1);
}
require $inner;
