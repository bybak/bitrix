<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Catalog\StoreTable;

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

if (!class_exists(Application::class))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Bitrix\\Main\\Application недоступен.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

$lib = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_external_price_lib.php';
if (!is_file($lib))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не найден mf_external_price_lib.php']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}
require_once $lib;

$brandDict = $_SERVER['DOCUMENT_ROOT'] . '/mf_brand_dict.php';
if (is_file($brandDict))
{
	require_once $brandDict;
}

Loader::includeModule('iblock');
Loader::includeModule('catalog');

if (function_exists('mf_ep_ensure_store_weight_ufs'))
{
	mf_ep_ensure_store_weight_ufs();
}

$iblockId = 4;

function mf_epu_escape(string $s): string
{
	return function_exists('htmlspecialcharsbx')
		? (string)htmlspecialcharsbx($s)
		: htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mf_epu_parse_csv_line(string $line, string $delimiter): array
{
	$line = preg_replace('~^\xEF\xBB\xBF~', '', $line) ?? $line;

	return str_getcsv($line, $delimiter);
}

function mf_epu_detect_delimiter(string $headerLine): string
{
	$headerLine = preg_replace('~^\xEF\xBB\xBF~', '', $headerLine) ?? $headerLine;
	if (mb_strpos($headerLine, ';') !== false)
	{
		return ';';
	}
	if (mb_strpos($headerLine, "\t") !== false)
	{
		return "\t";
	}

	return ',';
}

function mf_epu_norm_header_cell(string $s): string
{
	$s = mb_strtolower(trim($s));
	$s = preg_replace('~\s+~u', ' ', $s) ?? $s;

	return $s;
}

/**
 * @return array{manufacturer?: int, article?: int, name?: int, price?: int}
 */
function mf_epu_map_headers(array $headerCells): array
{
	$map = [];
	foreach ($headerCells as $i => $cell)
	{
		$h = mf_epu_norm_header_cell((string)$cell);
		if ($h === '')
		{
			continue;
		}
		if (preg_match('~(производитель|бренд|vendor)~u', $h))
		{
			$map['manufacturer'] = (int)$i;
		}
		elseif (preg_match('~(артикул|артик)~u', $h))
		{
			$map['article'] = (int)$i;
		}
		elseif (preg_match('~(наименование|название|name)~u', $h))
		{
			$map['name'] = (int)$i;
		}
		elseif (preg_match('~(цена|price)~u', $h))
		{
			$map['price'] = (int)$i;
		}
	}

	return $map;
}

function mf_epu_read_file_utf8(string $path): string
{
	$raw = (string)file_get_contents($path);
	if ($raw === '')
	{
		return '';
	}
	if (!mb_check_encoding($raw, 'UTF-8'))
	{
		if (function_exists('iconv'))
		{
			$c = @iconv('Windows-1251', 'UTF-8//IGNORE', $raw);
			if (is_string($c) && $c !== '')
			{
				return $c;
			}
		}
	}

	return $raw;
}

$stores = [];
$rs = \CCatalogStore::GetList(['TITLE' => 'ASC'], [], false, false, ['ID', 'TITLE', 'XML_ID', 'CODE']);
while ($s = $rs->Fetch())
{
	$sid = (int)($s['ID'] ?? 0);
	if ($sid <= 0)
	{
		continue;
	}
	if (function_exists('mf_ep_store_is_external_warehouse') && !mf_ep_store_is_external_warehouse($sid))
	{
		continue;
	}
	$stores[] = $s;
}

/** @var array<int, array{use: bool, rub_per_kg: float, min_rub: float}> */
$mfEpStoreWeightJson = [];
foreach ($stores as $s)
{
	$sid = (int)($s['ID'] ?? 0);
	if ($sid <= 0)
	{
		continue;
	}
	$mfEpStoreWeightJson[$sid] = function_exists('mf_ep_store_weight_uf_raw')
		? mf_ep_store_weight_uf_raw($sid)
		: ['use' => false, 'rub_per_kg' => 0.0, 'min_rub' => 0.0];
}

$stats = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['mf_external_price_do'] ?? '') === 'Y')
{
	if (!check_bitrix_sessid())
	{
		$error = 'Неверная сессия (sessid). Обновите страницу.';
	}
	else
	{
		$storeId = (int)($_POST['store_id'] ?? 0);
		$currency = mb_strtoupper(trim((string)($_POST['currency'] ?? 'RUB')));
		$zeroMissing = isset($_POST['zero_missing']) && $_POST['zero_missing'] === 'Y';
		$weightUse = isset($_POST['weight_use']) && $_POST['weight_use'] === 'Y';
		$weightRubKg = (float)str_replace(',', '.', (string)($_POST['weight_rub_kg'] ?? '0'));
		$weightMinRub = (float)str_replace(',', '.', (string)($_POST['weight_min_rub'] ?? '0'));
		if (!$weightUse)
		{
			$weightRubKg = 0.0;
			$weightMinRub = 0.0;
		}

		$importLogWritten = false;
		$importLogT0 = microtime(true);
		$importStartedAt = date('Y-m-d H:i:s');
		$importFileName = (string)($_FILES['price_file']['name'] ?? '');
		$importFileSize = 0;
		$importTmp = (string)($_FILES['price_file']['tmp_name'] ?? '');
		if ($importTmp !== '' && is_readable($importTmp))
		{
			$importFileSize = (int)@filesize($importTmp);
		}
		$importUserId = (is_object($USER) ? (int)$USER->GetID() : 0);
		$importUserLogin = (is_object($USER) ? (string)$USER->GetLogin() : '');

		if ($storeId <= 0)
		{
			$error = 'Выберите склад.';
		}
		elseif (function_exists('mf_ep_store_is_external_warehouse') && !mf_ep_store_is_external_warehouse($storeId))
		{
			$error = 'Импорт внешних прайсов доступен только для складов с включённым полем «Внешний склад» в карточке склада.';
		}
		elseif (!isset($_FILES['price_file']) || !is_array($_FILES['price_file']))
		{
			$error = 'Файл не загружен.';
		}
		elseif ((int)($_FILES['price_file']['error'] ?? 0) !== UPLOAD_ERR_OK)
		{
			$error = 'Ошибка загрузки файла (код ' . (int)($_FILES['price_file']['error'] ?? 0) . ').';
		}
		elseif ($currency !== 'RUB' && $currency !== 'USD' && $currency !== 'EUR')
		{
			$error = 'Недопустимая валюта.';
		}
		elseif ($weightUse && $weightRubKg <= 0)
		{
			$error = 'Укажите ставку ₽ за кг (больше нуля), если включена опция учёта веса.';
		}
		else
		{
			$st = \CCatalogStore::GetList([], ['ID' => $storeId], false, false, ['ID', 'TITLE', 'XML_ID'])->Fetch();
			if (!$st)
			{
				$error = 'Склад не найден.';
			}
			else
			{
				$xmlId = mb_strtoupper(trim((string)($st['XML_ID'] ?? '')));
				if ($xmlId === '')
				{
					$error = 'У склада не заполнен XML_ID — создайте тип цены вручную или задайте XML_ID складу.';
				}
				else
				{
					$priceGroupId = mf_ep_get_or_create_price_group($xmlId, (string)($st['TITLE'] ?? $xmlId), true);
					if ($priceGroupId <= 0)
					{
						$error = 'Не удалось получить тип цены для склада.';
					}
					else
					{
						if (function_exists('mf_supplier_store_to_price_group_reset'))
						{
							mf_supplier_store_to_price_group_reset();
						}
						if (function_exists('mf_ep_invalidate_catalog_price_group_cache'))
						{
							mf_ep_invalidate_catalog_price_group_cache();
						}

						try
						{
							$tmp = (string)($_FILES['price_file']['tmp_name'] ?? '');
							$text = mf_epu_read_file_utf8($tmp);
							$lines = preg_split('~\R~u', $text) ?: [];
							if (count($lines) < 2)
							{
								throw new RuntimeException('В файле нет данных (нужна строка заголовка и хотя бы одна строка).');
							}

							$delim = mf_epu_detect_delimiter((string)($lines[0] ?? ''));
							$header = mf_epu_parse_csv_line((string)($lines[0] ?? ''), $delim);
							$hmap = mf_epu_map_headers($header);

							if (!isset($hmap['manufacturer'], $hmap['article'], $hmap['price']))
							{
								throw new RuntimeException(
									'Не найдены колонки Производитель / Артикул / Цена в первой строке. Обнаружено: '
									. implode($delim, $header)
								);
							}

							$ok = 0;
							$notFound = 0;
							$bad = 0;
							$priceWriteFail = 0;
							$totalDataRows = 0;
							$matchedIds = [];
							$examplesNotFound = [];

							for ($li = 1, $n = count($lines); $li < $n; $li++)
							{
								$line = trim((string)($lines[$li] ?? ''));
								if ($line === '')
								{
									continue;
								}
								$totalDataRows++;

								$cells = mf_epu_parse_csv_line($line, $delim);
								$manufacturer = trim((string)($cells[$hmap['manufacturer']] ?? ''));
								$article = trim((string)($cells[$hmap['article']] ?? ''));
								$priceStr = trim((string)($cells[$hmap['price']] ?? ''));
								$priceStr = str_replace([' ', ','], ['', '.'], $priceStr);

								if ($manufacturer === '' || $article === '' || $priceStr === '')
								{
									$bad++;
									continue;
								}

								$priceVal = (float)$priceStr;
								if ($priceVal < 0 || !is_finite($priceVal))
								{
									$bad++;
									continue;
								}

								$canon = function_exists('mf_brand_find') ? mf_brand_find($manufacturer, false) : '';
								$brandRaw = $canon !== '' ? $canon : $manufacturer;
								$articleNorm = mf_ep_norm_article($article);
								$brandNorm = mf_ep_norm_brand($brandRaw);

								$pid = mf_ep_find_product($iblockId, $articleNorm, $brandRaw, $brandNorm);
								if ($pid === null || $pid <= 0)
								{
									$notFound++;
									if (count($examplesNotFound) < 15)
									{
										$examplesNotFound[] = $manufacturer . ' / ' . $article;
									}
									continue;
								}

								$catalogPid = function_exists('mf_ep_resolve_catalog_trade_product_id')
									? mf_ep_resolve_catalog_trade_product_id((int)$pid)
									: (int)$pid;
								if ($catalogPid <= 0)
								{
									$catalogPid = (int)$pid;
								}

								$rub = mf_ep_convert_to_rub($priceVal, $currency);
								// b_catalog_price: одна и та же RAW на родителе и на SKU, чтобы цена находилась с любого PRODUCT_ID.
								if (function_exists('mf_ep_set_raw_price_for_catalog_cluster'))
								{
									$priceWriteFail += mf_ep_set_raw_price_for_catalog_cluster((int)$pid, $priceGroupId, $rub);
								}
								else
								{
									if (!mf_ep_set_raw_price($catalogPid, $priceGroupId, $rub))
									{
										$priceWriteFail++;
									}
								}
								if ($rub > 0 && function_exists('mf_ep_ensure_unit_if_zero_stock'))
								{
									mf_ep_ensure_unit_if_zero_stock($catalogPid, $storeId);
								}
								// И родитель, и SKU (если разошлись) — чтобы «обнулить отсутствующие» не трогало дубликат по другому PRODUCT_ID.
								$matchedIds[(int)$pid] = true;
								$matchedIds[$catalogPid] = true;
								$ok++;
							}

							$zeroed = 0;
							if ($zeroMissing)
							{
								$candidates = mf_ep_collect_candidates_for_store($storeId, $priceGroupId);
								foreach ($candidates as $cpid)
								{
									if (isset($matchedIds[$cpid]))
									{
										continue;
									}
									mf_ep_zero_product_on_store((int)$cpid, $storeId, $priceGroupId);
									$zeroed++;
								}
							}

							foreach (array_keys($matchedIds) as $cpid)
							{
								mf_ep_sync_catalog_qty_from_stores((int)$cpid);
								mf_ep_recalc_base_one((int)$cpid);
							}

							global $USER_FIELD_MANAGER;
							if (is_object($USER_FIELD_MANAGER) && class_exists(StoreTable::class))
							{
								$uf = [
									'UF_MF_EXT_WEIGHT_USE' => $weightUse ? 1 : 0,
									'UF_MF_EXT_WEIGHT_RUB_PER_KG' => $weightRubKg,
									'UF_MF_EXT_WEIGHT_MIN_RUB' => $weightMinRub,
								];
								$USER_FIELD_MANAGER->Update(StoreTable::getUfId(), $storeId, $uf);
							}

							$stats = [
								'ok' => $ok,
								'not_found' => $notFound,
								'bad' => $bad,
								'zeroed' => $zeroed,
								'price_write_fail' => $priceWriteFail,
								'store' => (string)($st['TITLE'] ?? ''),
								'xml' => $xmlId,
								'currency' => $currency,
								'examples_nf' => $examplesNotFound,
							];

							$importFinishedAt = date('Y-m-d H:i:s');
							$importDurMs = (int)round((microtime(true) - $importLogT0) * 1000.0);
							$headerLineStr = mb_substr(implode($delim, $header), 0, 1000);
							$exNfStr = implode("\n", $examplesNotFound);
							if (function_exists('mf_external_price_import_log_insert'))
							{
								mf_external_price_import_log_insert([
									'UF_STARTED_AT' => $importStartedAt,
									'UF_FINISHED_AT' => $importFinishedAt,
									'UF_DURATION_MS' => $importDurMs,
									'UF_STATUS' => 'ok',
									'UF_USER_ID' => $importUserId > 0 ? $importUserId : null,
									'UF_USER_LOGIN' => $importUserLogin !== '' ? $importUserLogin : null,
									'UF_STORE_ID' => $storeId,
									'UF_STORE_XML_ID' => (string)($st['XML_ID'] ?? ''),
									'UF_STORE_TITLE' => (string)($st['TITLE'] ?? ''),
									'UF_PRICE_GROUP_ID' => $priceGroupId,
									'UF_INPUT_FILENAME' => $importFileName,
									'UF_FILE_SIZE' => $importFileSize > 0 ? $importFileSize : null,
									'UF_CURRENCY' => $currency,
									'UF_ZERO_MISSING' => $zeroMissing ? 'Y' : 'N',
									'UF_WEIGHT_USE' => $weightUse ? 'Y' : 'N',
									'UF_WEIGHT_RUB_PER_KG' => $weightRubKg,
									'UF_WEIGHT_MIN_RUB' => $weightMinRub,
									'UF_TOTAL_DATA_ROWS' => $totalDataRows,
									'UF_MATCHED' => $ok,
									'UF_NOT_FOUND' => $notFound,
									'UF_BAD_ROWS' => $bad,
									'UF_ZEROED' => $zeroed,
									'UF_HEADER_LINE' => $headerLineStr,
									'UF_EXAMPLES_NOT_FOUND' => $exNfStr !== '' ? $exNfStr : null,
									'UF_ERROR_MESSAGE' => null,
								]);
								$importLogWritten = true;
							}
						}
						catch (Throwable $e)
						{
							$error = $e->getMessage();
							if (!$importLogWritten && function_exists('mf_external_price_import_log_insert'))
							{
								$importFinishedAt = date('Y-m-d H:i:s');
								$importDurMs = (int)round((microtime(true) - $importLogT0) * 1000.0);
								$headerLineStr = null;
								if (isset($header) && is_array($header) && isset($delim))
								{
									$headerLineStr = mb_substr(implode($delim, $header), 0, 1000);
								}
								mf_external_price_import_log_insert([
									'UF_STARTED_AT' => $importStartedAt,
									'UF_FINISHED_AT' => $importFinishedAt,
									'UF_DURATION_MS' => $importDurMs,
									'UF_STATUS' => 'failed',
									'UF_USER_ID' => $importUserId > 0 ? $importUserId : null,
									'UF_USER_LOGIN' => $importUserLogin !== '' ? $importUserLogin : null,
									'UF_STORE_ID' => isset($storeId) ? $storeId : null,
									'UF_STORE_XML_ID' => isset($st['XML_ID']) ? (string)$st['XML_ID'] : null,
									'UF_STORE_TITLE' => isset($st['TITLE']) ? (string)$st['TITLE'] : null,
									'UF_PRICE_GROUP_ID' => isset($priceGroupId) ? $priceGroupId : null,
									'UF_INPUT_FILENAME' => $importFileName !== '' ? $importFileName : null,
									'UF_FILE_SIZE' => $importFileSize > 0 ? $importFileSize : null,
									'UF_CURRENCY' => $currency,
									'UF_ZERO_MISSING' => $zeroMissing ? 'Y' : 'N',
									'UF_WEIGHT_USE' => $weightUse ? 'Y' : 'N',
									'UF_WEIGHT_RUB_PER_KG' => $weightRubKg,
									'UF_WEIGHT_MIN_RUB' => $weightMinRub,
									'UF_TOTAL_DATA_ROWS' => isset($totalDataRows) ? $totalDataRows : null,
									'UF_MATCHED' => isset($ok) ? $ok : null,
									'UF_NOT_FOUND' => isset($notFound) ? $notFound : null,
									'UF_BAD_ROWS' => isset($bad) ? $bad : null,
									'UF_ZEROED' => isset($zeroed) ? $zeroed : null,
									'UF_HEADER_LINE' => $headerLineStr,
									'UF_EXAMPLES_NOT_FOUND' => null,
									'UF_ERROR_MESSAGE' => mb_substr($error, 0, 1000),
								]);
								$importLogWritten = true;
							}
						}
					}
				}
			}
		}

		if (!$importLogWritten && $error !== null && $error !== '')
		{
			$finVal = date('Y-m-d H:i:s');
			$durVal = (int)round((microtime(true) - $importLogT0) * 1000.0);
			$stVal = null;
			if ($storeId > 0)
			{
				$stVal = \CCatalogStore::GetList([], ['ID' => $storeId], false, false, ['ID', 'TITLE', 'XML_ID'])->Fetch();
			}
			if (function_exists('mf_external_price_import_log_insert'))
			{
				mf_external_price_import_log_insert([
					'UF_STARTED_AT' => $importStartedAt,
					'UF_FINISHED_AT' => $finVal,
					'UF_DURATION_MS' => $durVal,
					'UF_STATUS' => 'validation_failed',
					'UF_USER_ID' => $importUserId > 0 ? $importUserId : null,
					'UF_USER_LOGIN' => $importUserLogin !== '' ? $importUserLogin : null,
					'UF_STORE_ID' => $storeId > 0 ? $storeId : null,
					'UF_STORE_XML_ID' => ($stVal && !empty($stVal['XML_ID'])) ? (string)$stVal['XML_ID'] : null,
					'UF_STORE_TITLE' => ($stVal && !empty($stVal['TITLE'])) ? (string)$stVal['TITLE'] : null,
					'UF_PRICE_GROUP_ID' => null,
					'UF_INPUT_FILENAME' => $importFileName !== '' ? $importFileName : null,
					'UF_FILE_SIZE' => $importFileSize > 0 ? $importFileSize : null,
					'UF_CURRENCY' => $currency,
					'UF_ZERO_MISSING' => $zeroMissing ? 'Y' : 'N',
					'UF_WEIGHT_USE' => $weightUse ? 'Y' : 'N',
					'UF_WEIGHT_RUB_PER_KG' => $weightRubKg,
					'UF_WEIGHT_MIN_RUB' => $weightMinRub,
					'UF_TOTAL_DATA_ROWS' => null,
					'UF_MATCHED' => null,
					'UF_NOT_FOUND' => null,
					'UF_BAD_ROWS' => null,
					'UF_ZEROED' => null,
					'UF_HEADER_LINE' => null,
					'UF_EXAMPLES_NOT_FOUND' => null,
					'UF_ERROR_MESSAGE' => mb_substr((string)$error, 0, 1000),
				]);
			}
		}
	}
}

