<?php
/**
 * Import product analog links from catalog_bitrix.csv-like export.
 *
 * Source format:
 * - Column "id" (CSV product external id) -> Bitrix element XML_ID
 * - Column "С этим товаром покупают" contains comma-separated list of other "id" values
 *
 * We treat "С этим товаром покупают" as "analogs".
 *
 * Run inside bitrix_php container:
 *   php /var/www/html/mf_import_analogs_catalog_bitrix.php --dry-run --file=/var/www/html/catalog_bitrix.csv
 *   php /var/www/html/mf_import_analogs_catalog_bitrix.php --apply   --file=/var/www/html/catalog_bitrix.csv --mode=merge
 *
 * Options:
 *   --file=/path/file.csv
 *   --iblock-id=4
 *   --encoding=utf-8|cp1251 (default: utf-8; auto-keeps valid utf-8)
 *   --delimiter=; (default ;)
 *   --apply / --dry-run
 *   --mode=merge|replace (default merge)
 *   --source=catalog_bitrix (default)
 *   --progress-every=5000
 *   --limit=0 (0 = no limit; useful for testing)
 */

$_SERVER['DOCUMENT_ROOT'] = __DIR__;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

Loader::includeModule('iblock');
Loader::includeModule('highloadblock');

$mfAnalogs = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_analogs.php';
if (is_file($mfAnalogs))
{
	require_once $mfAnalogs;
}
if (!function_exists('mf_analogs_ensure_hl'))
{
	fwrite(STDERR, "ERROR: mf_analogs.php not loaded\n");
	exit(2);
}

while (ob_get_level() > 0) { @ob_end_flush(); }
@ob_implicit_flush(true);

function arg(string $name): ?string
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
function flag(string $name): bool { return in_array($name, $_SERVER['argv'], true); }
function out(string $s): void { echo $s . PHP_EOL; if (function_exists('flush')) flush(); }

function mf_toUtf8($v, string $fromEncoding): string
{
	$s = (string)($v ?? '');
	if ($s === '') return '';
	if (function_exists('mb_check_encoding') && mb_check_encoding($s, 'UTF-8'))
	{
		return $s;
	}
	$from = strtoupper(trim($fromEncoding));
	if ($from === '' || $from === 'UTF8') $from = 'UTF-8';
	if (function_exists('iconv'))
	{
		$converted = @iconv($from, 'UTF-8//IGNORE', $s);
		if (is_string($converted) && $converted !== '')
		{
			return $converted;
		}
	}
	if (function_exists('mb_convert_encoding'))
	{
		$converted = mb_convert_encoding($s, 'UTF-8', $from);
		return is_string($converted) ? $converted : $s;
	}
	return $s;
}

function mf_parse_int_ids_csv(string $s): array
{
	$s = trim($s);
	if ($s === '') return [];
	$parts = preg_split('~\\s*,\\s*~', $s) ?: [];
	$out = [];
	foreach ($parts as $p)
	{
		$p = trim((string)$p);
		if ($p === '') continue;
		// keep only digits
		if (!preg_match('~^\\d+$~', $p)) continue;
		$out[(int)$p] = true;
	}
	return array_map('intval', array_keys($out));
}

function mf_norm_header(string $s): string
{
	$s = trim($s);
	// Strip UTF-8 BOM if present.
	$s = preg_replace('/^\xEF\xBB\xBF/', '', $s) ?? $s;
	$s = mb_strtolower($s);
	// Normalize whitespace
	$s = preg_replace('~\s+~u', ' ', $s) ?? $s;
	return trim($s);
}

