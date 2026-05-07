<?php

declare(strict_types=1);

use Bitrix\Main\GroupTable;
use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\DiscountCouponTable;

if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
{
	die('Admin only');
}

global $APPLICATION, $USER;

$saleRight = is_object($APPLICATION) ? (string)$APPLICATION->GetGroupRight('sale') : 'D';
if ($saleRight < 'W' && (!is_object($USER) || !$USER->IsAdmin()))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Недостаточно прав (нужны права на запись в модуле «Интернет-магазин» или администратор).']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

if (!Loader::includeModule('sale'))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Модуль sale не установлен.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/sale/prolog.php';

$APPLICATION->SetTitle('Купон: скидка на заказ');

function mf_soc_esc(string $s): string
{
	return function_exists('htmlspecialcharsbx')
		? (string)htmlspecialcharsbx($s)
		: htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Все активные группы пользователей — чтобы правило не «не находилось» из‑за ограничения по группам. */
function mf_sale_order_coupon_user_groups(): array
{
	$ids = [];
	try
	{
		$res = GroupTable::getList([
			'select' => ['ID'],
			'filter' => ['=ACTIVE' => 'Y'],
		]);
		while ($row = $res->fetch())
		{
			$ids[] = (int)$row['ID'];
		}
	}
	catch (\Throwable $e)
	{
		$ids = [];
	}
	$ids = array_values(array_unique(array_filter($ids, static function ($id): bool {
		return (int)$id > 0;
	})));
	sort($ids);

	return $ids !== [] ? $ids : [2];
}

$siteList = [];
$rs = \CSite::GetList($by = 'sort', $order = 'asc', ['ACTIVE' => 'Y']);
while ($site = $rs->Fetch())
{
	$siteList[(string)$site['LID']] = '[' . $site['LID'] . '] ' . (string)$site['NAME'];
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid())
{
	$lid = (string)($_POST['LID'] ?? '');
	$name = trim((string)($_POST['NAME'] ?? ''));
	$unit = (string)($_POST['DISCOUNT_UNIT'] ?? 'Perc');
	$value = str_replace(',', '.', trim((string)($_POST['DISCOUNT_VALUE'] ?? '0')));
	$coupon = trim((string)($_POST['COUPON'] ?? ''));
	$maxUse = (int)($_POST['MAX_USE'] ?? 0);
	$couponType = (int)($_POST['COUPON_TYPE'] ?? DiscountCouponTable::TYPE_MULTI_ORDER);

	if ($lid === '' || !isset($siteList[$lid]))
	{
		$errors[] = 'Выберите сайт.';
	}
	if ($name === '')
	{
		$errors[] = 'Укажите название правила.';
	}
	if (!is_numeric($value) || (float)$value <= 0)
	{
		$errors[] = 'Укажите положительное значение скидки.';
	}
	if ($unit !== 'Perc' && $unit !== 'CurAll')
	{
		$errors[] = 'Некорректный тип скидки.';
	}
	if ($coupon === '')
	{
		$errors[] = 'Укажите код промокода.';
	}
	elseif (mb_strlen($coupon) > 32)
	{
		$errors[] = 'Код промокода не длиннее 32 символов.';
	}
	if (
		$couponType !== DiscountCouponTable::TYPE_ONE_ORDER
		&& $couponType !== DiscountCouponTable::TYPE_MULTI_ORDER
	)
	{
		$couponType = DiscountCouponTable::TYPE_MULTI_ORDER;
	}

	if ($errors === [])
	{
		$currency = \CSaleLang::GetLangCurrency($lid);
		if ($currency === '')
		{
			$errors[] = 'Не удалось определить валюту для сайта.';
		}
	}

	if ($errors === [])
	{
		$floatVal = round((float)$value, 4);
		$actions = [
			'CLASS_ID' => 'CondGroup',
			'DATA' => ['All' => 'AND'],
			'CHILDREN' => [
				[
					'CLASS_ID' => 'ActSaleBsktGrp',
					'DATA' => [
						'Type' => 'Discount',
						'Value' => $floatVal,
						'Unit' => $unit,
						'All' => 'AND',
						'Max' => 0,
						'True' => 'True',
					],
					'CHILDREN' => [],
				],
			],
		];

		$conditions = [
			'CLASS_ID' => 'CondGroup',
			'DATA' => [
				'All' => 'AND',
				'True' => 'True',
			],
			'CHILDREN' => [],
		];

		$fields = [
			'LID' => $lid,
			'NAME' => $name,
			'ACTIVE' => 'Y',
			'ACTIVE_FROM' => '',
			'ACTIVE_TO' => '',
			'PRIORITY' => 1,
			'SORT' => 100,
			'LAST_DISCOUNT' => 'Y',
			'XML_ID' => 'MF_ORDER_COUPON_' . time(),
			'USER_GROUPS' => mf_sale_order_coupon_user_groups(),
			'USE_COUPONS' => 'Y',
			'CONDITIONS' => $conditions,
			'ACTIONS' => $actions,
		];

		$discountId = (int)\CSaleDiscount::Add($fields);
		if ($discountId <= 0)
		{
			global $APPLICATION;
			$ex = $APPLICATION->GetException();
			$errors[] = $ex ? $ex->GetString() : 'Ошибка создания правила (CSaleDiscount::Add).';
		}
		else
		{
			$couponFields = [
				'DISCOUNT_ID' => $discountId,
				'ACTIVE' => 'Y',
				'COUPON' => $coupon,
				'TYPE' => $couponType,
				'MAX_USE' => $maxUse,
				'USER_ID' => 0,
				'USE_COUNT' => 0,
			];

			$cRes = DiscountCouponTable::add($couponFields);
			if (!$cRes->isSuccess())
			{
				\CSaleDiscount::Delete($discountId);
				$errors = array_merge($errors, $cRes->getErrorMessages());
			}
			else
			{
				$unitLabel = $unit === 'Perc' ? '% на сумму корзины' : ' (' . mf_soc_esc($currency) . ') на весь заказ';
				$success = 'Создано правило #' . $discountId . ' и промокод «' . mf_soc_esc($coupon) . '». Скидка: ' . $floatVal . $unitLabel . '.';
			}
		}
	}
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$listUrl = '/bitrix/admin/sale_discount.php?lang=' . LANGUAGE_ID;
$couponsUrl = '/bitrix/admin/sale_discount_coupons.php?lang=' . LANGUAGE_ID;
$editDiscountUrl = '/bitrix/admin/sale_discount_edit.php?lang=' . LANGUAGE_ID;

?>
<div class="adm-info-message" style="max-width:920px;margin-bottom:14px">
	<strong>Почему сумма могла не измениться:</strong>
	купон в сессии есть, но <strong>правило скидки не применилось к позициям</strong> — тогда в оформлении у купона подсказка вроде «не применён», а итог тот же.
	Частые причины: у товара в каталоге включено «не участвует в скидках» / своя цена без скидок; правило привязано к другому сайту (поле «Сайт»); конфликт с другими правилами (приоритет, «прекратить применение скидок»).
	Проверьте также <strong>Настройки → Торговый каталог → Скидки</strong>: режим «только скидки магазина» должен быть согласован с типом правил.
</div>
<p style="max-width:920px">
	Стандартные разделы Bitrix: <a href="<?= mf_soc_esc($listUrl) ?>">Правила корзины</a>,
	<a href="<?= mf_soc_esc($couponsUrl) ?>">Купоны</a>.
	Ниже — быстрое создание <strong>одного</strong> правила с промокодом: скидка распространяется на позиции корзины
	(процент или фиксированная сумма от суммы заказа — тип «на корзину» в терминологии sale).
	Правило создаётся сразу для <strong>всех активных групп пользователей</strong>.
</p>

<?php
if ($errors !== [])
{
	foreach ($errors as $e)
	{
		\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => $e]);
	}
}
if ($success !== '')
{
	\CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => $success]);
}
?>

