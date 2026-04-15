<?php
/**
 * Карточка товара в стиле поиска (mf_search): склады, сроки, количество, «В корзину» / «Запросить цену».
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

		return number_format($p, 0, '.', ' ') . ' ₽';
	}
}

if (!function_exists('mf_product_search_card_trim_text'))
{
	function mf_product_search_card_trim_text(string $html, int $limit = 170): string
	{
		$s = trim((string)strip_tags($html));
		if ($s === '')
		{
			return '';
		}
		if (mb_strlen($s) <= $limit)
		{
			return $s;
		}

		return rtrim(mb_substr($s, 0, $limit), " \t\n\r\0\x0B.,;:") . '…';
	}
}

if (!function_exists('mf_product_search_card_stores'))
{
	/**
	 * @return array<int, array{store_id:int,title:string,delivery_term:string,amount:float,price:?float,price_fmt:string,order_only?:bool}>
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
		$byStore = [];
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

		// Внешние склады с ценой по прайсу должны попадать в выдачу, даже без строки остатка / при нуле на складе.
		if (function_exists('mf_supplier_store_to_price_group') && function_exists('mf_ep_store_is_external_warehouse') && function_exists('mf_calc_store_price'))
		{
			foreach (array_keys(mf_supplier_store_to_price_group()) as $extSid)
			{
				$extSid = (int)$extSid;
				if ($extSid <= 0 || !mf_ep_store_is_external_warehouse($extSid))
				{
					continue;
				}
				$p = mf_calc_store_price($productId, $extSid);
				if ($p === null || $p <= 0)
				{
					continue;
				}
				if (!array_key_exists($extSid, $byStore))
				{
					$byStore[$extSid] = 0.0;
				}
			}
		}

		$orderOnly = function_exists('mf_product_single_external_store_only') && mf_product_single_external_store_only($productId);

		$makeRow = static function (int $storeId, float $amt) use ($productId, $orderOnly): array {
			$s = function_exists('mf_store_row') ? mf_store_row($storeId) : null;
			$title = is_array($s) ? trim((string)($s['TITLE'] ?? '')) : '';
			if ($title === '')
			{
				$title = 'Склад ' . $storeId;
			}

			$price = function_exists('mf_calc_store_price') ? mf_calc_store_price($productId, $storeId) : null;
			if (function_exists('mf_user_is_wholesale') && function_exists('mf_calc_store_price') && mf_user_is_wholesale() && $price !== null && $price > 0)
			{
				$price = round((float)$price * 0.9, 2);
			}

			$deliveryTerm = function_exists('mf_store_delivery_term') ? mf_store_delivery_term($storeId) : '—';

			return [
				'store_id' => $storeId,
				'title' => $title,
				'delivery_term' => $deliveryTerm,
				'amount' => $amt,
				'price' => $price,
				'price_fmt' => mf_product_search_card_money($price),
				'order_only' => $orderOnly,
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
			if (function_exists('mf_ep_store_is_external_warehouse') && mf_ep_store_is_external_warehouse($storeId) && function_exists('mf_calc_store_price'))
			{
				$p = mf_calc_store_price($productId, $storeId);
				if ($p !== null && $p > 0)
				{
					$out[] = $makeRow($storeId, 0.0);
				}
			}
		}

		if (empty($out) && $orderOnly && count($byStore) === 1)
		{
			$onlySid = 0;
			foreach ($byStore as $sid => $_amt)
			{
				$onlySid = (int)$sid;
				break;
			}
			if ($onlySid > 0)
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

		usort($out, static function ($a, $b) use ($isMotorForceInternal) {
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
		});

		return $out;
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
	 *   desc_source_html?:string,
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
		$descSource = (string)($p['desc_source_html'] ?? '');
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
		if ($code !== '' && function_exists('mf_mf_product_img_url'))
		{
			$u = (string)mf_mf_product_img_url($code, 1);
			if ($u !== '')
			{
				$img = $u;
			}
		}

		$desc = mf_product_search_card_trim_text($descSource, 170);
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
					<?php if ($desc !== ''): ?>
						<div class="mf-search-card__desc"><?=htmlspecialcharsbx($desc)?></div>
					<?php endif; ?>
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
								<th class="mf-ta-r">Остаток</th>
								<th class="mf-ta-r">Цена</th>
								<th class="mf-ta-r">Кол-во</th>
								<th class="mf-ta-r"></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($stores as $s): ?>
								<?php
								$mfOrdOnly = !empty($s['order_only']);
								$mfAmtRounded = round((float)$s['amount'], 3);
								?>
								<tr>
									<td><?=htmlspecialcharsbx((string)$s['title'])?></td>
									<td class="mf-search-stock-table__delivery"><?=htmlspecialcharsbx((string)($s['delivery_term'] ?? '—'))?></td>
									<td class="mf-search-stock-table__spb text-center"><?php
										$mfSpbSid = (int)($s['store_id'] ?? 0);
										if ($mfSpbSid > 0 && function_exists('mf_store_delivery_spb_icon_html')) {
											echo mf_store_delivery_spb_icon_html($mfSpbSid);
										} else {
											echo '—';
										}
									?></td>
									<td class="mf-ta-r"><?=$mfOrdOnly ? 'Под заказ' : htmlspecialcharsbx((string)$mfAmtRounded)?></td>
									<td class="mf-ta-r"><?=htmlspecialcharsbx((string)($s['price_fmt'] ?: '—'))?></td>
									<td class="mf-ta-r">
										<?php if ($mfOrdOnly): ?>
											<span class="mf-search-stock__order-only">Под заказ</span>
										<?php else: ?>
											<div class="mf-search-stock__actions">
												<div class="mf-search-qty" data-max-qty="<?=htmlspecialcharsbx((string)$mfAmtRounded)?>">
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
										<?php endif; ?>
									</td>
									<td class="mf-ta-r">
										<?php
										$mfCartSid = (int)($s['store_id'] ?? 0);
										$mfNoPrice = (($s['price'] ?? null) === null || (float)$s['price'] <= 0);
										$mfHideCartBtn = $mfOrdOnly && $mfNoPrice;
										if ($mfHideCartBtn && $mfCartSid > 0 && function_exists('mf_ep_store_is_external_warehouse') && mf_ep_store_is_external_warehouse($mfCartSid))
										{
											$mfHideCartBtn = false;
										}
										?>
										<?php if ($mfHideCartBtn): ?>
											—
										<?php else: ?>
											<button
												type="button"
												class="btn btn-sm btn-warning mf-search-stock__btn js-mf-add-store"
												data-product-id="<?=$id?>"
												data-store-id="<?= (int)$s['store_id'] ?>"
												data-qty="1"
											>В корзину</button>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="mf-search-card__no-stock-row">
						<div class="mf-search-card__no-stock">Отсутствует на складах</div>
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
