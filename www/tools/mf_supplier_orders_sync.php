<?php
/**
 * Синхронизация заказов поставщику из UNF API в таблицы mf_supplier_order / mf_supplier_order_line.
 *
 * Переменные окружения / настройки:
 *   MF_UNF_SUPPLIER_ORDERS_URL — полный URL метода supplier_order_get (обязательно для записи).
 *   MF_UNF_BASIC_USER / MF_UNF_BASIC_PASS — как для остального UNF (или mf.unf в опциях).
 *   MF_UNF_SUPPLIER_ORDERS_TIMEOUT — секунды HTTP (по умолчанию 180).
 *   MF_SUPPLIER_ORDERS_STATE_KEY — фильтр state.key (по умолчанию «в работе», сравнение без регистра).
 *   MF_SUPPLIER_ORDERS_IBLOCK_ID — инфоблок каталога для матчинга (по умолчанию 4).
 *   MF_SUPPLIER_ORDERS_LOG=Y — писать php_interface/logs/mf_supplier_orders.log
 *   MF_SUPPLIER_ORDERS_FILL_BASE_PRICE — 0|off: не проставлять закуп из 1С, если в строке есть price.unit_price
 *   MF_SUPPLIER_ORDERS_PRICE_CURRENCY — валюта RAW в b_catalog_price (по умолчанию RUB)
 *
 * Строки JSON: price.unit_price; при сопоставлении с товаром, если в типе цены склада MOTOR_FORCE_INTERNAL
 * (mf_supplier_store_to_price_group) ещё нет закупочной цены — пишется RAW для кластера товара (как внешние прайсы).
 * В --dry-run каталог не меняется, в JSON — prices_would_fill.
 *
 * Прогресс (по умолчанию вкл., в STDERR; JSON результата — в STDOUT):
 *   --quiet — без сообщений прогресса
 *   --progress-every-order=N — сообщать каждый N-й заказ (по умолчанию 1). 0 = только этапы fetch/filter/purge/done и строки по --progress-every-line
 *   --progress-every-line=N — каждые N обработанных строк (0 = выкл.)
 *
 * Запуск из контейнера (DOCUMENT_ROOT = корень сайта):
 *   php /var/www/html/tools/mf_supplier_orders_sync.php
 *   php /var/www/html/tools/mf_supplier_orders_sync.php --dry-run
 *   php /var/www/html/tools/mf_supplier_orders_sync.php --no-fill-base-price
 *   php /var/www/html/tools/mf_supplier_orders_sync.php --url=https://host/unf/hs/orders/supplier_order_get/
 *
 * С хоста рядом с репозиторием:
 *   php tools/mf_supplier_orders_sync.php
 */
declare(strict_types=1);

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

Loader::includeModule('iblock');

$lib = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_supplier_orders_lib.php';
if (!is_file($lib))
{
	fwrite(STDERR, "Не найден: {$lib}\n");
	exit(1);
}
require_once $lib;

while (ob_get_level() > 0)
{
	@ob_end_flush();
}
@ob_implicit_flush(true);

function mf_sos_arg(string $name): ?string
{
	$dd = '--' . $name . '=';
	$l = strlen($dd);
	foreach ($_SERVER['argv'] ?? [] as $a)
	{
		if (strncmp((string)$a, $dd, $l) === 0)
		{
			return substr((string)$a, $l);
		}
	}

	return null;
}

function mf_sos_has_flag(string $flag): bool
{
	return in_array($flag, $_SERVER['argv'] ?? [], true);
}