$APPLICATION->SetTitle('Загрузка внешних прайсов');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$langUi = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';

$wfRepost = ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['mf_external_price_do'] ?? '') === 'Y');
$formStoreId = $wfRepost ? (int)($_POST['store_id'] ?? 0) : 0;
$wfUseChecked = $wfRepost && (string)($_POST['weight_use'] ?? '') === 'Y';
$wfRubKgStr = $wfRepost ? (string)($_POST['weight_rub_kg'] ?? '0') : '0';
$wfMinRubStr = $wfRepost ? (string)($_POST['weight_min_rub'] ?? '0') : '0';
$wfWeightInputsDisabled = !$wfUseChecked;

?>
<p style="margin:0 0 12px 0"><a href="mf_external_price_history.php?lang=<?= mf_epu_escape($langUi) ?>">История импортов внешних прайсов</a></p>
<form method="post" enctype="multipart/form-data" action="<?= mf_epu_escape($APPLICATION->GetCurPage()) ?>?lang=<?= mf_epu_escape(LANGUAGE_ID) ?>">
	<?= bitrix_sessid_post() ?>
	<input type="hidden" name="mf_external_price_do" value="Y" />

	<?php
	if ($error)
	{
		CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => $error]);
	}
if ($stats)
{
	$msg = 'Обработано строк (найден товар): ' . (int)$stats['ok']
		. '; не найдено в каталоге: ' . (int)$stats['not_found']
		. '; пропуск/битые строки: ' . (int)$stats['bad']
		. '; обнулено (не в файле): ' . (int)$stats['zeroed']
		. '; не записана цена (ошибка API Bitrix / кэш типа цены): ' . (int)($stats['price_write_fail'] ?? 0)
		. '. Склад: ' . mf_epu_escape((string)$stats['store']) . ' (' . mf_epu_escape((string)$stats['xml']) . '), валюта прайса: ' . mf_epu_escape((string)$stats['currency']) . '.';
	CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => $msg]);
	if (!empty($stats['examples_nf']))
	{
		echo '<div style="margin:10px 0;padding:10px;background:#f8f8f8;border:1px solid #ddd;max-width:900px">Примеры не найденных (до 15):<br>'
			. mf_epu_escape(implode('; ', $stats['examples_nf'])) . '</div>';
	}
}
?>

	<p>Формат CSV: колонки <strong>Производитель</strong>, <strong>Артикул</strong>, <strong>Наименование</strong> (опционально), <strong>Цена</strong>. Разделитель — точка с запятой.</p>
	<p class="adm-info-message" style="max-width:900px">В списке складов — только те, у которых в карточке склада включено поле <strong>«Внешний склад»</strong> (пользовательское поле). Остальные склады отметьте в <em>Магазин → Склады</em> и снова откройте эту страницу.</p>
	<?php
	if (empty($stores))
	{
		CAdminMessage::ShowMessage([
			'TYPE' => 'ERROR',
			'MESSAGE' => 'Нет складов с признаком «Внешний склад». Отметьте хотя бы один склад в карточке склада.',
		]);
	}
	?>
	<p><strong>Валюта прайса (USD/EUR):</strong> сначала используется курс из модуля Bitrix
		(<em>Настройки → Настройки продукта → Настройки модулей → Валюты</em> — валюты и курсы к базовой, обычно рублю).
		Если курса в базе нет, подставляется курс ЦБ РФ (кэш в опции <code>mf_cbr_rates_*</code>), кроме случая, когда в <code>main</code> задано <code>mf_external_price_no_cbr=Y</code> — тогда только курсы Bitrix, без запросов к ЦБ.</p>

	<table class="adm-detail-content-table edit-table" style="max-width:720px">
		<tr>
			<td class="adm-detail-content-cell-l" width="40%">Файл CSV</td>
			<td><input type="file" name="price_file" accept=".csv,.txt" required /></td>
		</tr>
		<tr>
			<td>Склад (внешний)</td>
			<td>
				<select name="store_id" id="mf_ep_import_store_id" required<?= empty($stores) ? ' disabled' : '' ?>>
					<option value="">— выберите —</option>
					<?php foreach ($stores as $s) {
						$sid = (int)($s['ID'] ?? 0);
						$t = trim((string)($s['TITLE'] ?? ''));
						$x = trim((string)($s['XML_ID'] ?? ''));
						$lab = $t . ($x !== '' ? ' [' . $x . ']' : '');
						$sel = ($formStoreId > 0 && $formStoreId === $sid) ? ' selected' : '';
						?>
						<option value="<?= $sid ?>"<?= $sel ?>><?= mf_epu_escape($lab) ?></option>
					<?php } ?>
				</select>
			</td>
		</tr>
		<tr>
			<td>Вес: текущие значения на складе</td>
			<td>
				<div id="mf_ep_store_weight_hint" class="adm-info-message" style="margin:0;max-width:640px">
					Выберите склад — здесь отобразятся сохранённые в карточке склада поля «внешний прайс / вес».
				</div>
			</td>
		</tr>
		<tr>
			<td>Валюта цен в файле</td>
			<td>
				<select name="currency">
					<option value="RUB">Рубли (RUB)</option>
					<option value="USD">Доллары (USD), курс ЦБ</option>
					<option value="EUR">Евро (EUR), курс ЦБ</option>
				</select>
			</td>
		</tr>
		<tr>
			<td>Обнулять цену и остаток на складе</td>
			<td>
				<label><input type="checkbox" name="zero_missing" value="Y" /> для товаров, которых нет в загруженном файле (остальные склады не трогаем)</label>
			</td>
		</tr>
		<tr>
			<td>Учитывать вес товара</td>
			<td>
				<label><input type="checkbox" name="weight_use" id="mf_ep_weight_use" value="Y"<?= $wfUseChecked ? ' checked' : '' ?> /> при оформлении заказа к позиции добавляется доплата по весу из каталога (поле веса товара, коэффициент из настроек магазина sale/weight_koef)</label>
			</td>
		</tr>
		<tr>
			<td>₽ за 1 кг веса</td>
			<td><input type="text" name="weight_rub_kg" id="mf_ep_weight_rub_kg" value="<?= mf_epu_escape($wfRubKgStr) ?>" size="10"<?= $wfWeightInputsDisabled ? ' disabled' : '' ?> /> и минимальная доплата, ₽: <input type="text" name="weight_min_rub" id="mf_ep_weight_min_rub" value="<?= mf_epu_escape($wfMinRubStr) ?>" size="8"<?= $wfWeightInputsDisabled ? ' disabled' : '' ?> /></td>
		</tr>
	</table>

	<br /><input type="submit" class="adm-btn-save" value="Загрузить и применить"<?= empty($stores) ? ' disabled' : '' ?> />
