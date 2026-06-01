<?php
/**
 * Дополнение XML экспорта заказов Bitrix → 1С:УНФ.
 * В стандартном CSaleExport у строки <Товар> нет <Артикул>/<Категория>, хотя 1С читает их при создании номенклатуры.
 *
 * Важно: полный XML заказа часто невалиден для DOMDocument (битые символы в ФИО покупателя),
 * поэтому обогащаем каждый блок <Товар> отдельно через regex.
 */

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
		if ($meta['category'] !== '' && !preg_match('/<Категория\b[^>]*>[^<]+<\/Категория>/su', $itemBlock))
		{
			$insert .= '<Категория>' . mf_1c_export_xml_escape($meta['category']) . '</Категория>';
		}

		if ($insert === '')
		{
			return $itemBlock;
		}

		return mf_1c_export_insert_after_product_title($itemBlock, $insert);
	}
}

if (!function_exists('mf_1c_enrich_orders_xml_export'))
{
	/**
	 * Добавляет в XML заказов теги <Артикул> и <Категория> на уровне строки <Товар>.
	 */
	function mf_1c_enrich_orders_xml_export(string $contents): string
	{
		if (!mf_1c_export_is_query_request())
		{
			return $contents;
		}

		$trimmed = ltrim($contents);
		if ($trimmed === '' || $trimmed[0] !== '<')
		{
			return $contents;
		}
		if (stripos($contents, '<Товар') === false && stripos($contents, 'Товар>') === false)
		{
			return $contents;
		}

		$result = preg_replace_callback(
			'/<Товар\b[^>]*>.*?<\/Товар>/su',
			static function (array $matches): string {
				return mf_1c_export_enrich_single_item_block($matches[0]);
			},
			$contents
		);

		return is_string($result) ? $result : $contents;
	}
}
