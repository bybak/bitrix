<?php
/**
 * Быстрая проверка результата импорта каталога (разделы/редиректы/артикулы).
 *
 * Запуск (внутри контейнера bitrix_php):
 *   php /var/www/html/mf_verify_catalog_import.php --iblock-id=4
 */

$_SERVER["DOCUMENT_ROOT"] = __DIR__;
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Iblock\ElementTable;

Loader::includeModule("iblock");

function arg(string $name): ?string
{
	foreach ($_SERVER['argv'] as $a)
	{
		if (strpos($a, $name.'=') === 0)
		{
			return substr($a, strlen($name) + 1);
		}
	}
	return null;
}
function out(string $s): void { echo $s . PHP_EOL; }

$iblockId = (int)(arg('--iblock-id') ?: 4);

$ib = IblockTable::getList([
	'filter' => ['=ID' => $iblockId],
	'select' => ['ID', 'NAME', 'CODE', 'IBLOCK_TYPE_ID'],
])->fetch();
if (!$ib)
{
	out("IBLOCK not found: $iblockId");
	exit(1);
}

out("=== MF VERIFY CATALOG IMPORT ===");
out("IBLOCK_ID={$iblockId} NAME='{$ib['NAME']}' CODE='{$ib['CODE']}' TYPE='{$ib['IBLOCK_TYPE_ID']}'");

// Properties
$needProps = ['CML2_ARTICLE','MF_ARTICLE_NORM','MF_IS_REDIRECT','MF_CANONICAL_CODE','MF_SOURCE_IDS'];
foreach ($needProps as $code)
{
	// В legacy API фильтр по '=CODE' не работает, нужен 'CODE'
	$p = CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code])->Fetch();
	out("PROP\t$code\t" . ($p ? "OK (ID={$p['ID']})" : "MISSING"));
}

// Sections (top-level)
$topSections = SectionTable::getList([
	'filter' => ['=IBLOCK_ID' => $iblockId, '=DEPTH_LEVEL' => 1],
	'select' => ['ID', 'NAME'],
	'order' => ['NAME' => 'ASC'],
])->fetchAll();
out("TOP_SECTIONS\t" . count($topSections));
foreach (array_slice($topSections, 0, 25) as $s)
{
	out("TOP_SECTION\t{$s['ID']}\t{$s['NAME']}");
}
if (count($topSections) > 25) out("TOP_SECTION\t... truncated ...");

// Elements counters
$totalEl = (int)ElementTable::getCount(['=IBLOCK_ID' => $iblockId]);
$redirectEl = (int)CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, 'PROPERTY_MF_IS_REDIRECT' => 'Y'], []);
$canonEl = (int)CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, '!PROPERTY_MF_IS_REDIRECT' => 'Y'], []);
$withNorm = (int)CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, '!PROPERTY_MF_ARTICLE_NORM' => false], []);
out("ELEMENTS_TOTAL\t$totalEl");
out("ELEMENTS_CANONICAL\t$canonEl");
out("ELEMENTS_REDIRECT\t$redirectEl");
out("ELEMENTS_WITH_NORM\t$withNorm");

// Sample redirects
$rs = CIBlockElement::GetList(
	['ID' => 'ASC'],
	['IBLOCK_ID' => $iblockId, 'PROPERTY_MF_IS_REDIRECT' => 'Y'],
	false,
	['nTopCount' => 10],
	['ID', 'CODE', 'PROPERTY_MF_CANONICAL_CODE']
);
$i = 0;
while ($r = $rs->Fetch())
{
	$i++;
	out("REDIRECT_SAMPLE\tID={$r['ID']}\tCODE={$r['CODE']}\tCANON={$r['PROPERTY_MF_CANONICAL_CODE_VALUE']}");
}
if ($i === 0) out("REDIRECT_SAMPLE\t(none)");

out("DONE");

