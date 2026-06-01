<?php
/**
 * HL-based brand dictionary (aliases -> canonical).
 *
 * HL table: mf_brand_alias
 * Entity:   MfBrandAlias
 *
 * Fields:
 * - UF_CANONICAL        (string)   Canonical brand name (e.g., "Yamaha")
 * - UF_CANONICAL_NORM   (string)   Normalized canonical (e.g., "YAMAHA")
 * - UF_ALIAS            (string)   Alias text (e.g., "Ямаха", "YMH", "BRP CanAm")
 * - UF_ALIAS_NORM       (string)   Normalized alias
 * - UF_ACTIVE           (boolean)  Active flag
 * - UF_SORT             (integer)  Sort/priority (higher first)
 * - UF_CREATED_AT       (datetime)
 * - UF_UPDATED_AT       (datetime)
 *
 * Требуется модуль Bitrix «highloadblock».
 */

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

if (class_exists(Loader::class))
{
	Loader::includeModule('highloadblock');
}

function mf_brand_norm(string $s): string
{
	$s = mb_strtoupper(trim($s));
	$s = str_replace('Ё', 'Е', $s);
	$s = preg_replace('~[^A-ZА-Я0-9]+~u', '', $s) ?? '';
	return $s;
}

function mf_brand_hl_ensure(bool $create): ?array
{
	$table = 'mf_brand_alias';
	$hl = HighloadBlockTable::getList([
		'filter' => ['=TABLE_NAME' => $table],
		'select' => ['ID', 'NAME', 'TABLE_NAME'],
		'limit' => 1,
	])->fetch();

	if (!$hl && !$create) return null;

	if (!$hl)
	{
		$res = HighloadBlockTable::add([
			'NAME' => 'MfBrandAlias',
			'TABLE_NAME' => $table,
		]);
		if (!$res->isSuccess())
		{
			throw new RuntimeException("Не удалось создать HL-блок справочника брендов: " . implode('; ', $res->getErrorMessages()));
		}
		$hl = ['ID' => (int)$res->getId(), 'NAME' => 'MfBrandAlias', 'TABLE_NAME' => $table];
	}

	$hlId = (int)$hl['ID'];
	$entityId = 'HLBLOCK_' . $hlId;
	$ut = new CUserTypeEntity();

	$ensureUf = static function (string $fieldName, string $userTypeId, array $labels, int $sort, array $settings = []) use ($ut, $entityId): void {
		$exists = CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId, 'FIELD_NAME' => $fieldName])->Fetch();
		if ($exists) return;
		$id = $ut->Add([
			'ENTITY_ID' => $entityId,
			'FIELD_NAME' => $fieldName,
			'USER_TYPE_ID' => $userTypeId,
			'XML_ID' => $fieldName,
			'SORT' => $sort,
			'MULTIPLE' => 'N',
			'MANDATORY' => 'N',
			'SHOW_FILTER' => 'I',
			'SHOW_IN_LIST' => 'Y',
			'EDIT_IN_LIST' => 'Y',
			'IS_SEARCHABLE' => 'Y',
			'SETTINGS' => $settings,
			'EDIT_FORM_LABEL' => $labels,
			'LIST_COLUMN_LABEL' => $labels,
			'LIST_FILTER_LABEL' => $labels,
		]);
		if (!$id)
		{
			throw new RuntimeException("Не удалось создать UF поле $fieldName для HL-блока справочника брендов: " . $ut->LAST_ERROR);
		}
	};

	$ensureUf('UF_CANONICAL', 'string', ['ru' => 'Канонический бренд', 'en' => 'Canonical brand'], 100);
	$ensureUf('UF_CANONICAL_NORM', 'string', ['ru' => 'Канонический (норм)', 'en' => 'Canonical (norm)'], 110);
	$ensureUf('UF_ALIAS', 'string', ['ru' => 'Алиас', 'en' => 'Alias'], 120);
	$ensureUf('UF_ALIAS_NORM', 'string', ['ru' => 'Алиас (норм)', 'en' => 'Alias (norm)'], 130);
	$ensureUf('UF_ACTIVE', 'boolean', ['ru' => 'Активно', 'en' => 'Active'], 140);
	$ensureUf('UF_SORT', 'integer', ['ru' => 'Сортировка', 'en' => 'Sort'], 150);
	$ensureUf('UF_CREATED_AT', 'datetime', ['ru' => 'Создано', 'en' => 'Created'], 160);
	$ensureUf('UF_UPDATED_AT', 'datetime', ['ru' => 'Обновлено', 'en' => 'Updated'], 170);

	$entity = HighloadBlockTable::compileEntity($hl);
	$dataClass = $entity->getDataClass();
	return ['HL' => $hl, 'DATA_CLASS' => $dataClass];
}

