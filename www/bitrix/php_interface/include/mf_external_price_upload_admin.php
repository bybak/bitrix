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
Loader::includeModule('currency');

/**
 * Валюты из модуля Bitrix «Валюты» (CCurrency) для селекта импорта.
 *
 * @return list<array{code:string,title:string}>
 */
function mf_epu_get_currency_select_options(): array
{
	static $cached = null;
	if ($cached !== null)
	{
		return $cached;
	}
	$fallback = [
		['code' => 'RUB', 'title' => 'Российский рубль (RUB)'],
		['code' => 'USD', 'title' => 'Доллар США (USD)'],
		['code' => 'EUR', 'title' => 'Евро (EUR)'],
	];
	if (!class_exists(\CCurrency::class))
	{
		$cached = $fallback;
		return $cached;
	}
	$langId = defined('LANGUAGE_ID') && (string)LANGUAGE_ID !== '' ? (string)LANGUAGE_ID : 'ru';
	$out = [];
	$rs = \CCurrency::GetList('sort', 'asc');
	while ($row = $rs->Fetch())
	{
		$code = mb_strtoupper(trim((string)($row['CURRENCY'] ?? '')));
		if ($code === '')
		{
			continue;
		}
		$title = $code;
		if (class_exists(\CCurrencyLang::class))
		{
			$lr = \CCurrencyLang::GetByID($code, $langId);
			if (is_array($lr) && trim((string)($lr['FULL_NAME'] ?? '')) !== '')
			{
				$title = trim((string)$lr['FULL_NAME']) . ' (' . $code . ')';
			}
		}
		$out[] = ['code' => $code, 'title' => $title];
	}
	$cached = $out !== [] ? $out : $fallback;
	return $cached;
}

function mf_epu_normalize_import_currency(string $posted, array $allowedCodes): string
{
	$c = mb_strtoupper(trim($posted));
	if ($allowedCodes === [])
	{
		return 'RUB';
	}
	if ($c !== '' && in_array($c, $allowedCodes, true))
	{
		return $c;
	}
	return $allowedCodes[0];
}

// Сразу отдать JSON поллинга, не вызывая mf_ep_ensure_store_weight_ufs и т.д. (иначе запрос подвисает).
if ((string)($_GET['mf_ep_job_poll'] ?? '') === '1')
{
	$jobPollId = (int)($_GET['job'] ?? 0);
	$tokenPoll = preg_replace('~[^a-f0-9]~', '', (string)($_GET['token'] ?? ''));
	header('Content-Type: application/json; charset=utf-8');
	if (!is_object($USER) || !$USER->IsAdmin() || $jobPollId <= 0 || strlen($tokenPoll) !== 32)
	{
		echo json_encode(['ok' => false, 'error' => 'Доступ запрещён.'], JSON_UNESCAPED_UNICODE);
		die;
	}
	$rowP = function_exists('mf_external_price_import_job_get') ? mf_external_price_import_job_get($jobPollId) : null;
	if (
		!$rowP
		|| (int)($rowP['UF_USER_ID'] ?? 0) !== (int)$USER->GetID()
		|| (string)($rowP['UF_TOKEN'] ?? '') !== $tokenPoll
	)
	{
		echo json_encode(['ok' => false, 'error' => 'Задание не найдено.'], JSON_UNESCAPED_UNICODE);
		die;
	}
	$stP = (string)($rowP['UF_STATUS'] ?? '');
	$outP = [
		'ok' => true,
		'status' => $stP,
		'rows_done' => (int)($rowP['UF_ROWS_DONE'] ?? 0),
		'rows_total' => (int)($rowP['UF_ROWS_TOTAL'] ?? 0),
	];
	if ($stP === 'done' && !empty($rowP['UF_RESULT_JSON']))
	{
		$decoded = json_decode((string)$rowP['UF_RESULT_JSON'], true);
		$outP['stats'] = is_array($decoded) ? $decoded : null;
	}
	if ($stP === 'failed' && !empty($rowP['UF_ERROR_TEXT']))
	{
		$outP['error'] = (string)$rowP['UF_ERROR_TEXT'];
	}
	echo json_encode($outP, JSON_UNESCAPED_UNICODE);
	die;
}

