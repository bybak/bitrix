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

<div class="mb-4">
	<h2 class="h4">Выкупим вашу мототехнику</h2>
	<p class="mb-4">
		Осуществляем выкуп любой мототехники в любом состоянии: мотоциклы, квадроциклы, багги, снегоходы, гидроциклы,
		подвесные и стационарные моторы. Битые, некомплектные, с проблемами — рассмотрим любые предложения.
	</p>

	<h3 class="h5 mt-4">Как связаться</h3>
	<ul>
		<li><a href="mailto:andrey@motor-force.ru">andrey@motor-force.ru</a></li>
		<li>8 (812) 986-42-76</li>
		<li>8 (921) 883-73-40 (WhatsApp, Viber, Telegram)</li>
	</ul>

	<h3 class="h5 mt-4">Что нужно для предварительной оценки</h3>
	<p class="mb-2">По возможности приложите больше фото и укажите:</p>
	<ol>
		<li>Желаемую сумму</li>
		<li>Местонахождение техники</li>
		<li>Состояние на данный момент</li>
		<li>Комплектацию (доп. оборудование)</li>
		<li>VIN-номер</li>
		<li>Модельный год</li>
		<li>Марку, модель и модификацию</li>
	</ol>

	<h3 class="h5 mt-5">Отправить предложение</h3>

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
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>

