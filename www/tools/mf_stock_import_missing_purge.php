<?php
/**
 * Точка входа (cron): делегирует в local/site/tools/mf_stock_import_missing_purge.php
 *
 *   php tools/mf_stock_import_missing_purge.php
 *   php tools/mf_stock_import_missing_purge.php --days=7 --ensure-index
 *   php tools/mf_stock_import_missing_purge.php --dry-run
 */
require __DIR__ . '/../local/site/tools/mf_stock_import_missing_purge.php';
