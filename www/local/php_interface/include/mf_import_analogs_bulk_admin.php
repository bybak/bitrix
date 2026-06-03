<?php

declare(strict_types=1);

use Bitrix\Main\Loader;

if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
{
	die('Admin only');
}

global $APPLICATION, $USER;

if (!Loader::includeModule('iblock'))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Модуль iblock не подключён.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_analogs_import_job_lib.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_analogs_import_job_runner_inc.php';

function mf_analogs_escape(string $s): string
{
	return function_exists('htmlspecialcharsbx')
		? (string)htmlspecialcharsbx($s)
		: htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Пока только каталог с этим ID. */
const MF_ANALOGS_BULK_IBLOCK_ID = 4;

// ——— JSON poll (лёгкий ответ) ———
if ((string)($_GET['mf_analogs_job_poll'] ?? '') === '1')
{
	$jobPollId = (int)($_GET['job'] ?? 0);
	$tokenPoll = preg_replace('~[^a-f0-9]~', '', (string)($_GET['token'] ?? ''));
	header('Content-Type: application/json; charset=utf-8');
	if (!is_object($USER) || !$USER->IsAdmin() || $jobPollId <= 0 || strlen($tokenPoll) !== 32)
	{
		echo json_encode(['ok' => false, 'error' => 'Доступ запрещён.'], JSON_UNESCAPED_UNICODE);
		die;
	}
	$rowP = mf_analogs_import_job_get($jobPollId);
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
		'progress_pct' => array_key_exists('UF_PROGRESS_PCT', $rowP) && $rowP['UF_PROGRESS_PCT'] !== null && $rowP['UF_PROGRESS_PCT'] !== ''
			? max(0, min(100, (int)$rowP['UF_PROGRESS_PCT']))
			: null,
		'progress_note' => trim((string)($rowP['UF_PROGRESS_NOTE'] ?? '')),
		'progress_at' => trim((string)($rowP['UF_PROGRESS_AT'] ?? '')),
	];
	if ($stP === 'running' && !empty($rowP['UF_PROGRESS_AT']))
	{
		$ts = strtotime((string)$rowP['UF_PROGRESS_AT']);
		if ($ts > 0 && (time() - $ts) > 600)
		{
			$outP['stale_hint'] = 'Прогресс не обновлялся более 10 минут — возможно, PHP остановлен (таймаут FPM, OOM). См. mf_analogs_import.log.';
		}
	}
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

if ((string)($_GET['mf_analogs_job_nudge'] ?? '') === '1')
{
	$nudgeId = (int)($_GET['job'] ?? 0);
	$nudgeTok = preg_replace('~[^a-f0-9]~', '', (string)($_GET['token'] ?? ''));
	if (!is_object($USER) || !$USER->IsAdmin() || $nudgeId <= 0 || strlen($nudgeTok) !== 32)
	{
		header('Content-Type: text/plain; charset=utf-8');
		echo '0';
		die;
	}
	mf_analogs_import_job_run_or_die($nudgeId, false);
	die;
}

if (
	$_SERVER['REQUEST_METHOD'] === 'POST'
	&& (string)($_POST['mf_analogs_job_run'] ?? '') === 'Y'
	&& is_object($USER)
	&& $USER->IsAdmin()
)
{
	mf_analogs_import_job_run_or_die((int)($_POST['job'] ?? 0), true);
	die;
}

$errors = [];
$report = null;
$mfAiViewJob = null;

if (isset($_GET['analog_import_job']))
{
	$vji = (int)($_GET['analog_import_job'] ?? 0);
	$vjt = preg_replace('~[^a-f0-9]~', '', (string)($_GET['token'] ?? ''));
	if ($vji > 0 && strlen($vjt) === 32 && is_object($USER) && $USER->IsAdmin())
	{
		$vjr = mf_analogs_import_job_get($vji);
		if (
			$vjr
			&& (int)($vjr['UF_USER_ID'] ?? 0) === (int)$USER->GetID()
			&& (string)($vjr['UF_TOKEN'] ?? '') === $vjt
		)
		{
			$mfAiViewJob = $vjr;
		}
	}
}

// ——— Загрузка файла → фоновое задание ———
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['mf_analogs_bulk_import_do'] ?? '') === 'Y')
{
	if (!check_bitrix_sessid())
	{
		$errors[] = 'Неверная сессия (sessid).';
	}
	elseif (!is_object($USER) || !$USER->IsAdmin())
	{
		$errors[] = 'Недостаточно прав.';
	}
	else
	{
		$tmpPath = '';
		$origName = 'paste.csv';
		$file = $_FILES['FILE'] ?? null;
		if (is_array($file) && !empty($file['tmp_name']) && is_uploaded_file((string)$file['tmp_name']))
		{
			$tmpPath = (string)$file['tmp_name'];
			$origName = (string)($file['name'] ?? 'import.csv');
		}
		else
		{
			$text = trim((string)($_POST['TEXT'] ?? ''));
			if ($text !== '')
			{
				$tmpPath = (string)@tempnam(sys_get_temp_dir(), 'mfaib_');
				if ($tmpPath !== '' && @file_put_contents($tmpPath, $text) === false)
				{
					$tmpPath = '';
				}
				$origName = 'paste.csv';
			}
		}

		if ($tmpPath === '' || !is_readable($tmpPath))
		{
			$errors[] = 'Выберите CSV-файл или вставьте текст CSV.';
		}
		elseif (!mf_analogs_import_job_ensure_table())
		{
			$errors[] = 'Не удалось создать таблицу фоновых заданий (MySQL).';
		}
		else
		{
			$jobToken = bin2hex(random_bytes(16));
			$relJobPath = 'upload/mf_analogs_import/jobs/' . $jobToken . '.csv';
			$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT']), '/\\');
			$absJobDir = $docRoot . '/upload/mf_analogs_import/jobs';
			$absJobPath = $docRoot . '/' . $relJobPath;
			if (!is_dir($absJobDir) && !@mkdir($absJobDir, 0755, true) && !is_dir($absJobDir))
			{
				$errors[] = 'Не удалось создать каталог upload/mf_analogs_import/jobs/.';
			}
			elseif (is_uploaded_file($tmpPath))
			{
				if (!@move_uploaded_file($tmpPath, $absJobPath))
				{
					$errors[] = 'Не удалось сохранить CSV на сервере.';
				}
			}
			elseif (!@rename($tmpPath, $absJobPath))
			{
				$errors[] = 'Не удалось сохранить CSV на сервере.';
				@unlink($tmpPath);
			}

			if (empty($errors))
			{
				$newJobId = mf_analogs_import_job_insert([
					'UF_TOKEN' => $jobToken,
					'UF_USER_ID' => (int)$USER->GetID(),
					'UF_STATUS' => 'pending',
					'UF_FILE_PATH' => $relJobPath,
					'UF_ORIG_NAME' => $origName,
					'UF_FILE_SIZE' => (int)@filesize($absJobPath),
					'UF_IBLOCK_ID' => MF_ANALOGS_BULK_IBLOCK_ID,
					'UF_ROWS_TOTAL' => 0,
					'UF_ROWS_DONE' => 0,
				]);
				if ($newJobId <= 0)
				{
					@unlink($absJobPath);
					$errors[] = 'Не удалось создать задание импорта.';
				}
				else
				{
					$q = [
						'analog_import_job' => $newJobId,
						'token' => $jobToken,
					];
					if (defined('LANGUAGE_ID') && (string)LANGUAGE_ID !== '')
					{
						$q['lang'] = (string)LANGUAGE_ID;
					}
					LocalRedirect($APPLICATION->GetCurPage() . '?' . http_build_query($q));
				}
			}
		}
	}
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$APPLICATION->SetTitle('Импорт аналогов (общий)');

if (!empty($errors))
{
	\CAdminMessage::ShowMessage([
		'TYPE' => 'ERROR',
		'MESSAGE' => implode('<br>', array_map('mf_analogs_escape', $errors)),
		'HTML' => true,
	]);
}

$mfAiShowJobPanel = false;
$mfAiJobIdForJs = 0;
$mfAiJobTokenForJs = '';
$mfAiPageClean = (string)($APPLICATION->GetCurPage() ?? '');
$langUi = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
$mfAiQClean = ['lang' => $langUi];
$mfAiNewUploadUrl = $mfAiPageClean . (strpos($mfAiPageClean, '?') !== false ? '&' : '?') . http_build_query($mfAiQClean);

if (is_array($mfAiViewJob) && (int)($mfAiViewJob['ID'] ?? 0) > 0)
{
	$mfAiViewJob = mf_analogs_import_job_get((int)$mfAiViewJob['ID']) ?? $mfAiViewJob;
	$jst0 = (string)($mfAiViewJob['UF_STATUS'] ?? '');
	if ($jst0 === 'done' && !empty($mfAiViewJob['UF_RESULT_JSON']))
	{
		$report = json_decode((string)$mfAiViewJob['UF_RESULT_JSON'], true);
	}
	elseif ($jst0 === 'failed' && !empty($mfAiViewJob['UF_ERROR_TEXT']))
	{
		\CAdminMessage::ShowMessage([
			'TYPE' => 'ERROR',
			'MESSAGE' => mf_analogs_escape((string)$mfAiViewJob['UF_ERROR_TEXT']),
		]);
	}
	$mfAiShowJobPanel = in_array($jst0, ['pending', 'running'], true);
	$mfAiJobIdForJs = (int)$mfAiViewJob['ID'];
	$mfAiJobTokenForJs = (string)($mfAiViewJob['UF_TOKEN'] ?? '');
}

if (is_array($report))
{
	$msg = 'Готово. Строк: ' . (int)($report['rows'] ?? 0)
		. ', связей добавлено: ' . (int)($report['linked'] ?? 0) . '.';
	$nf = (array)($report['not_found'] ?? []);
	$nfMore = (int)($report['not_found_more'] ?? 0);
	if ($nf !== [] || $nfMore > 0)
	{
		$msg .= '<br>Не найдены/не распознаны'
			. ($nfMore > 0 ? ' (показаны первые ' . count($nf) . ', ещё ' . $nfMore . ')' : '')
			. ':<br><pre style="white-space:pre-wrap;max-height:240px;overflow:auto;">'
			. mf_analogs_escape(implode("\n", $nf))
			. '</pre>';
	}
	\CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => $msg, 'HTML' => true]);
}

