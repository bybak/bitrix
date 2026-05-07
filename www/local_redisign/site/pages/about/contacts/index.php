<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Задайте вопрос");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");
?>

<div class="mf-feedback-page mf-text-page mb-4">
	<header class="mf-cooperation-hero">
		<div class="mf-cooperation-hero__icon" aria-hidden="true"><?= function_exists('mf_icon') ? mf_icon('mail', ['class' => 'mf-icon mf-icon--xl']) : '' ?></div>
		<div class="mf-cooperation-hero__body">
			<span class="mf-cooperation-hero__label">Обратная связь</span>
			<h1>Задайте вопрос</h1>
			<p>Напишите нам — ответим максимально подробно. Все контакты, реквизиты и адрес офиса — на странице <a href="/contacts/">«Контакты»</a>.</p>
		</div>
	</header>

	<section class="mf-feedback" aria-label="Форма обратной связи">
		<header class="mf-feedback__head">
			<h2 class="mf-feedback__title">Форма обратной связи</h2>
			<p>Введите ваше имя и e-mail — и опишите вопрос. Капча защищает от спама.</p>
		</header>
		<?php
		$APPLICATION->IncludeComponent(
			"bitrix:main.feedback",
			"bootstrap_v4",
			array(
				"EMAIL_TO" => "andrey@motor-force.ru",
				"EVENT_MESSAGE_ID" => array(),
				"OK_TEXT" => "Спасибо, ваше сообщение принято.",
				"REQUIRED_FIELDS" => array("NAME", "EMAIL"),
				"USE_CAPTCHA" => "Y",
			)
		);
		?>
	</section>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>
