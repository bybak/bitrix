<?php
const MF_HIDE_TITLEBAR = true;
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("О магазине");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");
?>

<div class="mf-about mf-text-page user-inner mb-4">

	<header class="mf-cooperation-hero">
		<div class="mf-cooperation-hero__icon" aria-hidden="true"><?= function_exists('mf_icon') ? mf_icon('engine', ['class' => 'mf-icon mf-icon--xl']) : '' ?></div>
		<div class="mf-cooperation-hero__body">
			<span class="mf-cooperation-hero__label">Motor-Force</span>
			<h1>О магазине</h1>
			<p>Мы занимаемся запчастями, аксессуарами и обслуживанием мототехники: квадроциклы, снегоходы, гидроциклы, лодочные моторы. Базируемся в Санкт-Петербурге, отправляем по всей России и миру.</p>
		</div>
	</header>

	<section class="mf-cooperation-block">
		<header class="mf-cooperation-block__head">
			<span class="mf-cooperation-block__icon" aria-hidden="true"><?= function_exists('mf_icon') ? mf_icon('star') : '' ?></span>
			<h2>Что мы делаем</h2>
		</header>
		<ul class="mf-cooperation-list">
			<li>Поставляем оригинальные запчасти и качественные аналоги для мототехники.</li>
			<li>Ремонтируем двигатели внутреннего сгорания, восстанавливаем ЦПГ и коленвалы.</li>
			<li>Сдаём в прокат квадроциклы в Санкт-Петербурге и Ленинградской области.</li>
			<li>Выкупаем мототехнику в любом состоянии по всей РФ.</li>
		</ul>
	</section>

	<section class="mf-cooperation-block">
		<header class="mf-cooperation-block__head">
			<span class="mf-cooperation-block__icon" aria-hidden="true"><?= function_exists('mf_icon') ? mf_icon('flag') : '' ?></span>
			<h2>Бренды, с которыми работаем</h2>
		</header>
		<ul class="mf-cooperation-chips">
			<li>BRP / Bombardier</li>
			<li>Ski-Doo · Lynx · Can-Am</li>
			<li>Polaris · Arctic Cat</li>
			<li>Yamaha · Kawasaki · Suzuki · Honda</li>
			<li>Evinrude · Johnson · Mercury · Mariner · Tohatsu</li>
		</ul>
	</section>

	<section class="mf-cooperation-benefits">
		<h2>Почему выбирают Motor-Force</h2>
		<div class="mf-cooperation-benefits__grid">
			<article class="mf-cooperation-benefit">
				<div class="mf-cooperation-benefit__icon"><?= function_exists('mf_icon') ? mf_icon('lightning', ['class' => 'mf-icon mf-icon--lg']) : '' ?></div>
				<h4>Быстрая отгрузка</h4>
				<p>В наличии и под заказ — отправляем со склада в течение 1–4 дней.</p>
			</article>
			<article class="mf-cooperation-benefit">
				<div class="mf-cooperation-benefit__icon"><?= function_exists('mf_icon') ? mf_icon('shield', ['class' => 'mf-icon mf-icon--lg']) : '' ?></div>
				<h4>Гарантия и качество</h4>
				<p>Только проверенные поставщики и оригинальные позиции от производителей.</p>
			</article>
			<article class="mf-cooperation-benefit">
				<div class="mf-cooperation-benefit__icon"><?= function_exists('mf_icon') ? mf_icon('globe', ['class' => 'mf-icon mf-icon--lg']) : '' ?></div>
				<h4>Доставка по всему миру</h4>
				<p>Россия, СНГ и весь мир — Почта России, СДЭК, ТК, EMS, DHL и другие.</p>
			</article>
			<article class="mf-cooperation-benefit">
				<div class="mf-cooperation-benefit__icon"><?= function_exists('mf_icon') ? mf_icon('wrench', ['class' => 'mf-icon mf-icon--lg']) : '' ?></div>
				<h4>Сервис и ремонт</h4>
				<p>Ремонт двигателей с дефектовкой и сметой, прозрачные цены и сроки.</p>
			</article>
		</div>
	</section>

	<section class="mf-cooperation-block">
		<header class="mf-cooperation-block__head">
			<span class="mf-cooperation-block__icon" aria-hidden="true"><?= function_exists('mf_icon') ? mf_icon('mail') : '' ?></span>
			<h2>Связаться с нами</h2>
		</header>
		<p>Мы всегда рады общению с нашими клиентами. Если у вас есть пожелания, предложения, замечания, касающиеся работы нашего магазина — пишите нам, и мы с благодарностью примем ваше мнение во внимание.</p>
		<p><a class="mf-btn mf-btn--accent" href="/contacts/">Все контакты</a></p>
	</section>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>