</form>
<?php
$jsMapJson = json_encode($mfEpStoreWeightJson, JSON_UNESCAPED_UNICODE);
if ($jsMapJson === false)
{
	$jsMapJson = '{}';
}
?>
<script>
(function () {
	var MAP = <?= $jsMapJson ?>;
	var REPOST = <?= $wfRepost ? 'true' : 'false' ?>;
	function fmtInput(x) {
		if (x === undefined || x === null) return '0';
		var n = Number(x);
		return isFinite(n) ? String(n) : '0';
	}
	function fmtRub(x) {
		var n = Number(x);
		if (!isFinite(n)) return '0,00';
		return n.toFixed(2).replace('.', ',');
	}
	function pickWeight(sid) {
		sid = String(sid || '');
		if (!sid) return null;
		return MAP[sid] || MAP[Number(sid)] || null;
	}
	function hintHtml(w) {
		return '<strong>Сохранённые поля склада</strong> (те же, что в карточке склада):<br>'
			+ '«Внешний прайс: учитывать вес (доставка)» — <strong>' + (w.use ? 'да' : 'нет') + '</strong>;<br>'
			+ '«Доп. ₽ за кг веса» — <strong>' + fmtRub(w.rub_per_kg) + '</strong>;<br>'
			+ '«Мин. доплата по весу, ₽» — <strong>' + fmtRub(w.min_rub) + '</strong>.<br>'
			+ '<span style="color:#555">При смене склада поля ниже подставляются из базы. После успешного импорта введённые значения записываются на выбранный склад.</span>';
	}
	function syncWeightInputsEnabled() {
		var chk = document.getElementById('mf_ep_weight_use');
		var rub = document.getElementById('mf_ep_weight_rub_kg');
		var min = document.getElementById('mf_ep_weight_min_rub');
		if (!chk || !rub || !min) return;
		var on = chk.checked;
		rub.disabled = !on;
		min.disabled = !on;
	}
	function applyFromMap(sid, touchInputs) {
		var hint = document.getElementById('mf_ep_store_weight_hint');
		var chk = document.getElementById('mf_ep_weight_use');
		var rub = document.getElementById('mf_ep_weight_rub_kg');
		var min = document.getElementById('mf_ep_weight_min_rub');
		sid = String(sid || '');
		if (!hint) return;
		var w = pickWeight(sid);
		if (!sid || !w) {
			hint.innerHTML = 'Выберите склад — здесь появятся текущие значения полей веса (UF) этого склада.';
			syncWeightInputsEnabled();
			return;
		}
		hint.innerHTML = hintHtml(w);
		if (touchInputs && chk && rub && min) {
			chk.checked = !!w.use;
			rub.value = fmtInput(w.rub_per_kg);
			min.value = fmtInput(w.min_rub);
		}
		syncWeightInputsEnabled();
	}
	var sel = document.getElementById('mf_ep_import_store_id');
	var wChk = document.getElementById('mf_ep_weight_use');
	if (wChk) {
		wChk.addEventListener('change', syncWeightInputsEnabled);
	}
	if (!sel) return;
	sel.addEventListener('change', function () {
		applyFromMap(sel.value, true);
	});
	if (REPOST) {
		applyFromMap(sel.value, false);
	} else {
		applyFromMap(sel.value, true);
	}
	syncWeightInputsEnabled();
})();
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
