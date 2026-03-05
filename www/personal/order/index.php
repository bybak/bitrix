<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Заказы");
?><?
// Старый путь. Реальные заказы — в SEF разделе /personal/orders/
LocalRedirect('/personal/orders/');
?><?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>