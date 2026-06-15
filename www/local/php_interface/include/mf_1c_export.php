<?php
/**
 * Дополнение XML экспорта заказов Bitrix → 1С:УНФ.
 * В стандартном CSaleExport у строки <Товар> нет <Артикул>/<ТорговаяМарка>, хотя 1С читает их при создании номенклатуры.
 * Тег <Категория> в схеме CommerceML отсутствует — 1С его игнорирует; бренд дублируется в свойствах корзины.
 *
 * Важно: полный XML заказа часто невалиден для DOMDocument (битые символы в ФИО покупателя),
 * поэтому обогащаем каждый блок <Товар> отдельно через regex.
 */

$mfOrderAccountDisplayInclude = __DIR__ . '/mf_order_account_display.php';
if (is_file($mfOrderAccountDisplayInclude))
{
	require_once $mfOrderAccountDisplayInclude;
}
unset($mfOrderAccountDisplayInclude);

if (!function_exists('mf_1c_export_log'))
{
	function mf_1c_export_log(string $message): void
	{
		if (function_exists('mf_1c_import_log'))
		{
			mf_1c_import_log($message);
			return;
		}
		if (function_exists('mf1c_exchange_debug_log'))
		{
			mf1c_exchange_debug_log($message);
		}
	}
}

if (!function_exists('mf_1c_export_prepare_exchange'))
{
	/**
	 * Перед CommerceML-выгрузкой: сброс кэша ExportSettings и пустой префикс s1 (номер задаём в post-process).
	 */
	function mf_1c_export_prepare_exchange(): void
	{
		if (!mf_1c_export_is_query_request())
		{
			return;
		}
		if (!class_exists(\Bitrix\Sale\Exchange\OneC\ExportSettings::class))
		{
			return;
		}

		static $done = false;
		if ($done)
		{
			return;
		}
		$done = true;

		try
		{
			$ref = new \ReflectionClass(\Bitrix\Sale\Exchange\OneC\ExportSettings::class);
			$prop = $ref->getProperty('currentSettings');
			$prop->setAccessible(true);
			$prop->setValue(null, null);

			\Bitrix\Sale\Exchange\OneC\ExportSettings::getCurrent();
			$settings = $prop->getValue();
			if (!is_array($settings))
			{
				return;
			}

			$settings['accountNumberPrefix']['ORDER'] = '';
			$settings['accountNumberPrefix']['INVOICE'] = '';
			$settings['export']['CURRENCY'] = 'RUB';
			$prop->setValue(null, $settings);
			mf_1c_export_log('EXPORT PREPARE: accountNumberPrefix cleared, currency=RUB for CommerceML query');
		}
		catch (\Throwable $e)
		{
			mf_1c_export_log('EXPORT PREPARE ERROR: ' . $e->getMessage());
		}
	}
}

if (!function_exists('mf_1c_export_is_query_request'))
{
	function mf_1c_export_is_query_request(): bool
	{
		$mode = (string)($_REQUEST['mode'] ?? $_GET['mode'] ?? $_POST['mode'] ?? '');

		return $mode === 'query';
	}
}

if (!function_exists('mf_1c_export_prop_name_matches'))
{
	function mf_1c_export_prop_name_matches(string $propName, array $codes): bool
	{
		$propName = trim($propName);
		if ($propName === '')
		{
			return false;
		}

		$tail = $propName;
		if (($pos = strrpos($propName, '#')) !== false)
		{
			$tail = substr($propName, $pos + 1);
		}

		foreach ($codes as $code)
		{
			if (strcasecmp($tail, (string)$code) === 0)
			{
				return true;
			}
		}

		return false;
	}
}

