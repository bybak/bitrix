<?php

declare(strict_types=1);

/**
 * Админка: пакетное удаление товаров по списку ID из CSV (первая колонка — ID элемента инфоблока каталога).
 */

use Bitrix\Catalog\ProductTable;
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

$mfCdcIblockId = (int)(getenv('MF_SUPPLIER_ORDERS_IBLOCK_ID') ?: 0);
if ($mfCdcIblockId <= 0 && class_exists(\Bitrix\Main\Config\Option::class))
{
	$mfCdcIblockId = (int)\Bitrix\Main\Config\Option::get('mf.supplier_orders', 'catalog_iblock_id', '0');
}
if ($mfCdcIblockId <= 0)
{
	$mfCdcIblockId = 4;
}

const MF_CDC_SESSION_TOKEN = 'mf_cdc_del_token';
const MF_CDC_PENDING_PREFIX = 'mf_cdc_pending_';

/**
 * @return int[]
 */
function mf_cdc_allowed_catalog_iblocks(int $productIblockId): array
{
	$out = [$productIblockId];
	if ($productIblockId <= 0 || !class_exists(\CCatalogSKU::class))
	{
		return $out;
	}
	$skuInfo = \CCatalogSKU::GetInfoByProductIBlock($productIblockId);
	if (is_array($skuInfo) && !empty($skuInfo['IBLOCK_ID']))
	{
		$out[] = (int)$skuInfo['IBLOCK_ID'];
	}

	return array_values(array_unique(array_filter($out, static fn (int $v) => $v > 0)));
}

function mf_cdc_pending_file_path(string $token): string
{
	$token = strtolower(preg_replace('~[^a-f0-9]~', '', $token) ?? '');
	if (strlen($token) !== 32)
	{
		return '';
	}
	$dir = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/upload/tmp';
	if (!is_dir($dir))
	{
		@mkdir($dir, 0775, true);
	}

	return $dir . '/' . MF_CDC_PENDING_PREFIX . $token . '.json';
}

/**
 * @param list<int> $ids
 */
function mf_cdc_save_pending_ids(array $ids): string
{
	$token = bin2hex(random_bytes(16));
	$path = mf_cdc_pending_file_path($token);
	if ($path === '')
	{
		return '';
	}
	$payload = json_encode(['ids' => array_values($ids)]);
	if ($payload === false || file_put_contents($path, $payload) === false)
	{
		return '';
	}

	return $token;
}

/**
 * @return list<int>|null
 */
function mf_cdc_load_pending_ids(string $token): ?array
{
	$path = mf_cdc_pending_file_path($token);
	if ($path === '' || !is_file($path))
	{
		return null;
	}
	$raw = file_get_contents($path);
	if ($raw === false || $raw === '')
	{
		return null;
	}
	$data = json_decode($raw, true);
	if (!is_array($data) || !isset($data['ids']) || !is_array($data['ids']))
	{
		return null;
	}
	$out = [];
	foreach ($data['ids'] as $v)
	{
		$i = (int)$v;
		if ($i > 0)
		{
			$out[] = $i;
		}
	}

	return $out;
}

function mf_cdc_unlink_pending_file(string $token): void
{
	$p = mf_cdc_pending_file_path($token);
	if ($p !== '' && is_file($p))
	{
		@unlink($p);
	}
}

function mf_cdc_clear_pending_session(?string $token = null): void
{
	if ($token !== null && $token !== '')
	{
		mf_cdc_unlink_pending_file($token);
	}
	elseif (!empty($_SESSION[MF_CDC_SESSION_TOKEN]) && is_string($_SESSION[MF_CDC_SESSION_TOKEN]))
	{
		mf_cdc_unlink_pending_file($_SESSION[MF_CDC_SESSION_TOKEN]);
	}
	unset($_SESSION[MF_CDC_SESSION_TOKEN]);
}

/**
 * @return list<int>
 */
