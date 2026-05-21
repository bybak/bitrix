<?php

declare(strict_types=1);

/**
 * Пакетная очистка внешнего склада с прогрессом (админка «Очистка внешних складов»).
 */

if (!function_exists('mf_ep_clear_job_tmp_dir'))
{
	function mf_ep_clear_job_tmp_dir(): string
	{
		$doc = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

		return $doc . '/upload/tmp';
	}
}

if (!function_exists('mf_ep_clear_job_file_path'))
{
	function mf_ep_clear_job_file_path(string $token): string
	{
		$token = preg_replace('~[^a-f0-9]~', '', strtolower($token)) ?? '';

		return mf_ep_clear_job_tmp_dir() . '/mf_ep_clear_' . $token . '.json';
	}
}

if (!function_exists('mf_ep_clear_job_save'))
{
	/**
	 * @param array<string, mixed> $job
	 */
	function mf_ep_clear_job_save(array $job): bool
	{
		$token = (string)($job['token'] ?? '');
		if ($token === '')
		{
			return false;
		}
		$dir = mf_ep_clear_job_tmp_dir();
		if (!is_dir($dir))
		{
			@mkdir($dir, 0775, true);
		}
		$path = mf_ep_clear_job_file_path($token);
		$json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false)
		{
			return false;
		}

		return file_put_contents($path, $json, LOCK_EX) !== false;
	}
}

if (!function_exists('mf_ep_clear_job_load'))
{
	/**
	 * @return array<string, mixed>|null
	 */
	function mf_ep_clear_job_load(string $token): ?array
	{
		$path = mf_ep_clear_job_file_path($token);
		if (!is_file($path))
		{
			return null;
		}
		$raw = file_get_contents($path);
		if ($raw === false || $raw === '')
		{
			return null;
		}
		$data = json_decode($raw, true);

		return is_array($data) ? $data : null;
	}
}

if (!function_exists('mf_ep_clear_job_unlink'))
{
	function mf_ep_clear_job_unlink(string $token): void
	{
		$path = mf_ep_clear_job_file_path($token);
		if (is_file($path))
		{
			@unlink($path);
		}
	}
}

if (!function_exists('mf_ep_clear_warehouse_touch_product'))
{
	/**
	 * Обработка одного товара при очистке (режим feed / all).
	 *
	 * @return array{price_cleared: bool, recalc: bool}
	 */
	function mf_ep_clear_warehouse_touch_product(int $storeId, int $productId, int $priceGroupId, bool $feedMode): array
	{
		$storeId = (int)$storeId;
		$productId = (int)$productId;
		$priceGroupId = (int)$priceGroupId;
		$out = ['price_cleared' => false, 'recalc' => false];
		if ($productId <= 0 || $storeId <= 0)
		{
			return $out;
		}

		if ($feedMode && function_exists('mf_esf_price_touch_other_feed_exists') && mf_esf_price_touch_other_feed_exists($storeId, $productId))
		{
			if (function_exists('mf_ep_sync_catalog_qty_from_stores'))
			{
				mf_ep_sync_catalog_qty_from_stores($productId);
			}
			if (function_exists('mf_ep_recalc_base_one'))
			{
				mf_ep_recalc_base_one($productId);
			}
			$out['recalc'] = true;

			return $out;
		}

		if ($priceGroupId > 0 && function_exists('mf_ep_zero_product_on_store'))
		{
			mf_ep_zero_product_on_store($productId, $storeId, $priceGroupId);
			$out['price_cleared'] = true;
		}
		else
		{
			if (class_exists(\CCatalogStoreProduct::class))
			{
				$rsZ = \CCatalogStoreProduct::GetList(
					[],
					['PRODUCT_ID' => $productId, 'STORE_ID' => $storeId],
					false,
					false,
					['ID']
				);
				if ($rowZ = $rsZ->Fetch())
				{
					\CCatalogStoreProduct::Update((int)$rowZ['ID'], ['AMOUNT' => 0]);
				}
			}
			if (function_exists('mf_ep_sync_catalog_qty_from_stores'))
			{
				mf_ep_sync_catalog_qty_from_stores($productId);
			}
			if (function_exists('mf_ep_recalc_base_one'))
			{
				mf_ep_recalc_base_one($productId);
			}
			$out['recalc'] = true;
		}

		return $out;
	}
}

