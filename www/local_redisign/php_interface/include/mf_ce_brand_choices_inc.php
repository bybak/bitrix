<?php

declare(strict_types=1);

/**
 * Общая логика списка брендов для фильтра выгрузки каталога и др. админок.
 * Не привязана к конкретной странице (без ADMIN_SECTION).
 *
 * Кэш Redis (опционально): ключи с префиксом mf:ce:brand_choices:v1:
 * — чтение в mf_ce_load_brand_choices(), запись кроном / CLI mf_refresh_ce_brand_choices_cache.php.
 * Отключить чтение из Redis: MF_CE_BRAND_CHOICES_REDIS=N.
 * Хост/порт как у .settings.php: BITRIX_REDIS_HOST (по умолчанию redis), BITRIX_REDIS_PORT (6379).
 */

if (!function_exists('mf_ce_brand_choices_redis_read_enabled'))
{
	function mf_ce_brand_choices_redis_read_enabled(): bool
	{
		$v = getenv('MF_CE_BRAND_CHOICES_REDIS');

		return ($v === false || $v === '')
			? true
			: !in_array(strtoupper(trim((string)$v)), ['N', '0', 'NO', 'FALSE'], true);
	}
}

if (!function_exists('mf_ce_brand_choices_cache_ttl_seconds'))
{
	function mf_ce_brand_choices_cache_ttl_seconds(): int
	{
		$v = getenv('MF_CE_BRAND_CHOICES_CACHE_TTL');
		if ($v !== false && $v !== '' && ctype_digit((string)$v))
		{
			return max(60, (int)$v);
		}

		return 604800;
	}
}

if (!function_exists('mf_ce_brand_choices_cache_key_suffix'))
{
	/**
	 * @param list<string>|null $propertyCodes
	 */
	function mf_ce_brand_choices_cache_key_suffix(int $iblockId, bool $onlyActiveBrands, ?array $propertyCodes): string
	{
		if ($propertyCodes === null)
		{
			$tag = 'props_default';
		}
		else
		{
			$codes = array_values(array_filter(array_map(static fn($c) => trim((string)$c), $propertyCodes), static fn($c) => $c !== ''));
			sort($codes, SORT_STRING);
			$tag = 'props_' . md5(implode('|', $codes));
		}

		return 'iblock:' . (int)$iblockId . ':act:' . ($onlyActiveBrands ? '1' : '0') . ':' . $tag;
	}
}

if (!function_exists('mf_ce_brand_choices_cache_redis_key'))
{
	/**
	 * @param list<string>|null $propertyCodes
	 */
	function mf_ce_brand_choices_cache_redis_key(int $iblockId, bool $onlyActiveBrands, ?array $propertyCodes): string
	{
		return 'mf:ce:brand_choices:v1:' . mf_ce_brand_choices_cache_key_suffix($iblockId, $onlyActiveBrands, $propertyCodes);
	}
}

