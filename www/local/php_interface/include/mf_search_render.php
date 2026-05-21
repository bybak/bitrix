<?php
/**
 * HTML-карточка товара в результатах поиска (mf_search).
 */

if (!function_exists('mf_search_render_card_avail_inner'))
{
	function mf_search_render_card_avail_inner(int $id, string $titlePlain, string $url, array $stores): void
	{
		if (!empty($stores))
		{
			?>
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
							<td class="mf-ta-r"><?=htmlspecialcharsbx((string)($s['price_fmt'] ?: '—'))?></td>
							<td class="mf-ta-r">
								<?php
								$mfNoPrice = (($s['price'] ?? null) === null || (float)$s['price'] <= 0);
								$mfRequestPrice = $mfNoPrice;
								?>
								<?php if (!$mfNoPrice): ?>
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
								<?php if ($mfRequestPrice): ?>
									<button
										type="button"
										class="btn btn-sm btn-warning mf-search-stock__btn mf-search-stock__btn--request js-mf-request-price"
										data-product-id="<?=$id?>"
										data-product-name="<?=htmlspecialcharsbx($titlePlain)?>"
										data-product-url="<?=htmlspecialcharsbx($url)?>"
									>Запросить цену</button>
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
			<?php
			return;
		}
		?>
		<div class="mf-search-card__no-stock-row">
			<div class="mf-search-card__no-stock">Нет данных по складам</div>
			<button
				type="button"
				class="btn btn-sm btn-warning mf-search-stock__btn mf-search-stock__btn--request js-mf-request-price"
				data-product-id="<?=$id?>"
				data-product-name="<?=htmlspecialcharsbx($titlePlain)?>"
				data-product-url="<?=htmlspecialcharsbx($url)?>"
			>Запросить цену</button>
		</div>
		<?php
	}
}

if (!function_exists('mf_search_render_card_avail_html'))
{
	function mf_search_render_card_avail_html(int $id, string $titlePlain, string $url): string
	{
		$id = (int)$id;
		if ($id <= 0)
		{
			return '';
		}
		$stores = function_exists('mf_product_search_card_stores') ? mf_product_search_card_stores($id) : [];
		ob_start();
		mf_search_render_card_avail_inner($id, $titlePlain, $url, $stores);

		return (string)ob_get_clean();
	}
}

if (!function_exists('mf_search_stores_payload_for_products'))
{
	/**
	 * @return array<string, array{avail:string, price_from:string}>
	 */
	function mf_search_stores_payload_for_products(array $productIds): array
	{
		$productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static function (int $id): bool {
			return $id > 0;
		})));
		if (empty($productIds))
		{
			return [];
		}

		if (function_exists('mf_product_search_card_warm_cache'))
		{
			mf_product_search_card_warm_cache($productIds);
		}

		$metaById = [];
		if (class_exists('CIBlockElement'))
		{
			$rs = \CIBlockElement::GetList(
				['ID' => 'ASC'],
				['IBLOCK_ID' => 4, 'ID' => $productIds, 'ACTIVE' => 'Y'],
				false,
				false,
				['ID', 'NAME', 'CODE']
			);
			while ($r = $rs->Fetch())
			{
				$pid = (int)($r['ID'] ?? 0);
				if ($pid > 0)
				{
					$metaById[$pid] = $r;
				}
			}
		}

		$out = [];
		foreach ($productIds as $pid)
		{
			$pid = (int)$pid;
			$row = $metaById[$pid] ?? null;
			$titlePlain = is_array($row) ? trim((string)($row['NAME'] ?? '')) : '';
			if ($titlePlain === '')
			{
				$titlePlain = 'Товар #' . $pid;
			}
			$code = is_array($row) ? trim((string)($row['CODE'] ?? '')) : '';
			$url = ($code !== '' ? '/products/' . rawurlencode($code) . '/' : '/products/?ELEMENT_ID=' . $pid);
			$priceFrom = function_exists('mf_product_search_card_min_price_print')
				? mf_product_search_card_min_price_print($pid)
				: '';
			$avail = mf_search_render_card_avail_html($pid, $titlePlain, $url);
			$out[(string)$pid] = [
				'avail' => $avail,
				'price_from' => $priceFrom !== '' ? $priceFrom : 'Запросить цену',
			];
		}

		return $out;
	}
}

