<?php

declare(strict_types=1);

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

\Bitrix\Main\Loader::includeModule('catalog');
\Bitrix\Main\Loader::includeModule('iblock');

$APPLICATION->SetTitle('Склады и типы цен (XML_ID ↔ NAME)');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if (function_exists('mf_supplier_store_to_price_group_reset'))
{
	mf_supplier_store_to_price_group_reset();
}

$map = function_exists('mf_supplier_store_to_price_group') ? mf_supplier_store_to_price_group() : [];

$debugElementId = (int)($_GET['element_id'] ?? 0);
$debugCode = trim((string)($_GET['element_code'] ?? ''));
$debugIblockId = (int)($_GET['iblock_id'] ?? 4);

?>
<div class="adm-detail-content-wrap" style="padding:12px;">
	<p class="adm-info-message">
		Связка для <code>mf_calc_store_price</code> / импортов: у типа цены поле <strong>NAME</strong> должно совпадать с одним из вариантов для <strong>XML_ID</strong> склада
		(точное совпадение в верхнем регистре или пара <code>SUPPLIER_XXX</code> / <code>XXX</code>).
	</p>
	<table class="internal" style="width:100%; border-collapse:collapse;">
		<tr class="heading">
			<td>ID склада</td>
			<td>Название</td>
			<td>XML_ID склада</td>
			<td>Кандидаты NAME</td>
			<td>ID типа цены</td>
			<td>NAME типа цены</td>
			<td>Статус</td>
		</tr>
		<?php
		$rs = \CCatalogStore::GetList(['ID' => 'ASC'], [], false, false, ['ID', 'TITLE', 'XML_ID', 'CODE']);
		while ($s = $rs->Fetch())
		{
			$sid = (int)($s['ID'] ?? 0);
			$xmlRaw = trim((string)($s['XML_ID'] ?? ''));
			$cands = function_exists('mf_catalog_xml_price_group_name_candidates')
				? mf_catalog_xml_price_group_name_candidates($xmlRaw)
				: [];
			$gid = (int)($map[$sid] ?? 0);
			$gname = '';
			if ($gid > 0)
			{
				$gr = \CCatalogGroup::GetList([], ['=ID' => $gid], false, false, ['ID', 'NAME'])->Fetch();
				$gname = (string)($gr['NAME'] ?? '');
			}
			$ok = $gid > 0 && $xmlRaw !== '';
			?>
			<tr>
				<td><?= (int)$sid ?></td>
				<td><?= htmlspecialcharsbx((string)($s['TITLE'] ?? '')) ?></td>
				<td><code><?= htmlspecialcharsbx($xmlRaw !== '' ? $xmlRaw : '—') ?></code></td>
				<td><small><?= htmlspecialcharsbx(implode(', ', $cands)) ?></small></td>
				<td><?= $gid > 0 ? (int)$gid : '—' ?></td>
				<td><code><?= $gname !== '' ? htmlspecialcharsbx($gname) : '—' ?></code></td>
				<td><?= $ok ? '<span style="color:green">OK</span>' : '<span style="color:#c00">Нет типа цены</span>' ?></td>
			</tr>
			<?php
		}
		?>
	</table>

	<hr style="margin:24px 0;">
	<h2>Проверка товара (цены и остатки по кластеру SKU)</h2>
	<p class="adm-info-message">
		Если таблица выше везде OK, а на карточке нет цены по складу — смотрите, есть ли строка в <code>b_catalog_price</code> для нужного <code>CATALOG_GROUP_ID</code> и <code>PRODUCT_ID</code> из кластера, и остаток на этом складе.
	</p>
	<form method="get" action="" style="margin-bottom:16px;">
		<input type="hidden" name="lang" value="<?= htmlspecialcharsbx((string)(defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru')) ?>">
		<label>ID элемента каталога: <input type="number" name="element_id" value="<?= $debugElementId > 0 ? (int)$debugElementId : '' ?>" style="width:120px;"></label>
		<span style="margin:0 12px;">или</span>
		<label>CODE (символьный код): <input type="text" name="element_code" value="<?= htmlspecialcharsbx($debugCode) ?>" style="width:200px;"></label>
		<label> IBLOCK_ID <input type="number" name="iblock_id" value="<?= $debugIblockId > 0 ? $debugIblockId : 4 ?>" style="width:70px;"></label>
		<button type="submit" class="adm-btn">Показать</button>
	</form>
	<?php
	if ($debugCode !== '' && $debugElementId <= 0)
	{
		$r = \CIBlockElement::GetList(
			[],
			['IBLOCK_ID' => $debugIblockId, '=CODE' => $debugCode],
			false,
			['nTopCount' => 1],
			['ID']
		)->Fetch();
		if ($r && (int)$r['ID'] > 0)
		{
			$debugElementId = (int)$r['ID'];
		}
	}

	if ($debugElementId > 0)
	{
		$cluster = function_exists('mf_catalog_product_cluster_ids') ? mf_catalog_product_cluster_ids($debugElementId) : [$debugElementId];
		$cluster = array_values(array_unique(array_filter($cluster, static fn($v) => (int)$v > 0)));
		?>
		<p><strong>Элемент ID:</strong> <?= (int)$debugElementId ?> &nbsp; <strong>Кластер PRODUCT_ID:</strong> <?= htmlspecialcharsbx(implode(', ', $cluster)) ?></p>
		<h3>Цены (b_catalog_price) по кластеру</h3>
		<table class="internal" style="width:100%; border-collapse:collapse; margin-bottom:16px;">
			<tr class="heading">
				<td>PRODUCT_ID</td>
				<td>CATALOG_GROUP_ID</td>
				<td>PRICE</td>
				<td>NAME группы</td>
			</tr>
			<?php
			if (class_exists(\Bitrix\Catalog\PriceTable::class))
			{
				foreach ($cluster as $pid)
				{
					$rsP = \Bitrix\Catalog\PriceTable::getList([
						'filter' => ['=PRODUCT_ID' => (int)$pid],
						'select' => ['CATALOG_GROUP_ID', 'PRICE'],
						'order' => ['CATALOG_GROUP_ID' => 'ASC'],
					]);
					while ($pr = $rsP->fetch())
					{
						$gId = (int)$pr['CATALOG_GROUP_ID'];
						$gN = '';
						$gr = \CCatalogGroup::GetList([], ['=ID' => $gId], false, false, ['NAME'])->Fetch();
						$gN = (string)($gr['NAME'] ?? '');
						?>
						<tr>
							<td><?= (int)$pid ?></td>
							<td><?= $gId ?></td>
							<td><?= htmlspecialcharsbx((string)$pr['PRICE']) ?></td>
							<td><code><?= htmlspecialcharsbx($gN) ?></code></td>
						</tr>
						<?php
					}
				}
			}
			?>
		</table>

		<h3>По каждому складу (карта + расчёт как на витрине)</h3>
		<table class="internal" style="width:100%; border-collapse:collapse;">
			<tr class="heading">
				<td>STORE_ID</td>
				<td>Склад</td>
				<td>XML_ID</td>
				<td>gid из карты</td>
				<td>Остаток Σ по кластеру</td>
				<td>RAW по gid (первый найденный PID)</td>
				<td>mf_calc_store_price</td>
			</tr>
			<?php
			$rsSt = \CCatalogStore::GetList(['ID' => 'ASC'], [], false, false, ['ID', 'TITLE', 'XML_ID']);
			while ($st = $rsSt->Fetch())
			{
				$sid = (int)($st['ID'] ?? 0);
				if ($sid <= 0)
				{
					continue;
				}
				$gid = (int)($map[$sid] ?? 0);
				$sumAmt = 0.0;
				foreach ($cluster as $cpid)
				{
					$rsSp = \CCatalogStoreProduct::GetList(
						[],
						['PRODUCT_ID' => (int)$cpid, 'STORE_ID' => $sid],
						false,
						false,
						['AMOUNT']
					);
					if ($sp = $rsSp->Fetch())
					{
						$sumAmt += (float)($sp['AMOUNT'] ?? 0);
					}
				}
				$rawFound = null;
				$rawPid = null;
				if ($gid > 0 && class_exists(\Bitrix\Catalog\PriceTable::class))
				{
					foreach ($cluster as $cpid)
					{
						$one = \Bitrix\Catalog\PriceTable::getList([
							'filter' => ['=PRODUCT_ID' => (int)$cpid, '=CATALOG_GROUP_ID' => $gid],
							'select' => ['PRICE'],
							'limit' => 1,
						])->fetch();
						if ($one && (float)($one['PRICE'] ?? 0) > 0)
						{
							$rawFound = (float)$one['PRICE'];
							$rawPid = (int)$cpid;
							break;
						}
					}
				}
				$calc = function_exists('mf_calc_store_price') ? mf_calc_store_price($debugElementId, $sid) : null;
				?>
				<tr>
					<td><?= (int)$sid ?></td>
					<td><?= htmlspecialcharsbx((string)($st['TITLE'] ?? '')) ?></td>
					<td><code><?= htmlspecialcharsbx(trim((string)($st['XML_ID'] ?? ''))) ?></code></td>
					<td><?= $gid > 0 ? (int)$gid : '—' ?></td>
					<td><?= htmlspecialcharsbx((string)$sumAmt) ?></td>
					<td><?= $rawFound !== null ? htmlspecialcharsbx((string)$rawFound . ' (PID ' . (int)$rawPid . ')') : '—' ?></td>
					<td><?= $calc !== null ? htmlspecialcharsbx((string)$calc) : '—' ?></td>
				</tr>
				<?php
			}
			?>
		</table>
		<?php
	}
	?>
</div>
<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
