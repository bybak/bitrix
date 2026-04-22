<?php
/**
 * Обновление в БД бренда товара (свойства MF_BRAND, MF_BRAND_NORM) по выгрузке CSV.
 * Колонка «Бренд поменять новые» → в каталог, привязка по «ID элемента».
 *
 * Запуск (в контейнере bitrix_php, из корня сайта):
 *   php /var/www/html/tools/mf_apply_brand_to_db.php --csv=/var/www/html/upload/catalog.csv --dry-run
 *   php /var/www/html/tools/mf_apply_brand_to_db.php --csv=/var/www/html/upload/catalog.csv --apply
 *
 * С хоста (если лежит рядом с www):
 *   php tools/mf_apply_brand_to_db.php  # подключает www/tools/mf_apply_brand_to_db.php
 *
 * Опции:
 *   --csv=путь   (обязательно) UTF-8 с ; разделителем, как в выгрузке
 *   --iblock-id=4
 *   --apply      писать в БД (без этого — --dry-run)
 *   --verify-old=Y|N  по умолчанию N; если Y — обновлять только если MF_BRAND совпадает с «Бренд сайта старые» в CSV
 *   --skip-unchanged=Y|N  по умолчанию Y — не писать, если бренд уже равен новому
 *   --progress-every=2000  сколько строк CSV между сообщениями прогресса; 0 = только по времени (раз в 2 с)
 */
declare(strict_types=1);

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

Loader::includeModule('iblock');

require_once $_SERVER['DOCUMENT_ROOT'] . '/mf_brand_dict.php';

if (!function_exists('mf_brand_norm'))
{
	fwrite(STDERR, "mf_brand_dict.php: нет mf_brand_norm\n");
	exit(1);
}

while (ob_get_level() > 0) { @ob_end_flush(); }
@ob_implicit_flush(true);

const COL_ID = 'ID элемента';
const COL_NEW = 'Бренд поменять новые';
const COL_OLD = 'Бренд сайта старые';
const DEFAULT_IBLOCK = 4;

function arg(string $name): ?string
{
	$dd = '--' . $name . '=';
	$l = strlen($dd);
	foreach ($_SERVER['argv'] as $a) {
		if (strncmp($a, $dd, $l) === 0) {
			return substr($a, $l);
		}
	}
	// без «--» (как --csv, совместимость)
	$p = $name . '=';
	$l2 = strlen($p);
	foreach ($_SERVER['argv'] as $a) {
		if (strncmp($a, $p, $l2) === 0) {
			return substr($a, $l2);
		}
	}
	return null;
}
function hasFlag(string $name): bool
{
	return in_array($name, $_SERVER['argv'], true);
}
function yorn(?string $v, bool $def): bool
{
	if ($v === null || $v === '') {
		return $def;
	}
	$v = strtoupper(trim($v));
	if (in_array($v, ['Y', '1', 'YES', 'TRUE'], true)) {
		return true;
	}
	if (in_array($v, ['N', '0', 'NO', 'FALSE'], true)) {
		return false;
	}
	return $def;
}
function out(string $s): void
{
	echo $s . PHP_EOL;
	if (function_exists('flush')) {
		flush();
	}
}

$csv = trim((string)arg('csv'));
$iblockId = (int)(arg('iblock-id') ?: DEFAULT_IBLOCK);
$apply = hasFlag('apply');
$dry = !$apply;
$verifyOld = yorn(arg('verify-old'), false);
$skipUnchanged = yorn(arg('skip-unchanged'), true);
$progressEvery = (int)(arg('progress-every') ?? 2000);
if ($progressEvery < 0) {
	$progressEvery = 2000;
}

if ($csv === '' || !is_file($csv)) {
	fwrite(STDERR, "Укажите существующий файл: --csv=/полный/путь.csv\n");
	exit(1);
}
if ($iblockId <= 0) {
	fwrite(STDERR, "iblock-id должен быть > 0\n");
	exit(1);
}

$el = new CIBlockElement();
$readCount = 0;
$updateCount = 0;
$skipEmpty = 0;
$skipVerify = 0;
$skipSame = 0;
$skipBadId = 0;
$errors = 0;
$lineNo = 0;

$fp = fopen($csv, 'rb');
if ($fp === false) {
	fwrite(STDERR, "Не удалось открыть: $csv\n");
	exit(1);
}
$header = fgetcsv($fp, 0, ';', '"');
$lineNo++;
if (!is_array($header) || $header === []) {
	fclose($fp);
	fwrite(STDERR, "Пустой CSV\n");
	exit(1);
}
$idx = [];
foreach ($header as $i => $name) {
	$idx[trim((string)$name)] = (int)$i;
}
if (!isset($idx[COL_ID], $idx[COL_NEW])) {
	fclose($fp);
	fwrite(
		STDERR,
		"В заголовке нужны колонки «" . COL_ID . "» и «" . COL_NEW . "». Найдено: "
		. json_encode($header, JSON_UNESCAPED_UNICODE) . "\n"
	);
	exit(1);
}
$hasOldCol = isset($idx[COL_OLD]);