<form method="post" action="">
	<?= bitrix_sessid_post() ?>
	<table class="adm-detail-content-table edit-table" style="max-width:720px">
		<tr>
			<td width="40%">Сайт</td>
			<td>
				<select name="LID" required>
					<?php foreach ($siteList as $k => $label): ?>
						<option value="<?= mf_soc_esc($k) ?>" <?= ((string)($_POST['LID'] ?? '') === $k ? ' selected' : '') ?>><?= mf_soc_esc($label) ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<td>Название правила</td>
			<td><input type="text" name="NAME" size="50" maxlength="255" required value="<?= mf_soc_esc((string)($_POST['NAME'] ?? 'Скидка по промокоду')) ?>"></td>
		</tr>
		<tr>
			<td>Тип скидки</td>
			<td>
				<label><input type="radio" name="DISCOUNT_UNIT" value="Perc" <?= ((string)($_POST['DISCOUNT_UNIT'] ?? 'Perc') !== 'CurAll' ? ' checked' : '') ?>> Процент от суммы корзины</label><br>
				<label><input type="radio" name="DISCOUNT_UNIT" value="CurAll" <?= ((string)($_POST['DISCOUNT_UNIT'] ?? '') === 'CurAll' ? ' checked' : '') ?>> Фиксированная сумма скидки на весь заказ (валюта сайта)</label>
			</td>
		</tr>
		<tr>
			<td>Значение</td>
			<td><input type="text" name="DISCOUNT_VALUE" size="10" required value="<?= mf_soc_esc((string)($_POST['DISCOUNT_VALUE'] ?? '10')) ?>"> <span class="adm-info-message">для процента — например 10; для суммы — в валюте магазина</span></td>
		</tr>
		<tr>
			<td>Код промокода</td>
			<td><input type="text" name="COUPON" maxlength="32" required value="<?= mf_soc_esc((string)($_POST['COUPON'] ?? '')) ?>" autocomplete="off"></td>
		</tr>
		<tr>
			<td>Тип купона</td>
			<td>
				<select name="COUPON_TYPE">
					<option value="<?= (int)DiscountCouponTable::TYPE_MULTI_ORDER ?>" <?= ((int)($_POST['COUPON_TYPE'] ?? 0) !== (int)DiscountCouponTable::TYPE_ONE_ORDER ? ' selected' : '') ?>>Многоразовый (разные заказы)</option>
					<option value="<?= (int)DiscountCouponTable::TYPE_ONE_ORDER ?>" <?= ((int)($_POST['COUPON_TYPE'] ?? 0) === (int)DiscountCouponTable::TYPE_ONE_ORDER ? ' selected' : '') ?>>Одноразовый (один заказ)</option>
				</select>
			</td>
		</tr>
		<tr>
			<td>Макс. использований</td>
			<td><input type="number" name="MAX_USE" min="0" step="1" value="<?= (int)($_POST['MAX_USE'] ?? 0) ?>"> <span class="adm-info-message">0 — без ограничения (для многоразового)</span></td>
		</tr>
	</table>
	<br>
	<input type="submit" class="adm-btn-save" value="Создать правило и купон">
</form>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