function mf_brand_seed_defaults(array $hl): void
{
	$dataClass = $hl['DATA_CLASS'];
	// Idempotent seeding: ensure core brands/aliases exist even if HL already has records.
	// Priority: specific sub-brands should outrank umbrella brands (e.g., Lynx over BRP).
	$defaults = [
		['Can-Am', ['Can-Am', 'Can Am', 'CanAm', 'Кан-Ам', 'Кан Ам', 'Канам'], 200],
		['Sea-Doo', ['Sea-Doo', 'Sea Doo', 'SeaDoo', 'Си-Ду', 'Си Ду', 'Сиду'], 200],
		['Ski-Doo', ['Ski-Doo', 'Ski Doo', 'SkiDoo', 'Ски-Ду', 'Ски Ду', 'Скиду'], 200],
		// BRP sub-brands (keep as distinct canonical brands)
		['brp_lynx', ['Lynx', 'BRP Lynx', 'BRP (Lynx)', 'BRP-Lynx', 'БРП Линкс', 'Линкс'], 240],
		['brp_rotax', ['Rotax', 'BRP Rotax', 'BRP (Rotax)', 'BRP-Rotax', 'БРП Ротакс', 'Ротакс'], 240],
		['BRP', ['BRP', 'БРП'], 80],
		['Yamaha', ['Yamaha', 'Ямаха', 'YMH'], 150],
	];

	foreach ($defaults as [$canonical, $aliases, $sort])
	{
		foreach ($aliases as $a)
		{
			mf_brand_register_alias($hl, (string)$canonical, (string)$a, true, (int)$sort);
		}
	}
}

/**
 * Одна запись в HL для канона, если других алиасов ещё нет (якорь для mf_brand_find по CANONICAL_NORM).
 * Не создаёт дубликат «канон = алиас», если алиасы уже есть.
 */
function mf_brand_ensure_canonical_anchor(array $hl, string $canonical, int $sort = 0): void
{
	$canonical = trim($canonical);
	if ($canonical === '')
	{
		return;
	}
	$canonNorm = mf_brand_norm($canonical);
	if ($canonNorm === '')
	{
		return;
	}
	$dataClass = $hl['DATA_CLASS'];
	$cnt = (int)$dataClass::getCount([
		'filter' => ['=UF_CANONICAL_NORM' => $canonNorm, '=UF_ACTIVE' => 1],
	]);
	if ($cnt > 0)
	{
		return;
	}
	mf_brand_register_alias($hl, $canonical, $canonical, true, $sort);
}

function mf_brand_aliases_load(bool $createIfMissing = false): array
{
	if (isset($GLOBALS['MF_BRAND_DICT_CACHE']) && is_array($GLOBALS['MF_BRAND_DICT_CACHE']))
	{
		return $GLOBALS['MF_BRAND_DICT_CACHE'];
	}

	$hl = mf_brand_hl_ensure($createIfMissing);
	if (!$hl)
	{
		$GLOBALS['MF_BRAND_DICT_CACHE'] = [];
		return $GLOBALS['MF_BRAND_DICT_CACHE'];
	}

	if ($createIfMissing)
	{
		mf_brand_seed_defaults($hl);
	}

	$dataClass = $hl['DATA_CLASS'];
	$rows = [];
	$rs = $dataClass::getList([
		'filter' => ['=UF_ACTIVE' => 1],
		'select' => ['UF_CANONICAL', 'UF_CANONICAL_NORM', 'UF_ALIAS', 'UF_ALIAS_NORM', 'UF_SORT'],
	]);
	while ($r = $rs->fetch())
	{
		$aliasNorm = trim((string)($r['UF_ALIAS_NORM'] ?? ''));
		$canon = trim((string)($r['UF_CANONICAL'] ?? ''));
		$canonNorm = trim((string)($r['UF_CANONICAL_NORM'] ?? ''));
		if ($aliasNorm === '' || $canon === '') continue;
		if ($canonNorm === '')
		{
			$canonNorm = mf_brand_norm($canon);
		}
		$rows[] = [
			'ALIAS_NORM' => $aliasNorm,
			'CANONICAL' => $canon,
			'CANONICAL_NORM' => $canonNorm,
			'SORT' => (int)($r['UF_SORT'] ?? 0),
		];
	}

	// Prefer higher sort, then longer alias (more specific).
	usort($rows, static function (array $a, array $b): int {
		$sa = (int)($a['SORT'] ?? 0);
		$sb = (int)($b['SORT'] ?? 0);
		if ($sa !== $sb) return $sb <=> $sa;
		return strlen((string)$b['ALIAS_NORM']) <=> strlen((string)$a['ALIAS_NORM']);
	});

	$GLOBALS['MF_BRAND_DICT_CACHE'] = ['HL' => $hl, 'ROWS' => $rows];
	return $GLOBALS['MF_BRAND_DICT_CACHE'];
}

