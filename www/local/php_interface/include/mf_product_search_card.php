<?php
/**
 * Карточка товара в стиле поиска (mf_search): склады, сроки, количество, «В корзину»; «Запросить цену» только если нет складов.
 * Используется на детальной странице товара (аналоги) и может быть подключена из других шаблонов.
 */

if (!function_exists('mf_product_search_card_money'))
{
	function mf_product_search_card_money(?float $price): string
	{
		$p = (float)($price ?? 0);
		if ($p <= 0)
		{
			return '';
		}
		if (function_exists('mf_format_display_price_rub'))
		{
			return mf_format_display_price_rub($p);
		}

		$r = function_exists('mf_round_price') ? mf_round_price($p) : (float)(ceil($p / 10.0) * 10.0);

		return number_format($r, 0, '.', ' ') . ' ₽';
	}
}

if (!function_exists('mf_product_search_card_min_price_print'))
{
	/** Минимальная цена для подписи «От …»: минимум по всем строкам таблицы складов (в т.ч. «Под заказ»). */
	function mf_product_search_card_min_price_print(int $productId): string
	{
		$productId = (int)$productId;
		if ($productId <= 0)
		{
			return '';
		}
		$minP = function_exists('mf_catalog_listing_display_price')
			? mf_catalog_listing_display_price($productId)
			: null;
		if ($minP === null || (float)$minP <= 0)
		{
			return '';
		}

		$mp = (float)$minP;
		if (function_exists('mf_format_display_price_rub'))
		{
			return mf_format_display_price_rub($mp);
		}
		$r = function_exists('mf_round_price') ? mf_round_price($mp) : (float)(ceil($mp / 10.0) * 10.0);

		return number_format($r, 0, '.', ' ') . ' ₽';
	}
}