function mf_find_product_id_by_xml_id(\Bitrix\Main\DB\Connection $conn, int $iblockId, string $xmlId): int
{
	static $cache = [];
	static $order = [];
	$xmlId = trim($xmlId);
	if ($xmlId === '') return 0;
	$key = $iblockId . ':' . $xmlId;
	if (isset($cache[$key])) return (int)$cache[$key];

	$h = $conn->getSqlHelper();
	$iblockId = (int)$iblockId;
	$xmlSql = "'" . $h->forSql($xmlId) . "'";
	$r = $conn->query("
		SELECT ID
		FROM b_iblock_element
		WHERE IBLOCK_ID = {$iblockId}
		  AND XML_ID = {$xmlSql}
		ORDER BY ID ASC
		LIMIT 1
	")->fetch();
	$id = (int)($r['ID'] ?? 0);

	$cache[$key] = $id;
	$order[] = $key;
	// Basic cap to avoid unbounded memory on huge files.
	if (count($order) > 200000)
	{
		$drop = array_shift($order);
		if ($drop !== null) unset($cache[$drop]);
	}
	return $id;
}

function mf_insert_links_bulk(\Bitrix\Main\DB\Connection $conn, string $table, array $pairs, string $source): int
{
	if (empty($pairs)) return 0;
	$h = $conn->getSqlHelper();
	$now = "'" . $h->forSql(date('Y-m-d H:i:s')) . "'";
	$src = "'" . $h->forSql($source) . "'";

	$vals = [];
	foreach ($pairs as [$p1, $p2])
	{
		$p1 = (int)$p1; $p2 = (int)$p2;
		if ($p1 <= 0 || $p2 <= 0 || $p1 === $p2) continue;
		$vals[] = '(' . $p1 . ',' . $p2 . ',500,' . $src . ',' . $now . ')';
	}
	if (empty($vals)) return 0;

	$sql = "INSERT IGNORE INTO `" . $table . "` (`UF_P1_ID`,`UF_P2_ID`,`UF_SORT`,`UF_SOURCE`,`UF_CREATED_AT`) VALUES " . implode(',', $vals);
	$conn->queryExecute($sql);
	return count($vals);
}

$file = (string)(arg('--file') ?? (__DIR__ . '/catalog_bitrix.csv'));
$iblockId = (int)(arg('--iblock-id') ?? 4);
$encoding = (string)(arg('--encoding') ?? 'utf-8');
$delimiter = (string)(arg('--delimiter') ?? ';');
$apply = flag('--apply');
$dry = flag('--dry-run') || !$apply;
$mode = strtolower(trim((string)(arg('--mode') ?? 'merge')));
$source = (string)(arg('--source') ?? 'catalog_bitrix');
$progressEvery = (int)(arg('--progress-every') ?? 5000);
$limit = (int)(arg('--limit') ?? 0);

if (!is_file($file))
{
	fwrite(STDERR, "ERROR: file not found: {$file}\n");
	exit(2);
}
if (!in_array($mode, ['merge', 'replace'], true))
{
	fwrite(STDERR, "ERROR: --mode must be merge|replace\n");
	exit(2);
}
if ($delimiter === '') $delimiter = ';';

out("FILE: {$file}");
out("IBLOCK_ID: {$iblockId}");
out("MODE: " . ($dry ? 'dry-run' : 'apply') . ", LINKS: {$mode}, SOURCE: {$source}");

$hl = mf_analogs_ensure_hl();
$table = (string)$hl['TABLE'];
$conn = Application::getConnection();

$t0 = microtime(true);
$fileSize = (int)@filesize($file);

$fh = fopen($file, 'r');
if (!$fh)
{
	fwrite(STDERR, "ERROR: cannot open file\n");
	exit(2);
}

$headersRaw = fgetcsv($fh, 0, $delimiter);
if (!$headersRaw)
{
	fwrite(STDERR, "ERROR: empty CSV\n");
	exit(2);
}

function mf_detect_header_indexes(array $headersRaw, array $encodingsToTry): array
{
	foreach ($encodingsToTry as $enc)
	{
		$headers = array_map(static fn($v) => mf_toUtf8($v, (string)$enc), $headersRaw);
		$idxId = null;
		$idxAlsoBuy = null;
		foreach ($headers as $i => $hdr)
		{
			$h = mf_norm_header((string)$hdr);
			if ($h === 'id' || $h === 'ид') $idxId = $i;
			if (str_contains($h, 'с этим товаром покупают')) $idxAlsoBuy = $i;
		}
		if ($idxId !== null && $idxAlsoBuy !== null)
		{
			return [
				'encoding' => (string)$enc,
				'headers' => $headers,
				'idxId' => $idxId,
				'idxAlsoBuy' => $idxAlsoBuy,
			];
		}
	}
	return [
		'encoding' => '',
		'headers' => [],
		'idxId' => null,
		'idxAlsoBuy' => null,
	];
}

// Try user encoding first, then common fallbacks.
$tryEnc = [];
$encUser = strtolower(trim($encoding));
if ($encUser !== '') $tryEnc[] = $encUser;
foreach (['utf-8', 'cp1251', 'windows-1251'] as $e)
{
	if (!in_array($e, $tryEnc, true)) $tryEnc[] = $e;
}

$det = mf_detect_header_indexes($headersRaw, $tryEnc);
$detEnc = (string)$det['encoding'];
$headers = (array)$det['headers'];
$idxId = $det['idxId'];
$idxAlsoBuy = $det['idxAlsoBuy'];

$encoding = $detEnc !== '' ? $detEnc : $encoding;

if ($idxId === null || $idxAlsoBuy === null)
{
	fwrite(STDERR, "ERROR: required columns not found. Need: id, \"С этим товаром покупают\"\n");
	$hdrDump = array_map(static fn($x) => mf_norm_header((string)mf_toUtf8($x, (string)$encoding)), $headersRaw);
	fwrite(STDERR, "HEADERS: " . implode(' | ', $hdrDump) . "\n");
	fwrite(STDERR, "TIP: try --encoding=cp1251\n");
	exit(2);
}

out("ENCODING_DETECTED: {$encoding}");

$total = 0;
$rowsWithAnalogs = 0;
$productsMissing = 0;
$analogIdsMissing = 0;
$linksAttempted = 0;
$linksInsertedAttempts = 0;
$pairsBuf = [];
$pairsBufMax = 800;

while (($row = fgetcsv($fh, 0, $delimiter)) !== false)
{
	$total++;
	if ($limit > 0 && $total > $limit) break;

	$row = array_map(static fn($v) => mf_toUtf8($v, $encoding), $row);
	$csvId = trim((string)($row[$idxId] ?? ''));
	if ($csvId === '') continue;

	$also = trim((string)($row[$idxAlsoBuy] ?? ''));
	if ($also === '') continue;
	$analogCsvIds = mf_parse_int_ids_csv($also);
	if (empty($analogCsvIds)) continue;

	$rowsWithAnalogs++;

	$productId = mf_find_product_id_by_xml_id($conn, $iblockId, $csvId);
	if ($productId <= 0)
	{
		$productsMissing++;
		continue;
	}

	if ($mode === 'replace' && !$dry)
	{
		$conn->queryExecute("DELETE FROM `" . $table . "` WHERE `UF_P1_ID`=" . (int)$productId . " OR `UF_P2_ID`=" . (int)$productId);
	}

	foreach ($analogCsvIds as $aCsvIdInt)
	{
		$aCsvId = (string)$aCsvIdInt;
		$aid = mf_find_product_id_by_xml_id($conn, $iblockId, $aCsvId);
		if ($aid <= 0)
		{
			$analogIdsMissing++;
			continue;
		}
		if ($aid === $productId) continue;

		$p1 = min($productId, $aid);
		$p2 = max($productId, $aid);
		$pairsBuf[] = [$p1, $p2];
		$linksAttempted++;

		if (count($pairsBuf) >= $pairsBufMax)
		{
			if (!$dry)
			{
				$linksInsertedAttempts += mf_insert_links_bulk($conn, $table, $pairsBuf, $source);
			}
			$pairsBuf = [];
		}
	}

	if ($progressEvery > 0 && ($total % $progressEvery) === 0)
	{
		$elapsed = max(0.0001, microtime(true) - $t0);
		$rps = $total / $elapsed;
		$lps = $linksAttempted / $elapsed;
		$pct = 0.0;
		$eta = '';
		if ($fileSize > 0)
		{
			$pos = (int)@ftell($fh);
			if ($pos > 0)
			{
				$pct = min(1.0, max(0.0, $pos / $fileSize));
				if ($pct > 0.0001 && $pct < 0.9999)
				{
					$etaSec = (int)round(($elapsed * (1.0 - $pct)) / $pct);
					$eta = ", eta={$etaSec}s";
				}
			}
		}
		$pctStr = $fileSize > 0 ? sprintf('%.1f%%', $pct * 100.0) : 'n/a';
		out(
			"PROGRESS: rows={$total}, withAnalogs={$rowsWithAnalogs}, links={$linksAttempted}, insertedAttempts=" . ($dry ? 0 : $linksInsertedAttempts)
			. ", missingProducts={$productsMissing}, missingAnalogs={$analogIdsMissing}"
			. ", speed=" . sprintf('%.1f', $rps) . " rows/s, " . sprintf('%.1f', $lps) . " links/s"
			. ", file={$pctStr}{$eta}"
		);
	}
}

if (!empty($pairsBuf) && !$dry)
{
	$linksInsertedAttempts += mf_insert_links_bulk($conn, $table, $pairsBuf, $source);
}

fclose($fh);

out("DONE");
out("ROWS_TOTAL: {$total}");
out("ROWS_WITH_ANALOGS: {$rowsWithAnalogs}");
out("MISSING_PRODUCTS_BY_XML_ID: {$productsMissing}");
out("MISSING_ANALOGS_BY_XML_ID: {$analogIdsMissing}");
out("LINKS_ATTEMPTED: {$linksAttempted}");
out("INSERT_BATCH_ATTEMPTS: " . ($dry ? 0 : $linksInsertedAttempts));
$elapsed = microtime(true) - $t0;
out("ELAPSED_SEC: " . sprintf('%.3f', $elapsed));
if ($elapsed > 0)
{
	out("AVG_SPEED: " . sprintf('%.1f', $total / $elapsed) . " rows/s, " . sprintf('%.1f', $linksAttempted / $elapsed) . " links/s");
}