function mf_cdc_parse_ids_from_csv(string $path): array
{
	$content = file_get_contents($path);
	if ($content === false || $content === '')
	{
		return [];
	}
	if (str_starts_with($content, "\xEF\xBB\xBF"))
	{
		$content = substr($content, 3);
	}
	$lines = preg_split('~\r\n|\n|\r~', $content) ?: [];
	$ids = [];
	foreach ($lines as $line)
	{
		$line = trim($line);
		if ($line === '')
		{
			continue;
		}
		$delim = str_contains($line, ';') ? ';' : ',';
		$cells = str_getcsv($line, $delim);
		$first = trim((string)($cells[0] ?? ''));
		if ($first === '' || !ctype_digit($first))
		{
			continue;
		}
		$ids[] = (int)$first;
	}

	return array_values(array_unique($ids));
}

/**
 * ID из файла, по которым элемент есть и в разрешённых инфоблоках (будут удалены при успешном CIBlockElement::Delete).
 *
 * @param list<int>        $ids
 * @param array<int, int> $allowedIblocks
 * @return list<int>
 */
function mf_cdc_filter_eligible_ids(array $ids, array $allowedIblocks): array
{
	$eligible = [];
	foreach ($ids as $pid)
	{
		$pid = (int)$pid;
		if ($pid <= 0)
		{
			continue;
		}
		$el = \CIBlockElement::GetList(
			[],
			['ID' => $pid, 'CHECK_PERMISSIONS' => 'N'],
			false,
			false,
			['ID', 'IBLOCK_ID']
		)->Fetch();
		if (!is_array($el))
		{
			continue;
		}
		$ibEl = (int)($el['IBLOCK_ID'] ?? 0);
		if (in_array($ibEl, $allowedIblocks, true))
		{
			$eligible[] = $pid;
		}
	}

	return $eligible;
}

/**
 * @param int[] $allowedIblocks
 * @return array{ok: bool, message: string}
 */
function mf_cdc_delete_one_catalog_element(int $elementId, array $allowedIblocks): array
{
	global $APPLICATION;

	$elementId = (int)$elementId;
	if ($elementId <= 0)
	{
		return ['ok' => false, 'message' => 'Некорректный ID'];
	}

	$el = \CIBlockElement::GetList(
		[],
		['ID' => $elementId, 'CHECK_PERMISSIONS' => 'N'],
		false,
		false,
		['ID', 'IBLOCK_ID', 'NAME']
	)->Fetch();
	if (!is_array($el))
	{
		return ['ok' => false, 'message' => 'Элемент не найден'];
	}

	$ibEl = (int)($el['IBLOCK_ID'] ?? 0);
	if (!in_array($ibEl, $allowedIblocks, true))
	{
		return ['ok' => false, 'message' => 'IBLOCK_ID ' . $ibEl . ' не каталог (ожид. ' . implode(', ', $allowedIblocks) . ')'];
	}

	$prod = \CCatalogProduct::GetByID($elementId);
	$type = $prod ? (int)$prod['TYPE'] : 0;

	if ($type === ProductTable::TYPE_SKU && class_exists(\CCatalogSKU::class))
	{
		$list = \CCatalogSKU::getOffersList($elementId, $ibEl, [], [], [], [], ['ID' => 'ASC']);
		if (!empty($list[$elementId]) && is_array($list[$elementId]))
		{
			foreach ($list[$elementId] as $offerRow)
			{
				$oid = (int)($offerRow['ID'] ?? 0);
				if ($oid <= 0)
				{
					continue;
				}
				if (!\CIBlockElement::Delete($oid))
				{
					$ex = $APPLICATION->GetException();

					return ['ok' => false, 'message' => 'Оффер ' . $oid . ': ' . ($ex ? $ex->GetString() : 'ошибка удаления')];
				}
			}
		}
	}

	if (!\CIBlockElement::Delete($elementId))
	{
		$ex = $APPLICATION->GetException();

		return ['ok' => false, 'message' => $ex ? $ex->GetString() : 'CIBlockElement::Delete'];
	}

	return ['ok' => true, 'message' => ''];
}