$mfEpJobRunner = __DIR__ . '/mf_external_price_import_runner.php';
if (is_file($mfEpJobRunner))
{
	require_once $mfEpJobRunner;
}
$mfEpJobWorkerInc = __DIR__ . '/mf_external_price_job_worker_inc.php';
if (is_file($mfEpJobWorkerInc))
{
	require_once $mfEpJobWorkerInc;
}

// Резервный запуск воркера (GET) — сразу после поллинга, без mf_ep_ensure_store_weight_ufs (иначе iframe «висит»).
if ((string)($_GET['mf_ep_job_nudge'] ?? '') === '1')
{
	$nudgeId = (int)($_GET['job'] ?? 0);
	$nudgeTok = preg_replace('~[^a-f0-9]~', '', (string)($_GET['token'] ?? ''));
	if (!is_object($USER) || !$USER->IsAdmin() || $nudgeId <= 0 || strlen($nudgeTok) !== 32)
	{
		header('Content-Type: text/plain; charset=utf-8');
		echo '0';
		die;
	}
	if (function_exists('mf_epu_external_price_job_run_or_die'))
	{
		mf_epu_external_price_job_run_or_die($nudgeId, false);
	}
	die;
}

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

/**
 * Долгий импорт CSV: снимает лимит времени PHP (без этого бывает 504 при быстром ответе nginx).
 * Таймауты прокси до PHP (fastcgi_read_timeout, proxy_read_timeout) на сервере всё равно нужно поднять для очень больших файлов.
 */
function mf_epu_bootstrap_long_import(): void
{
	if (function_exists('set_time_limit'))
	{
		@set_time_limit(0);
	}
	@ini_set('max_execution_time', '0');
	if (function_exists('ignore_user_abort'))
	{
		@ignore_user_abort(true);
	}
}

