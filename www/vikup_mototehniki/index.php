<?php
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

<div class="mf-text-page user-inner mb-4">
	<h2 style="text-align: center;"><span style="font-weight: bold;">Выкупим Вашу Мототехнику</span></h2>
	<p><br /></p>
	<p style="text-align: left;"><span style="font-size: 18px;">Осуществляем выкуп любой мототехники в любом состоянии. </span><span style="font-size: 18px;">Мотоциклы, квадроциклы, багги, снегохды, гидроциклы, подвесные моторы, </span><span style="font-size: 18px;">стационарные моторы - готовы выкупить быстро и в любой точке РФ.&nbsp;Битые, некомплектные, с проблемами - рассмотрим любые предложения. </span></p>
	<p><br /></p>
	<h2 style="text-align: center;"><span style="font-weight: bold;">Ждем ваших предложений</span></h2>
	<p><span style="font-size: 18px;">По всем предложениям можете обращаться:</span></p>
	<p><span style="font-size: 18px;">andrey@motor-force.ru;</span><br /></p>
	<p><span style="font-size: 18px;">8-812-986-42-76 (Телефон);</span></p>
	<p><span style="font-size: 18px;">8-921-883-73-40 (Телефон,&nbsp;<span>Whatsapp, Viber, Telegram).</span></span></p>
	<p><br /></p>
	<p><span style="font-size: 18px;"><span style="font-size: 18px;">Для предварительной оценки просьба прикладывать как можно больше детальных и общих фотографий, а так же предоставить информацию о технике:</span></span></p>
	<p><br /></p>
	<ol>
		<li><span style="font-size: 18px;">Марку, модель&nbsp;и модификацию.</span></li>
		<li><span style="font-size: 18px;">Модельный год.</span></li>
		<li><span style="font-size: 18px;">Вин Номер.</span></li>
		<li><span style="font-size: 18px;">Комплектацию (Установленное доп.оборудование).</span></li>
		<li><span style="font-size: 18px;">Состояние на данный момент.</span></li>
		<li><span style="font-size: 18px;">Местонахождение техники.</span></li>
		<li><span style="font-size: 18px;">Желаемая сумма.</span></li>
	</ol>
	<p><br /></p>
	<p><br /></p>

	<section class="mf-feedback" aria-label="Форма отправки предложения">
		<h2 id="feedback" class="mf-feedback__title"><span style="font-weight: bold;">Отправить предложение</span></h2>

		<?php if ($mfForm["ok"]): ?>
			<div class="alert alert-success">Сообщение отправлено. Мы свяжемся с вами.</div>
		<?php elseif (!empty($mfForm["errors"])): ?>
			<div class="alert alert-danger">
				<ul class="mb-0">
					<?php foreach ($mfForm["errors"] as $e): ?>
						<li><?=htmlspecialchars($e, ENT_QUOTES, "UTF-8")?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form method="post" action="">
			<?=bitrix_sessid_post()?>
			<input type="hidden" name="mf_form_id" value="vikup_mototehniki">

			<div class="form-group">
				<label for="mf-vikup-name">Ваше имя</label>
				<input id="mf-vikup-name" class="form-control" name="NAME" value="<?=htmlspecialchars($mfForm["values"]["NAME"] ?? "", ENT_QUOTES, "UTF-8")?>">
			</div>
			<div class="form-group">
				<label for="mf-vikup-phone">Контактный телефон</label>
				<input id="mf-vikup-phone" class="form-control" name="PHONE" value="<?=htmlspecialchars($mfForm["values"]["PHONE"] ?? "", ENT_QUOTES, "UTF-8")?>">
			</div>
			<div class="form-group">
				<label for="mf-vikup-email">E-mail</label>
				<input id="mf-vikup-email" type="email" class="form-control" name="EMAIL" value="<?=htmlspecialchars($mfForm["values"]["EMAIL"] ?? "", ENT_QUOTES, "UTF-8")?>">
			</div>
			<div class="form-group">
				<label for="mf-vikup-offer">Ваше предложение</label>
				<textarea id="mf-vikup-offer" class="form-control" name="OFFER" rows="6"><?=htmlspecialchars($mfForm["values"]["OFFER"] ?? "", ENT_QUOTES, "UTF-8")?></textarea>
			</div>

			<button class="btn btn-success" type="submit">Отправить</button>
		</form>
	</section>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>