if (!function_exists('mf_ep_clear_job_plan'))
{
	/**
	 * @return array<string, mixed>
	 */
	function mf_ep_clear_job_plan(int $storeId, ?string $feedCode = null): array
	{
		$storeId = (int)$storeId;
		$fail = static function (string $msg): array {
			return ['ok' => false, 'error' => $msg];
		};

		if ($storeId <= 0)
		{
			return $fail('Некорректный ID склада.');
		}
		if (!function_exists('mf_ep_store_is_external_warehouse') || !mf_ep_store_is_external_warehouse($storeId))
		{
			return $fail('Операция доступна только для внешних складов.');
		}
		if (!class_exists(\CCatalogStoreProduct::class))
		{
			return $fail('Модуль catalog недоступен.');
		}

		$map = function_exists('mf_supplier_store_to_price_group') ? mf_supplier_store_to_price_group() : [];
		$priceGroupId = (int)($map[$storeId] ?? 0);

		$feedOnly = '';
		if ($feedCode !== null && $feedCode !== '')
		{
			$feedOnly = function_exists('mf_esf_normalize_feed_code') ? mf_esf_normalize_feed_code($feedCode) : '';
		}

		$storeRowIds = [];
		$productIds = [];
		$hint = '';
		$mode = 'all';

		if ($feedOnly !== '')
		{
			if (!function_exists('mf_esf_delete_feed_price_products_collect') || !function_exists('mf_esf_ensure_price_product_table'))
			{
				return $fail('Не подключён mf_external_store_feed_stock (привязка прайсов к товарам).');
			}
			mf_esf_ensure_price_product_table();
			$mode = 'feed';
			$productIds = mf_esf_delete_feed_price_products_collect($storeId, $feedOnly);
			sort($productIds, SORT_NUMERIC);
			if ($productIds === [])
			{
				$hint = 'Нет товаров, привязанных к этому прайсу: загрузите внешний CSV с этим кодом прайса или выполните полную очистку склада.';
			}
		}
		else
		{
			$pidSet = [];
			if ($priceGroupId > 0 && function_exists('mf_ep_collect_candidates_for_store'))
			{
				foreach (mf_ep_collect_candidates_for_store($storeId, $priceGroupId) as $p)
				{
					$p = (int)$p;
					if ($p > 0)
					{
						$pidSet[$p] = true;
					}
				}
			}
			else
			{
				$rs0 = \CCatalogStoreProduct::GetList(
					[],
					['STORE_ID' => $storeId],
					false,
					false,
					['PRODUCT_ID']
				);
				while ($r0 = $rs0->Fetch())
				{
					$pp = (int)($r0['PRODUCT_ID'] ?? 0);
					if ($pp > 0)
					{
						$pidSet[$pp] = true;
					}
				}
			}

			$rsDel = \CCatalogStoreProduct::GetList(
				[],
				['STORE_ID' => $storeId],
				false,
				false,
				['ID']
			);
			while ($rd = $rsDel->Fetch())
			{
				$idd = (int)($rd['ID'] ?? 0);
				if ($idd > 0)
				{
					$storeRowIds[] = $idd;
				}
			}

			$productIds = array_keys($pidSet);
			sort($productIds, SORT_NUMERIC);
		}

		return [
			'ok' => true,
			'store_id' => $storeId,
			'price_group_id' => $priceGroupId,
			'mode' => $mode,
			'feed_code' => $feedOnly,
			'store_row_ids' => $storeRowIds,
			'product_ids' => $productIds,
			'hint' => $hint,
			'total_units' => count($storeRowIds) + count($productIds),
		];
	}
}

