<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Ремонт ДВС");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");

require_once($_SERVER["DOCUMENT_ROOT"]."/include/mf_form.php");
$mfFields = [
	"NAME" => "Ваше имя",
	"PHONE" => "Контактный телефон",
	"EMAIL" => "E-mail",
	"REQUEST" => "Ваш запрос",
];
$mfForm = mf_handle_static_form(
	"remont_motorov",
	"andrey@motor-force.ru",
	"Motor-Force: запрос на ремонт ДВС",
	$mfFields,
	["NAME", "PHONE", "REQUEST"]
);
?>

<div class="mb-4">
	<h2 class="h4">
		Ремонт двигателей внутреннего сгорания для мототехники (квадроциклы, снегоходы и подвесные лодочные моторы)
	</h2>
	<p>Организуем ремонт моторов для квадроциклов, снегоходов и подвесных лодочных моторов.</p>

	<p class="mb-2">Ремонтируем моторы всех известных брендов:</p>
	<p class="mb-4">
		BRP (Bombardier, Ski-Doo, Lynx, Can-Am, Evinrude, Johnson, Rotax), Polaris, Arctic Cat, Yamaha, Kawasaki, Suzuki,
		Honda, Tohatsu, Mercury, Mariner.
	</p>

	<p class="mb-4">
		Выполняем полный перечень работ по ремонту моторов, восстановлению цилиндро‑поршневой группы и коленвалов.
	</p>

	<h3 class="h5 mt-4">Перечень выполняемых работ</h3>
	<ol>
		<li>Ремонт цилиндров (гильзовка, расточка, нанесение хона).</li>
		<li>Ремонт ГБЦ (головка блока цилиндра).</li>
		<li>
			Ремонт коленчатых валов (замена подшипников и вкладышей, шатунов, щек, сальников и других элементов).
		</li>
	</ol>

	<h3 class="h5 mt-5">Порядок работ — для клиентов из Санкт-Петербурга</h3>
	<ol>
		<li>Снятие ДВС (самостоятельно или нашими специалистами).</li>
		<li>Дефектовка ДВС.</li>
		<li>Предварительный сметный расчёт (работы и запчасти).</li>
		<li>Согласование стоимости и выставление счёта.</li>
		<li>Закупка и поставка запчастей.</li>
		<li>Ремонт ДВС.</li>
		<li>Установка ДВС (самостоятельно или нашими специалистами).</li>
		<li>Приёмка выполненных работ.</li>
	</ol>

	<h3 class="h5 mt-5">Порядок работ — для клиентов из других регионов</h3>
	<ol>
		<li>Оставляете заявку у нас на сайте или по телефону.</li>
		<li>Согласовываем возможность ремонта по почте/телефону.</li>
		<li>Самостоятельно снимаете ДВС со своей техники.</li>
		<li>Упаковываете двигатель для отправки ТК или почтой.</li>
		<li>Отправляете двигатель к нам в Санкт-Петербург (перевозку туда и обратно оплачивает заказчик).</li>
		<li>Мы производим дефектовку.</li>
		<li>Составляем предварительный сметный расчёт.</li>
		<li>Согласовываем стоимость и выставляем счёт.</li>
		<li>Закупаем/поставляем запчасти.</li>
		<li>Ремонтируем ДВС.</li>
		<li>Отправляем ДВС транспортной компанией.</li>
		<li>Получаете ДВС и устанавливаете на технику.</li>
	</ol>

	<h3 class="h5 mt-5">Контакты</h3>
	<ul>
		<li><a href="mailto:andrey@motor-force.ru">andrey@motor-force.ru</a></li>
		<li>8 (812) 986-42-76</li>
		<li>8 (921) 883-73-40</li>
	</ul>

	<h3 class="h5 mt-5">Прайс-лист (основные виды работ)</h3>
	<p class="text-muted">
		Таблица прайса на исходном сайте содержит вложенную разметку и переносится на следующем шаге “как есть” (с версткой).
		Сейчас оставил блок-заглушку, чтобы страница была готова.
	</p>

	<h3 class="h5 mt-5">Направления ремонта</h3>
	<ul>
		<li>Ремонт двигателей квадроциклов: BRP, Can-Am, Polaris, Arctic Cat, Yamaha, Kawasaki, Suzuki, Honda.</li>
		<li>Ремонт двигателей снегоходов: BRP, Ski-Doo, Lynx, Yamaha, Polaris, Arctic Cat.</li>
		<li>Ремонт лодочных моторов: Evinrude, Johnson, Yamaha, Suzuki, Honda, Tohatsu, Mercury, Mariner.</li>
	</ul>
	<p class="mb-0"><strong>Ждём ваших заявок на ремонт моторов!</strong></p>

	<h3 class="h5 mt-5">Оставить заявку</h3>

	<?php if ($mfForm["ok"]): ?>
		<div class="alert alert-success">Заявка отправлена. Мы свяжемся с вами.</div>
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
		<input type="hidden" name="mf_form_id" value="remont_motorov">

		<div class="form-group">
			<label for="mf-remont-name">Ваше имя</label>
			<input id="mf-remont-name" class="form-control" name="NAME" value="<?=htmlspecialchars($mfForm["values"]["NAME"] ?? "", ENT_QUOTES, "UTF-8")?>">
		</div>
		<div class="form-group">
			<label for="mf-remont-phone">Контактный телефон</label>
			<input id="mf-remont-phone" class="form-control" name="PHONE" value="<?=htmlspecialchars($mfForm["values"]["PHONE"] ?? "", ENT_QUOTES, "UTF-8")?>">
		</div>
		<div class="form-group">
			<label for="mf-remont-email">E-mail</label>
			<input id="mf-remont-email" type="email" class="form-control" name="EMAIL" value="<?=htmlspecialchars($mfForm["values"]["EMAIL"] ?? "", ENT_QUOTES, "UTF-8")?>">
		</div>
		<div class="form-group">
			<label for="mf-remont-request">Ваш запрос</label>
			<textarea id="mf-remont-request" class="form-control" name="REQUEST" rows="6"><?=htmlspecialchars($mfForm["values"]["REQUEST"] ?? "", ENT_QUOTES, "UTF-8")?></textarea>
		</div>

		<button class="btn btn-success" type="submit">Отправить</button>
	</form>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>