function mf_sos_stderr_progress(string $phase, array $ctx): void
{
	$t = date('Y-m-d H:i:s');
	switch ($phase)
	{
		case 'fetch_start':
			$url = (string)($ctx['url'] ?? '');
			$to = (int)($ctx['timeout_sec'] ?? 0);
			fwrite(STDERR, "[{$t}] Запрос API (до {$to} с)…\n");
			if ($url !== '')
			{
				fwrite(STDERR, "[{$t}] URL: {$url}\n");
			}
			break;
		case 'fetch_done':
			$bytes = (int)($ctx['bytes'] ?? 0);
			$http = (int)($ctx['http'] ?? 0);
			$err = $ctx['error'] ?? null;
			if ($err !== null && (string)$err !== '')
			{
				fwrite(STDERR, "[{$t}] Ошибка транспорта: {$err}\n");
				break;
			}
			fwrite(STDERR, "[{$t}] Ответ: HTTP {$http}, " . number_format($bytes, 0, '.', ' ') . " байт\n");
			break;
		case 'filtered':
			$all = (int)($ctx['orders_in_response'] ?? 0);
			$kept = (int)($ctx['orders_kept'] ?? 0);
			$sk = (string)($ctx['state_key'] ?? '');
			fwrite(STDERR, "[{$t}] После фильтра state.key={$sk}: заказов в ответе {$all}, оставляем «в работе» {$kept}\n");
			break;
		case 'order':
			$cur = (int)($ctx['current'] ?? 0);
			$tot = (int)($ctx['total'] ?? 0);
			$num = (string)($ctx['number'] ?? '');
			$sfx = !empty($ctx['dry_run']) ? ' (dry-run)' : '';
			fwrite(STDERR, "[{$t}] Заказ {$cur}/{$tot}{$sfx}: " . ($num !== '' ? $num : (string)($ctx['uuid'] ?? '')) . "\n");
			break;
		case 'lines':
			if (!empty($ctx['dry_run']))
			{
				$proc = (int)($ctx['processed'] ?? 0);
				$msf = (int)($ctx['matched_so_far'] ?? 0);
				fwrite(STDERR, "[{$t}] Строк обработано: {$proc}, сопоставлено с каталогом: {$msf}\n");
			}
			else
			{
				$saved = (int)($ctx['saved_total'] ?? 0);
				$matched = (int)($ctx['matched_total'] ?? 0);
				$oc = (int)($ctx['order_current'] ?? 0);
				$ot = (int)($ctx['order_total'] ?? 0);
				fwrite(STDERR, "[{$t}] Строк в БД: {$saved} (matched {$matched}), заказ {$oc}/{$ot}\n");
			}
			break;
		case 'purge_start':
			$mode = (string)($ctx['mode'] ?? '');
			fwrite(STDERR, "[{$t}] Очистка устаревших записей ({$mode})…\n");
			break;
		case 'purge_done':
			$rm = (int)($ctx['orders_removed'] ?? 0);
			fwrite(STDERR, "[{$t}] Удалено заказов не из текущей выборки: {$rm}\n");
			break;
		case 'done':
			$okOrders = (int)($ctx['orders_kept'] ?? 0);
			$okLines = isset($ctx['lines_saved'])
				? (int)$ctx['lines_saved']
				: (int)($ctx['lines_processed'] ?? 0);
			fwrite(STDERR, "[{$t}] Готово: заказов {$okOrders}, строк {$okLines}");
			if (isset($ctx['lines_matched']))
			{
				fwrite(STDERR, ', совпало с каталогом ' . (int)$ctx['lines_matched']);
			}
			if (isset($ctx['prices_filled']) && (int)$ctx['prices_filled'] > 0)
			{
				fwrite(STDERR, ', закуп (склад MOTOR_FORCE_INTERNAL) проставлена в каталог: ' . (int)$ctx['prices_filled']);
			}
			if (isset($ctx['prices_would_fill']) && (int)$ctx['prices_would_fill'] > 0)
			{
				fwrite(STDERR, ', было бы проставлено закупов: ' . (int)$ctx['prices_would_fill']);
			}
			fwrite(STDERR, "\n");
			break;
		case 'abort':
			$r = (string)($ctx['reason'] ?? '');
			fwrite(STDERR, "[{$t}] Прервано: {$r}");
			if (isset($ctx['error']))
			{
				fwrite(STDERR, ' — ' . (string)$ctx['error']);
			}
			if (isset($ctx['http']))
			{
				fwrite(STDERR, ' (HTTP ' . (int)$ctx['http'] . ')');
			}
			if (isset($ctx['message']))
			{
				fwrite(STDERR, ' — ' . (string)$ctx['message']);
			}
			fwrite(STDERR, "\n");
			break;
		default:
			fwrite(STDERR, "[{$t}] {$phase}: " . json_encode($ctx, JSON_UNESCAPED_UNICODE) . "\n");
	}
	fflush(STDERR);
}

$dryRun = mf_sos_has_flag('--dry-run');
if (mf_sos_has_flag('--no-fill-base-price'))
{
	putenv('MF_SUPPLIER_ORDERS_FILL_BASE_PRICE=0');
}
$urlOverride = mf_sos_arg('url');
if ($urlOverride !== null && trim($urlOverride) !== '')
{
	putenv('MF_UNF_SUPPLIER_ORDERS_URL=' . trim($urlOverride));
}

$iblockArg = mf_sos_arg('iblock-id');
if ($iblockArg !== null && is_numeric($iblockArg))
{
	putenv('MF_SUPPLIER_ORDERS_IBLOCK_ID=' . (int)$iblockArg);
}

$tArg = mf_sos_arg('timeout');
if ($tArg !== null && is_numeric($tArg))
{
	putenv('MF_UNF_SUPPLIER_ORDERS_TIMEOUT=' . (int)$tArg);
}

$quiet = mf_sos_has_flag('--quiet');

$peo = mf_sos_arg('progress-every-order');
$everyOrder = ($peo !== null && $peo !== '') ? max(0, (int)$peo) : 1;

$pel = mf_sos_arg('progress-every-line');
$everyLine = ($pel !== null && $pel !== '') ? max(0, (int)$pel) : 0;

$progressCb = $quiet ? null : 'mf_sos_stderr_progress';

$result = mf_supplier_orders_sync(
	$dryRun,
	is_callable($progressCb) ? $progressCb : null,
	['every_order' => $everyOrder, 'every_line' => $everyLine]
);

$json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($json === false)
{
	fwrite(STDERR, "json_encode failed\n");
	exit(1);
}
echo $json . "\n";

exit($result['ok'] ? 0 : 1);