function mf_brand_find(string $text, bool $createIfMissing = false): string
{
	$dict = mf_brand_aliases_load($createIfMissing);
	$normText = mf_brand_norm($text);
	if ($normText === '' || empty($dict['ROWS'])) return '';

	// Только полное совпадение нормализованной строки с алиасом или с каноном (без подстроки).
	foreach ($dict['ROWS'] as $r)
	{
		$an = (string)$r['ALIAS_NORM'];
		if ($an !== '' && $normText === $an)
		{
			return (string)$r['CANONICAL'];
		}
	}

	foreach ($dict['ROWS'] as $r)
	{
		$cn = (string)($r['CANONICAL_NORM'] ?? '');
		if ($cn !== '' && $normText === $cn)
		{
			return (string)$r['CANONICAL'];
		}
	}

	return '';
}

function mf_brand_aliases_reset_cache(): void
{
	unset($GLOBALS['MF_BRAND_DICT_CACHE']);
}

function mf_brand_register_alias(array $hl, string $canonical, string $alias, bool $active = true, int $sort = 0): void
{
	$alias = trim($alias);
	if ($alias !== '' && function_exists('mf_brand_import_skip_set'))
	{
		mf_brand_import_skip_set($alias, false);
	}

	$dataClass = $hl['DATA_CLASS'];
	$canonical = trim($canonical);
	if ($canonical === '' || $alias === '') return;

	$canonNorm = mf_brand_norm($canonical);
	$aliasNorm = mf_brand_norm($alias);
	if ($canonNorm === '' || $aliasNorm === '') return;

	$filter = ['=UF_CANONICAL_NORM' => $canonNorm, '=UF_ALIAS_NORM' => $aliasNorm];
	$existing = $dataClass::getList([
		'filter' => $filter,
		'select' => ['ID', 'UF_CANONICAL', 'UF_CANONICAL_NORM', 'UF_ALIAS', 'UF_ALIAS_NORM', 'UF_ACTIVE', 'UF_SORT'],
		'limit' => 1,
	])->fetch();

	$now = new DateTime();
	$activeInt = $active ? 1 : 0;
	$fields = [
		'UF_CANONICAL' => $canonical,
		'UF_CANONICAL_NORM' => $canonNorm,
		'UF_ALIAS' => $alias,
		'UF_ALIAS_NORM' => $aliasNorm,
		'UF_ACTIVE' => $activeInt,
		'UF_SORT' => $sort,
	];

	if ($existing)
	{
		if (
			trim((string)($existing['UF_CANONICAL'] ?? '')) === $canonical
			&& trim((string)($existing['UF_CANONICAL_NORM'] ?? '')) === $canonNorm
			&& trim((string)($existing['UF_ALIAS'] ?? '')) === $alias
			&& trim((string)($existing['UF_ALIAS_NORM'] ?? '')) === $aliasNorm
			&& (int)($existing['UF_ACTIVE'] ?? 0) === $activeInt
			&& (int)($existing['UF_SORT'] ?? 0) === $sort
		)
		{
			return;
		}
		$fields['UF_UPDATED_AT'] = $now;
		$dataClass::update((int)$existing['ID'], $fields);
		mf_brand_aliases_reset_cache();

		return;
	}

	$fields['UF_CREATED_AT'] = $now;
	$fields['UF_UPDATED_AT'] = $now;
	$dataClass::add($fields);
	mf_brand_aliases_reset_cache();
}