if (!function_exists('mf_ce_brand_choices_redis_connect'))
{
	function mf_ce_brand_choices_redis_connect(): ?\Redis
	{
		if (!extension_loaded('redis') || !class_exists(\Redis::class))
		{
			return null;
		}
		$h = getenv('BITRIX_REDIS_HOST');
		$h = ($h !== false && $h !== '') ? (string)$h : 'redis';
		$p = getenv('BITRIX_REDIS_PORT');
		$port = ($p !== false && $p !== '' && ctype_digit((string)$p)) ? (int)$p : 6379;

		try
		{
			$r = new \Redis();
			if (!$r->connect($h, $port, 1.5))
			{
				return null;
			}

			return $r;
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}
}

if (!function_exists('mf_ce_brand_choices_cache_get'))
{
	/**
	 * @param list<string>|null $propertyCodes
	 * @return list<string>|null
	 */
	function mf_ce_brand_choices_cache_get(int $iblockId, bool $onlyActiveBrands, ?array $propertyCodes): ?array
	{
		if (!mf_ce_brand_choices_redis_read_enabled())
		{
			return null;
		}
		$r = mf_ce_brand_choices_redis_connect();
		if (!$r)
		{
			return null;
		}
		$key = mf_ce_brand_choices_cache_redis_key($iblockId, $onlyActiveBrands, $propertyCodes);
		$raw = $r->get($key);
		if ($raw === false || $raw === '')
		{
			return null;
		}
		$j = json_decode((string)$raw, true);
		if (!is_array($j))
		{
			return null;
		}
		$out = [];
		foreach ($j as $item)
		{
			$s = trim((string)$item);
			if ($s !== '')
			{
				$out[] = $s;
			}
		}

		return $out;
	}
}

if (!function_exists('mf_ce_brand_choices_cache_set'))
{
	/**
	 * @param list<string> $brands
	 * @param list<string>|null $propertyCodes
	 */
	function mf_ce_brand_choices_cache_set(int $iblockId, bool $onlyActiveBrands, ?array $propertyCodes, array $brands): bool
	{
		$r = mf_ce_brand_choices_redis_connect();
		if (!$r)
		{
			return false;
		}
		$key = mf_ce_brand_choices_cache_redis_key($iblockId, $onlyActiveBrands, $propertyCodes);
		$payload = json_encode(array_values($brands), JSON_UNESCAPED_UNICODE);
		if ($payload === false)
		{
			return false;
		}
		$ttl = mf_ce_brand_choices_cache_ttl_seconds();

		return $r->setex($key, $ttl, $payload);
	}
}

if (!function_exists('mf_ce_brand_property_ids'))
{
	/**
	 * ID свойств MF_BRAND / MF_BRAND_NORM в инфоблоке.
	 *
	 * @return list<int>
	 */
	function mf_ce_brand_property_ids(int $iblockId): array
	{
		$iblockId = (int)$iblockId;
		if ($iblockId <= 0)
		{
			return [];
		}

		$propIds = [];
		foreach (['MF_BRAND', 'MF_BRAND_NORM'] as $propCode)
		{
			$rs = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $propCode]);
			if ($p = $rs->Fetch())
			{
				$id = (int)($p['ID'] ?? 0);
				if ($id > 0)
				{
					$propIds[$id] = true;
				}
			}
		}

		return array_keys($propIds);
	}
}

if (!function_exists('mf_ce_sql_bool_exportable_element'))
{
	/**
	 * Условие «элемент выгружаемый» (как в выгрузке): workflow + не редирект.
	 * Возвращает выражение для SQL (без ведущего AND), в скобках.
	 */
	function mf_ce_sql_bool_exportable_element(string $eAlias, int $iblockId): string
	{
		$iblockId = (int)$iblockId;
		$eAlias = preg_replace('~[^A-Za-z0-9_]+~', '', $eAlias) ?: 'e';
		$parts = [
			"({$eAlias}.WF_STATUS_ID = 1)",
			"({$eAlias}.WF_PARENT_ELEMENT_ID IS NULL)",
		];

		$rs = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => 'MF_IS_REDIRECT']);
		if ($p = $rs->Fetch())
		{
			$redirectPid = (int)($p['ID'] ?? 0);
			if ($redirectPid > 0)
			{
				$parts[] = "NOT EXISTS (
				SELECT 1 FROM b_iblock_element_property prd
				WHERE prd.IBLOCK_ELEMENT_ID = {$eAlias}.ID
				AND prd.IBLOCK_PROPERTY_ID = {$redirectPid}
				AND prd.VALUE = 'Y'
			)";
			}
		}

		return '(' . implode(' AND ', $parts) . ')';
	}
}

if (!function_exists('mf_ce_sql_and_exportable_element'))
{
	/**
	 * Фрагмент AND … для JOIN к `b_iblock_element` с алиасом $eAlias.
	 */
	function mf_ce_sql_and_exportable_element(string $eAlias, int $iblockId): string
	{
		return ' AND ' . mf_ce_sql_bool_exportable_element($eAlias, $iblockId);
	}
}

if (!function_exists('mf_ce_sql_and_active_if'))
{
	/**
	 * Учёт галочки «только активные»: должно совпадать с фильтром GetList при выгрузке.
	 */
	function mf_ce_sql_and_active_if(bool $onlyActive, string $eAlias = 'e'): string
	{
		if (!$onlyActive)
		{
			return '';
		}
		$eAlias = preg_replace('~[^A-Za-z0-9_]+~', '', $eAlias) ?: 'e';

		return " AND ({$eAlias}.ACTIVE = 'Y')";
	}
}

