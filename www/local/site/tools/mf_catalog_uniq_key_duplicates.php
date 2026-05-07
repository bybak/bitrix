<?php
/**
 * Быстрый поиск товаров с одинаковым MF_UNIQ_KEY — один SQL с GROUP BY (секунды, не часы).
 * Учитывается VERSION инфоблока Bitrix (1 → b_iblock_element_property, 2 → b_iblock_element_prop_s{N}).
 *
 * Требуется MySQL/MariaDB (GROUP_CONCAT).
 *
 * CLI:
 *   php /var/www/html/tools/mf_catalog_uniq_key_duplicates.php --iblock-id=4
 *   php /var/www/html/tools/mf_catalog_uniq_key_duplicates.php --export=/tmp/uniq_dup.csv
 *
 * Опции:
 *   --iblock-id=N (по умолчанию 4)
 *   --active-only=Y|N (по умолчанию Y)
 *   --include-redirect=Y|N (по умолчанию N — исключить PROPERTY_MF_IS_REDIRECT=Y, как в mf_catalog_fix_uniq_key)
 *   --include-empty-keys=Y|N (по умолчанию N — не считать пустой ключ дублем)
 *   --min-count=N (по умолчанию 2 — в группе не меньше N элементов)
 *   --export=/path.csv (разделитель «;», UTF-8 BOM)
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

Loader::includeModule('iblock');

function mfcud_arg(string $name): ?string
{
	foreach ($_SERVER['argv'] as $a)
	{
		if (strpos($a, $name . '=') === 0)
		{
			return substr($a, strlen($name) + 1);
		}
	}

	return null;
}

function mfcud_bool(string $raw, bool $default): bool
{
	$raw = strtoupper(trim($raw));
	if ($raw === '' || $raw === '*')
	{
		return $default;
	}

	return in_array($raw, ['Y', '1', 'YES', 'TRUE', 'ON'], true);
}

$iblockId = (int)(mfcud_arg('--iblock-id') ?: 4);
$activeOnly = mfcud_bool((string)(mfcud_arg('--active-only') ?: ''), true);
$includeRedirect = mfcud_bool((string)(mfcud_arg('--include-redirect') ?: ''), false);
$includeEmpty = mfcud_bool((string)(mfcud_arg('--include-empty-keys') ?: ''), false);
$exportPath = mfcud_arg('--export');
$exportPath = $exportPath !== null ? trim((string)$exportPath) : '';
$minCount = (int)(mfcud_arg('--min-count') ?: 2);
if ($minCount < 2)
{
	$minCount = 2;
}

if ($iblockId <= 0)
{
	fwrite(STDERR, "Нужен корректный --iblock-id\n");
	exit(1);
}

$ibRow = CIBlock::GetByID($iblockId)->Fetch();
if (!$ibRow)
{
	fwrite(STDERR, "Инфоблок ID={$iblockId} не найден.\n");
	exit(1);
}
$version = (int)($ibRow['VERSION'] ?? 1);

$uniqProp = CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => 'MF_UNIQ_KEY'])->Fetch();
if (!$uniqProp)
{
	fwrite(STDERR, "Свойство MF_UNIQ_KEY в IBLOCK_ID={$iblockId} не найдено.\n");
	exit(1);
}
$uniqPropId = (int)$uniqProp['ID'];

$eConds = ['e.IBLOCK_ID = ' . (int)$iblockId];
if ($activeOnly)
{
	$eConds[] = "e.ACTIVE = 'Y'";
}
$eWhere = implode(' AND ', $eConds);

$redirJoin = '';
$redirSql = '';
if (!$includeRedirect)
{
	$redir = CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => 'MF_IS_REDIRECT'])->Fetch();
	if ($redir)
	{
		$rid = (int)$redir['ID'];
		$redirJoin = "
LEFT JOIN b_iblock_element_property redir
  ON redir.IBLOCK_ELEMENT_ID = e.ID AND redir.IBLOCK_PROPERTY_ID = {$rid}";
		$redirSql = 'AND (redir.VALUE IS NULL OR redir.VALUE <> \'Y\')';
	}
}

$emptySqlV1 = '';
$emptySqlV2 = '';
if (!$includeEmpty)
{
	$emptySqlV1 = 'AND (p.VALUE IS NOT NULL AND p.VALUE <> \'\')';
	$emptySqlV2 = 'AND (s.PROPERTY_' . $uniqPropId . ' IS NOT NULL AND s.PROPERTY_' . $uniqPropId . ' <> \'\')';
}

if ($version === 2)
{
	$col = 's.PROPERTY_' . $uniqPropId;
	$sql = "
SELECT {$col} AS uniq_key,
       COUNT(*) AS cnt_elements,
 GROUP_CONCAT(s.IBLOCK_ELEMENT_ID ORDER BY s.IBLOCK_ELEMENT_ID SEPARATOR ',') AS element_ids
FROM b_iblock_element_prop_s{$iblockId} s
INNER JOIN b_iblock_element e ON e.ID = s.IBLOCK_ELEMENT_ID
{$redirJoin}
WHERE {$eWhere}
{$redirSql}
{$emptySqlV2}
GROUP BY {$col}
HAVING COUNT(*) >= {$minCount}
ORDER BY cnt_elements DESC, uniq_key
";
}
else
{
	$sql = "
SELECT p.VALUE AS uniq_key,
       COUNT(DISTINCT p.IBLOCK_ELEMENT_ID) AS cnt_elements,
 GROUP_CONCAT(DISTINCT p.IBLOCK_ELEMENT_ID ORDER BY p.IBLOCK_ELEMENT_ID SEPARATOR ',') AS element_ids
FROM b_iblock_element_property p
INNER JOIN b_iblock_element e ON e.ID = p.IBLOCK_ELEMENT_ID
{$redirJoin}
WHERE p.IBLOCK_PROPERTY_ID = {$uniqPropId}
  AND {$eWhere}
{$redirSql}
{$emptySqlV1}
GROUP BY p.VALUE
HAVING COUNT(DISTINCT p.IBLOCK_ELEMENT_ID) >= {$minCount}
ORDER BY cnt_elements DESC, uniq_key
";
}

$conn = \Bitrix\Main\Application::getConnection();
try
{
	$conn->queryExecute('SET SESSION group_concat_max_len = 8388608');
}
catch (\Throwable $e)
{
	// нет прав на SESSION — возможна усечённая строка element_ids у очень больших групп
}

$t0 = microtime(true);

echo '=== Дубли MF_UNIQ_KEY (SQL) ===' . PHP_EOL;
echo 'IBLOCK_ID: ' . $iblockId . ' | VERSION: ' . $version . ' | MF_UNIQ_KEY property ID: ' . $uniqPropId . PHP_EOL;
echo 'ACTIVE_ONLY: ' . ($activeOnly ? 'Y' : 'N') . ' | INCLUDE_REDIRECT: ' . ($includeRedirect ? 'Y' : 'N') . PHP_EOL;
echo PHP_EOL;

$result = $conn->query($sql);

$groups = 0;
$rowsOut = 0;
$fp = null;
if ($exportPath !== '')
{
	$dir = dirname($exportPath);
	if ($dir !== '' && $dir !== '.' && !is_dir($dir))
	{
		fwrite(STDERR, "Каталог для export не существует: {$dir}\n");
		exit(1);
	}
	if ($dir !== '' && $dir !== '.' && !is_writable($dir))
	{
		fwrite(STDERR, "Нет прав на запись в каталог: {$dir}\n");
		exit(1);
	}
	$fp = @fopen($exportPath, 'wb');
	if ($fp === false)
	{
		$last = error_get_last();
		$msg = is_array($last) ? ($last['message'] ?? '') : '';
		fwrite(STDERR, "Не удалось создать {$exportPath}" . ($msg !== '' ? ' — ' . $msg : '') . "\n");
		exit(1);
	}
	fwrite($fp, "\xEF\xBB\xBF");
	fputcsv($fp, ['MF_UNIQ_KEY', 'COUNT_ELEMENTS', 'ELEMENT_IDS'], ';');
}

while ($row = $result->fetch())
{
	$groups++;
	$key = (string)($row['uniq_key'] ?? $row['UNIQ_KEY'] ?? '');
	$cnt = (int)($row['cnt_elements'] ?? $row['CNT_ELEMENTS'] ?? 0);
	$ids = (string)($row['element_ids'] ?? $row['ELEMENT_IDS'] ?? '');
	echo sprintf("%s\t%d\t%s\n", $key, $cnt, $ids);
	if ($fp)
	{
		fputcsv($fp, [$key, (string)$cnt, $ids], ';');
		$rowsOut++;
	}
}

if ($fp)
{
	fclose($fp);
	echo PHP_EOL . 'CSV: ' . $exportPath . ' (строк данных: ' . $rowsOut . ')' . PHP_EOL;
}

echo PHP_EOL . 'Уникальных значений ключа с дублями (групп): ' . $groups . PHP_EOL;
echo 'Запрос занял: ' . sprintf('%.2f с', microtime(true) - $t0) . PHP_EOL;
