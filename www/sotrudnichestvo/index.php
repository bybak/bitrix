<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Сотрудничество");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");

require_once($_SERVER["DOCUMENT_ROOT"]."/include/mf_form.php");
$mfFields = [
	"NAME" => "Ваше имя",
	"PHONE" => "Контактный телефон",
	"EMAIL" => "E-mail",
	"ORG" => "Название (магазина, сервиса, организации)",
	"SITE" => "Ссылка на сайт (если есть)",
	"MESSAGE" => "Напишите, что вас интересует",
];
$mfForm = mf_handle_static_form(
	"sotrudnichestvo",
	"andrey@motor-force.ru",
	"Motor-Force: заявка на сотрудничество",
	$mfFields,
	["NAME", "PHONE", "ORG", "MESSAGE"]
);
?>

<div class="mb-4">
	<h2 class="h4">Сотрудничество с Motor-Force</h2>
	<p>
		Приглашаем к партнёрству сервисные центры, магазины, салоны и другие компании, работающие с мототехникой и запчастями.
	</p>
	<p class="mb-4">
		Предлагаем удобные условия для регулярных закупок, выгодные цены и сопровождение на всех этапах.
	</p>

	<h3 class="h5 mt-4">Работаем по всей России, СНГ и миру</h3>
	<ul>
		<li>Базируемся в Санкт-Петербурге</li>
		<li>Отправляем заказы по всей России, в страны СНГ и по миру</li>
		<li>Самовывоз со склада в Санкт-Петербурге</li>
	</ul>

	<h3 class="h5 mt-4">Условия для партнёров</h3>
	<ul>
		<li>Индивидуальные скидки при оплате наличными и по безналу</li>
		<li>Подписка на рассылку остатков склада — актуальная информация</li>
		<li>Быстрая отгрузка со сторонних складов (1–4 дня до нашего офиса)</li>
		<li>Прямые поставки из США и Европы (сроки от 1–2 месяцев, возможны ускоренные варианты)</li>
		<li>Дропшиппинг — отправляем напрямую вашим клиентам без наших документов или с вашими</li>
		<li>Удобная оплата — наличные, перевод на карту, безналичный перевод</li>
	</ul>

	<h3 class="h5 mt-4">Широкий ассортимент</h3>
	<p class="mb-2">Поставляем оригинальные и качественные аналоговые запчасти и аксессуары для:</p>
	<ul>
		<li>Квадроциклов</li>
		<li>Снегоходов</li>
		<li>Мотоциклов</li>
		<li>Гидроциклов</li>
		<li>Лодочных моторов и другой мототехники</li>
	</ul>
	<p class="mb-4">
		Бренды: BRP (Can-Am, Ski-Doo, Sea-Doo, Lynx), Polaris, Yamaha, Arctic Cat, Kawasaki, Honda, Suzuki и другие.
	</p>

	<h3 class="h5 mt-4">Как начать сотрудничество</h3>
	<ol>
		<li>Напишите нам на почту или через сайт — расскажите о вашем бизнесе (салон, магазин, сервис, прокат).</li>
		<li>Пришлите ссылку на ваш сайт или краткое описание.</li>
		<li>
			Зарегистрируйтесь на оптовом сайте для доступа к ценам и заказам:
			<a href="https://opt.motor-force.ru/" rel="nofollow noopener" target="_blank">opt.motor-force.ru</a>
		</li>
	</ol>
	<p class="mb-0">
		<a class="btn btn-outline-success" href="https://opt.motor-force.ru/" rel="nofollow noopener" target="_blank">Посетить оптовый сайт</a>
	</p>

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
		<input type="hidden" name="mf_form_id" value="sotrudnichestvo">

		<div class="form-group">
			<label for="mf-sotr-name">Ваше имя</label>
			<input id="mf-sotr-name" class="form-control" name="NAME" value="<?=htmlspecialchars($mfForm["values"]["NAME"] ?? "", ENT_QUOTES, "UTF-8")?>">
		</div>
		<div class="form-group">
			<label for="mf-sotr-phone">Контактный телефон</label>
			<input id="mf-sotr-phone" class="form-control" name="PHONE" value="<?=htmlspecialchars($mfForm["values"]["PHONE"] ?? "", ENT_QUOTES, "UTF-8")?>">
		</div>
		<div class="form-group">
			<label for="mf-sotr-email">E-mail</label>
			<input id="mf-sotr-email" type="email" class="form-control" name="EMAIL" value="<?=htmlspecialchars($mfForm["values"]["EMAIL"] ?? "", ENT_QUOTES, "UTF-8")?>">
		</div>
		<div class="form-group">
			<label for="mf-sotr-org">Название (магазина, сервиса, организации)</label>
			<input id="mf-sotr-org" class="form-control" name="ORG" value="<?=htmlspecialchars($mfForm["values"]["ORG"] ?? "", ENT_QUOTES, "UTF-8")?>">
		</div>
		<div class="form-group">
			<label for="mf-sotr-site">Ссылка на сайт (если есть)</label>
			<input id="mf-sotr-site" class="form-control" name="SITE" value="<?=htmlspecialchars($mfForm["values"]["SITE"] ?? "", ENT_QUOTES, "UTF-8")?>">
		</div>
		<div class="form-group">
			<label for="mf-sotr-message">Напишите, что вас интересует</label>
			<textarea id="mf-sotr-message" class="form-control" name="MESSAGE" rows="6"><?=htmlspecialchars($mfForm["values"]["MESSAGE"] ?? "", ENT_QUOTES, "UTF-8")?></textarea>
		</div>

		<button class="btn btn-success" type="submit">Отправить</button>
	</form>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>