if (!function_exists('mf_search_render_product_card'))
{
	function mf_search_render_product_card(array $data): void
	{
		$mfCatalogIblockId = 4;
		$mfPlaceholder = (function_exists('mf_mf_placeholder_img_url') ? mf_mf_placeholder_img_url() : '/bitrix/templates/eshop_bootstrap_v4/images/mf-no-photo.svg');

		$id = (int)($data['id'] ?? 0);
		$url = (string)($data['url'] ?? '');
		$titleHtml = (string)($data['title_html'] ?? '');
		$code = trim((string)($data['code'] ?? ''));
		$brand = trim((string)($data['brand'] ?? ''));
		$article = trim((string)($data['article'] ?? ''));
		$isAnalog = !empty($data['is_analog']);
		$lazyAnalogs = !empty($data['lazy_analogs']);
		$lazyStores = !empty($data['lazy_stores']);
		$analogs = (is_array($data['analogs'] ?? null) ? (array)$data['analogs'] : []);

		$img = $mfPlaceholder;
		$prefetchRow = (isset($data['prefetch_row']) && is_array($data['prefetch_row'])) ? $data['prefetch_row'] : null;
		if (function_exists('mf_mf_product_card_preview_src'))
		{
			$u = (string)mf_mf_product_card_preview_src($id, $code, $prefetchRow, $mfCatalogIblockId);
			if ($u !== '')
			{
				$img = $u;
			}
		}
		elseif ($code !== '' && function_exists('mf_mf_product_img_url'))
		{
			$u = (string)mf_mf_product_img_url($code, 1);
			if ($u !== '') $img = $u;
		}

		$priceFrom = (string)($data['price_from_print'] ?? '');
		if ($priceFrom === '' && !$lazyStores && function_exists('mf_product_search_card_min_price_print'))
		{
			$priceFrom = mf_product_search_card_min_price_print($id);
		}

		$stores = [];
		if (!$lazyStores && function_exists('mf_product_search_card_stores'))
		{
			$stores = mf_product_search_card_stores($id);
		}

		$wrapTag = $isAnalog ? 'div' : 'article';
		$titlePlain = trim(html_entity_decode(strip_tags($titleHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		if ($titlePlain === '')
		{
			$titlePlain = 'Товар #' . $id;
		}

		$rootAttrs = '';
		if (!$isAnalog && $id > 0)
		{
			$rootAttrs = ' data-product-id="' . $id . '"'
				. ' data-product-name="' . htmlspecialcharsbx($titlePlain) . '"'
				. ' data-product-url="' . htmlspecialcharsbx($url) . '"';
			if ($lazyStores)
			{
				$rootAttrs .= ' data-mf-stores-for="' . $id . '"';
			}
			if ($lazyAnalogs)
			{
				$rootAttrs .= ' data-mf-analogs-for="' . $id . '"';
			}
		}
		?>
		<<?=$wrapTag?> class="mf-search-card<?=($isAnalog ? ' mf-search-card--analog' : ' mf-search-card--root')?>"<?=$rootAttrs?>>
			<div class="mf-search-card__top">
				<a class="mf-search-card__img" href="<?=htmlspecialcharsbx($url)?>">
					<img src="<?=htmlspecialcharsbx($img)?>" alt="" loading="lazy" decoding="async" />
				</a>
				<div class="mf-search-card__main">
					<a class="mf-search-card__title" href="<?=htmlspecialcharsbx($url)?>"><?=$titleHtml?></a>
					<div class="mf-product-meta" aria-label="Цена, бренд и артикул">
						<div class="mf-product-meta__item">
							<span class="mf-product-meta__label">От</span>
							<span class="mf-product-meta__value<?=($lazyStores ? ' mf-product-meta__value--pending' : '')?>"<?=($lazyStores && $id > 0 ? ' data-mf-price-for="' . $id . '"' : '')?>>
								<?php if ($lazyStores): ?>
									<span class="mf-search-inline-spinner" aria-hidden="true"></span>
								<?php elseif ($priceFrom !== ''): ?>
									<?=htmlspecialcharsbx($priceFrom)?>
								<?php else: ?>
									Запросить цену
								<?php endif; ?>
							</span>
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

			<?php if ($lazyStores && !$isAnalog): ?>
				<div class="mf-search-card__avail mf-search-card__avail--lazy" aria-busy="true">
					<div class="mf-search-card__avail-loading">
						<span class="mf-search-inline-spinner" aria-hidden="true"></span>
						Загрузка складов…
					</div>
				</div>
			<?php else: ?>
				<div class="mf-search-card__avail">
					<?php mf_search_render_card_avail_inner($id, $titlePlain, $url, $stores); ?>
				</div>
			<?php endif; ?>

			<?php if ($lazyAnalogs && !$isAnalog): ?>
				<div class="mf-search-card__analogs-pending" data-mf-analogs-pending-for="<?=$id?>" aria-busy="true">
					<span class="mf-search-inline-spinner" aria-hidden="true"></span>
					Аналоги загружаются…
				</div>
			<?php elseif (!$isAnalog && !empty($analogs)): ?>
				<div class="mf-search-card__analogs">
					<div class="mf-search-card__catalog-note">Также Вы можете заказать данный товар или его аналог в каталогах</div>
					<div class="mf-search-card__analogs-title">Аналоги</div>
					<div class="mf-search-analogs">
						<?php foreach ($analogs as $a): ?>
							<?php mf_search_render_product_card($a); ?>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

		</<?=$wrapTag?>>
		<?php
	}
}

if (!function_exists('mf_search_analogs_html_for_products'))
{
	/**
	 * @return array<int, string> productId => HTML блока аналогов
	 */
	function mf_search_analogs_html_for_products(array $productIds, int $limit = 8): array
	{
		$productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static function (int $id): bool {
			return $id > 0;
		})));
		if (empty($productIds))
		{
			return [];
		}

		$mfCatalogIblockId = 4;
		$mfAnalogsByProductId = [];
		$mfAnalogRowsById = [];

		$analogsLib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_analogs.php';
		if (is_file($analogsLib))
		{
			require_once $analogsLib;
		}

		if (function_exists('mf_analogs_related_ids_for_products') && class_exists('CIBlockElement'))
		{
			$mfAnalogsByProductId = mf_analogs_related_ids_for_products($productIds, $limit);
		}
		elseif ((function_exists('mf_analogs_related_ids_for_product') || function_exists('mf_analogs_ids_for_product')) && class_exists('CIBlockElement'))
		{
			foreach ($productIds as $pid)
			{
				$ids = function_exists('mf_analogs_related_ids_for_product')
					? mf_analogs_related_ids_for_product((int)$pid, $limit)
					: mf_analogs_ids_for_product((int)$pid, $limit);
				if (!empty($ids))
				{
					$mfAnalogsByProductId[(int)$pid] = $ids;
				}
			}
		}

		$allAnalogIds = [];
		foreach ($mfAnalogsByProductId as $ids)
		{
			foreach ((array)$ids as $aid)
			{
				$allAnalogIds[(int)$aid] = true;
			}
		}
		$allAnalogIds = array_keys($allAnalogIds);
		if (empty($allAnalogIds))
		{
			return [];
		}

		if (function_exists('mf_product_search_card_warm_cache'))
		{
			mf_product_search_card_warm_cache(array_merge($productIds, $allAnalogIds));
		}

		if (class_exists('CIBlockElement'))
		{
			$rsA = \CIBlockElement::GetList(
				['NAME' => 'ASC', 'ID' => 'ASC'],
				[
					'IBLOCK_ID' => $mfCatalogIblockId,
					'ID' => $allAnalogIds,
					'ACTIVE' => 'Y',
				],
				false,
				false,
				[
					'ID',
					'NAME',
					'CODE',
					'PROPERTY_CML2_ARTICLE',
					'PROPERTY_MF_BRAND',
					'PROPERTY_MF_BRAND_NORM',
					'PROPERTY_OEM',
					'PROPERTY_MF_EXT_IMAGES',
				]
			);
			while ($r = $rsA->Fetch())
			{
				$aid = (int)($r['ID'] ?? 0);
				if ($aid > 0)
				{
					$mfAnalogRowsById[$aid] = $r;
				}
			}
		}

		$out = [];
		foreach ($productIds as $pid)
		{
			$mfAnalogs = isset($mfAnalogsByProductId[$pid]) ? (array)$mfAnalogsByProductId[$pid] : [];
			if (empty($mfAnalogs))
			{
				continue;
			}

			$analogsData = [];
			foreach ($mfAnalogs as $aid)
			{
				$rA = $mfAnalogRowsById[(int)$aid] ?? null;
				if (!is_array($rA)) continue;
				$aid2 = (int)($rA['ID'] ?? 0);
				if ($aid2 <= 0) continue;
				$codeA = trim((string)($rA['CODE'] ?? ''));
				$urlA = ($codeA !== '' ? '/products/' . rawurlencode($codeA) . '/' : '');
				if ($urlA === '') continue;
				$nameA = (string)($rA['NAME'] ?? ('ID ' . $aid2));
				$analogsData[] = [
					'id' => $aid2,
					'url' => $urlA,
					'code' => $codeA,
					'prefetch_row' => $rA,
					'title_html' => htmlspecialcharsbx($nameA),
					'brand' => trim((string)($rA['PROPERTY_MF_BRAND_VALUE'] ?? ($rA['PROPERTY_MF_BRAND_NORM_VALUE'] ?? ''))),
					'article' => trim((string)($rA['PROPERTY_CML2_ARTICLE_VALUE'] ?? '')),
					'oem' => trim((string)($rA['PROPERTY_OEM_VALUE'] ?? '')),
					'is_analog' => true,
				];
			}
			if (empty($analogsData))
			{
				continue;
			}

			ob_start();
			?>
			<div class="mf-search-card__catalog-note">Также Вы можете заказать данный товар или его аналог в каталогах</div>
			<div class="mf-search-card__analogs-title">Аналоги</div>
			<div class="mf-search-analogs">
				<?php foreach ($analogsData as $a): ?>
					<?php mf_search_render_product_card($a); ?>
				<?php endforeach; ?>
			</div>
			<?php
			$out[(int)$pid] = (string)ob_get_clean();
		}

		return $out;
	}
}