if (!function_exists('mf_ep_clear_job_start'))
{
	/**
	 * @return array<string, mixed>
	 */
	function mf_ep_clear_job_start(int $storeId, ?string $feedCode = null): array
	{
		$plan = mf_ep_clear_job_plan($storeId, $feedCode);
		if (empty($plan['ok']))
		{
			return [
				'ok' => false,
				'error' => (string)($plan['error'] ?? 'Ошибка планирования.'),
			];
		}

		$token = bin2hex(random_bytes(16));
		$now = time();
		$job = [
			'token' => $token,
			'created_at' => $now,
			'started_at' => $now,
			'updated_at' => $now,
			'store_id' => (int)$plan['store_id'],
			'price_group_id' => (int)$plan['price_group_id'],
			'mode' => (string)$plan['mode'],
			'feed_code' => (string)($plan['feed_code'] ?? ''),
			'hint' => (string)($plan['hint'] ?? ''),
			'phase' => count($plan['store_row_ids'] ?? []) > 0 ? 'store_rows' : 'products',
			'store_row_ids' => array_values(array_map('intval', $plan['store_row_ids'] ?? [])),
			'product_ids' => array_values(array_map('intval', $plan['product_ids'] ?? [])),
			'offset_store_rows' => 0,
			'offset_products' => 0,
			'total_units' => (int)($plan['total_units'] ?? 0),
			'stats' => [
				'deleted_store_rows' => 0,
				'products_price_cleared' => 0,
				'products_recalc' => 0,
				'affected_products' => count($plan['product_ids'] ?? []),
			],
			'done' => ((int)($plan['total_units'] ?? 0) === 0),
			'error' => '',
		];

		if ($job['done'])
		{
			$job = mf_ep_clear_job_finalize($job);
			mf_ep_clear_job_unlink($token);

			return [
				'ok' => true,
				'token' => $token,
				'done' => true,
				'total' => (int)$job['total_units'],
				'mode' => (string)$job['mode'],
				'feed_code' => (string)$job['feed_code'],
				'hint' => (string)$job['hint'],
				'result' => mf_ep_clear_job_build_result($job),
				'progress' => mf_ep_clear_job_progress_payload($job),
			];
		}
		if (!mf_ep_clear_job_save($job))
		{
			return ['ok' => false, 'error' => 'Не удалось сохранить задание очистки.'];
		}

		return [
			'ok' => true,
			'token' => $token,
			'done' => false,
			'total' => (int)$job['total_units'],
			'mode' => (string)$job['mode'],
			'feed_code' => (string)$job['feed_code'],
			'hint' => (string)$job['hint'],
			'progress' => mf_ep_clear_job_progress_payload($job),
		];
	}
}

