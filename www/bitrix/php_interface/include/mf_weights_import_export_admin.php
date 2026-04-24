<?php

declare(strict_types=1);

use Bitrix\Main\Loader;

if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
{
	die('Admin only');
}

global $APPLICATION, $USER;

if (!is_object($USER) || !$USER->IsAdmin())
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Недостаточно прав.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog'))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Нужны модули iblock и catalog.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

$libEp = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_external_price_lib.php';
$libW = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_weight_xlsx_import_lib.php';
if (!is_file($libEp) || !is_file($libW))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не найдены библиотеки mf_external_price_lib / mf_weight_xlsx_import_lib.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}
require_once $libEp;
require_once $libW;

$brandDict = $_SERVER['DOCUMENT_ROOT'] . '/mf_brand_dict.php';
if (is_file($brandDict))
{
	require_once $brandDict;
}

$iblockId = 4;

function mf_wgw_esc(string $s): string
{
	return function_exists('htmlspecialcharsbx')
		? (string)htmlspecialcharsbx($s)
		: htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Вес (г) в каталоге: первая позиция кластера.
 */
function mf_wgw_element_weight_grams(int $elementId): int
{
	$ids = function_exists('mf_catalog_product_cluster_ids')
		? mf_catalog_product_cluster_ids($elementId)
		: [$elementId];
	$ids = array_values(array_unique(array_filter(
		$ids,
		static fn($v) => (int)$v > 0
	)));
	if ($ids === [] || !class_exists(\CCatalogProduct::class))
	{
		return 0;
	}
	$row = \CCatalogProduct::GetByID((int)$ids[0]);

	return isset($row['WEIGHT']) ? (int)$row['WEIGHT'] : 0;
}

/**
 * @return \Generator<int, array{0: string, 1: string, 2: string, 3: int}>
 */
function mf_wgw_export_rows(int $iblockIblockId): \Generator
{
	$iblockIblockId = (int)$iblockIblockId;
	$filter = [
		'IBLOCK_ID' => $iblockIblockId,
		'ACTIVE' => 'Y',
		'!=PROPERTY_MF_IS_REDIRECT' => 'Y',
	];
	$rs = \CIBlockElement::GetList(
		['ID' => 'ASC'],
		$filter,
		false,
		false,
		['ID', 'IBLOCK_ID', 'NAME']
	);
	while ($ob = $rs->GetNextElement())
	{
		$f = $ob->GetFields();
		$p = $ob->GetProperties();
		$id = (int)($f['ID'] ?? 0);
		if ($id <= 0)
		{
			continue;
		}
		$brand = (string)($p['MF_BRAND']['VALUE'] ?? '');
		if (is_array($p['MF_BRAND']['VALUE'] ?? null) && $brand === '')
		{
			$v = reset($p['MF_BRAND']['VALUE']);
			$brand = $v === false ? '' : (string)$v;
		}
		$brand = trim($brand);
		if ($brand === '' && !empty($p['MF_BRAND_NORM']['VALUE']))
		{
			$bn = $p['MF_BRAND_NORM']['VALUE'];
			$brand = trim(is_array($bn) ? (string)reset($bn) : (string)$bn);
		}
		$article = (string)($p['CML2_ARTICLE']['VALUE'] ?? '');
		if (is_array($p['CML2_ARTICLE']['VALUE'] ?? null) && $article === '')
		{
			$v = reset($p['CML2_ARTICLE']['VALUE']);
			$article = $v === false ? '' : (string)$v;
		}
		$article = trim($article);
		if ($article === '' && !empty($p['MF_ARTICLE_NORM']['VALUE']))
		{
			$an = $p['MF_ARTICLE_NORM']['VALUE'];
			$article = trim(is_array($an) ? (string)reset($an) : (string)$an);
		}
		if ($brand === '' || $article === '')
		{
			continue;
		}
		$w = mf_wgw_element_weight_grams($id);
		$name = trim((string)($f['NAME'] ?? ''));

		yield [$brand, $article, $name, $w];
	}
}

// ——— Export ———
if (
	$_SERVER['REQUEST_METHOD'] === 'POST'
	&& (string)($_POST['mf_weights_export'] ?? '') === 'Y'
	&& check_bitrix_sessid()
) {
	while (ob_get_level() > 0)
	{
		ob_end_clean();
	}
	@set_time_limit(0);
	$fname = 'weights_' . date('Y-m-d_His');
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . $fname . '.csv"');
	header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
	echo "\xEF\xBB\xBF";
	$out = fopen('php://output', 'wb');
	$headers = ['бренд', 'артикул', 'наименование', 'вес(гр)'];
	fputcsv($out, $headers, ';');
	foreach (mf_wgw_export_rows($iblockId) as $r)
	{
		fputcsv($out, $r, ';');
	}
	fclose($out);
	exit;
}

// ——— Шаблон (заголовок + строка-пример) ———
if (
	$_SERVER['REQUEST_METHOD'] === 'POST'
	&& (string)($_POST['mf_weights_template'] ?? '') === 'Y'
	&& check_bitrix_sessid()
) {
	while (ob_get_level() > 0)
	{
		ob_end_clean();
	}
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="weights_template.csv"');
	header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
	echo "\xEF\xBB\xBF";
	$out = fopen('php://output', 'wb');
	fputcsv($out, ['бренд', 'артикул', 'наименование', 'вес(гр)'], ';');
	fputcsv($out, ['YAMAHA', '8DN-17641-01-00', 'Кольцо поршневое (пример)', '150'], ';');
	fclose($out);
	exit;
}