out('CSV: ' . realpath($csv));
out('IBLOCK_ID: ' . $iblockId);
out('Режим: ' . ($dry ? 'DRY-RUN (без записи)' : 'APPLY'));
out('Проверка «' . COL_OLD . '» == MF_BRAND: ' . ($verifyOld ? 'Y' : 'N'));
out('Пропуск без изменений: ' . ($skipUnchanged ? 'Y' : 'N'));
out('Прогресс: каждые ' . ($progressEvery > 0 ? (string)$progressEvery : 'N') . ' строк и/или не реже 2 с');
out('---');

$lastProgressAt = microtime(true);
$progressTimeSec = 2.0;

$maybeProgress = function () use ($dry, &$readCount, &$updateCount, &$lineNo, &$lastProgressAt, $progressEvery, $progressTimeSec): void {
	$label = $dry ? 'DRY' : 'APPLY';
	$now = microtime(true);
	$emit = false;
	if ($readCount === 1) {
		$emit = true;
	} elseif ($progressEvery > 0 && $readCount > 0 && ($readCount % $progressEvery) === 0) {
		$emit = true;
	} elseif (($now - $lastProgressAt) >= $progressTimeSec) {
		$emit = true;
	}
	if (!$emit) {
		return;
	}
	$ts = date('H:i:s');
	echo sprintf(
		"[%s] %s: строка %d, записей в CSV обработано %d, %s: %d (пропуски: пуст.бренд/вериф/без.изм./пл.ID/ош. — см. итог)\n",
		$ts,
		$label,
		$lineNo,
		$readCount,
		$dry ? 'к обновлению' : 'обновлено/применено',
		$updateCount
	);
	if (function_exists('flush')) {
		flush();
	}
	$lastProgressAt = $now;
};

while (($row = fgetcsv($fp, 0, ';', '"')) !== false) {
	$lineNo++;
	if (!is_array($row) || (count($row) === 1 && trim((string)$row[0]) === '')) {
		continue;
	}
	$readCount++;

	$id = (int)trim((string)($row[$idx[COL_ID]] ?? '0'));
	$new = trim((string)($row[$idx[COL_NEW]] ?? ''));
	$oldCsv = $hasOldCol ? trim((string)($row[$idx[COL_OLD]] ?? '')) : '';

	if ($id <= 0) {
		$skipBadId++;
		goto end_row;
	}
	if ($new === '') {
		$skipEmpty++;
		goto end_row;
	}

	$res = CIBlockElement::GetList(
		[],
		['IBLOCK_ID' => $iblockId, 'ID' => $id],
		false,
		false,
		['ID', 'PROPERTY_MF_BRAND', 'PROPERTY_MF_BRAND_NORM']
	);
	$e = $res?->GetNext();
	if (!$e || (int)($e['ID'] ?? 0) !== $id) {
		$errors++;
		fwrite(STDERR, "ID=$id: элемент не найден в iblock $iblockId (строка $lineNo)\n");
		goto end_row;
	}

	$curBrand = trim((string)($e['PROPERTY_MF_BRAND_VALUE'] ?? ''));
	$curNorm = trim((string)($e['PROPERTY_MF_BRAND_NORM_VALUE'] ?? ''));

	if ($verifyOld && $hasOldCol && $oldCsv !== '' && $curBrand !== $oldCsv) {
		$skipVerify++;
		goto end_row;
	}
	if ($skipUnchanged && $curBrand === $new && $curNorm === mf_brand_norm($new)) {
		$skipSame++;
		goto end_row;
	}

	$norm = mf_brand_norm($new);
	if ($dry) {
		$updateCount++;
		if ($updateCount <= 5) {
			out("DRY: ID=$id  MF_BRAND: '$curBrand' -> '$new' (norm: $norm)");
		}
		goto end_row;
	}

	$ok = $el->SetPropertyValuesEx($id, $iblockId, [
		'MF_BRAND' => $new,
		'MF_BRAND_NORM' => $norm,
	]);
	if ($ok === false) {
		$errors++;
		fwrite(STDERR, "ID=$id: SetPropertyValuesEx: " . $el->LAST_ERROR . "\n");
		goto end_row;
	}
	$updateCount++;

	end_row:
	$maybeProgress();
}

fclose($fp);

out("---\nИТОГО: строк в файле: $readCount, " . ($dry ? 'к обновлению' : 'обновлено') . ": $updateCount, пустой новый бренд: $skipEmpty" . ($verifyOld ? ", не совпал старый: $skipVerify" : ', verify_old_off') . ", уже совпадало: $skipSame, плохой ID: $skipBadId, ошибок: $errors");

exit($errors > 0 && !$dry ? 2 : 0);