if (!function_exists('mf_product_search_card_stores'))
{
	/**
	 * @return array<int, array{store_id:int,title:string,delivery_term:string,amount:float,price:?float,price_fmt:string,order_only?:bool,external_warehouse?:bool}>
	 *         external_warehouse: внешний прайс/склад; order_only: внешний склад и остаток 0 (служебно).
	 */
	function mf_product_search_card_stores(int $productId): array
	{
		$out = [];
		$productId = (int)$productId;
		if ($productId <= 0)
		{
			return $out;
		}
		if (!class_exists('CCatalogStoreProduct') || !class_exists('CCatalogStore'))
		{
			return $out;
		}
		try
		{
			\Bitrix\Main\Loader::includeModule('catalog');
		}
		catch (\Throwable $e)
		{
			return $out;
		}

		$clusterIds = function_exists('mf_catalog_product_cluster_ids')
			? mf_catalog_product_cluster_ids($productId)
			: [$productId];
		$byStore = function_exists('mf_catalog_product_store_amounts')
			? mf_catalog_product_store_amounts($productId)
			: [];
		if ($byStore === [])
		{
			foreach ($clusterIds as $cid)
			{
				$cid = (int)$cid;
				if ($cid <= 0)
				{
					continue;
				}
				$rs = \CCatalogStoreProduct::GetList(
					['STORE_ID' => 'ASC'],
					['PRODUCT_ID' => $cid],
					false,
					false,
					['STORE_ID', 'AMOUNT']
				);
				while ($r = $rs->Fetch())
				{
					$storeId = (int)($r['STORE_ID'] ?? 0);
					if ($storeId <= 0)
					{
						continue;
					}
					$byStore[$storeId] = ($byStore[$storeId] ?? 0.0) + (float)($r['AMOUNT'] ?? 0);
				}
			}
		}

		// Внешний склад — только если товар к нему «привязан»: уже есть строка остатка (цикл выше)
		// или есть закупка в типе цены этого склада (импорт прайса поставщика), иначе не подмешиваем все склады подряд.
		if (function_exists('mf_supplier_store_to_price_group') && function_exists('mf_ep_store_is_external_warehouse') && function_exists('mf_raw_store_price'))
		{
			foreach (array_keys(mf_supplier_store_to_price_group()) as $extSid)
			{
				$extSid = (int)$extSid;
				if ($extSid <= 0 || !mf_ep_store_is_external_warehouse($extSid) || array_key_exists($extSid, $byStore))
				{
					continue;
				}
				$raw = mf_raw_store_price($productId, $extSid);
				if ($raw !== null && $raw > 0)
				{
					$byStore[$extSid] = 0.0;
				}
			}
		}

		$makeRow = static function (int $storeId, float $amt) use ($productId): array {
			$s = function_exists('mf_store_row') ? mf_store_row($storeId) : null;
			$title = is_array($s) ? trim((string)($s['TITLE'] ?? '')) : '';
			if ($title === '')
			{
				$title = 'Склад ' . $storeId;
			}

			$price = function_exists('mf_ep_display_price_for_store')
				? mf_ep_display_price_for_store($productId, $storeId, 1.0)
				: (function_exists('mf_calc_store_price') ? mf_calc_store_price($productId, $storeId) : null);

			$deliveryTerm = function_exists('mf_store_delivery_term') ? mf_store_delivery_term($storeId) : '—';
			$isExternal = function_exists('mf_ep_store_is_external_warehouse')
				&& mf_ep_store_is_external_warehouse($storeId);
			$orderOnly = $isExternal && $amt <= 1e-9;

			return [
				'store_id' => $storeId,
				'title' => $title,
				'delivery_term' => $deliveryTerm,
				'amount' => $amt,
				'price' => $price,
				'price_fmt' => mf_product_search_card_money($price),
				'order_only' => $orderOnly,
				'external_warehouse' => $isExternal,
			];
		};

		foreach ($byStore as $storeId => $amt)
		{
			$storeId = (int)$storeId;
			$amt = (float)$amt;
			if ($amt > 0)
			{
				$out[] = $makeRow($storeId, $amt);
				continue;
			}
			// нулевой остаток: внешний склад — всегда строка (Под заказ / запрос цены), без требования цены в прайсе
			if (function_exists('mf_ep_store_is_external_warehouse') && mf_ep_store_is_external_warehouse($storeId))
			{
				$out[] = $makeRow($storeId, 0.0);
			}
		}

		if (empty($out) && count($byStore) === 1)
		{
			$onlySid = 0;
			$onlyAmt = 0.0;
			foreach ($byStore as $sid => $a)
			{
				$onlySid = (int)$sid;
				$onlyAmt = (float)$a;
				break;
			}
			if ($onlySid > 0
				&& function_exists('mf_ep_store_is_external_warehouse')
				&& mf_ep_store_is_external_warehouse($onlySid)
				&& $onlyAmt <= 1e-9)
			{
				$out[] = $makeRow($onlySid, 0.0);
			}
		}

		$isMotorForceInternal = static function (int $storeId): bool {
			$s = function_exists('mf_store_row') ? mf_store_row($storeId) : null;
			if (!is_array($s))
			{
				return false;
			}
			$code = mb_strtoupper(trim((string)($s['CODE'] ?? '')));
			$xml = mb_strtoupper(trim((string)($s['XML_ID'] ?? '')));

			return $code === 'MOTOR_FORCE_INTERNAL' || ($xml !== '' && mb_strpos($xml, 'MOTOR_FORCE_INTERNAL') !== false);
		};

		$storeSortFn = static function ($a, $b) use ($isMotorForceInternal) {
			$aid = (int)($a['store_id'] ?? 0);
			$bid = (int)($b['store_id'] ?? 0);
			$aInt = $isMotorForceInternal($aid);
			$bInt = $isMotorForceInternal($bid);
			if ($aInt !== $bInt)
			{
				return $bInt <=> $aInt;
			}
			$pa = (float)($a['price'] ?? 0);
			$pb = (float)($b['price'] ?? 0);
			if ($pa > 0 && $pb > 0 && $pa !== $pb)
			{
				return $pa <=> $pb;
			}

			return $aid <=> $bid;
		};

		usort($out, $storeSortFn);

		if (function_exists('mf_supplier_orders_internal_store_id')
			&& function_exists('mf_supplier_orders_cluster_amount_on_store')
			&& function_exists('mf_supplier_orders_pending_arrival_for_product'))
		{
			$mfIntSid = mf_supplier_orders_internal_store_id();
			if ($mfIntSid > 0 && mf_supplier_orders_cluster_amount_on_store($productId, $mfIntSid) <= 1e-9)
			{
				$mfPen = mf_supplier_orders_pending_arrival_for_product($productId);
				if (is_array($mfPen) && (float)($mfPen['qty'] ?? 0) > 1e-9 && ($mfPen['label'] ?? '') !== '')
				{
					$mfFound = false;
					foreach ($out as &$mfRow)
					{
						if ((int)($mfRow['store_id'] ?? 0) === $mfIntSid)
						{
							$mfFound = true;
							$mfRow['pending_supplier_display'] = (string)$mfPen['label'];
							$mfRow['pending_supplier_qty'] = (float)$mfPen['qty'];
							break;
						}
					}
					unset($mfRow);
					if (!$mfFound)
					{
						$mfNew = $makeRow($mfIntSid, 0.0);
						$mfNew['pending_supplier_display'] = (string)$mfPen['label'];
						$mfNew['pending_supplier_qty'] = (float)$mfPen['qty'];
						$out[] = $mfNew;
						usort($out, $storeSortFn);
					}
				}
			}
		}

		return $out;
	}
}

