<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Оплата");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");
?>

<div class="mf-text-page user-inner mb-4">
	<h2><strong>Способы оплаты</strong></h2>
	<p><br /></p>

	<h4><strong>Наличные</strong></h4>
	<p>Вы можете оплатить заказ наличными:</p>
	<ul>
		<li>Оплата наличными <strong>в нашем офисе;</strong></li>
		<li>Оплата наличными <strong>курьеру при получении.</strong></li>
	</ul>
	<p><br /></p>

	<h4><strong>Банковская карта</strong></h4>
	<p>Вы можете оплатить заказ переводом на карту:</p>
	<ul>
		<li>Оплата на <strong>карту Сбербанка;</strong></li>
		<li>Оплата на <strong>карту ВТБ;</strong></li>
		<li>Оплата на <strong>карту Тинькофф;</strong></li>
		<li>Оплата на <strong>карту Альфа Банка;</strong></li>
		<li>Оплата на <strong>кошелек PayPal;</strong></li>
		<li>Оплата через <strong>Золотую Корону;</strong></li>
		<li>Оплата через <strong>Western Union.</strong></li>
	</ul>
	<p><br /></p>

	<h4><strong>Расчетный счет</strong></h4>
	<p>Юридические и Физические лица могут оплачивать заказ на расчетный счет. Работаем без НДС. Отгрузка заказа осуществляется после зачисления денежных средств на наш расчетный счет.</p>
	<p><br /></p>

	<h4><strong>Наложенный платеж</strong></h4>
	<p>Вы можете оплатить заказ наложенным платежом:</p>
	<ul>
		<li>Наложенным платежом в почте России;</li>
		<li>Наложенным платежом в EMS;</li>
		<li>Наложенным платежом в СДЭКе.</li>
	</ul>
	<p>Другие ТК и прочие варианты доставки доступны только по предоплате.</p>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>