/**
 * @param list<int> $ids
 * @return array{TYPE: string, MESSAGE: string, DETAILS: string}
 */
function mf_cdc_run_delete_list(array $ids, array $allowedIblocks): array
{
	$ok = 0;
	$fail = 0;
	$errors = [];
	foreach ($ids as $pid)
	{
		$r = mf_cdc_delete_one_catalog_element((int)$pid, $allowedIblocks);
		if ($r['ok'])
		{
			$ok++;
		}
		else
		{
			$fail++;
			if (count($errors) < 40)
			{
				$errors[] = 'ID ' . $pid . ': ' . $r['message'];
			}
		}
	}

	$msg = 'Уникальных ID в списке: ' . count($ids) . '. Удалено: ' . $ok . ', ошибок: ' . $fail . '.';

	return [
		'TYPE' => $fail > 0 ? 'ERROR' : 'OK',
		'MESSAGE' => $msg,
		'DETAILS' => $errors !== [] ? '<pre style="white-space:pre-wrap">' . mf_cdc_h(implode("\n", $errors)) . '</pre>' : '',
	];
}

/**
 * @param list<int> $ids
 * @return array{TYPE: string, MESSAGE: string, DETAILS: string}
 */
function mf_cdc_run_dry_list(array $ids, array $allowedIblocks): array
{
	$ok = 0;
	$fail = 0;
	$errors = [];
	foreach ($ids as $pid)
	{
		$el = \CIBlockElement::GetList(
			[],
			['ID' => $pid, 'CHECK_PERMISSIONS' => 'N'],
			false,
			false,
			['ID', 'IBLOCK_ID', 'NAME']
		)->Fetch();
		if (!is_array($el))
		{
			$fail++;
			if (count($errors) < 40)
			{
				$errors[] = 'ID ' . $pid . ': элемент не найден';
			}
			continue;
		}
		$ibEl = (int)($el['IBLOCK_ID'] ?? 0);
		if (!in_array($ibEl, $allowedIblocks, true))
		{
			$fail++;
			if (count($errors) < 40)
			{
				$errors[] = 'ID ' . $pid . ': IBLOCK_ID ' . $ibEl . ' не в списке каталога';
			}
			continue;
		}
		$ok++;
	}
	$msg = 'Проверка без удаления. Уникальных ID в файле: ' . count($ids) . '. '
		. 'Готово к удалению: ' . $ok . ', проблем: ' . $fail . '.';

	return [
		'TYPE' => $fail > 0 ? 'ERROR' : 'OK',
		'MESSAGE' => $msg,
		'DETAILS' => $errors !== [] ? '<pre style="white-space:pre-wrap">' . mf_cdc_h(implode("\n", $errors)) . '</pre>' : '',
	];
}