if (
	$_SERVER['REQUEST_METHOD'] === 'POST'
	&& (string)($_POST['mf_ep_job_run'] ?? '') === 'Y'
	&& is_object($USER)
	&& $USER->IsAdmin()
	&& function_exists('mf_epu_external_price_job_run_or_die')
)
{
	mf_epu_external_price_job_run_or_die((int)($_POST['job'] ?? 0), true);
	die;
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

/** @var array<int, array{use: bool, amount_per_kg: float, currency: string, rub_per_kg: float}> */
$mfEpStoreWeightJson = [];
foreach ($stores as $s)
{
	$sid = (int)($s['ID'] ?? 0);
	if ($sid <= 0)
	{
		continue;
	}
	if (function_exists('mf_ep_store_weight_uf_raw') && function_exists('mf_ep_store_weight_fields'))
	{
		$wr = mf_ep_store_weight_uf_raw($sid);
		$wf = mf_ep_store_weight_fields($sid);
		$mfEpStoreWeightJson[$sid] = [
			'use' => $wr['use'],
			'amount_per_kg' => $wr['amount_per_kg'],
			'currency' => $wr['currency'],
			'rub_per_kg' => $wf['rub_per_kg'],
		];
	}
	else
	{
		$mfEpStoreWeightJson[$sid] = [
			'use' => false,
			'amount_per_kg' => 0.0,
			'currency' => 'RUB',
			'rub_per_kg' => 0.0,
		];
	}
}

$stats = null;
$error = null;
$mfEpViewJob = null;
if (isset($_GET['import_job']))
{
	$vji = (int)($_GET['import_job'] ?? 0);
	$vjt = preg_replace('~[^a-f0-9]~', '', (string)($_GET['token'] ?? ''));
	if (
		$vji > 0
		&& strlen($vjt) === 32
		&& is_object($USER)
		&& (int)$USER->GetID() > 0
		&& function_exists('mf_external_price_import_job_get')
	)
	{
		$vjr = mf_external_price_import_job_get($vji);
		if (
			$vjr
			&& (int)($vjr['UF_USER_ID'] ?? 0) === (int)$USER->GetID()
			&& (string)($vjr['UF_TOKEN'] ?? '') === $vjt
		)
		{
			$mfEpViewJob = $vjr;
		}
	}
}

$mfEpCurrencyOptions = mf_epu_get_currency_select_options();
$mfEpCurrencyCodes = array_column($mfEpCurrencyOptions, 'code');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['mf_external_price_do'] ?? '') === 'Y')
{
	if (!check_bitrix_sessid())
	{
		$error = 'Неверная сессия (sessid). Обновите страницу.';
	}
	else
	{
		$storeId = (int)($_POST['store_id'] ?? 0);
		$feedPosted = trim((string)($_POST['feed_code'] ?? ''));
		$feedNorm = function_exists('mf_esf_normalize_feed_code') ? mf_esf_normalize_feed_code($feedPosted) : '';
		$currency = mb_strtoupper(trim((string)($_POST['currency'] ?? 'RUB')));
		$zeroMissing = isset($_POST['zero_missing']) && $_POST['zero_missing'] === 'Y';
		$weightUse = isset($_POST['weight_use']) && $_POST['weight_use'] === 'Y';
		/** В валюте прайса (см. «Валюта цен в файле»); на склад — число и код валюты, в ₽ пересчитывается при заказе. */
		$weightTariffInput = (float)str_replace(',', '.', (string)($_POST['weight_rub_kg'] ?? '0'));

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
		elseif ($feedNorm === '')
		{
			$error = 'Укажите код прайса (латиница, цифры, символы _ и -) — он фиксирует привязку файла к складу и попадает в реестр прайсов.';
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
		elseif (!in_array($currency, $mfEpCurrencyCodes, true))
		{
			$error = 'Недопустимая валюта (нет в модуле «Валюты» Bitrix).';
		}
		else
		{
			if ($weightUse && $weightTariffInput <= 0)
			{
				$error = 'Укажите тариф за 1 кг в валюте прайса (поле «Валюта цен в файле» выше), больше нуля.';
			}

			if (!empty($error))
			{
				// Ошибка валидации тарифа за вес — импорт не выполняем.
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

						// Фоновая обработка: файл на диск, задание в БД, редирект на страницу с прогрессом (без 504).
						if (!function_exists('mf_external_price_import_job_ensure_table') || !mf_external_price_import_job_ensure_table())
						{
							$error = 'Не удалось создать таблицу фоновых заданий (MySQL). Проверьте права к БД.';
						}
						else
						{
							$upTmp = (string)($_FILES['price_file']['tmp_name'] ?? '');
							$jobToken = bin2hex(random_bytes(16));
							$relJobPath = 'upload/mf_ext_price/jobs/' . $jobToken . '.csv';
							$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT']), '/\\');
							$absJobDir = $docRoot . '/upload/mf_ext_price/jobs';
							$absJobPath = $docRoot . '/' . $relJobPath;
							if (!is_dir($absJobDir) && !@mkdir($absJobDir, 0755, true) && !is_dir($absJobDir))
							{
								$error = 'Не удалось создать каталог upload/mf_ext_price/jobs/.';
							}
							elseif ($upTmp === '' || !is_uploaded_file($upTmp))
							{
								$error = 'Временный файл загрузки недоступен. Повторите отправку формы.';
							}
							elseif (!@move_uploaded_file($upTmp, $absJobPath))
							{
								$error = 'Не удалось сохранить CSV на сервере.';
								if (is_dir($absJobDir) && !is_writable($absJobDir))
								{
									$error .= ' Каталог upload/mf_ext_price/jobs/ существует, но недоступен для записи от имени PHP (проверьте владельца и chmod, обычно пользователь пула php-fpm).';
								}
							}
							else
							{
								$newJobId = mf_external_price_import_job_insert([
									'UF_TOKEN' => $jobToken,
									'UF_USER_ID' => $importUserId,
									'UF_STATUS' => 'pending',
									'UF_FILE_PATH' => $relJobPath,
									'UF_ORIG_NAME' => $importFileName,
									'UF_FILE_SIZE' => $importFileSize,
									'UF_STORE_ID' => $storeId,
									'UF_FEED_CODE' => $feedNorm,
									'UF_CURRENCY' => $currency,
									'UF_ZERO_MISSING' => $zeroMissing ? 'Y' : 'N',
									'UF_WEIGHT_USE' => $weightUse ? 'Y' : 'N',
									'UF_WEIGHT_RUB_KG' => ($weightUse && $weightTariffInput > 0) ? $weightTariffInput : 0.0,
									'UF_ROWS_TOTAL' => 0,
									'UF_ROWS_DONE' => 0,
								]);
								if ($newJobId <= 0)
								{
									@unlink($absJobPath);
									$error = 'Не удалось создать задание импорта.';
								}
								else
								{
									$queuedAt = date('Y-m-d H:i:s');
									$importLogIdQueued = 0;
									if (function_exists('mf_external_price_import_log_insert'))
									{
										$importLogIdQueued = (int)mf_external_price_import_log_insert([
											'UF_STARTED_AT' => $queuedAt,
											'UF_FINISHED_AT' => null,
											'UF_DURATION_MS' => null,
											'UF_STATUS' => 'running',
											'UF_USER_ID' => $importUserId > 0 ? $importUserId : null,
											'UF_USER_LOGIN' => $importUserLogin !== '' ? $importUserLogin : null,
											'UF_STORE_ID' => $storeId,
											'UF_STORE_XML_ID' => (string)($st['XML_ID'] ?? ''),
											'UF_STORE_TITLE' => (string)($st['TITLE'] ?? ''),
											'UF_PRICE_GROUP_ID' => $priceGroupId,
											'UF_FEED_CODE' => $feedNorm,
											'UF_INPUT_FILENAME' => $importFileName !== '' ? $importFileName : null,
											'UF_FILE_SIZE' => $importFileSize > 0 ? $importFileSize : null,
											'UF_CURRENCY' => $currency,
											'UF_ZERO_MISSING' => $zeroMissing ? 'Y' : 'N',
											'UF_WEIGHT_USE' => $weightUse ? 'Y' : 'N',
											'UF_WEIGHT_RUB_PER_KG' => ($weightUse && $weightTariffInput > 0) ? $weightTariffInput : 0.0,
											'UF_WEIGHT_MIN_RUB' => 0.0,
											'UF_JOB_ID' => $newJobId,
										]);
									}
									if ($importLogIdQueued > 0 && function_exists('mf_external_price_import_job_update'))
									{
										mf_external_price_import_job_update($newJobId, [
											'UF_IMPORT_LOG_ID' => $importLogIdQueued,
										]);
									}
									$q = [
										'import_job' => $newJobId,
										'token' => $jobToken,
									];
									if (defined('LANGUAGE_ID') && (string)LANGUAGE_ID !== '')
									{
										$q['lang'] = (string)LANGUAGE_ID;
									}
									$importLogWritten = true;
									LocalRedirect($APPLICATION->GetCurPage() . '?' . http_build_query($q));
								}
							}
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
					'UF_FEED_CODE' => $feedNorm !== '' ? $feedNorm : null,
					'UF_INPUT_FILENAME' => $importFileName !== '' ? $importFileName : null,
					'UF_FILE_SIZE' => $importFileSize > 0 ? $importFileSize : null,
					'UF_CURRENCY' => $currency,
					'UF_ZERO_MISSING' => $zeroMissing ? 'Y' : 'N',
					'UF_WEIGHT_USE' => $weightUse ? 'Y' : 'N',
					'UF_WEIGHT_RUB_PER_KG' => ($weightUse && $weightTariffInput > 0) ? $weightTariffInput : 0.0,
					'UF_WEIGHT_MIN_RUB' => 0.0,
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
$wfFeedCodeRepost = $wfRepost ? trim((string)($_POST['feed_code'] ?? '')) : '';
$wfUseChecked = $wfRepost && (string)($_POST['weight_use'] ?? '') === 'Y';
$wfRubKgStr = $wfRepost ? (string)($_POST['weight_rub_kg'] ?? '0') : '0';
$wfCurrency = $wfRepost
	? mf_epu_normalize_import_currency((string)($_POST['currency'] ?? ''), $mfEpCurrencyCodes)
	: mf_epu_normalize_import_currency(
		in_array('RUB', $mfEpCurrencyCodes, true) ? 'RUB' : '',
		$mfEpCurrencyCodes
	);
$wfWeightInputsDisabled = !$wfUseChecked;

$mfEpShowJobPanel = false;
$mfEpJobIdForJs = 0;
$mfEpJobTokenForJs = '';
if (is_array($mfEpViewJob) && (int)($mfEpViewJob['ID'] ?? 0) > 0 && function_exists('mf_external_price_import_job_get'))
{
	$mfEpViewJob = mf_external_price_import_job_get((int)$mfEpViewJob['ID']) ?? $mfEpViewJob;
	$jst0 = (string)($mfEpViewJob['UF_STATUS'] ?? '');
	if ($jst0 === 'done' && !empty($mfEpViewJob['UF_RESULT_JSON']))
	{
		$decSt = json_decode((string)$mfEpViewJob['UF_RESULT_JSON'], true);
		if (is_array($decSt))
		{
			$stats = $decSt;
		}
	}
	elseif ($jst0 === 'failed' && $error === null && !empty($mfEpViewJob['UF_ERROR_TEXT']))
	{
		$error = (string)$mfEpViewJob['UF_ERROR_TEXT'];
	}
	$mfEpShowJobPanel = in_array($jst0, ['pending', 'running'], true);
	$mfEpJobIdForJs = (int)$mfEpViewJob['ID'];
	$mfEpJobTokenForJs = (string)($mfEpViewJob['UF_TOKEN'] ?? '');
}

$mfEpPageClean = (string)($APPLICATION->GetCurPage() ?? '');
$mfEpQClean = [
	'lang' => (defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru'),
];
$mfEpNewUploadUrl = $mfEpPageClean . (strpos($mfEpPageClean, '?') !== false ? '&' : '?') . http_build_query($mfEpQClean);

?>
<p style="margin:0 0 12px 0"><a href="mf_external_price_history.php?lang=<?= mf_epu_escape($langUi) ?>">История импортов внешних прайсов</a></p>
<?php
if ($mfEpShowJobPanel && $mfEpJobIdForJs > 0 && $mfEpJobTokenForJs !== '')
{
	$sessEp = (string)bitrix_sessid();
	$langQ = (defined('LANGUAGE_ID') && (string)LANGUAGE_ID !== '') ? '&lang=' . rawurlencode((string)LANGUAGE_ID) : '';
	$pollUrl0 = $mfEpPageClean . (strpos($mfEpPageClean, '?') !== false ? '&' : '?') . 'mf_ep_job_poll=1&job=' . $mfEpJobIdForJs . '&token=' . rawurlencode($mfEpJobTokenForJs) . $langQ;
	$nudgeUrl0 = $mfEpPageClean . (strpos($mfEpPageClean, '?') !== false ? '&' : '?') . 'mf_ep_job_nudge=1&job=' . $mfEpJobIdForJs . '&token=' . rawurlencode($mfEpJobTokenForJs) . $langQ;
	?>
	<div class="adm-info-message" id="mf_ep_job_panel" style="max-width:900px;margin-bottom:16px">
		<iframe src="<?= mf_epu_escape($nudgeUrl0) ?>" style="width:0;height:0;border:0;position:absolute;left:-9999px" tabindex="-1" title=""></iframe>
		<div id="mf_ep_job_text">Подготовка…</div>
		<div style="height:10px;background:#e8e8e8;border-radius:4px;margin:10px 0;overflow:hidden">
			<div id="mf_ep_job_bar" style="height:100%;width:0;background:#1d54a8;transition:width .2s"></div>
		</div>
		<p style="margin:0 0 8px 0;font-size:12px;color:#666">Страница опрашивает сервер примерно раз в <strong>1,2&nbsp;с</strong>; числа в БД обновляются примерно на <strong>1-й</strong> строке и далее каждые <strong>10</strong> строк (пока идёт чтение большого файла счётчики могут не меняться).</p>
		<a href="<?= mf_epu_escape($mfEpNewUploadUrl) ?>">Новая загрузка (без ожидания)</a>
	</div>
	<script>
	(function () {
		var jobId = <?= (int)$mfEpJobIdForJs ?>;
		var token = <?= json_encode($mfEpJobTokenForJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
		var sess = <?= json_encode($sessEp, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
		var pollUrl = <?= json_encode($pollUrl0, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
		var runUrl = <?= json_encode($mfEpPageClean, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
		var started = false;
		function setBar(pct) {
			var b = document.getElementById('mf_ep_job_bar');
			if (b) b.style.width = Math.max(0, Math.min(100, pct)) + '%';
		}
		function setText(t) { var x = document.getElementById('mf_ep_job_text'); if (x) x.textContent = t; }
		function startRun() {
			if (started) return;
			started = true;
			var fd = new FormData();
			fd.append('mf_ep_job_run', 'Y');
			fd.append('job', String(jobId));
			fd.append('token', token);
			fd.append('sessid', sess);
			fetch(runUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
		}
		function poll() {
			fetch(pollUrl, { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d || d.ok === false) {
						setText('Не удалось получить статус. Обновите страницу.');
						return;
					}
					var st = d.status || '';
					var done = parseInt(d.rows_done, 10) || 0;
					var tot = parseInt(d.rows_total, 10) || 0;
					if (tot > 0) setBar(100 * done / tot);
					if (st === 'pending' || st === 'running') {
						setText('Идёт обработка прайса' + (tot ? (' — ' + done + ' / ' + tot + ' строк') : '…'));
						if (st === 'pending') startRun();
						setTimeout(poll, 1200);
						return;
					}
					if (st === 'done') {
						setBar(100);
						setText('Готово. Идёт обновление страницы…');
						location.reload();
						return;
					}
					if (st === 'failed') {
						setBar(0);
						setText('Ошибка: ' + (d.error || 'неизвестно'));
					}
				})
				.catch(function () { setTimeout(poll, 2000); });
		}
		poll();
	})();
	</script>
	<?php
}
?>
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
		. '; создано новых товаров: ' . (int)($stats['created'] ?? 0)
		. '; не найдено в каталоге: ' . (int)$stats['not_found']
		. '; пропущено (бренд «не сопоставлять»): ' . (int)($stats['brand_skipped'] ?? 0)
		. '; пропуск/битые строки: ' . (int)$stats['bad']
		. '; обнулено (не в файле): ' . (int)$stats['zeroed']
		. '; не записана цена (ошибка API Bitrix / кэш типа цены): ' . (int)($stats['price_write_fail'] ?? 0)
		. '. Склад: ' . mf_epu_escape((string)$stats['store']) . ' (' . mf_epu_escape((string)$stats['xml']) . '), код прайса: ' . mf_epu_escape((string)($stats['feed_code'] ?? '')) . ', валюта прайса: ' . mf_epu_escape((string)$stats['currency']) . '.';
	CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => $msg]);
	if (!empty($stats['examples_nf']))
	{
		echo '<div style="margin:10px 0;padding:10px;background:#f8f8f8;border:1px solid #ddd;max-width:900px">Примеры не найденных (до 15):<br>'
			. mf_epu_escape(implode('; ', $stats['examples_nf'])) . '</div>';
	}
}
?>

	<p>Формат CSV: колонки <strong>Производитель</strong>, <strong>Артикул</strong>, <strong>Наименование</strong> (опционально), <strong>Цена</strong>. Разделитель — точка с запятой. Ячейка <strong>Цена</strong> может быть пустой — тогда для позиции снимается закуп по этому складу (тип цены внешнего прайса). Если товара с такой парой (артикул + бренд) ещё нет в каталоге, он <strong>создаётся</strong> автоматически; наименование берётся из колонки «Наименование» или, если она пуста, из «Производитель» и «Артикул».</p>
	<p class="adm-info-message" style="max-width:900px">Импорт выполняется <strong>в фоне</strong> после загрузки файла: открывается страница с прогрессом, ответ nginx приходит сразу (обычно без 504). Если прогресс «завис», проверьте таймаут PHP-FPM <code>request_terminate_timeout</code> — фоновый процесс всё равно ограничен им.</p>
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
	<p><strong>Валюта прайса:</strong> список совпадает с валютами в <strong>Настройки → Валюты</strong>. В тип цены склада записываются <strong>исходная сумма и валюта</strong> из файла; в рубли для заказа цена переводится по <strong>текущему</strong> курсу из модуля «Валюты» (для USD/EUR при отсутствии курса — опционально ЦБ, см. <code>mf_cbr_rates_*</code> и <code>mf_external_price_no_cbr</code>).</p>

	<?php
	$mfEpSampleHref = '/bitrix/admin/mf_external_price_upload_sample.csv';
	?>
	<table class="adm-detail-content-table edit-table" style="max-width:720px">
		<tr>
			<td class="adm-detail-content-cell-l" width="40%" style="vertical-align:top">Файл CSV</td>
			<td style="vertical-align:top">
				<input type="file" name="price_file" accept=".csv,.txt" required />
				<div style="margin-top:8px;max-width:560px;color:#555;font-size:12px;line-height:1.45;">
					Ожидаются колонки с заголовками вроде <strong>Производитель</strong>, <strong>Артикул</strong>, <strong>Наименование</strong>, <strong>Цена</strong> (разделитель <code>;</code> или <code>,</code>).
					<a href="<?= mf_epu_escape($mfEpSampleHref) ?>" download="mf_external_price_upload_sample.csv" style="white-space:nowrap;">Скачать шаблон примера</a>
				</div>
				<details style="margin-top:6px;max-width:560px;font-size:12px;">
					<summary style="cursor:pointer;color:#1d54a8;">Показать пример содержимого</summary>
					<pre style="margin:8px 0 0;padding:8px;background:#f5f9fc;border:1px solid #dce7f2;overflow:auto;white-space:pre-wrap;">Производитель;Артикул;Наименование;Цена
Delta;CT12025;Аккумулятор Дельта;5,04
BRP;420256454;OIL FILTER;11,04
BRP;420256455;OIL FILTER;11,04</pre>
				</details>
			</td>
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
			<td style="vertical-align:top">Код прайса</td>
			<td>
				<input type="text" name="feed_code" value="<?= mf_epu_escape($wfFeedCodeRepost) ?>" required
					maxlength="64" autocomplete="off" style="max-width:280px;font-family:monospace"
					placeholder="например SUPPLIER_A"
					<?= empty($stores) ? ' disabled' : '' ?> />
				<div style="margin-top:6px;max-width:560px;color:#555;font-size:12px;line-height:1.45;">
					Один и тот же код используйте для всех загрузок этого прайса на выбранный склад и при частичной очистке склада по прайсу в админке. Допустимы латинские буквы, цифры, <code>_</code> и <code>-</code>.
				</div>
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
					<?php foreach ($mfEpCurrencyOptions as $mfEpCurOpt): ?>
						<?php
						$cCode = (string)($mfEpCurOpt['code'] ?? '');
						if ($cCode === '')
						{
							continue;
						}
						$cSel = ($wfCurrency === $cCode) ? ' selected' : '';
						?>
						<option value="<?= mf_epu_escape($cCode) ?>"<?= $cSel ?>><?= mf_epu_escape((string)($mfEpCurOpt['title'] ?? $cCode)) ?></option>
					<?php endforeach; ?>
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
				<label><input type="checkbox" name="weight_use" id="mf_ep_weight_use" value="Y"<?= $wfUseChecked ? ' checked' : '' ?> />
					учитывать доставку по весу в розничной цене: <strong>(Закуп_₽ + вес_кг×тариф_₽/кг) × (1+Наценка/100)</strong>, где вес единицы из каталога (sale → коэффициент единицы веса). В тип цены из CSV по-прежнему только закуп без этой надбавки.</label>
			</td>
		</tr>
		<tr>
			<td>Тариф за 1 кг веса</td>
			<td>
				<input type="text" name="weight_rub_kg" id="mf_ep_weight_rub_kg" value="<?= mf_epu_escape($wfRubKgStr) ?>" size="10"<?= $wfWeightInputsDisabled ? ' disabled' : '' ?> />
				<span style="color:#666;font-size:12px;margin-left:6px;">В той же валюте, что и «Валюта цен в файле». На складе сохраняются число и валюта; в ₽ тариф переводится при расчёте цены. Формула: (закуп + вес×тариф) × (1+наценка/100) после пересчёта в рубли по курсу на момент заказа.</span>
			</td>
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
		var cur = (w.currency || 'RUB');
		var amt = Number(w.amount_per_kg);
		var rub = Number(w.rub_per_kg);
		var lineTar = isFinite(amt) ? (fmtRub(amt) + ' ' + cur + '/кг') : '—';
		var lineRub = isFinite(rub) ? ('сейчас ≈ ' + fmtRub(rub) + ' ₽/кг по курсу') : '';
		return '<strong>Текущие настройки склада</strong> (карточка склада → поля внешнего прайса):<br>'
			+ '«Внешний прайс: учитывать вес (доставка)» — <strong>' + (w.use ? 'да' : 'нет') + '</strong>;<br>'
			+ 'Тариф за кг — <strong>' + lineTar + '</strong>' + (lineRub ? ('; ' + lineRub) : '') + '.<br>'
			+ '<span style="color:#555">При смене склада поля ниже подставляются из базы. После успешного импорта введённые значения записываются на выбранный склад.</span>';
	}
	function syncWeightInputsEnabled() {
		var chk = document.getElementById('mf_ep_weight_use');
		var rub = document.getElementById('mf_ep_weight_rub_kg');
		if (!chk || !rub) return;
		var on = chk.checked;
		rub.disabled = !on;
	}
	function applyFromMap(sid, touchInputs) {
		var hint = document.getElementById('mf_ep_store_weight_hint');
		var chk = document.getElementById('mf_ep_weight_use');
		var rub = document.getElementById('mf_ep_weight_rub_kg');
		sid = String(sid || '');
		if (!hint) return;
		var w = pickWeight(sid);
		if (!sid || !w) {
			hint.innerHTML = 'Выберите склад — здесь появятся текущие значения полей веса (UF) этого склада.';
			syncWeightInputsEnabled();
			return;
		}
		hint.innerHTML = hintHtml(w);
		if (touchInputs && chk && rub) {
			chk.checked = !!w.use;
			rub.value = fmtInput(w.amount_per_kg);
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
