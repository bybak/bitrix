<?
define("HIDE_SIDEBAR", true);
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Заказы");
?>
<div class="mf-order" data-mf="order-make">
<?$APPLICATION->IncludeComponent("bitrix:sale.order.ajax", "bootstrap_v4", array(
	"PAY_FROM_ACCOUNT" => "Y",
	"COUNT_DELIVERY_TAX" => "N",
	"COUNT_DISCOUNT_4_ALL_QUANTITY" => "N",
	"ONLY_FULL_PAY_FROM_ACCOUNT" => "N",
	"ALLOW_AUTO_REGISTER" => "Y",
	"SEND_NEW_USER_NOTIFY" => "Y",
	// 'H' = считать стоимость заранее только для доставок с isCalculatePriceImmediately()=true
	// чтобы цены отображались прямо в списке доставок до выбора.
	"DELIVERY_NO_AJAX" => "H",
	"TEMPLATE_LOCATION" => "popup",
	"PROP_1" => array(
	),
	"PATH_TO_BASKET" => "/personal/cart/",
	"PATH_TO_PERSONAL" => "/personal/order/",
	"PATH_TO_PAYMENT" => "/personal/order/payment/",
	"PATH_TO_ORDER" => "/personal/order/make/",
	"SET_TITLE" => "Y" ,
	"SHOW_ACCOUNT_NUMBER" => "Y",
	"DELIVERY_NO_SESSION" => "Y",
	"COMPATIBLE_MODE" => "N",
	"BASKET_POSITION" => "before",
	"BASKET_IMAGES_SCALING" => "adaptive",
	"SERVICES_IMAGES_SCALING" => "adaptive",
	"USER_CONSENT" => "Y",
	"USER_CONSENT_ID" => "1",
	"USER_CONSENT_IS_CHECKED" => "Y",
	"USER_CONSENT_IS_LOADED" => "Y"
	),
	false
);?>
</div>
<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>