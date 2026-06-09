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
			$prop->setValue(null, $settings);
			mf_1c_export_log('EXPORT PREPARE: accountNumberPrefix cleared for CommerceML query');
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