if (!function_exists('mf_ce_brands_only_on_non_exportable_elements'))
{
	/**
	 * Значения MF_BRAND/MF_BRAND_NORM, у которых ни у одного выгружаемого элемента нет строки с этим значением,
	 * но у других (редирект, копии workflow и т.д.) — есть. Для поиска «мусорных» хвостов в свойствах.
	 *
	 * @return list<array{brand: string, cnt_any: int}>
	 */
	function mf_ce_brands_only_on_non_exportable_elements(int $iblockId, int $limit = 500): array
	{
		global $DB;

		$iblockId = (int)$iblockId;
		$limit = max(1, min(5000, $limit));
		if ($iblockId <= 0)
		{
			return [];
		}

		$propIds = mf_ce_brand_property_ids($iblockId);
		if ($propIds === [])
		{
			return [];
		}

		$in = implode(',', $propIds);
		$expBool = mf_ce_sql_bool_exportable_element('e', $iblockId);
		$sql = "
		SELECT TRIM(p.VALUE) AS V,
			COUNT(DISTINCT p.IBLOCK_ELEMENT_ID) AS CNT_ANY,
			COUNT(DISTINCT CASE WHEN {$expBool} THEN p.IBLOCK_ELEMENT_ID END) AS CNT_EXPORTABLE
		FROM b_iblock_element_property p
		INNER JOIN b_iblock_element e ON e.ID = p.IBLOCK_ELEMENT_ID AND e.IBLOCK_ID = {$iblockId}
		WHERE p.IBLOCK_PROPERTY_ID IN ({$in})
			AND p.VALUE IS NOT NULL
			AND TRIM(p.VALUE) <> ''
		GROUP BY TRIM(p.VALUE)
		HAVING CNT_EXPORTABLE = 0
		ORDER BY CNT_ANY DESC, V
		LIMIT {$limit}
	";

		$q = $DB->Query($sql);
		if (!$q)
		{
			return [];
		}

		$out = [];
		while ($r = $q->Fetch())
		{
			$v = trim((string)($r['V'] ?? ''));
			if ($v === '')
			{
				continue;
			}
			$out[] = [
				'brand' => $v,
				'cnt_any' => (int)($r['CNT_ANY'] ?? 0),
			];
		}

		return $out;
	}
}

if (!function_exists('mf_ce_load_brand_choices_from_db'))
{
	/**
	 * Список брендов напрямую из MySQL (тяжёлый запрос — только крон или fallback при пустом Redis).
	 *
	 * @param list<string>|null $propertyCodes
	 * @return list<string>
	 */
	function mf_ce_load_brand_choices_from_db(int $iblockId, bool $onlyActiveBrands = true, ?array $propertyCodes = null): array
	{
		global $DB;

		$iblockId = (int)$iblockId;
		if ($iblockId <= 0)
		{
			return [];
		}

		if ($propertyCodes === null)
		{
			$propIds = mf_ce_brand_property_ids($iblockId);
		}
		else
		{
			$propIds = [];
			foreach ($propertyCodes as $code)
			{
				$code = trim((string)$code);
				if ($code === '')
				{
					continue;
				}
				$rs = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code]);
				if ($p = $rs->Fetch())
				{
					$id = (int)($p['ID'] ?? 0);
					if ($id > 0)
					{
						$propIds[$id] = true;
					}
				}
			}
			$propIds = array_keys($propIds);
		}

		if ($propIds === [])
		{
			return [];
		}

		$in = implode(',', $propIds);
		$exEl = mf_ce_sql_and_exportable_element('e', $iblockId);
		$act = mf_ce_sql_and_active_if($onlyActiveBrands, 'e');
		// STRAIGHT_JOIN: иначе оптимизатор часто начинает с ~1M строк b_iblock_element (см. EXPLAIN ANALYZE на проде).
		$sql = "
		SELECT DISTINCT TRIM(p.VALUE) AS V
		FROM b_iblock_element_property p
		STRAIGHT_JOIN b_iblock_element e ON e.ID = p.IBLOCK_ELEMENT_ID AND e.IBLOCK_ID = {$iblockId}
		WHERE p.IBLOCK_PROPERTY_ID IN ({$in})
			AND p.VALUE IS NOT NULL
			AND TRIM(p.VALUE) <> ''
			{$exEl}{$act}
	";

		$q = $DB->Query($sql);
		if (!$q)
		{
			return [];
		}

		$seen = [];
		while ($r = $q->Fetch())
		{
			$v = trim((string)($r['V'] ?? ''));
			if ($v === '')
			{
				continue;
			}
			$seen[$v] = true;
		}

		$out = array_keys($seen);
		natcasesort($out);

		return array_values($out);
	}
}