?>

<div style="max-width: 980px;">
	<div style="margin: 8px 0 12px 0; color:#666;">
		Связываются только уже существующие в каталоге товары (по бренду и артикулу); новые карточки здесь не создаются.
		<br>Если <b>в одной строке файла</b> указано несколько оригиналов, они дополнительно связываются <b>между собой</b> как аналоги (попарно). Другие строки файла на это не влияют.
		<br><br>
		Формат CSV (разделитель <code>;</code>): заголовки <code>Бренд</code>, <code>Артикул</code>, <code>Оригиналы</code> — порядок колонок может быть любым.
		<pre style="white-space:pre-wrap;max-height:160px;overflow:auto;">Бренд;Артикул;Оригиналы
Yamaha;123;Yamaha:1HP-F582T-00-00, Yamaha:BB5-F514A-00-00</pre>
		<p style="margin:14px 0 10px 0;">
			<strong>Шаблон:</strong>
			<a href="mf_import_analogs_bulk_template.csv" download="import_analogov_shablon.csv">скачать CSV</a>
		</p>
		<p class="adm-info-message" style="max-width:900px;margin-top:12px">
			Импорт выполняется <strong>в фоне</strong> после загрузки файла: можно закрыть другие вкладки админки, витрина не блокируется сессией.
			Перед обработкой строк — <strong>пакетный SQL</strong> по уникальным парам «бренд+артикул» из файла (<code>MF_BRAND_NORM</code> + <code>MF_ARTICLE_NORM</code>).
			Редкие промахи — прежний поиск с fallback в PHP. Прогресс: каждые 50 строк. Лог: <code>mf_analogs_import.log</code>.
		</p>
	</div>

