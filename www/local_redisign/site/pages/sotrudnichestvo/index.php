<?php
const MF_HIDE_TITLEBAR = true;
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Сотрудничество");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");

require_once($_SERVER["DOCUMENT_ROOT"]."/include/mf_form.php");
$mfFields = [
	"NAME" => "Ваше имя",
	"PHONE" => "Контактный телефон",
	"EMAIL" => "E-mail",
	"COMPANY" => "Название (магазина, сервиса, организации)",
	"SITE" => "Ссылка на сайт (если есть)",
	"INTEREST" => "Напишите, что Вас интересует",
];
$mfForm = mf_handle_static_form(
	"sotrudnichestvo",
	"andrey@motor-force.ru",
	"Motor-Force: сотрудничество — заявка",
	$mfFields,
	["NAME", "PHONE", "EMAIL"]
);
?>

<div class="mf-cooperation mf-text-page mb-4">

	<header class="mf-cooperation-hero">
		<div class="mf-cooperation-hero__icon" aria-hidden="true"><?= mf_icon('rocket', ['class' => 'mf-icon mf-icon--xl']) ?></div>
		<div class="mf-cooperation-hero__body">
			<span class="mf-cooperation-hero__label">Партнёрство Motor-Force</span>
			<h1>Сотрудничество с Motor-Force</h1>
			<p>Приглашаем к партнёрству сервисные центры, магазины, салоны и другие компании, работающие с мототехникой и запчастями.</p>
			<p>Мы предлагаем удобные условия для регулярных закупок, выгодные цены и профессиональное сопровождение на всех этапах.</p>
			<a href="https://opt.motor-force.ru/" target="_blank" rel="noopener" class="mf-btn mf-btn--accent mf-btn--lg">
				Посетить оптовый сайт
				<?= mf_icon('arrow-right') ?>
			</a>
		</div>
	</header>

	<section class="mf-cooperation-block">
		<header class="mf-cooperation-block__head">
			<span class="mf-cooperation-block__icon" aria-hidden="true"><?= mf_icon('globe') ?></span>
			<h2>Работаем по всей России, СНГ и миру</h2>
		</header>
		<ul class="mf-cooperation-list">
			<li>Базируемся в Санкт-Петербурге</li>
			<li>Отправляем заказы по всей России, в страны СНГ и по миру</li>
			<li>Самовывоз со склада в Санкт-Петербурге</li>
		</ul>
	</section>

	<section class="mf-cooperation-block">
		<header class="mf-cooperation-block__head">
			<span class="mf-cooperation-block__icon" aria-hidden="true"><?= mf_icon('star') ?></span>
			<h2>Условия для партнёров</h2>
		</header>
		<ul class="mf-cooperation-list">
			<li>Индивидуальные скидки при оплате наличными и по безналу</li>
			<li>Возможность подписки на рассылку остатков склада — всегда актуальная информация</li>
			<li>Быстрая отгрузка со сторонних складов (1–4 дня до нашего офиса)</li>
			<li>Прямые поставки из США и Европы (сроки от 1–2 месяцев, возможны ускоренные варианты)</li>
			<li>Работа по системе дропшиппинга — отправляем напрямую вашим клиентам без наших документов или с вашими</li>
			<li>Удобная оплата — наличные, перевод на карту, безналичный перевод</li>
		</ul>
	</section>

	<section class="mf-cooperation-block">
		<header class="mf-cooperation-block__head">
			<span class="mf-cooperation-block__icon" aria-hidden="true"><?= mf_icon('engine') ?></span>
			<h2>Широкий ассортимент</h2>
		</header>
		<p>Мы поставляем оригинальные и качественные аналоговые запчасти и аксессуары для:</p>
		<ul class="mf-cooperation-chips">
			<li>Квадроциклов</li>
			<li>Снегоходов</li>
			<li>Мотоциклов</li>
			<li>Гидроциклов</li>
			<li>Лодочных моторов и другой мототехники</li>
		</ul>
		<p>Бренды: BRP (Can-Am, Ski-Doo, Sea-Doo, Lynx), Polaris, Yamaha, Arctic Cat, Kawasaki, Honda, Suzuki и многие другие.</p>
	</section>

	<section class="mf-cooperation-block">
		<header class="mf-cooperation-block__head">
			<span class="mf-cooperation-block__icon" aria-hidden="true"><?= mf_icon('flag') ?></span>
			<h2>Как начать сотрудничество</h2>
		</header>
		<ol class="mf-cooperation-steps">
			<li>
				<span class="mf-cooperation-steps__num">1</span>
				<p>Напишите нам на почту или через сайт — расскажите о вашем бизнесе (салон, магазин, сервис, прокат).</p>
			</li>
			<li>
				<span class="mf-cooperation-steps__num">2</span>
				<p>Пришлите ссылку на ваш сайт или краткое описание.</p>
			</li>
			<li>
				<span class="mf-cooperation-steps__num">3</span>
				<p>Зарегистрируйтесь на нашем оптовом сайте для доступа к ценам и заказам в любое время: <a href="https://opt.motor-force.ru/" target="_blank" rel="noopener"><strong>opt.motor-force.ru</strong></a></p>
			</li>
		</ol>
	</section>

	<section class="mf-cooperation-benefits">
		<h2>Выгода от сотрудничества</h2>
		<div class="mf-cooperation-benefits__grid">
			<article class="mf-cooperation-benefit">
				<div class="mf-cooperation-benefit__icon" aria-hidden="true"><?= mf_icon('check', ['class' => 'mf-icon mf-icon--lg']) ?></div>
				<h4>Наличие и под заказ</h4>
				<p>Товары есть в наличии и под заказ</p>
			</article>
			<article class="mf-cooperation-benefit">
				<div class="mf-cooperation-benefit__icon" aria-hidden="true"><?= mf_icon('wallet', ['class' => 'mf-icon mf-icon--lg']) ?></div>
				<h4>Скидки</h4>
				<p>Предоставляем скидки для партнёров</p>
			</article>
			<article class="mf-cooperation-benefit">
				<div class="mf-cooperation-benefit__icon" aria-hidden="true"><?= mf_icon('truck', ['class' => 'mf-icon mf-icon--lg']) ?></div>
				<h4>Дропшиппинг</h4>
				<p>Отправки напрямую вашим клиентам</p>
			</article>
		</div>
	</section>

	<section class="mf-cooperation-form" aria-label="Заявка на сотрудничество">
		<header class="mf-cooperation-form__head">
			<h2>Заявка на сотрудничество</h2>
			<p>Заполните форму — мы свяжемся с вами в ближайшее время.</p>
		</header>

		<?php if ($mfForm["ok"]): ?>
			<div class="mf-alert mf-alert--success">Заявка отправлена. Мы свяжемся с вами.</div>
		<?php elseif (!empty($mfForm["errors"])): ?>
			<div class="mf-alert mf-alert--danger">
				<ul class="mb-0">
					<?php foreach ($mfForm["errors"] as $e): ?>
						<li><?= htmlspecialchars($e, ENT_QUOTES, "UTF-8") ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form id="dynamic-form-sotrudnichestvo" method="post" action="" class="mf-form mf-form--grid js-no-tooltip">
			<?= bitrix_sessid_post() ?>
			<input type="hidden" name="mf_form_id" value="sotrudnichestvo">

			<label class="mf-field">
				<input required maxlength="100" type="text" id="mf-sotr-name" name="NAME" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["NAME"] ?? "", ENT_QUOTES, "UTF-8") ?>">
				<span>Ваше имя <em>*</em></span>
			</label>

			<label class="mf-field">
				<input required maxlength="100" type="tel" id="mf-sotr-phone" name="PHONE" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["PHONE"] ?? "", ENT_QUOTES, "UTF-8") ?>">
				<span>Контактный телефон <em>*</em></span>
			</label>

			<label class="mf-field">
				<input required maxlength="100" type="email" id="mf-sotr-email" name="EMAIL" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["EMAIL"] ?? "", ENT_QUOTES, "UTF-8") ?>">
				<span>E-mail <em>*</em></span>
			</label>

			<label class="mf-field">
				<input maxlength="100" type="text" id="mf-sotr-company" name="COMPANY" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["COMPANY"] ?? "", ENT_QUOTES, "UTF-8") ?>">
				<span>Название (магазина, сервиса, организации)</span>
			</label>

			<label class="mf-field">
				<input maxlength="100" type="text" id="mf-sotr-site" name="SITE" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["SITE"] ?? "", ENT_QUOTES, "UTF-8") ?>">
				<span>Ссылка на сайт (если есть)</span>
			</label>

			<label class="mf-field mf-field--full">
				<input maxlength="100" type="text" id="mf-sotr-interest" name="INTEREST" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["INTEREST"] ?? "", ENT_QUOTES, "UTF-8") ?>">
				<span>Напишите, что Вас интересует</span>
			</label>

			<button id="lead_form-button" class="mf-btn mf-btn--accent mf-btn--lg mf-form__submit" type="submit">
				Отправить заявку
				<?= mf_icon('arrow-right') ?>
			</button>
		</form>
	</section>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>
