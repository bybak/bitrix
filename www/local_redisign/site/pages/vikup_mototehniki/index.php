<?php
const MF_HIDE_TITLEBAR = true;
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Выкуп мототехники");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");

require_once($_SERVER["DOCUMENT_ROOT"]."/include/mf_form.php");
$mfFields = [
	"NAME" => "Ваше имя",
	"PHONE" => "Контактный телефон",
	"EMAIL" => "E-mail",
	"OFFER" => "Ваше предложение",
];
$mfForm = mf_handle_static_form(
	"vikup_mototehniki",
	"andrey@motor-force.ru",
	"Motor-Force: выкуп мототехники — предложение",
	$mfFields,
	["NAME", "PHONE", "OFFER"]
);
?>

<div class="mf-buyout mf-text-page mb-4">

	<header class="mf-buyout-hero">
		<div class="mf-buyout-hero__icon" aria-hidden="true"><?= mf_icon('cash', ['class' => 'mf-icon mf-icon--xl']) ?></div>
		<div class="mf-buyout-hero__body">
			<span class="mf-buyout-hero__label">Выкуп по всей РФ</span>
			<h1>Выкупим вашу мототехнику</h1>
			<p class="mf-buyout-hero__lead">Осуществляем выкуп любой мототехники в любом состоянии. Мотоциклы, квадроциклы, багги, снегоходы, гидроциклы, подвесные моторы, стационарные моторы — готовы выкупить быстро и в любой точке РФ. Битые, некомплектные, с проблемами — рассмотрим любые предложения.</p>
		</div>
	</header>

	<section class="mf-buyout-section">
		<header class="mf-buyout-section__head">
			<span class="mf-buyout-section__icon" aria-hidden="true"><?= mf_icon('phone') ?></span>
			<h2>Ждём ваших предложений</h2>
		</header>
		<p>По всем предложениям можете обращаться:</p>
		<div class="mf-buyout-contacts">
			<a href="mailto:andrey@motor-force.ru" class="mf-buyout-contact">
				<?= mf_icon('mail', ['class' => 'mf-icon mf-icon--lg']) ?>
				<span>andrey@motor-force.ru</span>
			</a>
			<a href="tel:+78129864276" class="mf-buyout-contact">
				<?= mf_icon('phone', ['class' => 'mf-icon mf-icon--lg']) ?>
				<span>8-812-986-42-76 (телефон)</span>
			</a>
			<a href="tel:+79218837340" class="mf-buyout-contact">
				<?= mf_icon('whatsapp', ['class' => 'mf-icon mf-icon--lg']) ?>
				<span>8-921-883-73-40 (телефон, WhatsApp, Viber, Telegram)</span>
			</a>
		</div>
	</section>

	<section class="mf-buyout-section">
		<header class="mf-buyout-section__head">
			<span class="mf-buyout-section__icon" aria-hidden="true"><?= mf_icon('info') ?></span>
			<h2>Что указать в предложении</h2>
		</header>
		<p>Для предварительной оценки просьба прикладывать как можно больше детальных и общих фотографий, а также предоставить информацию о технике:</p>
		<ol class="mf-buyout-list">
			<li><span class="mf-buyout-list__num">01</span><span>Марку, модель и модификацию</span></li>
			<li><span class="mf-buyout-list__num">02</span><span>Модельный год</span></li>
			<li><span class="mf-buyout-list__num">03</span><span>VIN-номер</span></li>
			<li><span class="mf-buyout-list__num">04</span><span>Комплектацию (установленное доп.&nbsp;оборудование)</span></li>
			<li><span class="mf-buyout-list__num">05</span><span>Состояние на данный момент</span></li>
			<li><span class="mf-buyout-list__num">06</span><span>Местонахождение техники</span></li>
			<li><span class="mf-buyout-list__num">07</span><span>Желаемая сумма</span></li>
		</ol>
	</section>

	<section class="mf-feedback" id="feedback" aria-label="Форма отправки предложения">
		<header class="mf-feedback__head">
			<h2 class="mf-feedback__title">Отправить предложение</h2>
			<p>Опишите технику и условия — мы вернёмся с ответом в ближайшее время.</p>
		</header>

		<?php if ($mfForm["ok"]): ?>
			<div class="mf-alert mf-alert--success">Сообщение отправлено. Мы свяжемся с вами.</div>
		<?php elseif (!empty($mfForm["errors"])): ?>
			<div class="mf-alert mf-alert--danger">
				<ul class="mb-0">
					<?php foreach ($mfForm["errors"] as $e): ?>
						<li><?= htmlspecialchars($e, ENT_QUOTES, "UTF-8") ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form method="post" action="" class="mf-form mf-form--grid">
			<?= bitrix_sessid_post() ?>
			<input type="hidden" name="mf_form_id" value="vikup_mototehniki">

			<label class="mf-field"><input id="mf-vikup-name" name="NAME" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["NAME"] ?? "", ENT_QUOTES, "UTF-8") ?>"><span>Ваше имя</span></label>
			<label class="mf-field"><input id="mf-vikup-phone" name="PHONE" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["PHONE"] ?? "", ENT_QUOTES, "UTF-8") ?>"><span>Контактный телефон</span></label>
			<label class="mf-field mf-field--full"><input id="mf-vikup-email" type="email" name="EMAIL" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["EMAIL"] ?? "", ENT_QUOTES, "UTF-8") ?>"><span>E-mail</span></label>
			<label class="mf-field mf-field--full"><textarea id="mf-vikup-offer" name="OFFER" rows="6" placeholder=" "><?= htmlspecialchars($mfForm["values"]["OFFER"] ?? "", ENT_QUOTES, "UTF-8") ?></textarea><span>Ваше предложение</span></label>

			<button class="mf-btn mf-btn--accent mf-btn--lg mf-form__submit" type="submit">Отправить предложение <?= mf_icon('arrow-right') ?></button>
		</form>
	</section>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>
