<?php
const MF_HIDE_TITLEBAR = true;
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Оплата");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");
?>

<div class="mf-payment mf-text-page mb-4">
	<div class="mf-payment-hero">
		<div class="mf-payment-hero__icon" aria-hidden="true"><?= mf_icon('wallet', ['class' => 'mf-icon mf-icon--xl']) ?></div>
		<div class="mf-payment-hero__body">
			<h1>Способы оплаты</h1>
			<p class="mf-payment-lead">Выберите удобный вариант оплаты — подскажем и поможем на каждом этапе.</p>
		</div>
	</div>

	<div class="mf-payment-grid">
		<section class="mf-payment-card">
			<div class="mf-payment-card-head">
				<div class="mf-payment-icon" aria-hidden="true"><?= mf_icon('cash') ?></div>
				<h4>Наличные</h4>
			</div>
			<p>Вы можете оплатить заказ наличными:</p>
			<ul>
				<li>Оплата наличными <strong>в нашем офисе;</strong></li>
				<li>Оплата наличными <strong>курьеру при получении.</strong></li>
			</ul>
		</section>

		<section class="mf-payment-card">
			<div class="mf-payment-card-head">
				<div class="mf-payment-icon" aria-hidden="true"><?= mf_icon('card') ?></div>
				<h4>Банковская карта</h4>
			</div>
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
		</section>

		<section class="mf-payment-card">
			<div class="mf-payment-card-head">
				<div class="mf-payment-icon" aria-hidden="true"><?= mf_icon('invoice') ?></div>
				<h4>Расчётный счёт</h4>
			</div>
			<p>Юридические и физические лица могут оплачивать заказ на расчётный счёт. Работаем без НДС. Отгрузка заказа осуществляется после зачисления денежных средств на наш расчётный счёт.</p>
		</section>

		<section class="mf-payment-card">
			<div class="mf-payment-card-head">
				<div class="mf-payment-icon" aria-hidden="true"><?= mf_icon('box') ?></div>
				<h4>Наложенный платёж</h4>
			</div>
			<p>Вы можете оплатить заказ наложенным платежом:</p>
			<ul>
				<li>Наложенным платежом в Почте России;</li>
				<li>Наложенным платежом в EMS;</li>
				<li>Наложенным платежом в СДЭКе.</li>
			</ul>
			<div class="mf-payment-note">
				Другие ТК и прочие варианты доставки доступны только по предоплате.
			</div>
		</section>
	</div>

	<aside class="mf-payment-cta">
		<div class="mf-payment-cta__icon" aria-hidden="true"><?= mf_icon('shield', ['class' => 'mf-icon mf-icon--lg']) ?></div>
		<div class="mf-payment-cta__body">
			<h3>Все платежи защищены</h3>
			<p>Чек и закрывающие документы выдаём по запросу. Если у вас есть вопросы по оплате — напишите менеджеру.</p>
		</div>
		<div class="mf-payment-cta__actions">
			<a class="mf-btn mf-btn--accent" href="/contacts/">Связаться с менеджером</a>
		</div>
	</aside>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>
