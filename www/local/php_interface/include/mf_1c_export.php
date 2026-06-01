<?php
/**
 * Дополнение XML экспорта заказов Bitrix → 1С:УНФ.
 * В стандартном CSaleExport у строки <Товар> нет <Артикул>/<Категория>, хотя 1С читает их при создании номенклатуры.
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

if (!function_exists('mf_1c_export_meta_from_product_xml_id'))
{
	/**
	 * @return array{article: string, category: string}
	 */
	function mf_1c_export_meta_from_product_xml_id(string $xmlId): array
	{
		$result = ['article' => '', 'category' => ''];
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

		$meta = mf_catalog_brand_article_by_product_id($productId);
		if (!is_array($meta))
		{
			return $result;
		}

		$result['article'] = trim((string)($meta['article'] ?? ''));
		$result['category'] = trim((string)($meta['brand'] ?? ''));

		return $result;
	}
}

if (!function_exists('mf_1c_export_extract_item_meta'))
{
	/**
	 * @return array{article: string, category: string}
	 */
	function mf_1c_export_extract_item_meta(\DOMElement $item): array
	{
		$article = '';
		$category = '';

		$propNodes = $item->getElementsByTagName('ЗначениеРеквизита');
		for ($i = 0; $i < $propNodes->length; $i++)
		{
			$prop = $propNodes->item($i);
			if (!$prop instanceof \DOMElement)
			{
				continue;
			}

			$nameNode = null;
			$valueNode = null;
			foreach ($prop->childNodes as $child)
			{
				if (!$child instanceof \DOMElement)
				{
					continue;
				}
				if ($child->localName === 'Наименование' || $child->tagName === 'Наименование')
				{
					$nameNode = $child;
				}
				elseif ($child->localName === 'Значение' || $child->tagName === 'Значение')
				{
					$valueNode = $child;
				}
			}

			if (!$nameNode || !$valueNode)
			{
				continue;
			}

			$name = trim((string)$nameNode->textContent);
			$value = trim((string)$valueNode->textContent);
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

		if ($article === '' || $category === '')
		{
			$idNode = null;
			foreach ($item->childNodes as $child)
			{
				if ($child instanceof \DOMElement && ($child->localName === 'Ид' || $child->tagName === 'Ид'))
				{
					$idNode = $child;
					break;
				}
			}
			if ($idNode)
			{
				$fallback = mf_1c_export_meta_from_product_xml_id((string)$idNode->textContent);
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

if (!function_exists('mf_1c_export_ensure_child_after'))
{
	function mf_1c_export_ensure_child_after(\DOMDocument $doc, \DOMElement $parent, string $tagName, string $value, \DOMElement $afterNode): void
	{
		$value = trim($value);
		if ($value === '')
		{
			return;
		}

		foreach ($parent->childNodes as $child)
		{
			if ($child instanceof \DOMElement && ($child->localName === $tagName || $child->tagName === $tagName))
			{
				if (trim((string)$child->textContent) === '')
				{
					while ($child->firstChild)
					{
						$child->removeChild($child->firstChild);
					}
					$child->appendChild($doc->createTextNode($value));
				}

				return;
			}
		}

		$newNode = $doc->createElement($tagName);
		$newNode->appendChild($doc->createTextNode($value));
		if ($afterNode->nextSibling)
		{
			$parent->insertBefore($newNode, $afterNode->nextSibling);
		}
		else
		{
			$parent->appendChild($newNode);
		}
	}
}

if (!function_exists('mf_1c_export_enrich_item_element'))
{
	function mf_1c_export_enrich_item_element(\DOMDocument $doc, \DOMElement $item): void
	{
		foreach ($item->childNodes as $child)
		{
			if ($child instanceof \DOMElement && ($child->localName === 'Ид' || $child->tagName === 'Ид'))
			{
				if (trim((string)$child->textContent) === 'ORDER_DELIVERY')
				{
					return;
				}
				break;
			}
		}

		$nameNode = null;
		foreach ($item->childNodes as $child)
		{
			if ($child instanceof \DOMElement && ($child->localName === 'Наименование' || $child->tagName === 'Наименование'))
			{
				$nameNode = $child;
				break;
			}
		}
		if (!$nameNode)
		{
			return;
		}

		$name = trim((string)$nameNode->textContent);
		if ($name === '' || stripos($name, 'доставка') !== false)
		{
			return;
		}

		$meta = mf_1c_export_extract_item_meta($item);
		if ($meta['article'] !== '')
		{
			mf_1c_export_ensure_child_after($doc, $item, 'Артикул', $meta['article'], $nameNode);
		}
		if ($meta['category'] !== '')
		{
			$after = $nameNode;
			foreach ($item->childNodes as $child)
			{
				if ($child instanceof \DOMElement && ($child->localName === 'Артикул' || $child->tagName === 'Артикул'))
				{
					$after = $child;
					break;
				}
			}
			mf_1c_export_ensure_child_after($doc, $item, 'Категория', $meta['category'], $after);
		}
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

		$previous = libxml_use_internal_errors(true);
		$dom = new \DOMDocument('1.0', 'UTF-8');
		$loaded = $dom->loadXML($contents, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if (!$loaded)
		{
			return $contents;
		}

		$itemNodes = $dom->getElementsByTagName('Товар');
		if ($itemNodes->length === 0)
		{
			return $contents;
		}

		for ($i = 0; $i < $itemNodes->length; $i++)
		{
			$item = $itemNodes->item($i);
			if ($item instanceof \DOMElement)
			{
				mf_1c_export_enrich_item_element($dom, $item);
			}
		}

		$xml = $dom->saveXML();
		if (!is_string($xml) || $xml === '')
		{
			return $contents;
		}

		return $xml;
	}
}