if (!function_exists('mf_product_search_card_warm_cache'))
{
	/**
	 * Прогрев данных каталога для списка карточек (поиск, аналоги).
	 *
	 * @param int[] $productIds
	 */
	function mf_product_search_card_warm_cache(array $productIds): void
	{
		$productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
		if ($productIds === [] || !function_exists('mf_catalog_warm_products'))
		{
			return;
		}
		mf_catalog_warm_products($productIds);
	}
}

if (!function_exists('mf_product_search_card_render'))
{
	/**
	 * @param array{
	 *   id:int,
	 *   url:string,
	 *   code?:string,
	 *   title_html:string,
	 *   brand?:string,
	 *   article?:string,
	 *   product_name_plain:string,
	 *   req_user_name?:string,
	 *   req_user_email?:string,
	 *   req_user_locked?:bool
	 * } $p
	 */
	function mf_product_search_card_render(array $p): void
	{
		$id = (int)($p['id'] ?? 0);
		$url = (string)($p['url'] ?? '');
		$code = trim((string)($p['code'] ?? ''));
		$titleHtml = (string)($p['title_html'] ?? '');
		$brand = trim((string)($p['brand'] ?? ''));
		$article = trim((string)($p['article'] ?? ''));
		$productNamePlain = trim((string)($p['product_name_plain'] ?? ''));
		$reqName = trim((string)($p['req_user_name'] ?? ''));
		$reqEmail = trim((string)($p['req_user_email'] ?? ''));
		$reqLocked = !empty($p['req_user_locked']);

		if ($id <= 0 || $url === '')
		{
			return;
		}

		$placeholder = function_exists('mf_mf_placeholder_img_url')
			? (string)mf_mf_placeholder_img_url()
			: '/bitrix/templates/eshop_bootstrap_v4/images/mf-no-photo.svg';
		$img = $placeholder;
		if (function_exists('mf_mf_product_card_preview_src'))
		{
			$u = (string)mf_mf_product_card_preview_src($id, $code, null, 4);
			if ($u !== '')
			{
				$img = $u;
			}
		}
		elseif ($code !== '' && function_exists('mf_mf_product_img_url'))
		{
			$u = (string)mf_mf_product_img_url($code, 1);
			if ($u !== '')
			{
				$img = $u;
			}
		}

		$priceFrom = mf_product_search_card_min_price_print($id);
		$stores = mf_product_search_card_stores($id);

		if ($productNamePlain === '')
		{
			$productNamePlain = 'Товар #' . $id;
		}
		?>
		<div class="mf-search-card mf-search-card--analog">
			<div class="mf-search-card__top">
				<a class="mf-search-card__img" href="<?=htmlspecialcharsbx($url)?>">
					<img src="<?=htmlspecialcharsbx($img)?>" alt="" loading="lazy" decoding="async" />
				</a>
				<div class="mf-search-card__main">
					<a class="mf-search-card__title" href="<?=htmlspecialcharsbx($url)?>"><?=$titleHtml?></a>
					<div class="mf-product-meta" aria-label="Цена, бренд и артикул">
						<div class="mf-product-meta__item">
							<span class="mf-product-meta__label">От</span>
							<span class="mf-product-meta__value"><?= $priceFrom !== '' ? htmlspecialcharsbx($priceFrom) : 'Запросить цену' ?></span>
						</div>
						<div class="mf-product-meta__item">
							<span class="mf-product-meta__label">Бренд</span>
							<span class="mf-product-meta__value"><?= $brand !== '' ? htmlspecialcharsbx($brand) : '—' ?></span>
						</div>
						<div class="mf-product-meta__item">
							<span class="mf-product-meta__label">Артикул</span>
							<span class="mf-product-meta__value"><?= $article !== '' ? htmlspecialcharsbx($article) : '—' ?></span>
						</div>
					</div>
				</div>
			</div>

			<div class="mf-search-card__avail">
				<?php if (!empty($stores)): ?>
					<table class="mf-search-stock-table">
						<thead>
							<tr>
								<th>Склад</th>
								<th>Срок доставки</th>
								<th class="mf-search-stock-table__spb text-center">Доставка</th>
								<th class="mf-ta-r">Наличие</th>
								<th class="mf-ta-r">Цена</th>
								<th class="mf-ta-r">Кол-во</th>
								<th class="mf-ta-r"></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($stores as $s): ?>
								<?php
								$mfExternal = !empty($s['external_warehouse']);
								$mfAmtRounded = round((float)$s['amount'], 3);
								$mfPendingDisp = trim((string)($s['pending_supplier_display'] ?? ''));
								$mfStockCell = '';
								if ($mfExternal)
								{
									$mfStockCell = ((float)$s['amount'] > 1e-9)
										? htmlspecialcharsbx((string)$mfAmtRounded)
										: 'Под заказ';
								}
								elseif ($mfPendingDisp !== '')
								{
									$mfStockCell = htmlspecialcharsbx($mfPendingDisp);
								}
								else
								{
									$mfStockCell = htmlspecialcharsbx((string)$mfAmtRounded);
								}
								$mfMaxQtyRounded = $mfExternal
									? (((float)$s['amount'] > 1e-9) ? $mfAmtRounded : 0.0)
									: (isset($s['pending_supplier_qty'])
										? round((float)$s['pending_supplier_qty'], 3)
										: $mfAmtRounded);
								?>
								<tr>
									<td><?=htmlspecialcharsbx((string)$s['title'])?></td>
									<td class="mf-search-stock-table__delivery"><?=htmlspecialcharsbx((string)($s['delivery_term'] ?? '—'))?></td>
									<td class="mf-search-stock-table__spb text-center"><?php
										$mfSpbSid = (int)($s['store_id'] ?? 0);
										if ($mfSpbSid > 0 && function_exists('mf_store_delivery_spb_icon_html')) {
											echo mf_store_delivery_spb_icon_html($mfSpbSid, $id);
										} else {
											echo '—';
										}
									?></td>
									<td class="mf-ta-r mf-search-stock-table__pending"><?=$mfStockCell?></td>
									<td class="mf-ta-r mf-search-stock-table__price mf-price"><?=htmlspecialcharsbx((string)($s['price_fmt'] ?: '—'))?></td>
									<td class="mf-ta-r">
										<?php
										$mfNoPrice = (($s['price'] ?? null) === null || (float)$s['price'] <= 0);
										$mfCanAddStore = !$mfNoPrice;
										?>
										<?php if ($mfCanAddStore): ?>
											<div class="mf-search-stock__actions">
												<div class="mf-search-qty" data-max-qty="<?=htmlspecialcharsbx((string)$mfMaxQtyRounded)?>">
													<button type="button" class="mf-search-qty__btn js-mf-qty-minus" aria-label="Уменьшить количество">-</button>
													<input
														type="number"
														class="mf-search-qty__input js-mf-qty-input"
														value="1"
														min="1"
														step="1"
														inputmode="numeric"
														aria-label="Количество"
													>
													<button type="button" class="mf-search-qty__btn js-mf-qty-plus" aria-label="Увеличить количество">+</button>
												</div>
											</div>
										<?php else: ?>
											<span class="mf-search-stock__order-only">—</span>
										<?php endif; ?>
									</td>
									<td class="mf-ta-r">
										<?php if ($mfCanAddStore): ?>
											<button
												type="button"
												class="btn btn-sm btn-warning mf-search-stock__btn js-mf-add-store"
												data-product-id="<?=$id?>"
												data-store-id="<?= (int)$s['store_id'] ?>"
												data-qty="1"
											>В корзину</button>
										<?php else: ?>
											<span class="mf-search-stock__order-only">—</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="mf-search-card__no-stock-row">
						<div class="mf-search-card__no-stock">Нет данных по складам</div>
						<button
							type="button"
							class="btn btn-sm btn-warning mf-search-stock__btn mf-search-stock__btn--request js-mf-request-price-global"
							data-product-id="<?=$id?>"
							data-product-name="<?=htmlspecialcharsbx($productNamePlain)?>"
							data-product-url="<?=htmlspecialcharsbx($url)?>"
							data-user-name="<?=htmlspecialcharsbx($reqName)?>"
							data-user-email="<?=htmlspecialcharsbx($reqEmail)?>"
							data-user-locked="<?=$reqLocked ? '1' : '0'?>"
						>Запросить цену</button>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
