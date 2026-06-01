<?php
/**
 * Удаляет из mf_brand_alias строки, где UF_ALIAS = UF_CANONICAL (или совпадают нормы),
 * если для того же канона уже есть другой активный алиас.
 *
 * Запуск:
 *   php /var/www/html/local/site/tools/mf_brand_alias_prune_identity.php
 *   php /var/www/html/local/site/tools/mf_brand_alias_prune_identity.php --dry-run
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli')
{
	fwrite(STDERR, "CLI only.\n");
	exit(1);
}

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 3);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_CRONTAB', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Application;

$dry = in_array('--dry-run', $argv ?? [], true);

$conn = Application::getConnection();
$h = $conn->getSqlHelper();

$exists = $conn->query("SHOW TABLES LIKE 'mf_brand_alias'")->fetch();
if (!$exists)
{
	fwrite(STDERR, "Таблица mf_brand_alias не найдена.\n");
	exit(1);
}

$sql = "
	SELECT a.ID, a.UF_CANONICAL, a.UF_ALIAS
	FROM mf_brand_alias a
	INNER JOIN mf_brand_alias b
		ON b.ID <> a.ID
		AND b.UF_CANONICAL_NORM = a.UF_CANONICAL_NORM
		AND b.UF_ALIAS_NORM <> a.UF_ALIAS_NORM
		AND (b.UF_ACTIVE = 1 OR b.UF_ACTIVE = '1')
	WHERE (a.UF_ACTIVE = 1 OR a.UF_ACTIVE = '1')
		AND (
			a.UF_ALIAS = a.UF_CANONICAL
			OR a.UF_ALIAS_NORM = a.UF_CANONICAL_NORM
		)
	ORDER BY a.ID
";

$rs = $conn->query($sql);
$ids = [];
$preview = [];
while ($row = $rs->fetch())
{
	$id = (int)($row['ID'] ?? 0);
	if ($id <= 0)
	{
		continue;
	}
	$ids[] = $id;
	if (count($preview) < 30)
	{
		$preview[] = sprintf(
			'#%d %s → %s',
			$id,
			(string)($row['UF_ALIAS'] ?? ''),
			(string)($row['UF_CANONICAL'] ?? '')
		);
	}
}

$total = count($ids);
echo 'Найдено избыточных identity-строк (есть другой алиас у того же канона): ' . $total . PHP_EOL;
if ($preview !== [])
{
	echo 'Примеры:' . PHP_EOL;
	foreach ($preview as $line)
	{
		echo '  ' . $line . PHP_EOL;
	}
	if ($total > count($preview))
	{
		echo '  … и ещё ' . ($total - count($preview)) . PHP_EOL;
	}
}

if ($total === 0)
{
	exit(0);
}

if ($dry)
{
	echo "Dry-run: удаление не выполнялось.\n";
	exit(0);
}

$deleted = 0;
$chunk = 500;
for ($i = 0; $i < $total; $i += $chunk)
{
	$part = array_slice($ids, $i, $chunk);
	$in = implode(',', array_map('intval', $part));
	$conn->queryExecute('DELETE FROM mf_brand_alias WHERE ID IN (' . $in . ')');
	$deleted += count($part);
}

if (function_exists('mf_brand_aliases_reset_cache'))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/mf_brand_dict.php';
	mf_brand_aliases_reset_cache();
}

echo 'Удалено: ' . $deleted . PHP_EOL;
