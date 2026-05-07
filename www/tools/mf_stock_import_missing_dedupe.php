<?php
/**
 * Точка входа (cron, документация): делегирует в канонический скрипт под local/site/tools.
 * Параметры те же — см. комментарии в local/site/tools/mf_stock_import_missing_dedupe.php.
 *
 * Запуск:
 *   php tools/mf_stock_import_missing_dedupe.php
 *   php tools/mf_stock_import_missing_dedupe.php --dry-run
 */
require __DIR__ . '/../local/site/tools/mf_stock_import_missing_dedupe.php';