function mf_cdc_h(?string $s): string
{
	$s = (string)$s;

	return function_exists('htmlspecialcharsbx') ? (string)htmlspecialcharsbx($s) : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$APPLICATION->SetTitle('Удаление товаров по CSV');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$allowedIblocks = mf_cdc_allowed_catalog_iblocks($mfCdcIblockId);
$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';

$adminPage = (string)$APPLICATION->GetCurPage();
$adminPageQs = $adminPage . '?lang=' . rawurlencode($lang);

$resultBlock = null;
$confirmState = null;

if (
	$_SERVER['REQUEST_METHOD'] === 'GET'
	&& (string)($_GET['mf_cdc_cancel'] ?? '') === 'Y'
	&& check_bitrix_sessid()
) {
	mf_cdc_clear_pending_session();
	LocalRedirect($adminPageQs);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid())
{
	$action = (string)($_POST['mf_cdc_action'] ?? '');

	if ($action === 'cancel')
	{
		mf_cdc_clear_pending_session();
		LocalRedirect($adminPageQs);
	}

	if ($action === 'confirm')
	{
		@set_time_limit(0);
		$postToken = (string)($_POST['mf_cdc_token'] ?? '');
		$sessToken = isset($_SESSION[MF_CDC_SESSION_TOKEN]) ? (string)$_SESSION[MF_CDC_SESSION_TOKEN] : '';
		if ($postToken === '' || $sessToken === '' || !hash_equals($sessToken, $postToken))
		{
			$resultBlock = ['TYPE' => 'ERROR', 'MESSAGE' => 'Сессия подтверждения устарела. Загрузите CSV снова.'];
			mf_cdc_clear_pending_session($sessToken !== '' ? $sessToken : null);
		}
		else
		{
			$ids = mf_cdc_load_pending_ids($sessToken);
			if ($ids === null || $ids === [])
			{
				$resultBlock = ['TYPE' => 'ERROR', 'MESSAGE' => 'Не удалось прочитать сохранённый список ID. Повторите загрузку файла.'];
				mf_cdc_clear_pending_session($sessToken);
			}
			else
			{
				$resultBlock = mf_cdc_run_delete_list($ids, $allowedIblocks);
				mf_cdc_clear_pending_session($sessToken);
			}
		}
	}

	if (
		$resultBlock === null
		&& (string)($_POST['mf_cdc_go'] ?? '') === 'Y'
	) {
		@set_time_limit(0);
		$dry = ((string)($_POST['mf_cdc_dry'] ?? '') === 'Y');

		if (!isset($_FILES['mf_cdc_file']) || !is_array($_FILES['mf_cdc_file']))
		{
			$resultBlock = ['TYPE' => 'ERROR', 'MESSAGE' => 'Файл не получен.'];
		}
		else
		{
			$err = (int)($_FILES['mf_cdc_file']['error'] ?? UPLOAD_ERR_NO_FILE);
			if ($err !== UPLOAD_ERR_OK)
			{
				$resultBlock = ['TYPE' => 'ERROR', 'MESSAGE' => 'Ошибка загрузки файла (код ' . $err . ').'];
			}
			else
			{
				$tmp = (string)($_FILES['mf_cdc_file']['tmp_name'] ?? '');
				if ($tmp === '' || !is_uploaded_file($tmp))
				{
					$resultBlock = ['TYPE' => 'ERROR', 'MESSAGE' => 'Временный файл недоступен.'];
				}
				else
				{
					$ids = mf_cdc_parse_ids_from_csv($tmp);
					if ($ids === [])
					{
						$resultBlock = ['TYPE' => 'ERROR', 'MESSAGE' => 'В файле нет ни одной строки с числовым ID в первой колонке.'];
					}
					elseif ($dry)
					{
						$resultBlock = mf_cdc_run_dry_list($ids, $allowedIblocks);
					}
					else
					{
						mf_cdc_clear_pending_session();

						$eligible = mf_cdc_filter_eligible_ids($ids, $allowedIblocks);
						$token = mf_cdc_save_pending_ids($ids);
						if ($token === '')
						{
							$resultBlock = ['TYPE' => 'ERROR', 'MESSAGE' => 'Не удалось сохранить список для подтверждения (проверьте каталог /upload/tmp).'];
						}
						else
						{
							$_SESSION[MF_CDC_SESSION_TOKEN] = $token;
							$first10 = array_slice($eligible, 0, 10);
							$confirmState = [
								'token' => $token,
								'eligible_count' => count($eligible),
								'total_ids' => count($ids),
								'first10' => $first10,
							];
						}
					}
				}
			}
		}
	}
}

if (is_array($resultBlock))
{
	\CAdminMessage::ShowMessage($resultBlock);
}

?>
<div class="adm-detail-content-wrap" style="padding:12px;">
	<?php
	if (is_array($confirmState))
	{
		$ec = (int)($confirmState['eligible_count'] ?? 0);
		$ti = (int)($confirmState['total_ids'] ?? 0);
		$f10 = $confirmState['first10'] ?? [];
		$f10s = is_array($f10) ? implode(', ', array_map(static fn ($v) => (string)(int)$v, $f10)) : '';
		$tok = mf_cdc_h((string)($confirmState['token'] ?? ''));
		?>
		<div class="adm-info-message" style="background:#fff3cd;border-color:#ffc107;">
			<p style="margin:0 0 12px 0;font-size:14px;">
				<strong>Подтверждение удаления</strong>
			</p>
			<ul style="margin:0 0 12px 18px;line-height:1.5;">
				<li>В файле уникальных ID: <strong><?= $ti ?></strong>.</li>
				<li>Будет предпринята попытка удалить элементы каталога (есть в нужном инфоблоке): <strong><?= $ec ?></strong>.</li>
				<li>Первые 10 ID из списка допустимых к удалению: <strong><?= $f10s !== '' ? mf_cdc_h($f10s) : '—' ?></strong></li>
			</ul>
			<p style="margin:0 0 12px 0;color:#666;">
				Остальные ID из файла будут обработаны при подтверждении; по ним возможны ошибки (нет элемента, неверный инфоблок) — они попадут в отчёт.
			</p>
			<form method="post" style="display:inline-block;margin-right:8px;" action="">
				<?= bitrix_sessid_post() ?>
				<input type="hidden" name="mf_cdc_action" value="confirm">
				<input type="hidden" name="mf_cdc_token" value="<?= $tok ?>">
				<input type="submit" class="adm-btn-save" value="OK — подтвердить удаление">
			</form>
			<form method="post" style="display:inline-block;" action="">
				<?= bitrix_sessid_post() ?>
				<input type="hidden" name="mf_cdc_action" value="cancel">
				<input type="submit" value="Отмена">
			</form>
		</div>
		<p><a href="<?= mf_cdc_h($adminPageQs . '&mf_cdc_cancel=Y&' . bitrix_sessid_get()) ?>">Сбросить и загрузить другой файл</a></p>
		<?php
	}
	else
	{
		?>
	<p class="adm-info-message">
		Загрузите CSV: в <strong>первой колонке</strong> — ID элемента каталога (как в выгрузке).
		Разделитель — точка с запятой или запятая; строка с нечисловым первым полем пропускается (удобно для заголовка <code>id</code>).
		Удалению подлежат только элементы инфоблока товаров (<code>IBLOCK_ID=<?= (int)$mfCdcIblockId ?></code>)
		и инфоблока ТП: <code><?= mf_cdc_h(implode(', ', $allowedIblocks)) ?></code>.
		Для товара с SKU сначала удаляются офферы, затем родитель. Операция <strong>необратима</strong>.
	</p>
	<p class="adm-info-message" style="background:#f8f9fa;">
		Если снять галку «Только проверить», после загрузки файла откроется окно подтверждения
		(сколько позиций готово к удалению и первые 10 ID), затем нужно нажать <strong>OK</strong> или <strong>Отмена</strong>.
	</p>
	<form method="post" enctype="multipart/form-data" action="">
		<?= bitrix_sessid_post() ?>
		<input type="hidden" name="mf_cdc_go" value="Y">
		<table class="internal" style="max-width:640px;">
			<tr>
				<td>CSV-файл</td>
				<td><input type="file" name="mf_cdc_file" accept=".csv,text/csv" required></td>
			</tr>
			<tr>
				<td></td>
				<td>
					<label>
						<input type="checkbox" name="mf_cdc_dry" value="Y" checked>
						Только проверить (не удалять, посчитать строки с ID)
					</label>
				</td>
			</tr>
			<tr>
				<td></td>
				<td><input type="submit" class="adm-btn-save" value="Выполнить"></td>
			</tr>
		</table>
	</form>
		<?php
	}
	?>
</div>
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
