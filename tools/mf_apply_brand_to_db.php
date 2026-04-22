<?php
/**
 * Локальная обёртка: запуск с хоста в корне репозитория (рядом с папкой www):
 *   php tools/mf_apply_brand_to_db.php --csv=../www/catalog_2026-04-21.csv
 *   (по умолчанию сухой прогон; для записи в БД добавьте --apply)
 *
 * В Docker (корень сайта = /var/www/html):
 *   php /var/www/html/tools/mf_apply_brand_to_db.php --csv=/var/www/html/catalog_2026-04-21.csv --apply
 */
$inner = __DIR__ . '/../www/tools/mf_apply_brand_to_db.php';
if (!is_file($inner)) {
	fwrite(STDERR, "Не найден: $inner\n");
	exit(1);
}
require $inner;