if (!function_exists('mf_1c_export_xml_escape'))
{
	function mf_1c_export_xml_escape(string $value): string
	{
		return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('mf_1c_export_meta_from_product_xml_id'))
{
	/**
	 * @return array{article: string, category: string, name: string, product_id: int}
	 */
	function mf_1c_export_meta_from_product_xml_id(string $xmlId): array
	{
		$result = ['article' => '', 'category' => '', 'name' => '', 'product_id' => 0];
		$xmlId = trim($xmlId);
		if ($xmlId === '' || !function_exists('mf_catalog_brand_article_by_product_id'))
		{
			return $result;
		}

		$productId = 0;
		if (ctype_digit($xmlId))
		{
			$productId = (int)$xmlId;
		}
		else
		{
			$parts = explode('#', $xmlId);
			$candidate = trim((string)end($parts));
			if ($candidate !== '' && ctype_digit($candidate))
			{
				$productId = (int)$candidate;
			}
		}

		if ($productId <= 0 && class_exists(\Bitrix\Main\Loader::class) && \Bitrix\Main\Loader::includeModule('iblock'))
		{
			$row = \CIBlockElement::GetList(
				[],
				['=XML_ID' => $xmlId],
				false,
				['nTopCount' => 1],
				['ID']
			)->Fetch();
			if (is_array($row))
			{
				$productId = (int)($row['ID'] ?? 0);
			}
		}

		if ($productId <= 0)
		{
			return $result;
		}

		$result['product_id'] = $productId;
		$meta = mf_catalog_brand_article_by_product_id($productId);
		if (!is_array($meta))
		{
			return $result;
		}

		$result['article'] = trim((string)($meta['article'] ?? ''));
		$result['category'] = trim((string)($meta['brand'] ?? ''));
		if (function_exists('mf_catalog_product_export_name'))
		{
			$result['name'] = mf_catalog_product_export_name($productId);
		}
		else
		{
			$result['name'] = trim((string)($meta['name'] ?? ''));
		}

		return $result;
	}
}

if (!function_exists('mf_1c_export_product_title_from_block'))
{
	function mf_1c_export_product_title_from_block(string $itemBlock): string
	{
		if (!preg_match('/<Товар\b[^>]*>(.*?)<\/Товар>/su', $itemBlock, $wrap))
		{
			return '';
		}
		$inner = (string)$wrap[1];
		$propsPos = stripos($inner, '<ЗначенияРеквизитов');
		if ($propsPos !== false)
		{
			$inner = substr($inner, 0, $propsPos);
		}
		if (!preg_match('/<Наименование\b[^>]*>(.*?)<\/Наименование>/su', $inner, $nameMatch))
		{
			return '';
		}

		return trim(html_entity_decode(strip_tags($nameMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}
}

if (!function_exists('mf_1c_export_fix_product_title'))
{
	function mf_1c_export_fix_product_title(string $itemBlock, string $article): string
	{
		if (!function_exists('mf_name_is_trivial_article'))
		{
			return $itemBlock;
		}

		$title = mf_1c_export_product_title_from_block($itemBlock);
		if ($title === '' || $article === '' || !mf_name_is_trivial_article($title, $article))
		{
			return $itemBlock;
		}

		$exportName = '';
		if (preg_match('/<Ид\b[^>]*>(.*?)<\/Ид>/su', $itemBlock, $idMatch))
		{
			$meta = mf_1c_export_meta_from_product_xml_id(trim(strip_tags($idMatch[1])));
			$exportName = trim((string)($meta['name'] ?? ''));
		}
		if ($exportName === '' || mf_name_is_trivial_article($exportName, $article))
		{
			return $itemBlock;
		}

		$propsPos = stripos($itemBlock, '<ЗначенияРеквизитов');
		if ($propsPos === false)
		{
			return preg_replace(
				'/(<Наименование\b[^>]*>)(.*?)(<\/Наименование>)/su',
				'$1' . mf_1c_export_xml_escape($exportName) . '$3',
				$itemBlock,
				1
			) ?? $itemBlock;
		}

		$head = substr($itemBlock, 0, $propsPos);
		$tail = substr($itemBlock, $propsPos);
		$head = preg_replace(
			'/(<Наименование\b[^>]*>)(.*?)(<\/Наименование>)/su',
			'$1' . mf_1c_export_xml_escape($exportName) . '$3',
			$head,
			1
		) ?? $head;

		return $head . $tail;
	}
}

if (!function_exists('mf_1c_export_insert_after_product_title'))
{
	function mf_1c_export_insert_after_product_title(string $itemBlock, string $insert): string
	{
		if ($insert === '')
		{
			return $itemBlock;
		}

		$propsPos = stripos($itemBlock, '<ЗначенияРеквизитов');
		if ($propsPos === false)
		{
			return preg_replace(
				'/(<Наименование\b[^>]*>.*?<\/Наименование>)/su',
				'$1' . $insert,
				$itemBlock,
				1
			) ?? $itemBlock;
		}

		$head = substr($itemBlock, 0, $propsPos);
		$tail = substr($itemBlock, $propsPos);
		$head = preg_replace(
			'/(<Наименование\b[^>]*>.*?<\/Наименование>)/su',
			'$1' . $insert,
			$head,
			1
		) ?? $head;

		return $head . $tail;
	}
}

if (!function_exists('mf_1c_export_extract_item_meta_from_block'))
{
	/**
	 * @return array{article: string, category: string}
	 */
	function mf_1c_export_extract_item_meta_from_block(string $itemBlock): array
	{
		$article = '';
		$category = '';

		if (preg_match_all('/<ЗначениеРеквизита\b[^>]*>(.*?)<\/ЗначениеРеквизита>/su', $itemBlock, $propMatches))
		{
			foreach ($propMatches[1] as $propInner)
			{
				if (!preg_match('/<Наименование\b[^>]*>(.*?)<\/Наименование>/su', $propInner, $nameMatch))
				{
					continue;
				}
				if (!preg_match('/<Значение\b[^>]*>(.*?)<\/Значение>/su', $propInner, $valueMatch))
				{
					continue;
				}

				$name = trim(html_entity_decode(strip_tags($nameMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
				$value = trim(html_entity_decode(strip_tags($valueMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
				if ($value === '')
				{
					continue;
				}

				if ($article === '' && mf_1c_export_prop_name_matches($name, ['CML2_ARTICLE', 'ARTNUMBER', 'Артикул']))
				{
					$article = $value;
				}
				if ($category === '' && mf_1c_export_prop_name_matches($name, ['Категория', 'MF_CATEGORY', 'MF_BRAND', 'CML2_MANUFACTURER']))
				{
					$category = $value;
				}
			}
		}

		if ($article === '' || $category === '')
		{
			if (preg_match('/<Ид\b[^>]*>(.*?)<\/Ид>/su', $itemBlock, $idMatch))
			{
				$fallback = mf_1c_export_meta_from_product_xml_id(trim(strip_tags($idMatch[1])));
				if ($article === '')
				{
					$article = $fallback['article'];
				}
				if ($category === '')
				{
					$category = $fallback['category'];
				}
			}
		}

		return ['article' => $article, 'category' => $category];
	}
}

if (!function_exists('mf_1c_export_enrich_single_item_block'))
{
	function mf_1c_export_enrich_single_item_block(string $itemBlock): string
	{
		if (preg_match('/<Ид\b[^>]*>\s*ORDER_DELIVERY\s*<\/Ид>/su', $itemBlock))
		{
			return $itemBlock;
		}

		$itemName = mf_1c_export_product_title_from_block($itemBlock);
		if ($itemName !== '' && mb_stripos($itemName, 'доставка') !== false)
		{
			return $itemBlock;
		}

		$meta = mf_1c_export_extract_item_meta_from_block($itemBlock);
		$itemBlock = mf_1c_export_fix_product_title($itemBlock, $meta['article']);

		$insert = '';

		if ($meta['article'] !== '' && !preg_match('/<Артикул\b[^>]*>[^<]+<\/Артикул>/su', $itemBlock))
		{
			$insert .= '<Артикул>' . mf_1c_export_xml_escape($meta['article']) . '</Артикул>';
		}
		// <Категория> 1С не читает (нет в схеме CommerceML). Бренд — в <ТорговаяМарка> и в свойствах корзины.
		if ($meta['category'] !== '' && !preg_match('/<ТорговаяМарка\b[^>]*>[^<]+<\/ТорговаяМарка>/su', $itemBlock))
		{
			$insert .= '<ТорговаяМарка>' . mf_1c_export_xml_escape($meta['category']) . '</ТорговаяМарка>';
		}

		if ($insert === '')
		{
			return $itemBlock;
		}

		return mf_1c_export_insert_after_product_title($itemBlock, $insert);
	}
}

if (!function_exists('mf_1c_export_document_header'))
{
	function mf_1c_export_document_header(string $documentBlock): string
	{
		$headerEnd = strlen($documentBlock);
		foreach (['<Товары', '<Контрагенты', '<Подчиненные', '<Подчиненный', '<Скидки'] as $marker)
		{
			$pos = stripos($documentBlock, $marker);
			if ($pos !== false && $pos < $headerEnd)
			{
				$headerEnd = $pos;
			}
		}

		return substr($documentBlock, 0, $headerEnd);
	}
}

if (!function_exists('mf_1c_export_is_order_document'))
{
	function mf_1c_export_is_order_document(string $documentBlock): bool
	{
		$header = mf_1c_export_document_header($documentBlock);
		if (!preg_match('/<ХозОперация\b[^>]*>(.*?)<\/ХозОперация>/su', $header, $opMatch))
		{
			return true;
		}

		$operation = trim(html_entity_decode(strip_tags($opMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

		return $operation === '' || mb_stripos($operation, 'заказ') !== false;
	}
}

if (!function_exists('mf_1c_export_document_order_id'))
{
	function mf_1c_export_document_order_id(string $documentBlock): int
	{
		$header = mf_1c_export_document_header($documentBlock);
		if (preg_match('/<Ид\b[^>]*>\s*(\d+)\s*<\/Ид>/su', $header, $idMatch))
		{
			return (int)$idMatch[1];
		}

		return 0;
	}
}

if (!function_exists('mf_1c_export_rewrite_order_document_currency'))
{
	/**
	 * УНФ иначе может принять цену заказа как USD и умножить её на курс при создании заказа.
	 */
	function mf_1c_export_rewrite_order_document_currency(string $documentBlock): string
	{
		if (!mf_1c_export_is_order_document($documentBlock))
		{
			return $documentBlock;
		}

		if (!preg_match('/<Валюта\b[^>]*>.*?<\/Валюта>/su', $documentBlock))
		{
			return $documentBlock;
		}

		$updated = preg_replace(
			'/<Валюта\b[^>]*>.*?<\/Валюта>/su',
			'<Валюта>RUB</Валюта>',
			$documentBlock,
			1
		);

		if (!is_string($updated))
		{
			return $documentBlock;
		}

		return mf_1c_export_rewrite_document_header_tag($updated, 'Курс', '1');
	}
}

if (!function_exists('mf_1c_export_format_float'))
{
	function mf_1c_export_format_float(float $value): string
	{
		$formatted = number_format($value, 4, '.', '');

		return rtrim(rtrim($formatted, '0'), '.');
	}
}

if (!function_exists('mf_1c_export_rewrite_xml_tag'))
{
	function mf_1c_export_rewrite_xml_tag(string $xml, string $tagName, string $value): string
	{
		$escaped = mf_1c_export_xml_escape($value);
		if (preg_match('/<' . preg_quote($tagName, '/') . '\b[^>]*>.*?<\/' . preg_quote($tagName, '/') . '>/su', $xml))
		{
			return preg_replace(
				'/<' . preg_quote($tagName, '/') . '\b[^>]*>.*?<\/' . preg_quote($tagName, '/') . '>/su',
				'<' . $tagName . '>' . $escaped . '</' . $tagName . '>',
				$xml,
				1
			) ?? $xml;
		}

		return $xml;
	}
}

if (!function_exists('mf_1c_export_rewrite_document_header_tag'))
{
	function mf_1c_export_rewrite_document_header_tag(string $documentBlock, string $tagName, string $value): string
	{
		$header = mf_1c_export_document_header($documentBlock);
		$updatedHeader = mf_1c_export_rewrite_xml_tag($header, $tagName, $value);
		if ($updatedHeader === $header)
		{
			return $documentBlock;
		}

		return $updatedHeader . substr($documentBlock, strlen($header));
	}
}

if (!function_exists('mf_1c_export_item_requisite_xml'))
{
	function mf_1c_export_item_requisite_xml(string $name, string $value): string
	{
		return '<ЗначениеРеквизита>'
			. '<Наименование>' . mf_1c_export_xml_escape($name) . '</Наименование>'
			. '<Значение>' . mf_1c_export_xml_escape($value) . '</Значение>'
			. '</ЗначениеРеквизита>';
	}
}

if (!function_exists('mf_1c_export_inject_item_requisites'))
{
	/**
	 * Отдельные реквизиты строки нужны УНФ: типовая загрузка может пересчитать <Цена>,
	 * а расширение 1С сможет восстановить цену заказа сайта из этих реквизитов.
	 *
	 * @param array<string, string> $requisites
	 */
	function mf_1c_export_inject_item_requisites(string $itemBlock, array $requisites): string
	{
		$xml = '';
		foreach ($requisites as $name => $value)
		{
			$name = trim((string)$name);
			$value = trim((string)$value);
			if ($name === '' || $value === '')
			{
				continue;
			}
			if (mb_stripos($itemBlock, '<Наименование>' . $name . '</Наименование>') !== false)
			{
				continue;
			}
			$xml .= mf_1c_export_item_requisite_xml($name, $value);
		}
		if ($xml === '')
		{
			return $itemBlock;
		}

		if (preg_match('/<ЗначенияРеквизитов\b[^>]*>/su', $itemBlock))
		{
			return preg_replace(
				'/(<ЗначенияРеквизитов\b[^>]*>)/su',
				'$1' . $xml,
				$itemBlock,
				1
			) ?? $itemBlock;
		}

		$block = '<ЗначенияРеквизитов>' . $xml . '</ЗначенияРеквизитов>';
		if (preg_match('/<\/Товар>/su', $itemBlock))
		{
			return preg_replace('/<\/Товар>/su', $block . '</Товар>', $itemBlock, 1) ?? $itemBlock;
		}

		return $itemBlock . $block;
	}
}

if (!function_exists('mf_1c_export_order_basket_prices'))
{
	/**
	 * @return array<int, array{price: float, quantity: float, sum: float, name: string}>
	 */
	function mf_1c_export_order_basket_prices(int $orderId): array
	{
		if ($orderId <= 0 || !class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return [];
		}

		$order = \Bitrix\Sale\Order::load($orderId);
		if (!$order)
		{
			return [];
		}

		$items = [];
		$basket = $order->getBasket();
		if (!$basket)
		{
			return [];
		}

		foreach ($basket as $basketItem)
		{
			if (!$basketItem instanceof \Bitrix\Sale\BasketItemBase)
			{
				continue;
			}
			$name = trim((string)$basketItem->getField('NAME'));
			if ($name !== '' && mb_stripos($name, 'доставка') !== false)
			{
				continue;
			}

			$quantity = (float)$basketItem->getQuantity();
			if ($quantity <= 0)
			{
				$quantity = 1.0;
			}
			$price = (float)$basketItem->getPrice();
			if ($price <= 0)
			{
				$price = (float)$basketItem->getBasePrice();
			}
			if ($price <= 0)
			{
				continue;
			}

			$items[] = [
				'price' => $price,
				'quantity' => $quantity,
				'sum' => $price * $quantity,
				'name' => $name,
			];
		}

		return $items;
	}
}

if (!function_exists('mf_1c_export_rewrite_order_item_prices'))
{
	function mf_1c_export_rewrite_order_item_prices(string $documentBlock, int $orderId): string
	{
		$prices = mf_1c_export_order_basket_prices($orderId);
		if (empty($prices) || stripos($documentBlock, '<Товар') === false)
		{
			return $documentBlock;
		}
		$itemsTotal = 0.0;
		foreach ($prices as $row)
		{
			$itemsTotal += (float)$row['sum'];
		}

		$rowIndex = 0;
		$result = preg_replace_callback(
			'/<Товар\b[^>]*>.*?<\/Товар>/su',
			static function (array $matches) use (&$rowIndex, $prices, $orderId): string {
				$itemBlock = $matches[0];
				if (preg_match('/<Ид\b[^>]*>\s*ORDER_DELIVERY\s*<\/Ид>/su', $itemBlock))
				{
					return $itemBlock;
				}
				if (!isset($prices[$rowIndex]))
				{
					return $itemBlock;
				}

				$row = $prices[$rowIndex];
				$rowIndex++;

				$oldPrice = '';
				if (preg_match('/<ЦенаЗаЕдиницу\b[^>]*>(.*?)<\/ЦенаЗаЕдиницу>/su', $itemBlock, $priceMatch))
				{
					$oldPrice = trim(html_entity_decode(strip_tags($priceMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
				}

				$price = mf_1c_export_format_float((float)$row['price']);
				$quantity = mf_1c_export_format_float((float)$row['quantity']);
				$sum = mf_1c_export_format_float((float)$row['sum']);

				$itemBlock = mf_1c_export_rewrite_xml_tag($itemBlock, 'ЦенаЗаЕдиницу', $price);
				$itemBlock = mf_1c_export_rewrite_xml_tag($itemBlock, 'Цена', $price);
				$itemBlock = mf_1c_export_rewrite_xml_tag($itemBlock, 'Количество', $quantity);
				$itemBlock = mf_1c_export_rewrite_xml_tag($itemBlock, 'Сумма', $sum);
				$itemBlock = mf_1c_export_inject_item_requisites($itemBlock, [
					'Цена заказа сайта MF' => $price,
					'Сумма строки сайта MF' => $sum,
					'Количество заказа сайта MF' => $quantity,
					'Номер строки заказа сайта MF' => (string)$rowIndex,
				]);

				if ($oldPrice !== '' && $oldPrice !== $price)
				{
					mf_1c_export_log(
						'EXPORT ITEM PRICE: order_id=' . $orderId
						. ' row=' . $rowIndex
						. ' old=' . $oldPrice
						. ' new=' . $price
						. ' qty=' . $quantity
					);
				}

				return $itemBlock;
			},
			$documentBlock
		);

		if (!is_string($result))
		{
			return $documentBlock;
		}

		$result = mf_1c_export_rewrite_document_header_tag(
			$result,
			'Сумма',
			mf_1c_export_format_float($itemsTotal)
		);

		return $result;
	}
}

if (!function_exists('mf_1c_export_order_id_by_xml_number'))
{
	function mf_1c_export_order_id_by_xml_number(string $xmlNumber): int
	{
		$xmlNumber = trim($xmlNumber);
		if ($xmlNumber === '' || !class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return 0;
		}

		if (function_exists('mf_order_account_number_parse_display'))
		{
			$parsed = mf_order_account_number_parse_display($xmlNumber);
			if (is_array($parsed))
			{
				$row = \Bitrix\Sale\Internals\OrderTable::getList([
					'filter' => [
						'=USER_ID' => $parsed[0],
						'=ACCOUNT_NUMBER' => $parsed[1],
					],
					'select' => ['ID'],
					'order' => ['ID' => 'DESC'],
					'limit' => 1,
				])->fetch();
				if (is_array($row))
				{
					return (int)($row['ID'] ?? 0);
				}
			}
		}

		$accountNumber = $xmlNumber;
		if (preg_match('/^s1(\d+)$/iu', $xmlNumber, $prefixed))
		{
			$accountNumber = $prefixed[1];
		}
		elseif (preg_match('/^(\d+)-(\d+)$/u', $xmlNumber, $display))
		{
			$row = \Bitrix\Sale\Internals\OrderTable::getList([
				'filter' => [
					'=USER_ID' => (int)$display[1],
					'=ACCOUNT_NUMBER' => $display[2],
				],
				'select' => ['ID'],
				'order' => ['ID' => 'DESC'],
				'limit' => 1,
			])->fetch();
			if (is_array($row))
			{
				return (int)($row['ID'] ?? 0);
			}

			$accountNumber = $display[2];
		}

		if (!ctype_digit($accountNumber))
		{
			return 0;
		}

		$row = \Bitrix\Sale\Internals\OrderTable::getList([
			'filter' => ['=ACCOUNT_NUMBER' => $accountNumber],
			'select' => ['ID'],
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? (int)($row['ID'] ?? 0) : 0;
	}
}

if (!function_exists('mf_1c_export_order_display_number_by_id'))
{
	function mf_1c_export_order_display_number_by_id(int $orderId): string
	{
		if ($orderId <= 0 || !class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return '';
		}

		$order = \Bitrix\Sale\Order::load($orderId);
		if (!$order)
		{
			return '';
		}

		$accountNumber = trim((string)$order->getField('ACCOUNT_NUMBER'));
		if ($accountNumber === '')
		{
			return '';
		}

		$uid = (int)$order->getUserId();
		if (function_exists('mf_order_account_number_for_display'))
		{
			return mf_order_account_number_for_display($uid, $accountNumber);
		}

		return $uid . '-' . $accountNumber;
	}
}

if (!function_exists('mf_1c_export_rewrite_order_document_number'))
{
	/**
	 * В CommerceML вместо s1{ACCOUNT_NUMBER} подставляет печатный номер {USER_ID}-{ACCOUNT_NUMBER}.
	 */
	function mf_1c_export_rewrite_order_document_number(string $documentBlock): string
	{
		if (!mf_1c_export_is_order_document($documentBlock))
		{
			return $documentBlock;
		}
		$documentBlock = mf_1c_export_rewrite_order_document_currency($documentBlock);
		$documentBlock = mf_1c_export_normalize_payment_requisite_names($documentBlock);

		$orderId = mf_1c_export_document_order_id($documentBlock);
		$oldNumber = '';
		if (preg_match('/<Номер\b[^>]*>(.*?)<\/Номер>/su', $documentBlock, $numberMatch))
		{
			$oldNumber = trim(html_entity_decode(strip_tags($numberMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		}
		if ($orderId <= 0 && $oldNumber !== '')
		{
			$orderId = mf_1c_export_order_id_by_xml_number($oldNumber);
		}

		$displayNumber = mf_1c_export_order_display_number_by_id($orderId);
		if ($orderId <= 0 && $displayNumber !== '')
		{
			$orderId = mf_1c_export_order_id_by_xml_number($displayNumber);
		}

		if ($orderId > 0)
		{
			$documentBlock = mf_1c_export_rewrite_order_item_prices($documentBlock, $orderId);
			$documentBlock = mf_1c_export_inject_order_pay_system_requisite($documentBlock, $orderId);
			$documentBlock = mf_1c_export_inject_order_email_requisite($documentBlock, $orderId);
			$documentBlock = mf_1c_export_inject_order_phone_requisite($documentBlock, $orderId);
			$documentBlock = mf_1c_export_inject_counterparty_contacts($documentBlock, $orderId);
		}

		$documentBlock = mf_1c_export_inject_order_pay_system_requisite_from_xml($documentBlock);

		if ($displayNumber === '' || $oldNumber === '' || !preg_match('/<Номер\b[^>]*>.*?<\/Номер>/su', $documentBlock))
		{
			return $documentBlock;
		}
		if ($oldNumber === $displayNumber)
		{
			return mf_1c_export_inject_order_document_requisites($documentBlock, $displayNumber);
		}

		$updated = preg_replace(
			'/<Номер\b[^>]*>.*?<\/Номер>/su',
			'<Номер>' . mf_1c_export_xml_escape($displayNumber) . '</Номер>',
			$documentBlock,
			1
		);
		if (!is_string($updated))
		{
			return $documentBlock;
		}

		if (!preg_match('/<Номер1С\b[^>]*>/su', $updated))
		{
			$updated = preg_replace(
				'/(<Номер\b[^>]*>.*?<\/Номер>)/su',
				'$1' . '<Номер1С>' . mf_1c_export_xml_escape($displayNumber) . '</Номер1С>',
				$updated,
				1
			) ?? $updated;
		}

		mf_1c_export_log(
			'EXPORT NUMBER: order_id=' . $orderId
			. ' old=' . $oldNumber
			. ' new=' . $displayNumber
		);

		return mf_1c_export_inject_order_document_requisites($updated, $displayNumber);
	}
}

if (!function_exists('mf_1c_export_extract_document_requisite'))
{
	function mf_1c_export_extract_document_requisite(string $documentBlock, string $name): string
	{
		$name = trim($name);
		if ($name === '' || !preg_match_all(
			'/<ЗначениеРеквизита\b[^>]*>(.*?)<\/ЗначениеРеквизита>/su',
			$documentBlock,
			$matches
		))
		{
			return '';
		}

		foreach ($matches[1] as $inner)
		{
			if (!preg_match('/<Наименование\b[^>]*>(.*?)<\/Наименование>/su', $inner, $nameMatch))
			{
				continue;
			}
			if (!preg_match('/<Значение\b[^>]*>(.*?)<\/Значение>/su', $inner, $valueMatch))
			{
				continue;
			}

			$propName = trim(html_entity_decode(strip_tags($nameMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			if ($propName !== $name)
			{
				continue;
			}

			return trim(html_entity_decode(strip_tags($valueMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		}

		return '';
	}
}

if (!function_exists('mf_1c_export_normalize_payment_requisite_names'))
{
	/**
	 * Bitrix export с setLanguage('en') отдаёт PaymentSystem, а 1С ждёт «Метод оплаты».
	 */
	function mf_1c_export_normalize_payment_requisite_names(string $documentBlock): string
	{
		$map = [
			'PaymentSystem' => 'Метод оплаты',
			'Payment system ID' => 'Метод оплаты ИД',
			'PaymentSystemID' => 'Метод оплаты ИД',
		];

		foreach ($map as $from => $to)
		{
			if (mb_stripos($documentBlock, '<Наименование>' . $to . '</Наименование>') !== false)
			{
				continue;
			}

			$value = mf_1c_export_extract_document_requisite($documentBlock, $from);
			if ($value === '')
			{
				continue;
			}

			if (mb_stripos($documentBlock, '<Наименование>' . $to . '</Наименование>') !== false)
			{
				continue;
			}

			$requisite = '<ЗначениеРеквизита>'
				. '<Наименование>' . mf_1c_export_xml_escape($to) . '</Наименование>'
				. '<Значение>' . mf_1c_export_xml_escape($value) . '</Значение>'
				. '</ЗначениеРеквизита>';

			if (preg_match('/<ЗначенияРеквизитов\b[^>]*>/su', $documentBlock))
			{
				$updated = preg_replace(
					'/(<ЗначенияРеквизитов\b[^>]*>)/su',
					'$1' . $requisite,
					$documentBlock,
					1
				);
				$documentBlock = is_string($updated) ? $updated : $documentBlock;
			}
			else
			{
				$block = '<ЗначенияРеквизитов>' . $requisite . '</ЗначенияРеквизитов>';
				if (preg_match('/<\/Документ>/su', $documentBlock))
				{
					$documentBlock = preg_replace('/<\/Документ>/su', $block . '</Документ>', $documentBlock, 1) ?? $documentBlock;
				}
				else
				{
					$documentBlock .= $block;
				}
			}
		}

		return $documentBlock;
	}
}

if (!function_exists('mf_1c_export_order_payment_meta'))
{
	/**
	 * @return array{code: string, name: string, id: string}
	 */
	function mf_1c_export_order_payment_meta(int $orderId): array
	{
		$empty = ['code' => '', 'name' => '', 'id' => ''];
		if ($orderId <= 0 || !class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return $empty;
		}

		$order = \Bitrix\Sale\Order::load($orderId);
		if (!$order)
		{
			return $empty;
		}

		$paymentCollection = $order->getPaymentCollection();
		if (!$paymentCollection)
		{
			return $empty;
		}

		foreach ($paymentCollection as $payment)
		{
			if (!$payment instanceof \Bitrix\Sale\Payment || $payment->isInner())
			{
				continue;
			}

			$paySystemId = (int)$payment->getPaymentSystemId();
			if ($paySystemId <= 0)
			{
				continue;
			}

			$service = \Bitrix\Sale\PaySystem\Manager::getObjectById($paySystemId);
			if (!$service)
			{
				continue;
			}

			$name = trim((string)$payment->getPaymentSystemName());
			if ($name === '')
			{
				$name = trim((string)$service->getField('NAME'));
			}

			$actionFile = mb_strtolower(trim((string)$service->getField('ACTION_FILE')));
			$code = '';
			if ($actionFile === 'mf_paykeeper' || $actionFile === 'mfpaykeeper')
			{
				$code = 'paykeeper';
			}
			elseif ($actionFile === 'mf_card2card')
			{
				$code = 'card2card';
			}
			elseif ($actionFile === 'bill')
			{
				$code = 'bill';
			}

			return [
				'code' => $code,
				'name' => $name,
				'id' => (string)$paySystemId,
			];
		}

		return $empty;
	}
}

if (!function_exists('mf_1c_export_order_pay_system_code'))
{
	function mf_1c_export_order_pay_system_code(int $orderId): string
	{
		$meta = mf_1c_export_order_payment_meta($orderId);

		return (string)($meta['code'] ?? '');
	}
}

if (!function_exists('mf_1c_export_order_property_value'))
{
	function mf_1c_export_order_property_value(int $orderId, array $codes): string
	{
		if ($orderId <= 0 || $codes === [] || !class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return '';
		}

		$order = \Bitrix\Sale\Order::load($orderId);
		if (!$order)
		{
			return '';
		}

		$propCollection = $order->getPropertyCollection();
		if (!$propCollection)
		{
			return '';
		}

		foreach ($codes as $code)
		{
			$prop = $propCollection->getItemByOrderPropertyCode((string)$code);
			if (!$prop)
			{
				continue;
			}
			$value = trim((string)$prop->getValue());
			if ($value !== '')
			{
				return $value;
			}
		}

		return '';
	}
}

if (!function_exists('mf_1c_export_order_customer_email'))
{
	function mf_1c_export_order_customer_email(int $orderId): string
	{
		if ($orderId <= 0 || !class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return '';
		}

		$order = \Bitrix\Sale\Order::load($orderId);
		if (!$order)
		{
			return '';
		}

		$email = mf_1c_export_order_property_value($orderId, ['EMAIL', 'email', 'E_MAIL', 'MAIL']);
		if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
		{
			return $email;
		}

		$userId = (int)$order->getUserId();
		if ($userId > 0)
		{
			try
			{
				$user = \CUser::GetByID($userId)->Fetch();
				if (is_array($user))
				{
					$email = trim((string)($user['EMAIL'] ?? ''));
					if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
					{
						return $email;
					}
				}
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}

		return '';
	}
}

if (!function_exists('mf_1c_export_order_customer_phone'))
{
	function mf_1c_export_order_customer_phone(int $orderId): string
	{
		if ($orderId <= 0 || !class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return '';
		}

		$phone = mf_1c_export_order_property_value($orderId, ['PHONE', 'phone', 'TEL', 'MOBILE', 'PERSONAL_PHONE']);
		if ($phone !== '')
		{
			return $phone;
		}

		$order = \Bitrix\Sale\Order::load($orderId);
		if (!$order)
		{
			return '';
		}

		$userId = (int)$order->getUserId();
		if ($userId > 0)
		{
			try
			{
				$user = \CUser::GetByID($userId)->Fetch();
				if (is_array($user))
				{
					foreach (['PERSONAL_PHONE', 'PERSONAL_MOBILE', 'WORK_PHONE'] as $field)
					{
						$phone = trim((string)($user[$field] ?? ''));
						if ($phone !== '')
						{
							return $phone;
						}
					}
				}
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}

		return '';
	}
}

if (!function_exists('mf_1c_export_inject_document_requisites'))
{
	function mf_1c_export_inject_document_requisites(string $documentBlock, array $requisites): string
	{
		$xml = '';
		foreach ($requisites as $name => $value)
		{
			$name = trim((string)$name);
			$value = trim((string)$value);
			if ($name === '' || $value === '')
			{
				continue;
			}
			if (mb_stripos($documentBlock, '<Наименование>' . $name . '</Наименование>') !== false)
			{
				continue;
			}
			$xml .= '<ЗначениеРеквизита>'
				. '<Наименование>' . mf_1c_export_xml_escape($name) . '</Наименование>'
				. '<Значение>' . mf_1c_export_xml_escape($value) . '</Значение>'
				. '</ЗначениеРеквизита>';
		}

		if ($xml === '')
		{
			return $documentBlock;
		}

		if (preg_match('/<ЗначенияРеквизитов\b[^>]*>/su', $documentBlock))
		{
			$updated = preg_replace(
				'/(<ЗначенияРеквизитов\b[^>]*>)/su',
				'$1' . $xml,
				$documentBlock,
				1
			);

			return is_string($updated) ? $updated : $documentBlock;
		}

		$block = '<ЗначенияРеквизитов>' . $xml . '</ЗначенияРеквизитов>';
		if (preg_match('/<\/Документ>/su', $documentBlock))
		{
			return preg_replace('/<\/Документ>/su', $block . '</Документ>', $documentBlock, 1) ?? $documentBlock;
		}

		return $documentBlock . $block;
	}
}

if (!function_exists('mf_1c_export_inject_order_email_requisite'))
{
	function mf_1c_export_inject_order_email_requisite(string $documentBlock, int $orderId): string
	{
		$email = mf_1c_export_order_customer_email($orderId);
		if ($email === '')
		{
			return $documentBlock;
		}

		$requisites = [];
		foreach (['Email', 'E-mail', 'E-Mail'] as $name)
		{
			$requisites[$name] = $email;
		}

		return mf_1c_export_inject_document_requisites($documentBlock, $requisites);
	}
}

if (!function_exists('mf_1c_export_inject_order_phone_requisite'))
{
	function mf_1c_export_inject_order_phone_requisite(string $documentBlock, int $orderId): string
	{
		$phone = mf_1c_export_order_customer_phone($orderId);
		if ($phone === '')
		{
			return $documentBlock;
		}

		$requisites = [];
		foreach (['Телефон', 'PHONE', 'Phone', 'Контактный телефон'] as $name)
		{
			$requisites[$name] = $phone;
		}

		return mf_1c_export_inject_document_requisites($documentBlock, $requisites);
	}
}

if (!function_exists('mf_1c_export_counterparty_contact_exists'))
{
	function mf_1c_export_counterparty_contact_exists(string $contragentBlock, string $kind): bool
	{
		$kind = mb_strtolower(trim($kind));
		if ($kind === '' || !preg_match_all('/<Контакт\b[^>]*>(.*?)<\/Контакт>/su', $contragentBlock, $matches))
		{
			return false;
		}

		foreach ($matches[1] as $inner)
		{
			if (!preg_match('/<Тип\b[^>]*>(.*?)<\/Тип>/su', $inner, $typeMatch))
			{
				continue;
			}
			$type = mb_strtolower(trim(html_entity_decode(strip_tags($typeMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
			if ($kind === 'phone' && (mb_strpos($type, 'тел') !== false || mb_strpos($type, 'phone') !== false))
			{
				return true;
			}
			if ($kind === 'email' && (mb_strpos($type, 'почт') !== false || mb_strpos($type, 'mail') !== false || mb_strpos($type, 'e-mail') !== false))
			{
				return true;
			}
		}

		return false;
	}
}

if (!function_exists('mf_1c_export_build_counterparty_contact_xml'))
{
	function mf_1c_export_build_counterparty_contact_xml(string $type, string $value): string
	{
		return '<Контакт>'
			. '<Тип>' . mf_1c_export_xml_escape($type) . '</Тип>'
			. '<Значение>' . mf_1c_export_xml_escape($value) . '</Значение>'
			. '</Контакт>';
	}
}

if (!function_exists('mf_1c_export_inject_counterparty_contacts_block'))
{
	function mf_1c_export_inject_counterparty_contacts_block(string $contragentBlock, string $phone, string $email): string
	{
		$contactsXml = '';
		if ($phone !== '' && !mf_1c_export_counterparty_contact_exists($contragentBlock, 'phone'))
		{
			$contactsXml .= mf_1c_export_build_counterparty_contact_xml('Телефон рабочий', $phone);
		}
		if ($email !== '' && !mf_1c_export_counterparty_contact_exists($contragentBlock, 'email'))
		{
			$contactsXml .= mf_1c_export_build_counterparty_contact_xml('Электронная почта', $email);
		}
		if ($contactsXml === '')
		{
			return $contragentBlock;
		}

		if (preg_match('/<Контакты\b[^>]*>/su', $contragentBlock))
		{
			$updated = preg_replace(
				'/(<Контакты\b[^>]*>)/su',
				'$1' . $contactsXml,
				$contragentBlock,
				1
			);

			return is_string($updated) ? $updated : $contragentBlock;
		}

		$block = '<Контакты>' . $contactsXml . '</Контакты>';
		if (preg_match('/<Роль\b[^>]*>/su', $contragentBlock))
		{
			$updated = preg_replace('/(<Роль\b[^>]*>)/su', $block . '$1', $contragentBlock, 1);

			return is_string($updated) ? $updated : $contragentBlock;
		}

		if (preg_match('/<\/Контрагент>/su', $contragentBlock))
		{
			$updated = preg_replace('/<\/Контрагент>/su', $block . '</Контрагент>', $contragentBlock, 1);

			return is_string($updated) ? $updated : $contragentBlock;
		}

		return $contragentBlock . $block;
	}
}

if (!function_exists('mf_1c_export_inject_counterparty_contacts'))
{
	function mf_1c_export_inject_counterparty_contacts(string $documentBlock, int $orderId): string
	{
		if ($orderId <= 0 || stripos($documentBlock, '<Контрагент') === false)
		{
			return $documentBlock;
		}

		$phone = mf_1c_export_order_customer_phone($orderId);
		$email = mf_1c_export_order_customer_email($orderId);
		if ($phone === '' && $email === '')
		{
			return $documentBlock;
		}

		$updated = preg_replace_callback(
			'/<Контрагенты\b[^>]*>.*?<\/Контрагенты>/su',
			static function (array $matches) use ($phone, $email): string {
				$block = $matches[0];
				if (!preg_match('/<Контрагент\b[^>]*>.*?<\/Контрагент>/su', $block, $agentMatch))
				{
					return $block;
				}

				$newAgent = mf_1c_export_inject_counterparty_contacts_block($agentMatch[0], $phone, $email);

				return str_replace($agentMatch[0], $newAgent, $block);
			},
			$documentBlock,
			1
		);

		return is_string($updated) ? $updated : $documentBlock;
	}
}

if (!function_exists('mf_1c_export_inject_order_pay_system_requisite'))
{
	function mf_1c_export_inject_order_pay_system_requisite(string $documentBlock, int $orderId): string
	{
		$meta = mf_1c_export_order_payment_meta($orderId);
		if (($meta['code'] ?? '') === '' && ($meta['name'] ?? '') === '' && ($meta['id'] ?? '') === '')
		{
			return $documentBlock;
		}

		$requisites = [];
		if (($meta['code'] ?? '') !== '')
		{
			$requisites['MF_PAY_SYSTEM'] = (string)$meta['code'];
		}
		if (($meta['name'] ?? '') !== '')
		{
			$requisites['Метод оплаты'] = (string)$meta['name'];
		}
		if (($meta['id'] ?? '') !== '')
		{
			$requisites['Метод оплаты ИД'] = (string)$meta['id'];
		}
		if (($meta['code'] ?? '') === 'paykeeper')
		{
			$requisites['MF_PAYKEEPER'] = '1';
		}

		$xml = '';
		foreach ($requisites as $name => $value)
		{
			if (mb_stripos($documentBlock, '<Наименование>' . $name . '</Наименование>') !== false)
			{
				continue;
			}
			$xml .= '<ЗначениеРеквизита>'
				. '<Наименование>' . mf_1c_export_xml_escape($name) . '</Наименование>'
				. '<Значение>' . mf_1c_export_xml_escape($value) . '</Значение>'
				. '</ЗначениеРеквизита>';
		}

		if ($xml === '')
		{
			return $documentBlock;
		}

		if (preg_match('/<ЗначенияРеквизитов\b[^>]*>/su', $documentBlock))
		{
			$updated = preg_replace(
				'/(<ЗначенияРеквизитов\b[^>]*>)/su',
				'$1' . $xml,
				$documentBlock,
				1
			);

			return is_string($updated) ? $updated : $documentBlock;
		}

		$block = '<ЗначенияРеквизитов>' . $xml . '</ЗначенияРеквизитов>';
		if (preg_match('/<\/Документ>/su', $documentBlock))
		{
			return preg_replace('/<\/Документ>/su', $block . '</Документ>', $documentBlock, 1) ?? $documentBlock;
		}

		return $documentBlock . $block;
	}
}

if (!function_exists('mf_1c_export_detect_pay_code'))
{
	function mf_1c_export_detect_pay_code(string $name, string $id): string
	{
		$hay = mb_strtolower($name . ' ' . $id);
		if (mb_strpos($hay, 'paykeeper') !== false || mb_strpos($hay, 'пейкипер') !== false)
		{
			return 'paykeeper';
		}
		if (mb_strpos($hay, 'карт') !== false && mb_strpos($hay, 'перевод') !== false)
		{
			return 'card2card';
		}
		if (mb_strpos($hay, 'безнал') !== false || mb_strpos($hay, 'bill') !== false || mb_strpos($hay, 'счет') !== false)
		{
			return 'bill';
		}

		return '';
	}
}

if (!function_exists('mf_1c_export_inject_order_pay_system_requisite_from_xml'))
{
	function mf_1c_export_inject_order_pay_system_requisite_from_xml(string $documentBlock): string
	{
		$name = mf_1c_export_extract_document_requisite($documentBlock, 'Метод оплаты');
		if ($name === '')
		{
			$name = mf_1c_export_extract_document_requisite($documentBlock, 'PaymentSystem');
		}

		$id = mf_1c_export_extract_document_requisite($documentBlock, 'Метод оплаты ИД');
		if ($id === '')
		{
			$id = mf_1c_export_extract_document_requisite($documentBlock, 'Payment system ID');
		}

		$code = mf_1c_export_detect_pay_code($name, $id);
		if ($code === '')
		{
			return $documentBlock;
		}

		$requisites = ['MF_PAY_SYSTEM' => $code];
		if ($code === 'paykeeper')
		{
			$requisites['MF_PAYKEEPER'] = '1';
		}
		if ($name !== '' && mb_stripos($documentBlock, '<Наименование>Метод оплаты</Наименование>') === false)
		{
			$requisites['Метод оплаты'] = $name;
		}
		if ($id !== '' && mb_stripos($documentBlock, '<Наименование>Метод оплаты ИД</Наименование>') === false)
		{
			$requisites['Метод оплаты ИД'] = $id;
		}

		$xml = '';
		foreach ($requisites as $reqName => $value)
		{
			if (mb_stripos($documentBlock, '<Наименование>' . $reqName . '</Наименование>') !== false)
			{
				continue;
			}
			$xml .= '<ЗначениеРеквизита>'
				. '<Наименование>' . mf_1c_export_xml_escape($reqName) . '</Наименование>'
				. '<Значение>' . mf_1c_export_xml_escape($value) . '</Значение>'
				. '</ЗначениеРеквизита>';
		}

		if ($xml === '')
		{
			return $documentBlock;
		}

		if (preg_match('/<ЗначенияРеквизитов\b[^>]*>/su', $documentBlock))
		{
			$updated = preg_replace(
				'/(<ЗначенияРеквизитов\b[^>]*>)/su',
				'$1' . $xml,
				$documentBlock,
				1
			);

			return is_string($updated) ? $updated : $documentBlock;
		}

		$block = '<ЗначенияРеквизитов>' . $xml . '</ЗначенияРеквизитов>';
		if (preg_match('/<\/Документ>/su', $documentBlock))
		{
			return preg_replace('/<\/Документ>/su', $block . '</Документ>', $documentBlock, 1) ?? $documentBlock;
		}

		return $documentBlock . $block;
	}
}

if (!function_exists('mf_1c_export_inject_order_document_requisites'))
{
	/**
	 * Реквизиты для УНФ при CommerceML-обмене: явный печатный номер заказа.
	 */
	function mf_1c_export_inject_order_document_requisites(string $documentBlock, string $displayNumber): string
	{
		$displayNumber = trim($displayNumber);
		if ($displayNumber === '')
		{
			return $documentBlock;
		}

		$requisiteNames = [
			'Номер по 1С',
			'Номер заказа сайта',
			'Номер заказа на сайте',
			'Номер на сайте',
			'Номер заказа MF',
			'Идентификатор заказа',
		];
		$requisites = '';
		foreach ($requisiteNames as $name)
		{
			if (mb_stripos($documentBlock, '<Наименование>' . $name . '</Наименование>') !== false)
			{
				continue;
			}
			$requisites .= '<ЗначениеРеквизита>'
				. '<Наименование>' . mf_1c_export_xml_escape($name) . '</Наименование>'
				. '<Значение>' . mf_1c_export_xml_escape($displayNumber) . '</Значение>'
				. '</ЗначениеРеквизита>';
		}
		if ($requisites === '')
		{
			return $documentBlock;
		}

		$block = '<ЗначенияРеквизитов>' . $requisites . '</ЗначенияРеквизитов>';
		if (preg_match('/<ЗначенияРеквизитов\b[^>]*>/su', $documentBlock))
		{
			$updated = preg_replace(
				'/(<ЗначенияРеквизитов\b[^>]*>)/su',
				'$1' . $requisites,
				$documentBlock,
				1
			);

			return is_string($updated) ? $updated : $documentBlock;
		}

		if (preg_match('/<\/Документ>/su', $documentBlock))
		{
			return preg_replace('/<\/Документ>/su', $block . '</Документ>', $documentBlock, 1) ?? $documentBlock;
		}

		return $documentBlock . $block;
	}
}

if (!function_exists('mf_1c_rewrite_order_numbers_xml_export'))
{
	function mf_1c_rewrite_order_numbers_xml_export(string $contents): string
	{
		if (!mf_1c_export_is_query_request())
		{
			return $contents;
		}

		$trimmed = ltrim($contents);
		if ($trimmed === '' || $trimmed[0] !== '<' || stripos($contents, '<Документ') === false)
		{
			return $contents;
		}

		try
		{
			$result = preg_replace_callback(
				'/<Документ\b[^>]*>.*?<\/Документ>/su',
				static function (array $matches): string {
					try
					{
						return mf_1c_export_rewrite_order_document_number($matches[0]);
					}
					catch (\Throwable $e)
					{
						return $matches[0];
					}
				},
				$contents
			);

			return is_string($result) ? $result : $contents;
		}
		catch (\Throwable $e)
		{
			return $contents;
		}
	}
}

if (!function_exists('mf_1c_enrich_orders_xml_export'))
{
	/**
	 * Обогащение XML заказов перед выгрузкой в 1С: номер {USER_ID}-{ACCOUNT_NUMBER}, артикул и бренд в строках.
	 */
	function mf_1c_enrich_orders_xml_export(string $contents): string
	{
		if (!mf_1c_export_is_query_request())
		{
			return $contents;
		}

		$contents = mf_1c_rewrite_order_numbers_xml_export($contents);

		$trimmed = ltrim($contents);
		if ($trimmed === '' || $trimmed[0] !== '<')
		{
			return $contents;
		}
		if (stripos($contents, '<Товар') === false && stripos($contents, 'Товар>') === false)
		{
			return $contents;
		}

		try
		{
			$result = preg_replace_callback(
				'/<Товар\b[^>]*>.*?<\/Товар>/su',
				static function (array $matches): string {
					try
					{
						return mf_1c_export_enrich_single_item_block($matches[0]);
					}
					catch (\Throwable $e)
					{
						return $matches[0];
					}
				},
				$contents
			);

			return is_string($result) ? $result : $contents;
		}
		catch (\Throwable $e)
		{
			return $contents;
		}
	}
}
