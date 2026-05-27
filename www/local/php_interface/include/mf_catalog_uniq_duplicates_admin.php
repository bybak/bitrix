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

$libCandidates = [
	$_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_catalog_uniq_duplicates_lib.php',
	$_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_catalog_uniq_duplicates_lib.php',
];
$libPath = null;
foreach ($libCandidates as $p)
{
	if (is_file($p))
	{
		$libPath = $p;
		break;
	}
}
if ($libPath === null)
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не найден mf_catalog_uniq_duplicates_lib.php']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}
require_once $libPath;

$cdcLib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_catalog_delete_by_csv_lib.php';
if (!is_file($cdcLib))
{
	$cdcLib = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_catalog_delete_by_csv_lib.php';
}
if (is_file($cdcLib))
{
	require_once $cdcLib;
}

const MF_CUD_SESSION_PENDING = 'mf_cud_pending_delete';

function mf_cud_h(?string $s): string
{
	$s = (string)$s;

	return function_exists('htmlspecialcharsbx') ? (string)htmlspecialcharsbx($s) : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$iblockId = mf_cud_catalog_iblock_id();
$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
$curAdminPage = (string)$APPLICATION->GetCurPage();
$baseQs = $curAdminPage . '?lang=' . rawurlencode($lang);

$perPage = max(5, min(500, (int)($_REQUEST['per_page'] ?? 20)));
$page = max(1, (int)($_REQUEST['page'] ?? 1));
$activeOnly = (string)($_REQUEST['active_only'] ?? 'Y') !== 'N';
$includeRedirect = (string)($_REQUEST['include_redirect'] ?? '') === 'Y';
$includeEmpty = (string)($_REQUEST['include_empty'] ?? '') === 'Y';
$groupBy = function_exists('mf_cud_normalize_group_by')
	? mf_cud_normalize_group_by((string)($_REQUEST['group_by'] ?? 'uniq_key'))
	: 'uniq_key';

$opts = [
	'active_only' => $activeOnly,
	'include_redirect' => $includeRedirect,
	'include_empty_keys' => $includeEmpty,
	'group_by' => $groupBy,
];

$filterQs = http_build_query([
	'lang' => $lang,
	'per_page' => $perPage,
	'active_only' => $activeOnly ? 'Y' : 'N',
	'include_redirect' => $includeRedirect ? 'Y' : 'N',
	'include_empty' => $includeEmpty ? 'Y' : 'N',
	'group_by' => $groupBy,
]);

$mfCudGroupByLabel = match ($groupBy) {
	'element_code' => 'символьный код (CODE → /products/…/)',
	'article_brand_norm' => 'норм. артикул + норм. бренд',
	default => 'MF_UNIQ_KEY (свойство)',
};
$mfCudEmptyCheckboxLabel = match ($groupBy) {
	'element_code' => 'Пустой символьный код (CODE)',
	'article_brand_norm' => 'Пустой артикул (норм / CML2)',
	default => 'Пустой MF_UNIQ_KEY',
};
$mfCudGroupFieldLabel = match ($groupBy) {
	'element_code' => 'Символьный код (CODE)',
	'article_brand_norm' => 'Ключ (артикул_норм + бренд_норм)',
	default => 'MF_UNIQ_KEY',
};

$APPLICATION->SetTitle('Дубликаты каталога — ' . $mfCudGroupByLabel);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$mode = (string)($_GET['mode'] ?? '');

if ($mode === 'confirm' && $_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid())
{
	$pending = $_SESSION[MF_CUD_SESSION_PENDING] ?? null;
	if (!is_array($pending) || empty($pending['delete_ids']) || !is_array($pending['delete_ids']))
	{
		\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Нет сохранённого списка на удаление. Отметьте товары заново.']);
		echo '<p><a href="' . mf_cud_h($baseQs) . '">← К списку дублей</a></p>';
	}
	else
	{
		@set_time_limit(0);
		$deleteIds = array_values(array_unique(array_filter(array_map('intval', $pending['delete_ids']), static fn (int $v) => $v > 0)));
		$res = mf_cud_delete_elements($deleteIds, $iblockId);
		unset($_SESSION[MF_CUD_SESSION_PENDING]);

		$msg = 'Удалено карточек: ' . (int)$res['deleted'] . ' из ' . count($deleteIds) . '.';
		if ((int)($res['purged'] ?? 0) > 0)
		{
			$msg .= ' Очищено хвостов свойств (ID без карточки в БД): ' . (int)$res['purged'] . '.';
		}
		if (!empty($res['failed']))
		{
			$msg .= ' Ошибок: ' . count($res['failed']) . '.';
		}
		$okType = empty($res['failed']) && ((int)$res['deleted'] > 0 || (int)($res['purged'] ?? 0) > 0);
		\CAdminMessage::ShowMessage(['TYPE' => $okType ? 'OK' : 'ERROR', 'MESSAGE' => $msg]);
		if (!empty($res['failed']))
		{
			echo '<table class="internal" style="max-width:900px;margin-top:12px;"><tr class="heading"><td>ID</td><td>Ошибка</td></tr>';
			foreach ($res['failed'] as $f)
			{
				echo '<tr><td>' . (int)($f['id'] ?? 0) . '</td><td>' . mf_cud_h((string)($f['message'] ?? '')) . '</td></tr>';
			}
			echo '</table>';
		}
		echo '<p><a href="' . mf_cud_h($baseQs . '&' . $filterQs) . '">← К списку дублей</a></p>';
	}

	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

if ($mode === 'confirm' && $_SERVER['REQUEST_METHOD'] !== 'POST')
{
	$pending = $_SESSION[MF_CUD_SESSION_PENDING] ?? null;
	if (!is_array($pending) || empty($pending['delete_ids']))
	{
		LocalRedirect($baseQs);
	}

	$deleteIds = array_values(array_unique(array_filter(array_map('intval', $pending['delete_ids']), static fn (int $v) => $v > 0)));
	$details = mf_cud_fetch_elements_detail($iblockId, $deleteIds, $opts);

	echo '<p><a href="' . mf_cud_h($baseQs . '&' . $filterQs) . '">← Назад к дублям</a></p>';
	echo '<p class="adm-info-message">Будет обработано <strong>' . count($deleteIds) . '</strong> ID: удаление карточек каталога и/или очистка хвостов свойств для ID без записи в <code>b_iblock_element</code>. Операция необратима.</p>';
	echo '<table class="internal" style="width:100%;max-width:1100px;"><tr class="heading">';
	echo '<td>ID</td><td>Название</td><td>Артикул</td><td>Бренд</td><td>MF_UNIQ_KEY</td><td>Редирект</td></tr>';
	foreach ($deleteIds as $id)
	{
		$d = $details[$id] ?? null;
		echo '<tr>';
		echo '<td>' . (int)$id . '</td>';
		if (is_array($d))
		{
			echo '<td>' . mf_cud_h((string)$d['name']) . '</td>';
			echo '<td>' . mf_cud_h((string)$d['article']) . '</td>';
			echo '<td>' . mf_cud_h((string)$d['brand']) . '</td>';
			echo '<td><code>' . mf_cud_h((string)$d['uniq_key']) . '</code></td>';
			echo '<td>' . (!empty($d['is_redirect']) ? 'Y' : '—') . '</td>';
		}
		else
		{
			echo '<td colspan="5">—</td>';
		}
		echo '</tr>';
	}
	echo '</table>';
	echo '<form method="post" action="' . mf_cud_h($baseQs . '&mode=confirm') . '" style="margin-top:16px;" onsubmit="return confirm(\'Удалить отмеченные товары без возможности восстановления?\');">';
	echo bitrix_sessid_post();
	echo '<button type="submit" class="adm-btn adm-btn-save" style="background:#c0392b;border-color:#a93226;color:#fff;">Удалить ' . count($deleteIds) . ' товар(ов)</button> ';
	echo '<a class="adm-btn" href="' . mf_cud_h($baseQs . '&' . $filterQs) . '">Отмена</a>';
	echo '</form>';

	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && (string)($_POST['mf_cud_action'] ?? '') === 'prepare_delete')
{
	$deleteIds = [];
	if (isset($_POST['del']) && is_array($_POST['del']))
	{
		foreach ($_POST['del'] as $id => $v)
		{
			if ((string)$v === 'Y' || (string)$v === '1')
			{
				$deleteIds[] = (int)$id;
			}
		}
	}
	$deleteIds = array_values(array_unique(array_filter($deleteIds, static fn (int $v) => $v > 0)));
	$classDel = mf_cud_classify_element_ids($deleteIds, $iblockId, array_merge($opts, ['active_only' => false]));
	$deleteIds = array_values(array_unique(array_merge($classDel['product'], $classDel['missing'])));

	if ($deleteIds === [])
	{
		\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не отмечено ни одного ID на удаление / очистку хвоста.']);
	}
	else
	{
		$_SESSION[MF_CUD_SESSION_PENDING] = [
			'delete_ids' => $deleteIds,
			'created_at' => time(),
			'user_id' => (int)$USER->GetID(),
		];
		LocalRedirect($baseQs . '&mode=confirm');
	}
}

$offset = ($page - 1) * $perPage;
$data = mf_cud_fetch_duplicate_groups($iblockId, $opts, $perPage, $offset);
$total = (int)$data['total'];
$groups = $data['rows'];
$totalPages = $perPage > 0 ? (int)ceil($total / $perPage) : 1;

$allIds = [];
foreach ($groups as $g)
{
	foreach ($g['element_ids'] as $id)
	{
		$allIds[] = (int)$id;
	}
}
$details = mf_cud_fetch_elements_detail($iblockId, $allIds, $opts);

?>
<p class="adm-info-message" style="max-width:960px;">
	Группы с одинаковым <strong><?= mf_cud_h($mfCudGroupFieldLabel) ?></strong><?= match ($groupBy) {
		'element_code' => ' — несколько карточек с одним ЧПУ <code>/products/{code}/</code>.',
		'article_brand_norm' => ' — из <code>MF_ARTICLE_NORM</code> (или CML2_ARTICLE) и <code>MF_BRAND_NORM</code> (или MF_BRAND), как при расчёте ключа. Находит пары с <em>разным</em> сохранённым MF_UNIQ_KEY (битый ключ).',
		default => ' — по сохранённому свойству MF_UNIQ_KEY.',
	} ?>
	Отметьте галочкой товары на удаление; по умолчанию оставляется одна карточка (не редирект<?= $groupBy === 'element_code' ? ', у которой CODE совпадает с группой' : '' ?>, минимальный ID).
	<?= $groupBy === 'element_code'
		? 'Редирект с <em>другим</em> CODE, но тем же товаром — режим «норм. артикул + бренд» или «Включая редиректы» + MF_UNIQ_KEY. '
		: ($groupBy === 'article_brand_norm'
			? 'Для массового исправления MF_UNIQ_KEY: <code>php tools/mf_catalog_fix_uniq_key.php --apply --include-redirect=Y</code>. '
			: '') ?>
	Строки «Нет в БД» — хвост в свойствах; очистка без <code>CIBlockElement::Delete</code>.
	Удаление карточек — <code>CIBlockElement::Delete</code> (как в «Удаление товаров CSV»).
</p>
<p class="adm-info-message" style="max-width:960px;background:#fff8e6;">
	<strong>Не находите товар в поиске админки?</strong> Строка поиска в шапке ищет по <em>индексу названий</em>, а не по числовому ID.
	После массового импорта каталога индекс часто не обновлялся — карточка есть, но поиск по названию/артикулу пустой.
	Откройте элемент по ссылкам <strong>редакт.</strong> / <strong>список</strong> в таблице ниже или
	«Контент → Инфоблоки → Товары (IB<?= (int)$iblockId ?>)» → фильтр <strong>ID</strong> = нужное число.
	Редиректы (<code>MF_IS_REDIRECT=Y</code>) в списке дублей по умолчанию скрыты — включите «Включая редиректы».
</p>

<form method="get" action="<?= mf_cud_h($curAdminPage) ?>" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
	<input type="hidden" name="lang" value="<?= mf_cud_h($lang) ?>" />
	<label>Группировать по
		<select name="group_by">
			<option value="uniq_key"<?= $groupBy === 'uniq_key' ? ' selected' : '' ?>>MF_UNIQ_KEY (в свойстве)</option>
			<option value="article_brand_norm"<?= $groupBy === 'article_brand_norm' ? ' selected' : '' ?>>Норм. артикул + норм. бренд</option>
			<option value="element_code"<?= $groupBy === 'element_code' ? ' selected' : '' ?>>Символьный код (CODE / URL)</option>
		</select>
	</label>
	<label>На странице
		<select name="per_page">
			<?php foreach ([10, 20, 50, 100, 200, 500] as $n): ?>
				<option value="<?= $n ?>"<?= $perPage === $n ? ' selected' : '' ?>><?= $n ?></option>
			<?php endforeach; ?>
		</select>
	</label>
	<label><input type="checkbox" name="active_only" value="Y"<?= $activeOnly ? ' checked' : '' ?> /> Только активные</label>
	<label><input type="checkbox" name="include_redirect" value="Y"<?= $includeRedirect ? ' checked' : '' ?> /> Включая редиректы</label>
	<label><input type="checkbox" name="include_empty" value="Y"<?= $includeEmpty ? ' checked' : '' ?> /> <?= mf_cud_h($mfCudEmptyCheckboxLabel) ?></label>
	<button type="submit" class="adm-btn">Применить фильтр</button>
</form>

<p><strong>Групп дублей:</strong> <?= (int)$total ?>
	<?php if ($totalPages > 1): ?>
		| Страница <?= (int)$page ?> из <?= (int)$totalPages ?>
		<?php if ($page > 1): ?>
			<a href="<?= mf_cud_h($baseQs . '&' . $filterQs . '&page=' . ($page - 1)) ?>">←</a>
		<?php endif; ?>
		<?php if ($page < $totalPages): ?>
			<a href="<?= mf_cud_h($baseQs . '&' . $filterQs . '&page=' . ($page + 1)) ?>">→</a>
		<?php endif; ?>
	<?php endif; ?>
</p>

<?php if ($groups === []): ?>
	<p style="color:#666;">Дубликатов не найдено<?= match ($groupBy) {
		'element_code' => ' (нет двух и более элементов с одинаковым CODE).',
		'article_brand_norm' => ' (нет двух и более с одной парой артикул+бренд или нет свойств артикула/бренда).',
		default => ' (или нет свойства MF_UNIQ_KEY).',
	} ?></p>
<?php else: ?>
<form method="post" action="<?= mf_cud_h($baseQs . '&' . $filterQs . '&page=' . $page) ?>">
	<?= bitrix_sessid_post() ?>
	<input type="hidden" name="mf_cud_action" value="prepare_delete" />

	<?php foreach ($groups as $g): ?>
		<?php
		$uniqKey = (string)$g['uniq_key'];
		$gkey = (string)$g['group_key'];
		$ids = $g['element_ids'];
		$grpDetails = [];
		foreach ($ids as $id)
		{
			if (isset($details[$id]))
			{
				$grpDetails[$id] = $details[$id];
			}
		}
		$keepId = mf_cud_suggest_keep_id($grpDetails, $uniqKey, $groupBy);
		$groupCorruptHint = $groupBy === 'uniq_key' && function_exists('mf_cud_uniq_key_looks_corrupt')
			? mf_cud_uniq_key_looks_corrupt($uniqKey)
			: '';
		$cntProduct = 0;
		$cntMissing = 0;
		foreach ($ids as $id)
		{
			$d = $grpDetails[(int)$id] ?? null;
			if (!is_array($d))
			{
				$cntMissing++;
				continue;
			}
			$st = (string)($d['status'] ?? '');
			if ($st === 'missing')
			{
				$cntMissing++;
			}
			elseif ($st === 'product')
			{
				$cntProduct++;
			}
		}
		?>
		<div style="margin:20px 0;padding:12px;border:1px solid #dce1e5;background:#fafbfc;max-width:1200px;">
			<div style="margin-bottom:8px;">
				<strong><?= mf_cud_h($mfCudGroupFieldLabel) ?>:</strong> <code><?= mf_cud_h($uniqKey) ?></code>
				<?php if ($groupBy === 'element_code' && $uniqKey !== ''): ?>
					<span style="margin-left:8px;font-size:12px;"><a href="/products/<?= mf_cud_h(rawurlencode($uniqKey)) ?>/" target="_blank" rel="noopener">/products/<?= mf_cud_h($uniqKey) ?>/</a></span>
				<?php endif; ?>
				<span style="color:#666;margin-left:8px;">
					(в SQL: <?= (int)$g['cnt'] ?>, в БД: <?= (int)($g['cnt_found'] ?? $cntProduct) ?><?= $cntMissing > 0 ? ', только хвост ID: ' . (int)$cntMissing : '' ?>, товаров IB<?= (int)$iblockId ?>: <?= (int)$cntProduct ?>)
				</span>
				<?php if ($groupCorruptHint !== ''): ?>
					<div style="margin-top:6px;color:#a04000;font-size:12px;">⚠ <?= mf_cud_h($groupCorruptHint) ?> Пересчёт ключей: <code>php tools/mf_catalog_fix_uniq_key.php --apply</code> (сначала без <code>--apply</code>).</div>
				<?php elseif ($groupBy === 'article_brand_norm'): ?>
					<div style="margin-top:6px;color:#666;font-size:12px;">Сверьте колонку «MF_UNIQ_KEY в карточке»: если отличается от ключа группы — свойство нужно пересчитать.</div>
				<?php endif; ?>
			</div>
			<table class="internal" style="width:100%;">
				<tr class="heading">
					<td style="width:40px;">Удалить</td>
					<td style="width:50px;">Оставить</td>
					<td>ID</td>
					<td>Статус</td>
					<td>Название</td>
					<td>ЧПУ (CODE)</td>
					<td>Артикул</td>
					<td>Бренд</td>
					<?php if ($groupBy === 'article_brand_norm'): ?>
						<td>MF_UNIQ_KEY в карточке</td>
					<?php endif; ?>
					<td>Активен</td>
					<td>Редирект</td>
					<td>Остатки</td>
					<td>Создан</td>
					<td></td>
				</tr>
				<?php foreach ($ids as $id): ?>
					<?php
					$id = (int)$id;
					$d = $grpDetails[$id] ?? null;
					$status = is_array($d) ? (string)($d['status'] ?? '') : 'missing';
					$statusLabel = match ($status) {
						'product' => 'Товар',
						'sku' => 'SKU (др. IB)',
						'inactive' => 'Неактивен',
						'other_iblock' => 'Другой IB ' . (int)($d['iblock_id'] ?? 0),
						'missing' => 'Нет в БД',
						default => $status,
					};
					$rowStyle = '';
					if ($status === 'missing')
					{
						$rowStyle = 'background:#fde8e8;';
					}
					elseif ($status === 'sku' || $status === 'other_iblock')
					{
						$rowStyle = 'background:#fff8e6;';
					}
					$canEdit = in_array($status, ['product', 'sku', 'inactive', 'other_iblock'], true);
					$isKeep = ($id === $keepId && $status === 'product');
					$checkDel = !$isKeep && ($status === 'product' || $status === 'missing');
					$showKeepRadio = ($status === 'product');
					?>
					<tr style="<?= $rowStyle ?>">
						<td class="text-center">
							<?php if ($status === 'product' || $status === 'missing'): ?>
								<input type="checkbox" name="del[<?= $id ?>]" value="Y"<?= $checkDel ? ' checked' : '' ?> class="mf-cud-del" data-group="<?= mf_cud_h($gkey) ?>" title="<?= $status === 'missing' ? 'Очистить хвост свойств' : 'Удалить карточку' ?>" />
							<?php else: ?>
								—
							<?php endif; ?>
						</td>
						<td class="text-center">
							<?php if ($showKeepRadio): ?>
								<input type="radio" name="keep_grp[<?= mf_cud_h($gkey) ?>]" value="<?= $id ?>"<?= $isKeep ? ' checked' : '' ?> class="mf-cud-keep" data-group="<?= mf_cud_h($gkey) ?>" data-id="<?= $id ?>" />
							<?php else: ?>
								—
							<?php endif; ?>
						</td>
						<td><?= $id ?></td>
						<td><?= mf_cud_h($statusLabel) ?></td>
						<td><?= is_array($d) && (string)$d['name'] !== '' ? mf_cud_h((string)$d['name']) : '—' ?></td>
						<td style="font-size:11px;"><?= is_array($d) && (string)($d['code'] ?? '') !== '' ? mf_cud_h((string)$d['code']) : '—' ?><?= is_array($d) && !empty($d['canonical_code']) ? '<br><span style="color:#666;">→ ' . mf_cud_h((string)$d['canonical_code']) . '</span>' : '' ?></td>
						<td><?= is_array($d) && (string)$d['article'] !== '' ? mf_cud_h((string)$d['article']) : '—' ?></td>
						<td><?= is_array($d) && (string)$d['brand'] !== '' ? mf_cud_h((string)$d['brand']) : '—' ?></td>
						<?php if ($groupBy === 'article_brand_norm'): ?>
							<?php
							$storedUk = is_array($d) ? trim((string)($d['uniq_key'] ?? '')) : '';
							$ukMismatch = $storedUk !== '' && $storedUk !== $uniqKey;
							?>
							<td style="font-size:11px;<?= $ukMismatch ? 'color:#a04000;font-weight:600;' : '' ?>">
								<?= $storedUk !== '' ? mf_cud_h($storedUk) : '—' ?>
								<?= $ukMismatch ? '<br><span style="font-weight:normal;">≠ группа</span>' : '' ?>
							</td>
						<?php endif; ?>
						<td><?= is_array($d) && (string)$d['active'] !== '' ? mf_cud_h((string)$d['active']) : '—' ?></td>
						<td><?= (is_array($d) && !empty($d['is_redirect'])) ? 'Y' : '—' ?></td>
						<td style="font-size:11px;max-width:220px;">
							<?php
							$stock = is_array($d) && is_array($d['stock'] ?? null) ? $d['stock'] : null;
							$stockLabel = is_array($stock) ? (string)($stock['label'] ?? '—') : '—';
							$stockTitle = is_array($stock) ? (string)($stock['title_attr'] ?? '') : '';
							$stockColor = is_array($stock) && !empty($stock['has_any']) ? '#1a6b2f' : '#666';
							?>
							<span style="color:<?= mf_cud_h($stockColor) ?>;"<?= $stockTitle !== '' ? ' title="' . mf_cud_h($stockTitle) . '"' : '' ?>><?= mf_cud_h($stockLabel) ?></span>
						</td>
						<td style="font-size:11px;"><?= is_array($d) && (string)$d['date_create'] !== '' ? mf_cud_h((string)$d['date_create']) : '—' ?></td>
						<td>
							<?php
							$editIblock = is_array($d) ? (int)($d['iblock_id'] ?? $iblockId) : $iblockId;
							$editUrl = $canEdit ? mf_cud_admin_edit_url($editIblock, $id, $lang) : '';
							$listUrl = $canEdit && function_exists('mf_cud_admin_list_url')
								? mf_cud_admin_list_url($editIblock, $id, $lang)
								: '';
							?>
							<?php if ($editUrl !== ''): ?>
								<a href="<?= mf_cud_h($editUrl) ?>" target="_blank" rel="noopener">редакт.</a>
							<?php endif; ?>
							<?php if ($listUrl !== ''): ?>
								<a href="<?= mf_cud_h($listUrl) ?>" target="_blank" rel="noopener" style="margin-left:6px;">список</a>
							<?php endif; ?>
							<?php if ($status === 'missing'): ?>
								<span style="margin-left:6px;color:#c0392b;font-size:11px;">нет в БД</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>
	<?php endforeach; ?>

	<p style="margin-top:16px;">
		<button type="submit" class="adm-btn adm-btn-save">Перейти к подтверждению удаления</button>
	</p>
</form>

<script>
(function () {
	function syncGroup(groupKey, keepId) {
		document.querySelectorAll('input.mf-cud-del[data-group="' + groupKey + '"]').forEach(function (cb) {
			var id = parseInt(cb.name.replace(/^del\[(\d+)\]$/, '$1'), 10);
			cb.checked = (id !== keepId);
		});
	}
	document.querySelectorAll('input.mf-cud-keep').forEach(function (radio) {
		radio.addEventListener('change', function () {
			if (radio.checked) {
				syncGroup(radio.getAttribute('data-group'), parseInt(radio.value, 10));
			}
		});
	});
})();
</script>
<?php endif; ?>

<p style="margin-top:24px;color:#888;font-size:12px;">
	CLI: <code>php tools/mf_catalog_uniq_key_duplicates.php --iblock-id=<?= (int)$iblockId ?></code>
</p>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