if (!function_exists('mf_ce_refresh_brand_choices_cache'))
{
	/**
	 * Пересчитать список из БД и записать в Redis (для крона).
	 *
	 * @param list<string>|null $propertyCodes
	 */
	function mf_ce_refresh_brand_choices_cache(int $iblockId, bool $onlyActiveBrands = true, ?array $propertyCodes = null): bool
	{
		$list = mf_ce_load_brand_choices_from_db($iblockId, $onlyActiveBrands, $propertyCodes);

		return mf_ce_brand_choices_cache_set($iblockId, $onlyActiveBrands, $propertyCodes, $list);
	}
}

if (!function_exists('mf_ce_load_brand_choices'))
{
	/**
	 * Список непустых значений свойств бренда для выпадающего списка.
	 * По умолчанию MF_BRAND + MF_BRAND_NORM (как фильтр выгрузки).
	 * Если передан $propertyCodes (например ['MF_BRAND']) — меньше строк в b_iblock_element_property, быстрее на больших каталогах.
	 *
	 * При включённом Redis (не MF_CE_BRAND_CHOICES_REDIS=N) сначала читается кэш, обновляемый кроном.
	 * Если ключа нет — запрос к БД (долго на больших каталогах).
	 *
	 * @param list<string>|null $propertyCodes коды свойств IBLOCK, null = MF_BRAND и MF_BRAND_NORM
	 * @return list<string>
	 */
	function mf_ce_load_brand_choices(int $iblockId, bool $onlyActiveBrands = true, ?array $propertyCodes = null): array
	{
		$cached = mf_ce_brand_choices_cache_get($iblockId, $onlyActiveBrands, $propertyCodes);
		if ($cached !== null)
		{
			return $cached;
		}

		return mf_ce_load_brand_choices_from_db($iblockId, $onlyActiveBrands, $propertyCodes);
	}
}

if (!function_exists('mf_ce_element_ids_for_brand_value'))
{
	/**
	 * Элементы, у которых в MF_BRAND или MF_BRAND_NORM после TRIM совпадает значение с выбранным в списке.
	 * Так же, как DISTINCT в mf_ce_load_brand_choices — без расхождений из-за пробелов в БД и без OR-фильтра GetList.
	 *
	 * @return list<int>
	 */
	function mf_ce_element_ids_for_brand_value(int $iblockId, string $brand, bool $onlyActiveBrands = true): array
	{
		global $DB;

		$iblockId = (int)$iblockId;
		$brand = trim($brand);
		if ($iblockId <= 0 || $brand === '')
		{
			return [];
		}

		$propIds = mf_ce_brand_property_ids($iblockId);
		if ($propIds === [])
		{
			return [];
		}

		$in = implode(',', $propIds);
		$b = $DB->ForSql($brand);
		$exEl = mf_ce_sql_and_exportable_element('e', $iblockId);
		$act = mf_ce_sql_and_active_if($onlyActiveBrands, 'e');
		$sql = "
		SELECT DISTINCT p.IBLOCK_ELEMENT_ID AS ID
		FROM b_iblock_element_property p
		INNER JOIN b_iblock_element e ON e.ID = p.IBLOCK_ELEMENT_ID AND e.IBLOCK_ID = {$iblockId}
		WHERE p.IBLOCK_PROPERTY_ID IN ({$in})
			AND TRIM(p.VALUE) = TRIM('{$b}')
			{$exEl}{$act}
	";

		$q = $DB->Query($sql);
		if (!$q)
		{
			return [];
		}

		$out = [];
		while ($r = $q->Fetch())
		{
			$id = (int)($r['ID'] ?? 0);
			if ($id > 0)
			{
				$out[$id] = true;
			}
		}

		return array_keys($out);
	}
}
