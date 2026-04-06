<?
define("HIDE_SIDEBAR", true);
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Личный кабинет");

global $USER;

$mfClientStatus = 'Не указан';
$mfClientType = 'Не указан';
$mfDiscountSize = '0%';
$mfUserSystemId = '—';

if (is_object($USER) && $USER->IsAuthorized())
{
	$userId = (int)$USER->GetID();

	if ($userId > 0)
	{
		$mfUserSystemId = (string)$userId;
		if (
			function_exists('mf_checkout_person_type_map')
			&& class_exists('CSaleOrderUserProps')
		)
		{
			$personTypeMap = mf_checkout_person_type_map();
			$fizPersonTypeId = (int)($personTypeMap['fiz'] ?? 0);
			$jurPersonTypeId = (int)($personTypeMap['jur'] ?? 0);

			$latestProfile = CSaleOrderUserProps::GetList(
				['DATE_UPDATE' => 'DESC', 'ID' => 'DESC'],
				['USER_ID' => $userId],
				false,
				false,
				['ID', 'PERSON_TYPE_ID']
			)->Fetch();

			$profilePersonTypeId = (int)($latestProfile['PERSON_TYPE_ID'] ?? 0);
			if ($profilePersonTypeId > 0)
			{
				if ($jurPersonTypeId > 0 && $profilePersonTypeId === $jurPersonTypeId)
				{
					$mfClientStatus = 'Юр лицо';
				}
				elseif ($fizPersonTypeId > 0 && $profilePersonTypeId === $fizPersonTypeId)
				{
					$mfClientStatus = 'Физ лицо';
				}
			}
		}

		if (class_exists('CUser') && class_exists('CUserFieldEnum'))
		{
			$by = 'ID';
			$order = 'ASC';
			$rsUser = CUser::GetList($by, $order, ['ID' => $userId], ['SELECT' => ['UF_MF_CUSTOMER_TYPE']]);
			$userRow = $rsUser ? $rsUser->Fetch() : false;
			$customerTypeEnumId = (int)($userRow['UF_MF_CUSTOMER_TYPE'] ?? 0);
			if ($customerTypeEnumId > 0)
			{
				$enum = CUserFieldEnum::GetList([], ['ID' => $customerTypeEnumId])->Fetch();
				$enumValue = trim((string)($enum['VALUE'] ?? ''));
				if ($enumValue !== '')
				{
					$mfClientType = $enumValue;
				}
			}
		}

		if (function_exists('mf_user_is_wholesale') && mf_user_is_wholesale())
		{
			$mfDiscountSize = '10%';
		}
	}
}
?>

<div class="mf-personal">
	<section class="mf-personal-hero">
		<div class="mf-personal-hero-inner">
			<div class="mf-personal-title">Личный кабинет</div>
			<p class="mf-personal-subtitle">Заказы, профили, личные данные и оплата — всё в одном месте.</p>
			<div class="mf-personal-actions">
				<a class="mf-personal-action mf-personal-action_primary" href="/personal/orders/">Мои заказы</a>
				<a class="mf-personal-action" href="/personal/cart/">Корзина</a>
				<a class="mf-personal-action" href="/?logout=yes&<?=bitrix_sessid_get()?>">Выйти</a>
			</div>
		</div>
	</section>

	<section class="mf-personal-summary">
		<div class="mf-personal-summary-card">
			<div class="mf-personal-summary-label">Статус клиента</div>
			<div class="mf-personal-summary-value"><?=$mfClientStatus?></div>
		</div>
		<div class="mf-personal-summary-card">
			<div class="mf-personal-summary-label">Тип клиента</div>
			<div class="mf-personal-summary-value"><?=$mfClientType?></div>
		</div>
		<div class="mf-personal-summary-card">
			<div class="mf-personal-summary-label">Размер скидки</div>
			<div class="mf-personal-summary-value"><?=$mfDiscountSize?></div>
		</div>
		<div class="mf-personal-summary-card">
			<div class="mf-personal-summary-label">ID в системе</div>
			<div class="mf-personal-summary-value"><?=htmlspecialcharsbx($mfUserSystemId)?></div>
		</div>
	</section>

	<section class="mf-personal-body">
<?$APPLICATION->IncludeComponent(
	"bitrix:sale.personal.section",
	"bootstrap_v4",
	Array(
		"ACCOUNT_PAYMENT_ELIMINATED_PAY_SYSTEMS" => array("0"),
		"ACCOUNT_PAYMENT_PERSON_TYPE" => "1",
		"ACCOUNT_PAYMENT_SELL_SHOW_FIXED_VALUES" => "Y",
		"ACCOUNT_PAYMENT_SELL_TOTAL" => array("100","200","500","1000","5000",""),
		"ACCOUNT_PAYMENT_SELL_USER_INPUT" => "Y",
		"ACTIVE_DATE_FORMAT" => "d.m.Y",
		"CACHE_GROUPS" => "Y",
		"CACHE_TIME" => "3600",
		"CACHE_TYPE" => "A",
		"CHECK_RIGHTS_PRIVATE" => "N",
		"COMPATIBLE_LOCATION_MODE_PROFILE" => "N",
		"CUSTOM_PAGES" => "",
		"CUSTOM_SELECT_PROPS" => array(""),
		"NAV_TEMPLATE" => "",
		"ORDER_HISTORIC_STATUSES" => array("F"),
		"PATH_TO_BASKET" => "/personal/cart",
		"PATH_TO_CATALOG" => "/products/",
		"PATH_TO_CONTACT" => "/about/contacts",
		"PATH_TO_PAYMENT" => "/personal/order/payment/",
		"PER_PAGE" => "20",
		"PROP_1" => array(),
		"PROP_2" => array(),
		"SAVE_IN_SESSION" => "Y",
		"SEF_FOLDER" => "/personal/",
		"SEF_MODE" => "Y",
		"SEF_URL_TEMPLATES" => array(
			"account"=>"account/",
			"index"=>"index.php",
			"order_cancel"=>"cancel/#ID#",
			"order_detail"=>"orders/#ID#",
			"orders"=>"orders/",
			"private"=>"private/",
			"profile"=>"profiles/",
			"profile_detail"=>"profiles/#ID#",
			"subscribe"=>"subscribe/"
		),
		"SEND_INFO_PRIVATE" => "N",
		"SET_TITLE" => "Y",
		"SHOW_ACCOUNT_COMPONENT" => "Y",
		"SHOW_ACCOUNT_PAGE" => "Y",
		"SHOW_ACCOUNT_PAY_COMPONENT" => "Y",
		"SHOW_BASKET_PAGE" => "Y",
		"SHOW_CONTACT_PAGE" => "Y",
		"SHOW_ORDER_PAGE" => "Y",
		"SHOW_PRIVATE_PAGE" => "Y",
		"SHOW_PROFILE_PAGE" => "Y",
		"ALLOW_INNER" => "N",
		"ONLY_INNER_FULL" => "N",
		"SHOW_SUBSCRIBE_PAGE" => "Y",
		"USER_PROPERTY_PRIVATE" => array(),
		"USE_AJAX_LOCATIONS_PROFILE" => "N"
	)
);?>
	</section>
</div>

<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>