$importResult = null;
$importStats = null;
$importException = '';

if (
	$_SERVER['REQUEST_METHOD'] === 'POST'
	&& (string)($_POST['mf_weights_import'] ?? '') === 'Y'
	&& check_bitrix_sessid()
) {
	$weightInKg = !empty($_POST['weight_in_kg']) && (string)($_POST['weight_in_kg'] ?? '') === 'Y';
	$f = $_FILES['csv_file'] ?? null;
	$err = (int)($f['error'] ?? -1);
	$path = (string)($f['tmp_name'] ?? '');

	if ($f === null || $err !== UPLOAD_ERR_OK || $path === '' || !is_uploaded_file($path) || !is_readable($path))
	{
		$importResult = 'error:upload';
	}
	else
	{
		try
		{
			$importStats = mf_wxi_import_csv_file($path, $iblockId, $weightInKg);
			$importResult = 'ok';
		}
		catch (Throwable $e)
		{
			$importResult = 'error:exception';
			$importException = $e->getMessage();
		}
	}
}

$APPLICATION->SetTitle('Выгрузка / загрузка весов');
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$langUi = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
$actionPage = (string)$APPLICATION->GetCurPage();

if ($importResult === 'ok' && is_array($importStats))
{
	$msg = sprintf(
		'Готово. Обновлено: %d; строк данных: %d; не найдено товар: %d; пропущено (брак): %d; ошибок записи веса: %d.',
		(int)($importStats['ok'] ?? 0),
		(int)($importStats['rows_seen'] ?? 0),
		(int)($importStats['not_found'] ?? 0),
		(int)($importStats['bad'] ?? 0),
		(int)($importStats['weight_fail'] ?? 0)
	);
	\CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => $msg]);
	$exNf = $importStats['examples_not_found'] ?? [];
	if (is_array($exNf) && $exNf !== [] && count($exNf) > 0)
	{
		echo '<p class="adm-info-message" style="max-width:640px">Примеры «не найдено» (до 20):<br/>';
		foreach ($exNf as $l)
		{
			echo mf_wgw_esc((string)$l) . "<br/>\n";
		}
		echo '</p>';
	}
}
elseif ($importResult === 'error:upload')
{
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Файл не получен. Выберите CSV и повторите.']);
}
elseif ($importResult === 'error:exception' && $importException !== '')
{
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Ошибка: ' . mf_wgw_esc($importException)]);
}

?>
<p class="adm-info-message" style="max-width:720px">
	Каталог: инфоблок ID <strong><?= (int)$iblockId ?></strong>.
	Колонки: <strong>бренд</strong>, <strong>артикул</strong>, <strong>наименование</strong>, <strong>вес(гр)</strong> (наименование при импорте только для ориентира в файле, поиск товара — по бренду и артикулу). Разделитель <code>;</code> или <code>,</code>, первая строка — заголовок. Запись веса — как в
	<code>mf_import_weight_xlsx.php</code> / <code>mf_wxi_import_csv_file</code> (<code>mf_ep_set_weight_for_catalog_cluster</code>).
	Редиректы (MF_IS_REDIRECT) в полную выгрузку не попадают.
</p>

<div class="adm-info-message" style="max-width:640px">
	<strong>Формат одной строки (пример):</strong>
	<pre style="margin:8px 0 0;padding:10px 12px;background:#f5f5f5;border:1px solid #e0e0e0;overflow:auto;font-size:12px">бренд;артикул;наименование;вес(гр)
YAMAHA;8DN-17641-01-00;Кольцо поршневое;150</pre>
	<div style="margin-top:6px;font-size:12px;color:#666">Старые файлы с тремя колонками (без наименования) при импорте по-прежнему обрабатываются. Можно скачать готовый шаблон ниже.</div>
</div>

<h3>Шаблон</h3>
<form method="post" action="<?= mf_wgw_esc($actionPage) ?>?lang=<?= mf_wgw_esc($langUi) ?>" style="margin:8px 0 16px">
	<?= bitrix_sessid_post() ?>
	<input type="hidden" name="mf_weights_template" value="Y" />
	<input type="submit" class="adm-btn" value="Скачать шаблон CSV (заголовок + пример)" />
</form>

<h3>Выгрузка</h3>
<form method="post" action="<?= mf_wgw_esc($actionPage) ?>?lang=<?= mf_wgw_esc($langUi) ?>">
	<?= bitrix_sessid_post() ?>
	<input type="hidden" name="mf_weights_export" value="Y" />
	<input type="submit" class="adm-btn" value="Скачать полный CSV" />
</form>

<h3 style="margin-top:1.5em">Загрузка</h3>
<form method="post" enctype="multipart/form-data" action="<?= mf_wgw_esc($actionPage) ?>?lang=<?= mf_wgw_esc($langUi) ?>">
	<?= bitrix_sessid_post() ?>
	<input type="hidden" name="mf_weights_import" value="Y" />
	<p>
		<input type="file" name="csv_file" accept=".csv,text/csv" required />
	</p>
	<p>
		<label>
			<input type="checkbox" name="weight_in_kg" value="Y" />
			Вес в файле в <strong>килограммах</strong> (по умолчанию в граммах)
		</label>
	</p>
	<p>
		<input type="submit" class="adm-btn adm-btn-save" value="Импортировать" />
	</p>
</form>
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