if (!function_exists('mf_brand_import_skip_ensure_table'))
{
	function mf_brand_import_skip_ensure_table(): bool
	{
		if (!class_exists(\Bitrix\Main\Application::class))
		{
			return false;
		}
		try
		{
			$conn = \Bitrix\Main\Application::getConnection();
		}
		catch (\Throwable $e)
		{
			return false;
		}
		$driver = method_exists($conn, 'getType') ? (string)$conn->getType() : '';
		if ($driver !== '' && stripos($driver, 'mysql') === false)
		{
			return false;
		}
		$sql = "CREATE TABLE IF NOT EXISTS mf_brand_import_skip (
			ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
			UF_ALIAS_NORM VARCHAR(128) NOT NULL,
			UF_ALIAS_RAW VARCHAR(512) NULL,
			UF_ACTIVE CHAR(1) NOT NULL DEFAULT 'Y',
			UF_UPDATED_AT DATETIME NULL,
			PRIMARY KEY (ID),
			UNIQUE KEY IX_MF_BIS_NORM (UF_ALIAS_NORM)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
		try
		{
			$conn->queryExecute($sql);
			return true;
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}
}

if (!function_exists('mf_brand_import_is_skipped'))
{
	function mf_brand_import_is_skipped(string $rawFromFile): bool
	{
		$rawFromFile = trim($rawFromFile);
		if ($rawFromFile === '')
		{
			return false;
		}
		if (!function_exists('mf_brand_norm'))
		{
			return false;
		}
		$n = mf_brand_norm($rawFromFile);
		if ($n === '' || !mf_brand_import_skip_ensure_table())
		{
			return false;
		}
		try
		{
			$conn = \Bitrix\Main\Application::getConnection();
			$h = $conn->getSqlHelper();
			$r = $conn->query(
				"SELECT ID FROM mf_brand_import_skip WHERE UF_ACTIVE='Y' AND UF_ALIAS_NORM='"
					. $h->forSql($n) . "' LIMIT 1"
			)->fetch();
			return (bool)$r;
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}
}

if (!function_exists('mf_brand_import_skip_set'))
{
	function mf_brand_import_skip_set(string $rawBrand, bool $skip): void
	{
		$rawBrand = trim($rawBrand);
		if ($rawBrand === '' || !function_exists('mf_brand_norm'))
		{
			return;
		}
		$n = mf_brand_norm($rawBrand);
		if ($n === '' || !mf_brand_import_skip_ensure_table())
		{
			return;
		}
		try
		{
			$conn = \Bitrix\Main\Application::getConnection();
			$h = $conn->getSqlHelper();
			$now = date('Y-m-d H:i:s');
			if (!$skip)
			{
				$conn->queryExecute("DELETE FROM mf_brand_import_skip WHERE UF_ALIAS_NORM='" . $h->forSql($n) . "'");
				return;
			}
			$r = $conn->query(
				"SELECT ID FROM mf_brand_import_skip WHERE UF_ALIAS_NORM='" . $h->forSql($n) . "' LIMIT 1"
			)->fetch();
			if ($r)
			{
				$conn->queryExecute(
					"UPDATE mf_brand_import_skip SET UF_ACTIVE='Y', UF_ALIAS_RAW='"
						. $h->forSql($rawBrand) . "', UF_UPDATED_AT='"
						. $h->forSql($now) . "' WHERE ID=" . (int)$r['ID']
				);
			}
			else
			{
				$conn->queryExecute(
					"INSERT INTO mf_brand_import_skip (UF_ALIAS_NORM, UF_ALIAS_RAW, UF_ACTIVE, UF_UPDATED_AT) VALUES ('"
						. $h->forSql($n) . "', '" . $h->forSql($rawBrand) . "', 'Y', '" . $h->forSql($now) . "')"
				);
			}
			$exists = $conn->query("SHOW TABLES LIKE 'mf_brand_alias'")->fetch();
			if ($exists)
			{
				$conn->queryExecute("DELETE FROM mf_brand_alias WHERE UF_ALIAS_NORM='" . $h->forSql($n) . "'");
			}
			mf_brand_aliases_reset_cache();
		}
		catch (\Throwable $e)
		{
			// ignore
		}
	}
}