if (!function_exists('mf_ep_clear_job_progress_payload'))
{
	/**
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	function mf_ep_clear_job_progress_payload(array $job): array
	{
		$total = max(0, (int)($job['total_units'] ?? 0));
		$doneUnits = min(
			$total,
			(int)($job['offset_store_rows'] ?? 0) + (int)($job['offset_products'] ?? 0)
		);
		$pct = $total > 0 ? (int)min(100, round(100.0 * $doneUnits / $total)) : 100;
		if (!empty($job['done']))
		{
			$pct = 100;
		}

		$phase = (string)($job['phase'] ?? '');
		$note = '';
		if ($phase === 'store_rows')
		{
			$srTotal = count($job['store_row_ids'] ?? []);
			$note = 'Удаление строк остатков: ' . (int)($job['offset_store_rows'] ?? 0) . ' / ' . $srTotal;
		}
		elseif ($phase === 'products')
		{
			$pTotal = count($job['product_ids'] ?? []);
			$note = 'Обработка товаров: ' . (int)($job['offset_products'] ?? 0) . ' / ' . $pTotal;
		}
		elseif ($phase === 'finalize')
		{
			$note = 'Завершение…';
			$pct = min(99, $pct);
		}
		elseif (!empty($job['done']))
		{
			$note = 'Готово';
		}

		$etaSec = null;
		$started = (int)($job['started_at'] ?? 0);
		$updated = (int)($job['updated_at'] ?? $started);
		if ($doneUnits > 0 && $total > $doneUnits && $started > 0)
		{
			$elapsed = max(1, $updated - $started);
			$rate = $doneUnits / $elapsed;
			if ($rate > 0)
			{
				$etaSec = (int)ceil(($total - $doneUnits) / $rate);
			}
		}

		return [
			'pct' => $pct,
			'done' => !empty($job['done']),
			'phase' => $phase,
			'processed' => $doneUnits,
			'total' => $total,
			'note' => $note,
			'eta_sec' => $etaSec,
			'stats' => is_array($job['stats'] ?? null) ? $job['stats'] : [],
		];
	}
}

if (!function_exists('mf_ep_clear_job_finalize'))
{
	/**
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	function mf_ep_clear_job_finalize(array $job): array
	{
		$storeId = (int)($job['store_id'] ?? 0);
		$mode = (string)($job['mode'] ?? '');

		if ($mode === 'all')
		{
			if (function_exists('mf_esf_registry_delete_all_for_store'))
			{
				mf_esf_registry_delete_all_for_store($storeId);
			}
			if (function_exists('mf_esf_delete_all_price_product_for_store'))
			{
				mf_esf_delete_all_price_product_for_store($storeId);
			}
		}
		elseif ($mode === 'feed')
		{
			$feed = (string)($job['feed_code'] ?? '');
			$affected = (int)($job['stats']['affected_products'] ?? 0);
			if ($affected > 0 && $feed !== '' && function_exists('mf_esf_registry_remove_feed'))
			{
				mf_esf_registry_remove_feed($storeId, $feed);
			}
		}

		if (function_exists('mf_ep_invalidate_catalog_price_group_cache'))
		{
			mf_ep_invalidate_catalog_price_group_cache();
		}

		$job['phase'] = 'done';
		$job['done'] = true;
		$job['updated_at'] = time();

		return $job;
	}
}

if (!function_exists('mf_ep_clear_job_run_step'))
{
	/**
	 * @return array<string, mixed>
	 */
	function mf_ep_clear_job_run_step(string $token, int $batchSize = 30): array
	{
		$batchSize = max(1, min(200, $batchSize));
		$job = mf_ep_clear_job_load($token);
		if ($job === null)
		{
			return ['ok' => false, 'error' => 'Задание не найдено или уже завершено.'];
		}
		if (!empty($job['done']))
		{
			return [
				'ok' => true,
				'done' => true,
				'result' => mf_ep_clear_job_build_result($job),
				'progress' => mf_ep_clear_job_progress_payload($job),
			];
		}

		$storeId = (int)($job['store_id'] ?? 0);
		$priceGroupId = (int)($job['price_group_id'] ?? 0);
		$feedMode = ((string)($job['mode'] ?? '') === 'feed');
		$stats = is_array($job['stats'] ?? null) ? $job['stats'] : [];
		$phase = (string)($job['phase'] ?? 'products');

		try
		{
			if ($phase === 'store_rows')
			{
				$ids = $job['store_row_ids'] ?? [];
				$off = (int)($job['offset_store_rows'] ?? 0);
				$slice = array_slice($ids, $off, $batchSize);
				foreach ($slice as $rowId)
				{
					$rowId = (int)$rowId;
					if ($rowId > 0 && class_exists(\CCatalogStoreProduct::class) && \CCatalogStoreProduct::Delete($rowId))
					{
						$stats['deleted_store_rows'] = (int)($stats['deleted_store_rows'] ?? 0) + 1;
					}
				}
				$job['offset_store_rows'] = $off + count($slice);
				if ($job['offset_store_rows'] >= count($ids))
				{
					$job['phase'] = 'products';
				}
			}

			if ((string)($job['phase'] ?? '') === 'products')
			{
				$pids = $job['product_ids'] ?? [];
				$off = (int)($job['offset_products'] ?? 0);
				$slice = array_slice($pids, $off, $batchSize);
				foreach ($slice as $productId)
				{
					$productId = (int)$productId;
					if ($productId <= 0)
					{
						continue;
					}
					$t = mf_ep_clear_warehouse_touch_product($storeId, $productId, $priceGroupId, $feedMode);
					if (!empty($t['price_cleared']))
					{
						$stats['products_price_cleared'] = (int)($stats['products_price_cleared'] ?? 0) + 1;
					}
					if (!empty($t['recalc']))
					{
						$stats['products_recalc'] = (int)($stats['products_recalc'] ?? 0) + 1;
					}
				}
				$job['offset_products'] = $off + count($slice);
				if ($job['offset_products'] >= count($pids))
				{
					$job['phase'] = 'finalize';
				}
			}

			if ((string)($job['phase'] ?? '') === 'finalize')
			{
				$job = mf_ep_clear_job_finalize($job);
			}

			$job['stats'] = $stats;
			$job['updated_at'] = time();
			$total = (int)($job['total_units'] ?? 0);
			$doneUnits = (int)($job['offset_store_rows'] ?? 0) + (int)($job['offset_products'] ?? 0);
			if ($total === 0 || $doneUnits >= $total)
			{
				if (empty($job['done']))
				{
					$job = mf_ep_clear_job_finalize($job);
				}
			}

			mf_ep_clear_job_save($job);
		}
		catch (\Throwable $e)
		{
			$job['error'] = $e->getMessage();
			$job['done'] = true;
			mf_ep_clear_job_save($job);

			return [
				'ok' => false,
				'error' => $e->getMessage(),
				'progress' => mf_ep_clear_job_progress_payload($job),
			];
		}

		$progress = mf_ep_clear_job_progress_payload($job);
		$out = [
			'ok' => true,
			'done' => !empty($job['done']),
			'progress' => $progress,
		];
		if (!empty($job['done']))
		{
			$out['result'] = mf_ep_clear_job_build_result($job);
			mf_ep_clear_job_unlink($token);
		}

		return $out;
	}
}