<?php
if ($mfAiShowJobPanel && $mfAiJobIdForJs > 0 && $mfAiJobTokenForJs !== '')
{
	$sessAi = (string)bitrix_sessid();
	$langQ = (defined('LANGUAGE_ID') && (string)LANGUAGE_ID !== '') ? '&lang=' . rawurlencode((string)LANGUAGE_ID) : '';
	$pollUrl0 = $mfAiPageClean . (strpos($mfAiPageClean, '?') !== false ? '&' : '?')
		. 'mf_analogs_job_poll=1&job=' . $mfAiJobIdForJs . '&token=' . rawurlencode($mfAiJobTokenForJs) . $langQ;
	$nudgeUrl0 = $mfAiPageClean . (strpos($mfAiPageClean, '?') !== false ? '&' : '?')
		. 'mf_analogs_job_nudge=1&job=' . $mfAiJobIdForJs . '&token=' . rawurlencode($mfAiJobTokenForJs) . $langQ;
	?>
	<div class="adm-info-message" id="mf_ai_job_panel" style="max-width:900px;margin-bottom:16px">
		<iframe src="<?= mf_analogs_escape($nudgeUrl0) ?>" style="width:0;height:0;border:0;position:absolute;left:-9999px" tabindex="-1" title=""></iframe>
		<div id="mf_ai_job_text">Подготовка импорта аналогов…</div>
		<div style="height:10px;background:#e8e8e8;border-radius:4px;margin:10px 0;overflow:hidden">
			<div id="mf_ai_job_bar" style="height:100%;width:0;background:#1d54a8;transition:width .2s"></div>
		</div>
		<p style="margin:0 0 8px 0;font-size:12px;color:#666">
			Опрос статуса — примерно раз в <strong>1,2&nbsp;с</strong>. Обработка идёт в отдельном запросе PHP после ответа браузеру.
		</p>
		<a href="<?= mf_analogs_escape($mfAiNewUploadUrl) ?>">Новая загрузка (без ожидания)</a>
	</div>
	<script>
	(function () {
		var jobId = <?= (int)$mfAiJobIdForJs ?>;
		var token = <?= json_encode($mfAiJobTokenForJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
		var sess = <?= json_encode($sessAi, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
		var pollUrl = <?= json_encode($pollUrl0, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
		var runUrl = <?= json_encode($mfAiPageClean, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
		var started = false;
		function setBar(pct) {
			var b = document.getElementById('mf_ai_job_bar');
			if (b) { b.style.width = Math.max(0, Math.min(100, pct)) + '%'; }
		}
		function setText(t) {
			var x = document.getElementById('mf_ai_job_text');
			if (x) { x.textContent = t; }
		}
		function startRun() {
			if (started) { return; }
			started = true;
			var fd = new FormData();
			fd.append('mf_analogs_job_run', 'Y');
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
					var ppRaw = d.progress_pct;
					var pctDb = ppRaw !== undefined && ppRaw !== null ? parseInt(ppRaw, 10) : NaN;
					var pct = !isFinite(pctDb) ? NaN : Math.max(0, Math.min(100, pctDb));
					if (!isFinite(pct) && tot > 0) {
						pct = Math.round(100 * done / tot);
					}
					setBar(isFinite(pct) ? pct : (st === 'done' ? 100 : 0));
					var note = (typeof d.progress_note === 'string' && d.progress_note.trim() !== '')
						? d.progress_note.trim()
						: 'Импорт аналогов…';
					if (d.stale_hint) {
						note += '. ' + d.stale_hint;
					} else if (d.progress_at) {
						note += ' (обновлено ' + d.progress_at + ')';
					}
					if (st === 'pending' || st === 'running') {
						setText(note + (tot > 0 ? (' — строк ' + done + ' / ' + tot) : ''));
						if (st === 'pending') { startRun(); }
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
						return;
					}
					setText('Статус: ' + st);
					setTimeout(poll, 1200);
				})
				.catch(function () {
					setText('Ошибка сети при опросе статуса.');
					setTimeout(poll, 2500);
				});
		}
		startRun();
		poll();
	})();
	</script>
	<?php
}
?>

	<form method="post" enctype="multipart/form-data" action="<?= mf_analogs_escape($mfAiPageClean) ?>?lang=<?= mf_analogs_escape($langUi) ?>">
		<?= bitrix_sessid_post() ?>
		<input type="hidden" name="mf_analogs_bulk_import_do" value="Y">

		<table class="adm-detail-content-table edit-table" style="width:100%;">
			<tbody>
			<tr>
				<td class="adm-detail-content-cell-l" width="40%">Инфоблок каталога</td>
				<td class="adm-detail-content-cell-r">
					<input type="number" value="<?= (int)MF_ANALOGS_BULK_IBLOCK_ID ?>" min="1" step="1" style="width:120px;" disabled>
					<span style="margin-left:8px;color:#888;font-size:12px;">сейчас не меняется</span>
				</td>
			</tr>
			<tr>
				<td class="adm-detail-content-cell-l">Файл</td>
				<td class="adm-detail-content-cell-r">
					<input type="file" name="FILE" accept=".csv,.txt,text/plain">
				</td>
			</tr>
			<tr>
				<td class="adm-detail-content-cell-l">Или вставить CSV</td>
				<td class="adm-detail-content-cell-r">
					<textarea name="TEXT" rows="10" style="width:100%;max-width:740px;"></textarea>
				</td>
			</tr>
			</tbody>
		</table>

		<div style="margin-top:12px;">
			<input type="submit" class="adm-btn-save" value="Загрузить и импортировать в фоне">
		</div>
	</form>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
