<?php

declare(strict_types=1);

/**
 * Заказы поставщику из UNF HTTP API (supplier_order_get): локальное зеркало в MySQL.
 * Храним только заказы в статусе «в работе»; при исчезновении из выборки — удаляем из БД (без истории).
 * price.unit_price при отсутствии закупа в типе цены склада MOTOR_FORCE_INTERNAL — через mf_ep_set_raw_price_for_catalog_cluster.
 */

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\HttpClient;

if (!function_exists('mf_supplier_orders_log'))
{
	function mf_supplier_orders_log(string $message, array $context = []): void
	{
		$env = getenv('MF_SUPPLIER_ORDERS_LOG');
		$on = ($env !== false && in_array(strtolower(trim((string)$env)), ['1', 'true', 'yes', 'y', 'on'], true))
			|| Option::get('mf.supplier_orders', 'log', 'N') === 'Y';
		if (!$on)
		{
			return;
		}
		if ($context !== [])
		{
			$message .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		Debug::writeToFile($message, '', 'mf_supplier_orders.log');
	}
}

if (!function_exists('mf_supplier_orders_api_url'))
{
	function mf_supplier_orders_api_url(): string
	{
		$env = getenv('MF_UNF_SUPPLIER_ORDERS_URL');
		if ($env !== false && trim((string)$env) !== '')
		{
			return trim((string)$env);
		}

		return trim(Option::get('mf.supplier_orders', 'api_url', ''));
	}
}

if (!function_exists('mf_supplier_orders_timeout_seconds'))
{
	function mf_supplier_orders_timeout_seconds(): int
	{
		$env = getenv('MF_UNF_SUPPLIER_ORDERS_TIMEOUT');
		if ($env !== false && is_numeric($env))
		{
			return max(30, (int)$env);
		}
		$v = (int)Option::get('mf.supplier_orders', 'timeout', '180');

		return max(30, $v > 0 ? $v : 180);
	}
}

if (!function_exists('mf_supplier_orders_state_key_expected'))
{
	/**
	 * Нормализованное сравнение с state.key из JSON (например «в работе»).
	 */
	function mf_supplier_orders_state_key_expected(): string
	{
		$env = getenv('MF_SUPPLIER_ORDERS_STATE_KEY');
		if ($env !== false && trim((string)$env) !== '')
		{
			return mb_strtolower(trim((string)$env));
		}
		$s = trim(Option::get('mf.supplier_orders', 'state_key', 'в работе'));

		return mb_strtolower($s !== '' ? $s : 'в работе');
	}
}

if (!function_exists('mf_supplier_orders_catalog_iblock_id'))
{
	function mf_supplier_orders_catalog_iblock_id(): int
	{
		$env = getenv('MF_SUPPLIER_ORDERS_IBLOCK_ID');
		if ($env !== false && is_numeric($env))
		{
			return max(1, (int)$env);
		}
		$v = (int)Option::get('mf.supplier_orders', 'catalog_iblock_id', '4');

		return $v > 0 ? $v : 4;
	}
}

if (!function_exists('mf_supplier_orders_null_receipt_max_order_age_days'))
{
	/**
	 * Если у строки заказа нет RECEIPT_DATE, учитываем её только если дата документа заказа не старше N дней от сегодня.
	 */
	function mf_supplier_orders_null_receipt_max_order_age_days(): int
	{
		$env = getenv('MF_SUPPLIER_ORDERS_NULL_RECEIPT_MAX_ORDER_AGE_DAYS');
		if ($env !== false && is_numeric($env))
		{
			return max(0, (int)$env);
		}

		return max(0, (int)Option::get('mf.supplier_orders', 'null_receipt_max_order_age_days', '365'));
	}
}

if (!function_exists('mf_supplier_orders_line_is_pending_arrival'))
{
	/**
	 * Строка ещё актуальна для «ожидаем поступление» / синка: поступление не в прошлом.
	 *
	 * @param \Bitrix\Main\Type\Date|null $receiptDate дата поступления из строки (может быть null)
	 * @param \Bitrix\Main\Type\DateTime|null $orderDocDate дата документа заказа
	 */
	function mf_supplier_orders_line_is_pending_arrival(?\Bitrix\Main\Type\Date $receiptDate, ?\Bitrix\Main\Type\DateTime $orderDocDate): bool
	{
		$today = new \DateTimeImmutable('today');

		if ($receiptDate !== null)
		{
			try
			{
				$rd = \DateTimeImmutable::createFromFormat('Y-m-d', $receiptDate->format('Y-m-d'));
			}
			catch (\Throwable $e)
			{
				return false;
			}
			if (!$rd)
			{
				return false;
			}

			return $rd >= $today;
		}

		if ($orderDocDate === null)
		{
			return false;
		}

		try
		{
			$dd = new \DateTimeImmutable($orderDocDate->format('Y-m-d'));
		}
		catch (\Throwable $e)
		{
			return false;
		}

		$maxAge = mf_supplier_orders_null_receipt_max_order_age_days();
		$cutoff = $today->sub(new \DateInterval('P' . $maxAge . 'D'));

		return $dd >= $cutoff;
	}
}

if (!function_exists('mf_supplier_orders_normalize_state_key'))
{
	function mf_supplier_orders_normalize_state_key(?string $key): string
	{
		return mb_strtolower(trim((string)$key));
	}
}

if (!function_exists('mf_supplier_orders_ensure_schema'))
{
	function mf_supplier_orders_ensure_schema(): void
	{
		$conn = Application::getConnection();
		$helper = $conn->getSqlHelper();

		if (!$conn->isTableExists('mf_supplier_order'))
		{
			$sql = "
				CREATE TABLE " . $helper->quote('mf_supplier_order') . " (
					`ID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
					`DOC_UUID` VARCHAR(40) NOT NULL,
					`DOC_NUMBER` VARCHAR(128) NULL,
					`DOC_DATE` DATETIME NULL,
					`DOC_REF` VARCHAR(768) NULL,
					`STATE_KEY` VARCHAR(128) NULL,
					`STATE_NAME` VARCHAR(255) NULL,
					`SUPPLIER_UUID` VARCHAR(40) NULL,
					`SUPPLIER_NAME` VARCHAR(512) NULL,
					`SYNCED_AT` DATETIME NOT NULL,
					PRIMARY KEY (`ID`),
					UNIQUE KEY `UX_MF_SO_DOC_UUID` (`DOC_UUID`),
					KEY `IX_MF_SO_SYNCED` (`SYNCED_AT`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
			";
			$conn->queryExecute($sql);
		}

		if (!$conn->isTableExists('mf_supplier_order_line'))
		{
			$sql = "
				CREATE TABLE " . $helper->quote('mf_supplier_order_line') . " (
					`ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					`ORDER_ID` INT UNSIGNED NOT NULL,
					`LINE_NO` INT NOT NULL,
					`NOM_UUID` VARCHAR(40) NULL,
					`NOM_NAME` VARCHAR(768) NULL,
					`ARTICLE` VARCHAR(256) NULL,
					`BRAND` VARCHAR(512) NULL,
					`QTY` DECIMAL(18,4) NOT NULL DEFAULT 0,
					`UNIT` VARCHAR(32) NULL,
					`RECEIPT_DATE` DATE NULL,
					`PRODUCT_ID` INT NULL,
					`MATCH_STATUS` VARCHAR(16) NOT NULL DEFAULT 'not_found',
					`UNIT_PRICE` DECIMAL(18,4) NULL,
					PRIMARY KEY (`ID`),
					UNIQUE KEY `UX_MF_SOL_ORDER_LINE` (`ORDER_ID`, `LINE_NO`),
					KEY `IX_MF_SOL_PRODUCT` (`PRODUCT_ID`),
					KEY `IX_MF_SOL_MATCH` (`MATCH_STATUS`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
			";
			$conn->queryExecute($sql);
		}
		if ($conn->isTableExists('mf_supplier_order_line'))
		{
			$lineTable = $helper->quote('mf_supplier_order_line');
			$hasUnit = $conn->query('SHOW COLUMNS FROM ' . $lineTable . " LIKE 'UNIT_PRICE'")->fetch();
			if (!$hasUnit)
			{
				$conn->queryExecute(
					'ALTER TABLE ' . $lineTable . ' ADD COLUMN `UNIT_PRICE` DECIMAL(18,4) NULL DEFAULT NULL'
				);
			}
		}
	}
}

if (!function_exists('mf_supplier_orders_http_basic'))
{
	/**
	 * @return array{user: string, pass: string}
	 */
	function mf_supplier_orders_http_basic(): array
	{
		if (class_exists(\Mf\Unf\Config::class))
		{
			return [
				'user' => \Mf\Unf\Config::basicUser(),
				'pass' => \Mf\Unf\Config::basicPass(),
			];
		}

		$u = getenv('MF_UNF_BASIC_USER');
		$p = getenv('MF_UNF_BASIC_PASS');
		if ($u !== false && trim((string)$u) !== '')
		{
			return ['user' => trim((string)$u), 'pass' => $p !== false ? (string)$p : ''];
		}

		return [
			'user' => trim(Option::get('mf.unf', 'basic_user', '')),
			'pass' => trim(Option::get('mf.unf', 'basic_pass', '')),
		];
	}
}

if (!function_exists('mf_supplier_orders_fetch_json'))
{
	/**
	 * GET JSON с тем же Basic Auth, что и остальной UNF.
	 *
	 * @return array{http: int, body: string, error: ?string}
	 */
	function mf_supplier_orders_fetch_json(string $url): array
	{
		$url = trim($url);
		if ($url === '')
		{
			return ['http' => 0, 'body' => '', 'error' => 'MF_UNF_SUPPLIER_ORDERS_URL / mf.supplier_orders api_url is empty'];
		}

		$basic = mf_supplier_orders_http_basic();
		$token = '';
		if (class_exists(\Mf\Unf\Config::class))
		{
			$token = \Mf\Unf\Config::token();
		}

		// Bitrix HttpClient не использует ключ «timeout» — только socketTimeout и streamTimeout.
		// По умолчанию streamTimeout=60 с, из‑за этого длинный ответ API обрывался (HTTP 0, 0 байт).
		$waitSec = mf_supplier_orders_timeout_seconds();
		$client = new HttpClient([
			'redirect' => true,
			'streamTimeout' => $waitSec,
			'socketTimeout' => min(120, max(30, (int)ceil($waitSec / 2))),
			'disableSslVerification' => false,
		]);

		$headers = [
			'Accept' => 'application/json, */*',
		];
		$client->setHeaders($headers);
		if ($basic['user'] !== '')
		{
			$client->setAuthorization($basic['user'], $basic['pass']);
			if ($token !== '')
			{
				$client->setHeader('X-MF-Token', $token);
			}
		}
		elseif ($token !== '')
		{
			$client->setHeader('Authorization', 'Bearer ' . $token);
		}

		$error = null;
		$body = '';
		$http = 0;
		try
		{
			$body = (string)$client->get($url);
			$http = (int)$client->getStatus();
		}
		catch (\Throwable $e)
		{
			$error = $e->getMessage();
		}

		return ['http' => $http, 'body' => $body, 'error' => $error];
	}
}

if (!function_exists('mf_supplier_orders_parse_date'))
{
	function mf_supplier_orders_parse_date(?string $iso): ?\Bitrix\Main\Type\Date
	{
		$s = trim((string)$iso);
		if ($s === '')
		{
			return null;
		}
		if (preg_match('~^(\d{4}-\d{2}-\d{2})~', $s, $m))
		{
			try
			{
				return new \Bitrix\Main\Type\Date($m[1], 'Y-m-d');
			}
			catch (\Throwable $e)
			{
				return null;
			}
		}

		return null;
	}
}

if (!function_exists('mf_supplier_orders_parse_datetime'))
{
	function mf_supplier_orders_parse_datetime(?string $iso): ?\Bitrix\Main\Type\DateTime
	{
		$s = trim((string)$iso);
		if ($s === '')
		{
			return null;
		}
		try
		{
			$d = new \DateTimeImmutable($s);

			return \Bitrix\Main\Type\DateTime::createFromPhp($d);
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}
}

if (!function_exists('mf_supplier_orders_parse_line_unit_price'))
{
	/**
	 * Цена единицы из строки 1С: price.unit_price.
	 */
	function mf_supplier_orders_parse_line_unit_price(array $line): ?float
	{
		$pb = is_array($line['price'] ?? null) ? $line['price'] : [];
		if (!\array_key_exists('unit_price', $pb))
		{
			return null;
		}
		$v = (float)($pb['unit_price'] ?? 0.0);
		if (!is_finite($v) || $v <= 0.0)
		{
			return null;
		}

		return round($v, 4);
	}
}

if (!function_exists('mf_supplier_orders_line_qty_from_api'))
{
	/**
	 * Количество для mf_supplier_order_line.QTY: из JSON 1С поле quantity_ordered (заказано в строке).
	 */
	function mf_supplier_orders_line_qty_from_api(array $line): float
	{
		$qty = isset($line['quantity_ordered']) ? (float)$line['quantity_ordered'] : 0.0;
		if (!is_finite($qty) || $qty < 0)
		{
			return 0.0;
		}

		return $qty;
	}
}

if (!function_exists('mf_supplier_orders_fill_base_price_enabled'))
{
	function mf_supplier_orders_fill_base_price_enabled(): bool
	{
		$e = getenv('MF_SUPPLIER_ORDERS_FILL_BASE_PRICE');
		if ($e !== false && in_array(mb_strtolower(trim((string)$e)), ['0', 'false', 'n', 'no', 'off'], true))
		{
			return false;
		}

		return true;
	}
}

if (!function_exists('mf_supplier_orders_base_price_currency'))
{
	function mf_supplier_orders_base_price_currency(): string
	{
		$e = getenv('MF_SUPPLIER_ORDERS_PRICE_CURRENCY');
		if ($e !== false && trim((string)$e) !== '')
		{
			return strtoupper(trim((string)$e));
		}

		return 'RUB';
	}
}

if (!function_exists('mf_supplier_orders_internal_store_price_group_id'))
{
	/**
	 * CATALOG_GROUP_ID для склада MOTOR_FORCE_INTERNAL (см. mf_supplier_store_to_price_group).
	 */
	function mf_supplier_orders_internal_store_price_group_id(): int
	{
		$storeId = function_exists('mf_supplier_orders_internal_store_id')
			? mf_supplier_orders_internal_store_id()
			: 0;
		if ($storeId <= 0)
		{
			return 0;
		}
		$map = function_exists('mf_supplier_store_to_price_group') ? mf_supplier_store_to_price_group() : [];

		return (int)($map[$storeId] ?? 0);
	}
}

if (!function_exists('mf_supplier_orders_catalog_ids_for_store_raw_price'))
{
	/**
	 * Те же ID, что и mf_ep_set_raw_price_for_catalog_cluster (кластер + торговый ID).
	 *
	 * @return int[]
	 */
	function mf_supplier_orders_catalog_ids_for_store_raw_price(int $foundElementId): array
	{
		$foundElementId = (int)$foundElementId;
		if ($foundElementId <= 0)
		{
			return [];
		}
		$ids = [$foundElementId];
		if (function_exists('mf_catalog_product_cluster_ids'))
		{
			$cluster = mf_catalog_product_cluster_ids($foundElementId);
			if (!empty($cluster))
			{
				$ids = $cluster;
			}
		}
		if (function_exists('mf_ep_resolve_catalog_trade_product_id'))
		{
			$trade = mf_ep_resolve_catalog_trade_product_id($foundElementId);
			if ($trade > 0)
			{
				$ids[] = $trade;
			}
		}

		return array_values(array_unique(array_filter($ids, static function ($v): bool {
			return (int)$v > 0;
		})));
	}
}

if (!function_exists('mf_supplier_orders_internal_store_raw_price_missing'))
{
	/**
	 * true — ни у одного ID кластера нет положительной цены в данном типе (закуп по складу).
	 */
	function mf_supplier_orders_internal_store_raw_price_missing(int $productId, int $priceGroupId): bool
	{
		$productId = (int)$productId;
		$priceGroupId = (int)$priceGroupId;
		if ($productId <= 0 || $priceGroupId <= 0)
		{
			return true;
		}
		if (!Loader::includeModule('catalog') || !class_exists(\CPrice::class))
		{
			return true;
		}
		foreach (mf_supplier_orders_catalog_ids_for_store_raw_price($productId) as $cid)
		{
			$db = \CPrice::GetList(
				['ID' => 'ASC'],
				['PRODUCT_ID' => (int)$cid, 'CATALOG_GROUP_ID' => $priceGroupId],
				false,
				false,
				['PRICE']
			);
			if (!($row = $db->Fetch()) || !is_array($row))
			{
				continue;
			}
			$p = (float)($row['PRICE'] ?? 0.0);
			if (is_finite($p) && $p > 0.0)
			{
				return false;
			}
		}

		return true;
	}
}

if (!function_exists('mf_supplier_orders_try_set_internal_store_raw_price_from_1c'))
{
	/**
	 * Закуп из 1С (price.unit_price) в RAW для склада MOTOR_FORCE_INTERNAL, если в типе цены этого склада ещё пусто.
	 * Пишет по кластеру каталога, как внешние прайсы (mf_ep_set_raw_price_for_catalog_cluster).
	 *
	 * @return bool true, если dry-run сработал «бы проставил» или реальная запись без ошибок
	 */
	function mf_supplier_orders_try_set_internal_store_raw_price_from_1c(int $productId, ?float $unitPrice, bool $dryRun): bool
	{
		$productId = (int)$productId;
		if ($productId <= 0 || $unitPrice === null || $unitPrice <= 0.0)
		{
			return false;
		}
		if (!mf_supplier_orders_fill_base_price_enabled())
		{
			return false;
		}
		$gid = mf_supplier_orders_internal_store_price_group_id();
		if ($gid <= 0)
		{
			mf_supplier_orders_log('internal store price type not found (map store→CATALOG_GROUP_ID)', [
				'store_id' => function_exists('mf_supplier_orders_internal_store_id') ? mf_supplier_orders_internal_store_id() : 0,
			]);

			return false;
		}
		if (!mf_supplier_orders_internal_store_raw_price_missing($productId, $gid))
		{
			return false;
		}
		if ($dryRun)
		{
			return true;
		}
		if (!function_exists('mf_ep_set_raw_price_for_catalog_cluster'))
		{
			mf_supplier_orders_log('mf_ep_set_raw_price_for_catalog_cluster missing', ['product_id' => $productId]);

			return false;
		}
		$cur = mf_supplier_orders_base_price_currency();
		$price = function_exists('mf_round_price') ? mf_round_price($unitPrice) : (float)ceil($unitPrice);
		if ($price <= 0.0)
		{
			return false;
		}
		$fail = mf_ep_set_raw_price_for_catalog_cluster($productId, $gid, $price, $cur);
		if ($fail > 0)
		{
			mf_supplier_orders_log('mf_ep_set_raw_price_for_catalog_cluster partial fail', [
				'product_id' => $productId,
				'price_group_id' => $gid,
				'price' => $price,
				'fail' => $fail,
			]);
		}

		return $fail === 0;
	}
}

if (!function_exists('mf_supplier_orders_match_product_id'))
{
	function mf_supplier_orders_match_product_id(int $iblockId, string $articleRaw, string $brandRaw): array
	{
		if (!function_exists('mf_ep_norm_article') || !function_exists('mf_ep_norm_brand') || !function_exists('mf_ep_find_product'))
		{
			return ['product_id' => null, 'status' => 'not_found'];
		}

		$articleNorm = mf_ep_norm_article($articleRaw);
		$brandNorm = mf_ep_norm_brand($brandRaw);
		if ($articleNorm === '')
		{
			return ['product_id' => null, 'status' => 'not_found'];
		}

		$pid = mf_ep_find_product($iblockId, $articleNorm, trim($brandRaw), $brandNorm);

		return [
			'product_id' => $pid !== null && $pid > 0 ? $pid : null,
			'status' => ($pid !== null && $pid > 0) ? 'matched' : 'not_found',
		];
	}
}

if (!function_exists('mf_supplier_orders_sync'))
{
	/**
	 * Загрузка API, фильтр по state.key, upsert заказов/строк, удаление отсутствующих в выборке.
	 *
	 * @param callable|null $progress function(string $phase, array $context): void
	 *   Фазы: fetch_start, fetch_done, filtered, order (current,total,uuid,number), lines (saved_total или processed),
	 *   purge_start, done (краткая сводка перед успешным завершением).
	 * @param array $progressOpts every_order (int, по умолчанию 1; 0 = не выводить по заказам),
	 *   every_line (int, по умолчанию 0; >0 — событие lines каждые N сохранённых/обработанных строк).
	 *
	 * @return array{
	 *   ok: bool,
	 *   http: int,
	 *   error: ?string,
	 *   orders_in_response: int,
	 *   orders_kept: int,
	 *   lines_saved: int,
	 *   lines_matched: int,
	 *   orders_removed: int,
	 *   dry_run: bool,
	 *   prices_filled: int,
	 *   prices_would_fill: int
	 * }
	 */
	function mf_supplier_orders_sync(bool $dryRun = false, ?callable $progress = null, array $progressOpts = []): array
	{
		$everyOrder = (int)($progressOpts['every_order'] ?? 1);
		if ($everyOrder < 0)
		{
			$everyOrder = 1;
		}
		$everyLine = (int)($progressOpts['every_line'] ?? 0);
		if ($everyLine < 0)
		{
			$everyLine = 0;
		}

		$p = static function (string $phase, array $context = []) use ($progress): void {
			if ($progress === null)
			{
				return;
			}
			try
			{
				$progress($phase, $context);
			}
			catch (\Throwable $e)
			{
				mf_supplier_orders_log('progress callback failed', ['phase' => $phase, 'e' => $e->getMessage()]);
			}
		};

		$shouldEmitOrder = static function (int $current, int $total) use ($everyOrder): bool {
			if ($everyOrder === 0)
			{
				return false;
			}
			if ($current === $total && $total > 0)
			{
				return true;
			}

			return $current % $everyOrder === 0;
		};

		$out = [
			'ok' => false,
			'http' => 0,
			'error' => null,
			'orders_in_response' => 0,
			'orders_kept' => 0,
			'lines_saved' => 0,
			'lines_matched' => 0,
			'orders_removed' => 0,
			'dry_run' => $dryRun,
			'prices_filled' => 0,
			'prices_would_fill' => 0,
		];

		$url = mf_supplier_orders_api_url();
		$p('fetch_start', ['url' => $url, 'timeout_sec' => mf_supplier_orders_timeout_seconds()]);
		$fetch = mf_supplier_orders_fetch_json($url);
		$out['http'] = $fetch['http'];
		$p('fetch_done', [
			'http' => $fetch['http'],
			'bytes' => strlen($fetch['body']),
			'error' => $fetch['error'],
		]);

		if ($fetch['error'] !== null)
		{
			$out['error'] = $fetch['error'];
			$p('abort', ['reason' => 'fetch_transport', 'error' => $fetch['error']]);
			mf_supplier_orders_log('fetch failed', ['e' => $fetch['error']]);

			return $out;
		}

		if ($fetch['http'] < 200 || $fetch['http'] >= 300)
		{
			$out['error'] = 'HTTP ' . $fetch['http'];
			$p('abort', ['reason' => 'fetch_http', 'http' => $fetch['http']]);
			mf_supplier_orders_log('bad http', ['http' => $fetch['http'], 'snippet' => mb_substr($fetch['body'], 0, 300)]);

			return $out;
		}

		$decoded = json_decode($fetch['body'], true);
		if (!is_array($decoded))
		{
			$out['error'] = 'invalid JSON';
			$p('abort', ['reason' => 'invalid_json']);
			mf_supplier_orders_log('json decode failed', []);

			return $out;
		}

		if (empty($decoded['ok']))
		{
			$out['error'] = 'API ok=false';
			$p('abort', ['reason' => 'api_ok_false']);
			mf_supplier_orders_log('api ok false', ['decoded_keys' => array_keys($decoded)]);

			return $out;
		}

		$ordersRaw = $decoded['orders'] ?? null;
		if (!is_array($ordersRaw))
		{
			$out['error'] = 'missing orders array';
			$p('abort', ['reason' => 'missing_orders']);
			mf_supplier_orders_log('no orders key', []);

			return $out;
		}

		$wantState = mf_supplier_orders_state_key_expected();
		$toSync = [];
		foreach ($ordersRaw as $ord)
		{
			if (!is_array($ord))
			{
				continue;
			}
			$state = $ord['state'] ?? null;
			$key = is_array($state) ? ($state['key'] ?? '') : '';
			if (mf_supplier_orders_normalize_state_key((string)$key) !== $wantState)
			{
				continue;
			}
			$uuid = trim((string)($ord['uuid'] ?? ''));
			if ($uuid === '')
			{
				continue;
			}
			$toSync[$uuid] = $ord;
		}

		$out['orders_in_response'] = count($ordersRaw);
		$out['orders_kept'] = count($toSync);

		$p('filtered', [
			'orders_in_response' => $out['orders_in_response'],
			'orders_kept' => $out['orders_kept'],
			'state_key' => $wantState,
		]);

		$iblockId = mf_supplier_orders_catalog_iblock_id();
		if (!Loader::includeModule('iblock'))
		{
			$out['error'] = 'iblock module unavailable';
			$p('abort', ['reason' => 'no_iblock']);
			mf_supplier_orders_log('no iblock', []);

			return $out;
		}

		if ($dryRun)
		{
			$matched = 0;
			$lines = 0;
			$orderTotal = count($toSync);
			$oIndex = 0;
			foreach ($toSync as $uuid => $ord)
			{
				$oIndex++;
				if ($shouldEmitOrder($oIndex, $orderTotal))
				{
					$p('order', [
						'current' => $oIndex,
						'total' => $orderTotal,
						'uuid' => $uuid,
						'number' => trim((string)($ord['number'] ?? '')),
						'dry_run' => true,
					]);
				}

				$linesArr = $ord['lines'] ?? [];
				if (!is_array($linesArr))
				{
					continue;
				}
				$docDtOrd = mf_supplier_orders_parse_datetime(trim((string)($ord['date'] ?? '')));
				foreach ($linesArr as $ln)
				{
					if (!is_array($ln))
					{
						continue;
					}
					$expectedDr = is_array($ln['expected'] ?? null) ? $ln['expected'] : [];
					$receiptIsoDry = trim((string)($expectedDr['receipt_date'] ?? ''));
					$rdDry = mf_supplier_orders_parse_date($receiptIsoDry);
					if (!mf_supplier_orders_line_is_pending_arrival($rdDry, $docDtOrd))
					{
						continue;
					}
					$lines++;
					$nom = $ln['nomenclature'] ?? [];
					$article = is_array($nom) ? trim((string)($nom['article'] ?? '')) : '';
					$brand = is_array($nom) ? trim((string)($nom['brand'] ?? '')) : '';
					$m = mf_supplier_orders_match_product_id($iblockId, $article, $brand);
					if (($m['status'] ?? '') === 'matched')
					{
						$matched++;
					}
					$unitDry = mf_supplier_orders_parse_line_unit_price($ln);
					$pidDry = isset($m['product_id']) ? (int)$m['product_id'] : 0;
					if ($pidDry > 0 && mf_supplier_orders_try_set_internal_store_raw_price_from_1c($pidDry, $unitDry, true))
					{
						$out['prices_would_fill']++;
					}
					if ($everyLine > 0 && $lines % $everyLine === 0)
					{
						$p('lines', ['processed' => $lines, 'matched_so_far' => $matched, 'dry_run' => true]);
					}
				}
			}
			$out['lines_saved'] = $lines;
			$out['lines_matched'] = $matched;
			$out['ok'] = true;
			$p('done', [
				'ok' => true,
				'dry_run' => true,
				'orders_kept' => $out['orders_kept'],
				'lines_processed' => $lines,
				'lines_matched' => $matched,
				'prices_would_fill' => $out['prices_would_fill'],
			]);

			return $out;
		}

		mf_supplier_orders_ensure_schema();

		$conn = Application::getConnection();
		$helper = $conn->getSqlHelper();
		$now = new \Bitrix\Main\Type\DateTime();

		$uuids = array_keys($toSync);
		$orderTotal = count($toSync);
		$oIndex = 0;

		try
		{
			$conn->startTransaction();
			$sqlNow = $helper->convertToDbDateTime($now);

			foreach ($toSync as $uuid => $ord)
			{
				$oIndex++;
				$number = trim((string)($ord['number'] ?? ''));
				if ($shouldEmitOrder($oIndex, $orderTotal))
				{
					$p('order', [
						'current' => $oIndex,
						'total' => $orderTotal,
						'uuid' => $uuid,
						'number' => $number,
						'dry_run' => false,
					]);
				}
				$ref = trim((string)($ord['ref'] ?? ''));
				$dateIso = trim((string)($ord['date'] ?? ''));
				$state = is_array($ord['state'] ?? null) ? $ord['state'] : [];
				$stateKey = trim((string)($state['key'] ?? ''));
				$stateName = trim((string)($state['name'] ?? ''));
				$sup = is_array($ord['supplier'] ?? null) ? $ord['supplier'] : [];
				$supUuid = trim((string)($sup['uuid'] ?? ''));
				$supName = trim((string)($sup['name'] ?? ''));

				$docDt = mf_supplier_orders_parse_datetime($dateIso);
				$docSql = $docDt !== null ? $helper->convertToDbDateTime($docDt) : 'NULL';

				$conn->queryExecute(
					"INSERT INTO " . $helper->quote('mf_supplier_order') . "
					(`DOC_UUID`,`DOC_NUMBER`,`DOC_DATE`,`DOC_REF`,`STATE_KEY`,`STATE_NAME`,`SUPPLIER_UUID`,`SUPPLIER_NAME`,`SYNCED_AT`)
					VALUES (
						'" . $helper->forSql($uuid) . "',
						" . ($number !== '' ? "'" . $helper->forSql($number) . "'" : 'NULL') . ",
						" . ($docDt !== null ? $docSql : 'NULL') . ",
						" . ($ref !== '' ? "'" . $helper->forSql($ref) . "'" : 'NULL') . ",
						" . ($stateKey !== '' ? "'" . $helper->forSql($stateKey) . "'" : 'NULL') . ",
						" . ($stateName !== '' ? "'" . $helper->forSql($stateName) . "'" : 'NULL') . ",
						" . ($supUuid !== '' ? "'" . $helper->forSql($supUuid) . "'" : 'NULL') . ",
						" . ($supName !== '' ? "'" . $helper->forSql($supName) . "'" : 'NULL') . ",
						" . $sqlNow . "
					)
					ON DUPLICATE KEY UPDATE
						`DOC_NUMBER` = VALUES(`DOC_NUMBER`),
						`DOC_DATE` = VALUES(`DOC_DATE`),
						`DOC_REF` = VALUES(`DOC_REF`),
						`STATE_KEY` = VALUES(`STATE_KEY`),
						`STATE_NAME` = VALUES(`STATE_NAME`),
						`SUPPLIER_UUID` = VALUES(`SUPPLIER_UUID`),
						`SUPPLIER_NAME` = VALUES(`SUPPLIER_NAME`),
						`SYNCED_AT` = VALUES(`SYNCED_AT`)"
				);

				$row = $conn->query(
					"SELECT `ID` FROM " . $helper->quote('mf_supplier_order') . " WHERE `DOC_UUID`='" . $helper->forSql($uuid) . "' LIMIT 1"
				)->fetch();
				$orderId = (int)($row['ID'] ?? 0);
				if ($orderId <= 0)
				{
					throw new \RuntimeException('order id not found after upsert');
				}

				$conn->queryExecute(
					'DELETE FROM ' . $helper->quote('mf_supplier_order_line') . ' WHERE `ORDER_ID`=' . $orderId
				);

				$linesArr = $ord['lines'] ?? [];
				if (!is_array($linesArr))
				{
					$linesArr = [];
				}

				$linesInserted = 0;

				foreach ($linesArr as $ln)
				{
					if (!is_array($ln))
					{
						continue;
					}
					$lineNo = (int)($ln['line_no'] ?? 0);
					if ($lineNo <= 0)
					{
						continue;
					}
					$nom = is_array($ln['nomenclature'] ?? null) ? $ln['nomenclature'] : [];
					$nomUuid = trim((string)($nom['uuid'] ?? ''));
					$nomName = trim((string)($nom['name'] ?? ''));
					$article = trim((string)($nom['article'] ?? ''));
					$brand = trim((string)($nom['brand'] ?? ''));
					$qty = mf_supplier_orders_line_qty_from_api($ln);
					$qtyLiteral = sprintf('%.6F', $qty);
					$unit = trim((string)($ln['unit'] ?? ''));
					$expected = is_array($ln['expected'] ?? null) ? $ln['expected'] : [];
					$receiptIso = trim((string)($expected['receipt_date'] ?? ''));

					$rd = mf_supplier_orders_parse_date($receiptIso);
					if (!mf_supplier_orders_line_is_pending_arrival($rd, $docDt))
					{
						continue;
					}

					$unitPrice = mf_supplier_orders_parse_line_unit_price($ln);
					$uPriceSql = $unitPrice !== null ? sprintf('%.4F', $unitPrice) : 'NULL';

					$match = mf_supplier_orders_match_product_id($iblockId, $article, $brand);
					$pid = $match['product_id'];
					$mstat = $match['status'];

					$rdSql = $rd !== null ? "'" . $helper->forSql($rd->format('Y-m-d')) . "'" : 'NULL';

					$conn->queryExecute(
						'INSERT INTO ' . $helper->quote('mf_supplier_order_line') . "
						(`ORDER_ID`,`LINE_NO`,`NOM_UUID`,`NOM_NAME`,`ARTICLE`,`BRAND`,`QTY`,`UNIT`,`RECEIPT_DATE`,`PRODUCT_ID`,`MATCH_STATUS`,`UNIT_PRICE`)
						VALUES (
							{$orderId},
							{$lineNo},
							" . ($nomUuid !== '' ? "'" . $helper->forSql($nomUuid) . "'" : 'NULL') . ',
							' . ($nomName !== '' ? "'" . $helper->forSql(mb_substr($nomName, 0, 750)) . "'" : 'NULL') . ",
							" . ($article !== '' ? "'" . $helper->forSql(mb_substr($article, 0, 250)) . "'" : 'NULL') . ",
							" . ($brand !== '' ? "'" . $helper->forSql(mb_substr($brand, 0, 500)) . "'" : 'NULL') . ",
							" . $qtyLiteral . ",
							" . ($unit !== '' ? "'" . $helper->forSql(mb_substr($unit, 0, 32)) . "'" : 'NULL') . ",
							{$rdSql},
							" . ($pid !== null && $pid > 0 ? (string)(int)$pid : 'NULL') . ",
							'" . $helper->forSql($mstat) . "',
							" . $uPriceSql . '
						)'
					);

					$linesInserted++;
					$out['lines_saved']++;
					if ($mstat === 'matched')
					{
						$out['lines_matched']++;
					}
					if ($mstat === 'matched' && $pid !== null && (int)$pid > 0
						&& mf_supplier_orders_try_set_internal_store_raw_price_from_1c((int)$pid, $unitPrice, false))
					{
						$out['prices_filled']++;
					}

					if ($everyLine > 0 && $out['lines_saved'] % $everyLine === 0)
					{
						$p('lines', [
							'saved_total' => $out['lines_saved'],
							'matched_total' => $out['lines_matched'],
							'order_current' => $oIndex,
							'order_total' => $orderTotal,
						]);
					}
				}

				if ($linesInserted === 0)
				{
					$conn->queryExecute(
						'DELETE FROM ' . $helper->quote('mf_supplier_order') . ' WHERE `ID`=' . $orderId
					);
				}
			}

			if ($uuids === [])
			{
				$p('purge_start', ['mode' => 'clear_all']);
				$cntRow = $conn->query(
					'SELECT COUNT(*) AS `C` FROM ' . $helper->quote('mf_supplier_order')
				)->fetch();
				$out['orders_removed'] = (int)($cntRow['C'] ?? 0);
				$conn->queryExecute('DELETE FROM ' . $helper->quote('mf_supplier_order_line'));
				$conn->queryExecute('DELETE FROM ' . $helper->quote('mf_supplier_order'));
			}
			else
			{
				$p('purge_start', ['mode' => 'remove_stale']);
				$inList = [];
				foreach ($uuids as $u)
				{
					$inList[] = "'" . $helper->forSql($u) . "'";
				}
				$inSql = implode(',', $inList);

				$cntRow = $conn->query(
					'SELECT COUNT(*) AS `C` FROM ' . $helper->quote('mf_supplier_order') . '
					WHERE `DOC_UUID` NOT IN (' . $inSql . ')'
				)->fetch();
				$out['orders_removed'] = (int)($cntRow['C'] ?? 0);

				$conn->queryExecute(
					'DELETE FROM ' . $helper->quote('mf_supplier_order_line') . '
					WHERE `ORDER_ID` IN (
						SELECT `ID` FROM (
							SELECT `ID` FROM ' . $helper->quote('mf_supplier_order') . ' WHERE `DOC_UUID` NOT IN (' . $inSql . ')
						) t
					)'
				);
				$conn->queryExecute(
					'DELETE FROM ' . $helper->quote('mf_supplier_order') . ' WHERE `DOC_UUID` NOT IN (' . $inSql . ')'
				);
			}

			$p('purge_done', ['orders_removed' => $out['orders_removed']]);

			$conn->commitTransaction();
			$out['ok'] = true;
			$p('done', [
				'ok' => true,
				'dry_run' => false,
				'orders_kept' => $out['orders_kept'],
				'lines_saved' => $out['lines_saved'],
				'lines_matched' => $out['lines_matched'],
				'orders_removed' => $out['orders_removed'],
				'prices_filled' => $out['prices_filled'],
			]);
			mf_supplier_orders_log('sync ok', [
				'orders_kept' => $out['orders_kept'],
				'lines_saved' => $out['lines_saved'],
				'lines_matched' => $out['lines_matched'],
				'prices_filled' => $out['prices_filled'],
			]);
		}
		catch (\Throwable $e)
		{
			try
			{
				$conn->rollbackTransaction();
			}
			catch (\Throwable $e2)
			{
			}
			$out['error'] = $e->getMessage();
			$p('abort', ['reason' => 'exception', 'message' => $e->getMessage()]);
			mf_supplier_orders_log('sync exception', ['e' => $e->getMessage()]);
		}

		return $out;
	}
}

if (!function_exists('mf_supplier_orders_internal_store_id'))
{
	/**
	 * Склад Motor-Force (внутренний): CODE = MOTOR_FORCE_INTERNAL или XML_ID содержит MOTOR_FORCE_INTERNAL.
	 */
	function mf_supplier_orders_internal_store_id(): int
	{
		static $cached = null;
		if ($cached !== null)
		{
			return $cached;
		}
		$cached = 0;
		if (!Loader::includeModule('catalog') || !class_exists(\CCatalogStore::class))
		{
			return $cached;
		}
		$rs = \CCatalogStore::GetList([], ['ACTIVE' => 'Y'], false, ['nTopCount' => 200], ['ID', 'CODE', 'XML_ID']);
		while ($r = $rs->Fetch())
		{
			$code = mb_strtoupper(trim((string)($r['CODE'] ?? '')));
			$xml = mb_strtoupper(trim((string)($r['XML_ID'] ?? '')));
			if ($code === 'MOTOR_FORCE_INTERNAL' || ($xml !== '' && mb_strpos($xml, 'MOTOR_FORCE_INTERNAL') !== false))
			{
				$cached = (int)($r['ID'] ?? 0);
				break;
			}
		}

		return $cached;
	}
}

if (!function_exists('mf_supplier_orders_cluster_amount_on_store'))
{
	/**
	 * Сумма остатка по кластеру торговых ID на одном складе.
	 */
	function mf_supplier_orders_cluster_amount_on_store(int $productId, int $storeId): float
	{
		$productId = (int)$productId;
		$storeId = (int)$storeId;
		if ($productId <= 0 || $storeId <= 0 || !class_exists(\CCatalogStoreProduct::class))
		{
			return 0.0;
		}
		$sum = 0.0;
		$cluster = function_exists('mf_catalog_product_cluster_ids')
			? mf_catalog_product_cluster_ids($productId)
			: [$productId];
		foreach ($cluster as $cid)
		{
			$cid = (int)$cid;
			if ($cid <= 0)
			{
				continue;
			}
			$rs = \CCatalogStoreProduct::GetList(
				[],
				['PRODUCT_ID' => $cid, 'STORE_ID' => $storeId],
				false,
				false,
				['AMOUNT']
			);
			while ($r = $rs->Fetch())
			{
				$sum += (float)($r['AMOUNT'] ?? 0);
			}
		}

		return $sum;
	}
}

if (!function_exists('mf_supplier_orders_pending_arrival_label'))
{
	/**
	 * Текст для витрины: «N шт., ожидаем поступление …».
	 *
	 * @param float $qty сумма количества по строкам заказов
	 */
	function mf_supplier_orders_pending_arrival_label(float $qty, ?string $receiptDateYmd): string
	{
		if ($qty <= 1e-9)
		{
			return '';
		}
		$qtyPretty = (abs($qty - round($qty)) < 1e-6)
			? (string)(int)round($qty)
			: rtrim(rtrim(sprintf('%.4f', $qty), '0'), '.');
		$base = $qtyPretty . ' шт., ожидаем поступление';

		if ($receiptDateYmd === null || trim($receiptDateYmd) === '')
		{
			return $base;
		}
		$rd = \DateTimeImmutable::createFromFormat('Y-m-d', trim($receiptDateYmd));
		if (!$rd)
		{
			return $base;
		}
		$today = new \DateTimeImmutable('today');
		$days = (int)floor(($rd->getTimestamp() - $today->getTimestamp()) / 86400);
		if ($days < 0)
		{
			return $base . ' (план: ' . $rd->format('d.m.Y') . ')';
		}
		if ($days === 0)
		{
			return $base . ' (сегодня)';
		}
		if ($days === 1)
		{
			return $base . ' (завтра)';
		}
		$decl = function_exists('mf_store_decl_days_ru') ? mf_store_decl_days_ru($days) : 'дн.';

		return $base . ' (через ' . $days . ' ' . $decl . ')';
	}
}

if (!function_exists('mf_supplier_orders_pending_arrival_for_product'))
{
	/**
	 * Данные по активным заказам поставщику: MATCH_STATUS = matched, только строки «ещё не поступили»:
	 * — RECEIPT_DATE >= сегодня, либо дата поступления пуста и дата документа заказа не старше N дней (см. mf_supplier_orders_null_receipt_max_order_age_days).
	 * Количество — сумма QTY (из quantity_ordered при синке) по всем таким строкам.
	 *
	 * @return array{qty: float, receipt_date: ?string, days_until: ?int, label: string}|null
	 */
	function mf_supplier_orders_pending_arrival_for_product(int $productId): ?array
	{
		static $memo = [];
		$productId = (int)$productId;
		if ($productId <= 0)
		{
			return null;
		}
		if (array_key_exists($productId, $memo))
		{
			return $memo[$productId];
		}

		try
		{
			$conn = Application::getConnection();
			if (!$conn->isTableExists('mf_supplier_order_line'))
			{
				return $memo[$productId] = null;
			}

			$cluster = function_exists('mf_catalog_product_cluster_ids')
				? mf_catalog_product_cluster_ids($productId)
				: [$productId];
			$ids = [];
			foreach ($cluster as $cid)
			{
				$cid = (int)$cid;
				if ($cid > 0)
				{
					$ids[$cid] = true;
				}
			}
			if ($ids === [])
			{
				return $memo[$productId] = null;
			}

			$h = $conn->getSqlHelper();
			$inParts = [];
			foreach (array_keys($ids) as $cid)
			{
				$inParts[] = (string)(int)$cid;
			}
			$inSql = implode(',', $inParts);
			$tLine = $h->quote('mf_supplier_order_line');
			$tOrder = $h->quote('mf_supplier_order');
			$maxAge = mf_supplier_orders_null_receipt_max_order_age_days();

			$row = $conn->query(
				'SELECT COALESCE(SUM(l.`QTY`), 0) AS `SQ`,
					MIN(CASE WHEN l.`RECEIPT_DATE` IS NOT NULL AND l.`RECEIPT_DATE` >= CURDATE() THEN l.`RECEIPT_DATE` END) AS `RD_NEXT`
				FROM ' . $tLine . ' l
				INNER JOIN ' . $tOrder . ' o ON o.`ID` = l.`ORDER_ID`
				WHERE l.`PRODUCT_ID` IN (' . $inSql . ")
				  AND l.`MATCH_STATUS` = 'matched'
				  AND (
					(l.`RECEIPT_DATE` IS NOT NULL AND l.`RECEIPT_DATE` >= CURDATE())
					OR (l.`RECEIPT_DATE` IS NULL AND o.`DOC_DATE` >= DATE_SUB(CURDATE(), INTERVAL " . (int)$maxAge . ' DAY))
				  )'
			)->fetch();

			$qty = isset($row['SQ']) ? (float)$row['SQ'] : 0.0;
			if ($qty <= 1e-9)
			{
				return $memo[$productId] = null;
			}

			$rdStr = null;
			$rdNextRaw = $row['RD_NEXT'] ?? null;
			if ($rdNextRaw !== null && $rdNextRaw !== '')
			{
				if ($rdNextRaw instanceof \Bitrix\Main\Type\Date)
				{
					$rdStr = $rdNextRaw->format('Y-m-d');
				}
				else
				{
					$s = trim((string)$rdNextRaw);
					if (preg_match('~^(\d{4}-\d{2}-\d{2})~', $s, $m))
					{
						$rdStr = $m[1];
					}
				}
			}

			$daysUntil = null;
			if ($rdStr !== null && $rdStr !== '')
			{
				$rd = \DateTimeImmutable::createFromFormat('Y-m-d', $rdStr);
				if ($rd)
				{
					$today = new \DateTimeImmutable('today');
					$daysUntil = (int)floor(($rd->getTimestamp() - $today->getTimestamp()) / 86400);
				}
			}

			$label = mf_supplier_orders_pending_arrival_label($qty, $rdStr);

			return $memo[$productId] = [
				'qty' => $qty,
				'receipt_date' => $rdStr,
				'days_until' => $daysUntil,
				'label' => $label,
			];
		}
		catch (\Throwable $e)
		{
			return $memo[$productId] = null;
		}
	}
}