if (!function_exists('mf_ep_clear_job_build_result'))
{
	/**
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	function mf_ep_clear_job_build_result(array $job): array
	{
		$stats = is_array($job['stats'] ?? null) ? $job['stats'] : [];
		$mode = (string)($job['mode'] ?? '');
		$out = [
			'ok' => true,
			'error' => '',
			'deleted_store_rows' => (int)($stats['deleted_store_rows'] ?? 0),
			'products_price_cleared' => (int)($stats['products_price_cleared'] ?? 0),
			'products_recalc' => (int)($stats['products_recalc'] ?? 0),
			'mode' => $mode,
		];
		if ($mode === 'feed')
		{
			$out['feed_code'] = (string)($job['feed_code'] ?? '');
			$out['affected_products'] = (int)($stats['affected_products'] ?? 0);
			$hint = trim((string)($job['hint'] ?? ''));
			if ($hint !== '')
			{
				$out['hint'] = $hint;
			}
		}

		return $out;
	}
}

if (!function_exists('mf_ep_clear_external_warehouse_sync'))
{
	/** Синхронная очистка (CLI / старый вызов): выполняет все шаги подряд. */
	function mf_ep_clear_external_warehouse_sync(int $storeId, ?string $feedCode = null, int $batchSize = 50): array
	{
		$start = mf_ep_clear_job_start($storeId, $feedCode);
		if (empty($start['ok']))
		{
			return [
				'ok' => false,
				'error' => (string)($start['error'] ?? 'Ошибка.'),
				'deleted_store_rows' => 0,
				'products_price_cleared' => 0,
				'products_recalc' => 0,
			];
		}
		if (!empty($start['done']) && is_array($start['result'] ?? null))
		{
			return $start['result'];
		}

		$token = (string)($start['token'] ?? '');
		if ($token === '')
		{
			return [
				'ok' => false,
				'error' => 'Не получен token задания.',
				'deleted_store_rows' => 0,
				'products_price_cleared' => 0,
				'products_recalc' => 0,
			];
		}

		$guard = 0;
		do
		{
			$step = mf_ep_clear_job_run_step($token, $batchSize);
			$guard++;
		} while (empty($step['done']) && $guard < 50000);

		if (empty($step['ok']))
		{
			return [
				'ok' => false,
				'error' => (string)($step['error'] ?? 'Ошибка.'),
				'deleted_store_rows' => 0,
				'products_price_cleared' => 0,
				'products_recalc' => 0,
			];
		}

		return is_array($step['result'] ?? null)
			? $step['result']
			: mf_ep_clear_job_build_result(mf_ep_clear_job_load($token) ?? ['stats' => [], 'mode' => '']);
	}
}
