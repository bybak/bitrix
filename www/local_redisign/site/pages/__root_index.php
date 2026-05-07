<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Motor-Force — запчасти и обслуживание мототехники");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");

$mfHeroCategories = [
	[
		'href' => '/products/category/zapchasti-dlya-kvadrotsiklov/',
		'title' => 'Квадроциклы',
		'sub' => 'оригинал и аналог',
		'icon' => 'bike',
	],
	[
		'href' => '/products/category/zapchasti-dlya-snegokhodov/',
		'title' => 'Снегоходы',
		'sub' => 'комплектующие и расходники',
		'icon' => 'snow',
	],
	[
		'href' => '/products/category/zapchasti-dlya-mototsiklov/',
		'title' => 'Мотоциклы',
		'sub' => 'все классы и кубатуры',
		'icon' => 'bike',
	],
	[
		'href' => '/products/category/zapchasti-dlya-gidrotsiklov/',
		'title' => 'Гидроциклы',
		'sub' => 'PWC: Sea-Doo, Yamaha, Kawasaki',
		'icon' => 'wave',
	],
	[
		'href' => '/products/category/zapchasti-dlya-lodochnykh-motorov/',
		'title' => 'Лодочные моторы',
		'sub' => '2T и 4T, любой производитель',
		'icon' => 'boat',
	],
	[
		'href' => '/products/category/aksessuary-i-komplektuyushchie-dlya-katerov-lodok-i-yakht/',
		'title' => 'Аксессуары для катеров и лодок',
		'sub' => 'оснастка, экипировка, комплектация',
		'icon' => 'anchor',
	],
];

$mfServices = [
	[
		'href' => '/remont_motorov/',
		'title' => 'Ремонт ДВС',
		'desc' => 'Лодочные моторы, гидро- и квадроциклы, снегоходы, мотоциклы. Оборудование для гильзовки, расточки и притирки.',
		'icon' => 'tools',
		'cta' => 'Узнать стоимость',
	],
	[
		'href' => '/prokat/',
		'title' => 'Прокат техники',
		'desc' => 'Прокат квадроциклов и прицепов. С доставкой по СПб и Ленобласти. Прозрачные тарифы и понятные правила.',
		'icon' => 'calendar',
		'cta' => 'Условия аренды',
	],
	[
		'href' => '/vikup_mototehniki/',
		'title' => 'Выкуп мототехники',
		'desc' => 'Мы выкупаем мото-, квадро-, гидротехнику и лодочные моторы — целиком или на запчасти. Быстрая оценка.',
		'icon' => 'wallet',
		'cta' => 'Получить оценку',
	],
	[
		'href' => '/sotrudnichestvo/',
		'title' => 'Опт и сотрудничество',
		'desc' => 'Магазинам, сервисам и прокатам — индивидуальные условия, доступ к оптовому сайту и стабильное наличие.',
		'icon' => 'handshake',
		'cta' => 'Стать партнёром',
	],
];

$mfBenefits = [
	[ 'icon' => 'truck',    'title' => 'Доставка по РФ',     'desc' => 'Транспортные компании, СДЭК, Почта России. Стоимость и сроки рассчитаем при заказе.' ],
	[ 'icon' => 'box',      'title' => 'Большой ассортимент', 'desc' => 'Тысячи позиций в наличии и под заказ. От расходников до редких узлов.' ],
	[ 'icon' => 'shield',   'title' => 'Гарантия качества',   'desc' => 'Только проверенные поставщики. По товарам — гарантия и обмен брака.' ],
	[ 'icon' => 'wrench',   'title' => 'Сервис под ключ',     'desc' => 'Ремонт ДВС, диагностика, обслуживание и профилактика — у себя или с приёмом по согласованию.' ],
];
?>

<section class="mf-home-hero">
	<div class="mf-home-hero__inner">
		<div class="mf-home-hero__copy">
			<span class="mf-home-hero__eyebrow">Motor-Force · Санкт-Петербург</span>
			<h1 class="mf-home-hero__title">Запчасти<br>и&nbsp;обслуживание<br><span class="mf-home-hero__title-accent">мототехники</span></h1>
			<p class="mf-home-hero__lead">
				Квадроциклы, снегоходы, мотоциклы, гидроциклы и&nbsp;лодочные моторы.
				Оригинал и&nbsp;аналог, ремонт ДВС, прокат, опт.
			</p>
			<div class="mf-home-hero__actions">
				<a class="mf-btn mf-btn--accent mf-btn--lg" href="/products/">
					<?=function_exists('mf_icon') ? mf_icon('search', ['width' => 18, 'height' => 18]) : ''?>
					<span>Перейти в каталог</span>
				</a>
				<a class="mf-btn mf-btn--ghost mf-btn--lg mf-btn--ghost-on-dark" href="#mf-home-services">
					<span>Услуги сервиса</span>
				</a>
			</div>
			<dl class="mf-home-hero__stats">
				<div>
					<dt>17+</dt>
					<dd>лет в&nbsp;мото&shy;тематике</dd>
				</div>
				<div>
					<dt>10&nbsp;000+</dt>
					<dd>позиций в&nbsp;наличии и&nbsp;под заказ</dd>
				</div>
				<div>
					<dt>RU&nbsp;+&nbsp;мир</dt>
					<dd>доставка по&nbsp;всей стране</dd>
				</div>
			</dl>
		</div>
		<div class="mf-home-hero__art" aria-hidden="true">
			<div class="mf-home-hero__art-glow"></div>
			<div class="mf-home-hero__art-card mf-home-hero__art-card--1">
				<?=function_exists('mf_icon') ? mf_icon('bike', ['width' => 56, 'height' => 56]) : ''?>
				<span>Квадроциклы</span>
			</div>
			<div class="mf-home-hero__art-card mf-home-hero__art-card--2">
				<?=function_exists('mf_icon') ? mf_icon('wave', ['width' => 56, 'height' => 56]) : ''?>
				<span>Гидроциклы</span>
			</div>
			<div class="mf-home-hero__art-card mf-home-hero__art-card--3">
				<?=function_exists('mf_icon') ? mf_icon('boat', ['width' => 56, 'height' => 56]) : ''?>
				<span>Лодочные моторы</span>
			</div>
		</div>
	</div>
	<div class="mf-home-hero__pattern" aria-hidden="true"></div>
</section>

<section class="mf-home-search" aria-labelledby="mf-home-search-title">
	<div class="mf-home-search__inner">
		<div class="mf-home-search__head">
			<h2 id="mf-home-search-title" class="mf-home-search__title">Поиск по&nbsp;каталогу</h2>
			<p class="mf-home-search__lead">Введите артикул, OEM, модель или название узла — мы&nbsp;найдём оригинал и&nbsp;подберём аналог.</p>
		</div>
		<form class="mf-home-search__form" method="get" action="/search/">
			<input type="hidden" name="how" value="r" />
			<label class="mf-home-search__label" for="mfHomeSearchInput">Поиск</label>
			<div class="mf-home-search__field">
				<span class="mf-home-search__ico" aria-hidden="true"><?=function_exists('mf_icon') ? mf_icon('search', ['width' => 22, 'height' => 22]) : ''?></span>
				<input
					id="mfHomeSearchInput"
					class="mf-home-search__input"
					type="text"
					name="q"
					placeholder="Например: 422280283 или ремень BRP"
					autocomplete="off"
				/>
				<button class="mf-home-search__btn" type="submit">Найти</button>
			</div>
			<div class="mf-home-search__hints">
				<span>Популярные запросы:</span>
				<a href="/search/?q=%D1%80%D0%B5%D0%BC%D0%B5%D0%BD%D1%8C%20BRP&amp;how=r">ремень BRP</a>
				<a href="/search/?q=%D1%81%D0%B2%D0%B5%D1%87%D0%B8%20%D0%B7%D0%B0%D0%B6%D0%B8%D0%B3%D0%B0%D0%BD%D0%B8%D1%8F&amp;how=r">свечи зажигания</a>
				<a href="/search/?q=Yamaha%20%D0%BC%D0%B0%D1%81%D0%BB%D0%BE&amp;how=r">Yamaha масло</a>
			</div>
		</form>
	</div>
</section>

<section class="mf-home-cats" aria-labelledby="mf-home-cats-title">
	<header class="mf-home-section-head">
		<span class="mf-home-section-head__eyebrow">Каталог</span>
		<h2 id="mf-home-cats-title" class="mf-home-section-head__title">Запчасти по&nbsp;типу техники</h2>
		<p class="mf-home-section-head__lead">Шесть направлений — выбирайте свой и&nbsp;погружайтесь в&nbsp;каталог. Если не&nbsp;нашли позицию — напишите, подберём.</p>
	</header>
	<div class="mf-home-cats__grid">
		<?php foreach ($mfHeroCategories as $i => $cat): ?>
			<a class="mf-home-cat-card" href="<?=htmlspecialcharsbx($cat['href'])?>" data-mf-anim="cat" style="--mf-cat-i: <?=$i?>;">
				<span class="mf-home-cat-card__ico" aria-hidden="true">
					<?=function_exists('mf_icon') ? mf_icon($cat['icon'], ['width' => 28, 'height' => 28]) : ''?>
				</span>
				<span class="mf-home-cat-card__title"><?=htmlspecialcharsbx($cat['title'])?></span>
				<span class="mf-home-cat-card__sub"><?=htmlspecialcharsbx($cat['sub'])?></span>
				<span class="mf-home-cat-card__arrow" aria-hidden="true">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
				</span>
			</a>
		<?php endforeach; ?>
	</div>
	<div class="mf-home-cats__cta">
		<a class="mf-btn mf-btn--accent" href="/products/">Смотреть весь каталог</a>
	</div>
</section>

<section id="mf-home-services" class="mf-home-services" aria-labelledby="mf-home-services-title">
	<header class="mf-home-section-head mf-home-section-head--on-dark">
		<span class="mf-home-section-head__eyebrow">Услуги</span>
		<h2 id="mf-home-services-title" class="mf-home-section-head__title">Сервис, прокат, выкуп, опт</h2>
		<p class="mf-home-section-head__lead">Мы&nbsp;не&nbsp;только продаём запчасти&nbsp;— мы&nbsp;обслуживаем, ремонтируем, выкупаем и&nbsp;даём в&nbsp;прокат.</p>
	</header>
	<div class="mf-home-services__grid">
		<?php foreach ($mfServices as $svc): ?>
			<article class="mf-home-service-card">
				<span class="mf-home-service-card__ico" aria-hidden="true">
					<?=function_exists('mf_icon') ? mf_icon($svc['icon'], ['width' => 32, 'height' => 32]) : ''?>
				</span>
				<h3 class="mf-home-service-card__title"><?=htmlspecialcharsbx($svc['title'])?></h3>
				<p class="mf-home-service-card__desc"><?=htmlspecialcharsbx($svc['desc'])?></p>
				<a class="mf-home-service-card__cta" href="<?=htmlspecialcharsbx($svc['href'])?>">
					<?=htmlspecialcharsbx($svc['cta'])?>
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
				</a>
			</article>
		<?php endforeach; ?>
	</div>
</section>

<section class="mf-home-benefits" aria-labelledby="mf-home-benefits-title">
	<header class="mf-home-section-head">
		<span class="mf-home-section-head__eyebrow">Почему&nbsp;мы</span>
		<h2 id="mf-home-benefits-title" class="mf-home-section-head__title">Чем мы отличаемся</h2>
	</header>
	<div class="mf-home-benefits__grid">
		<?php foreach ($mfBenefits as $bn): ?>
			<div class="mf-home-benefit">
				<span class="mf-home-benefit__ico" aria-hidden="true">
					<?=function_exists('mf_icon') ? mf_icon($bn['icon'], ['width' => 26, 'height' => 26]) : ''?>
				</span>
				<h3 class="mf-home-benefit__title"><?=htmlspecialcharsbx($bn['title'])?></h3>
				<p class="mf-home-benefit__desc"><?=htmlspecialcharsbx($bn['desc'])?></p>
			</div>
		<?php endforeach; ?>
	</div>
</section>

<section class="mf-home-news" aria-labelledby="mf-home-news-title">
	<header class="mf-home-section-head mf-home-section-head--inline">
		<div>
			<span class="mf-home-section-head__eyebrow">Новости</span>
			<h2 id="mf-home-news-title" class="mf-home-section-head__title">События и&nbsp;объявления</h2>
		</div>
		<a class="mf-home-news__all" href="/posts/">Все новости
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
		</a>
	</header>
	<div class="mf-home-news__list">
		<?php
		$APPLICATION->IncludeComponent(
			"bitrix:news.list",
			"mf_main_posts_slider",
			[
				"IBLOCK_TYPE" => "news",
				"IBLOCK_ID" => "1",
				"NEWS_COUNT" => "3",
				"SORT_BY1" => "ACTIVE_FROM",
				"SORT_ORDER1" => "DESC",
				"SORT_BY2" => "SORT",
				"SORT_ORDER2" => "ASC",
				"CHECK_DATES" => "Y",
				"DETAIL_URL" => "/posts/#ELEMENT_CODE#/",
				"FIELD_CODE" => [
					"DATE_ACTIVE_FROM",
					"DATE_CREATE",
					"PREVIEW_TEXT",
					"PREVIEW_TEXT_TYPE",
					"DETAIL_TEXT",
					"DETAIL_TEXT_TYPE",
				],
				"PROPERTY_CODE" => [],
				"SET_TITLE" => "N",
				"INCLUDE_IBLOCK_INTO_CHAIN" => "N",
				"ADD_SECTIONS_CHAIN" => "N",
				"DISPLAY_DATE" => "Y",
				"DISPLAY_NAME" => "Y",
				"DISPLAY_PICTURE" => "N",
				"DISPLAY_PREVIEW_TEXT" => "Y",
				"ACTIVE_DATE_FORMAT" => "d.m.Y",
				"PREVIEW_TRUNCATE_LEN" => "180",
				"CACHE_TYPE" => "A",
				"CACHE_TIME" => "36000000",
				"CACHE_FILTER" => "N",
				"CACHE_GROUPS" => "Y",
			],
			false
		);
		?>
	</div>
</section>

<section class="mf-home-lead" id="lead-form" aria-labelledby="mf-home-lead-title">
	<div class="mf-home-lead__inner">
		<div class="mf-home-lead__copy">
			<span class="mf-home-section-head__eyebrow">Заявка</span>
			<h2 id="mf-home-lead-title" class="mf-home-lead__title">Не&nbsp;нашли запчасть? Подберём&nbsp;её.</h2>
			<p class="mf-home-lead__lead">Оставьте заявку — укажите модель, VIN&nbsp;или артикул, мы&nbsp;найдём, рассчитаем стоимость и&nbsp;сроки доставки.</p>
			<ul class="mf-home-lead__bullets">
				<li>Подбор по&nbsp;VIN, OEM или артикулу</li>
				<li>Бесплатная консультация по&nbsp;аналогам</li>
				<li>Доставка по&nbsp;РФ и&nbsp;СНГ</li>
			</ul>
		</div>
		<form
			id="lead_form"
			class="mf-home-lead__form dynamic-form js-no-tooltip dynamic-form-3"
			data-type="3"
			method="POST"
			action="/mailer/sendmessage/"
		>
			<input type="hidden" name="urnAnchor" value="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzaXRlSWQiOiI1NzIwOTgiLCJ1cm5BbmNob3IiOiJcLyNsZWFkLWZvcm0ifQ.p6_iAlVsFhf8mG5BUqz5HFKR-Q1Evdo8lE_dQxI5O6o"/>
			<input type="hidden" name="formType" value="3" id="formType">
			<input type="hidden" name="token_key" value="7cda694f49f860ed62b67eb43ac393fd19195e27dc042e3ed5210d87189c7269" id="token_key">
			<input type="hidden" name="token" value="88fa072bfc30752fac9e15c68f35c70dd401c97d4520029ecc9edd1b4ca6874b" id="token">
			<input class="lead-form__lastname" type="text" name="lastname" value="" id="lastname">

			<div class="mf-home-lead__row mf-home-lead__row--two">
				<div class="mf-home-lead__field">
					<label for="fio">Ваше имя</label>
					<input type="text" name="fio" id="fio" maxlength="100" class="dynamic-form-required dynamic-form-fio dynamic-form-text" placeholder="Иван Иванов">
				</div>
				<div class="mf-home-lead__field">
					<label for="phone">Контактный телефон</label>
					<input type="text" name="phone" id="phone" maxlength="100" class="dynamic-form-required dynamic-form-phone dynamic-form-text" placeholder="+7 (___) ___-__-__">
				</div>
			</div>

			<div class="mf-home-lead__field">
				<label for="email">E-mail</label>
				<input type="text" name="email" id="email" maxlength="100" class="dynamic-form-required dynamic-form-email dynamic-form-text" placeholder="you@example.com">
			</div>

			<div class="mf-home-lead__row mf-home-lead__row--two">
				<div class="mf-home-lead__field">
					<label for="field2">Марка</label>
					<input type="text" name="field2" id="field2" maxlength="100" class="dynamic-form-field2 dynamic-form-text" placeholder="BRP, Yamaha, ...">
				</div>
				<div class="mf-home-lead__field">
					<label for="field3">Модель</label>
					<input type="text" name="field3" id="field3" maxlength="100" class="dynamic-form-field3 dynamic-form-text" placeholder="Например: Outlander 800">
				</div>
			</div>

			<div class="mf-home-lead__row mf-home-lead__row--two">
				<div class="mf-home-lead__field">
					<label for="field4">Модельный год</label>
					<input type="text" name="field4" id="field4" maxlength="100" class="dynamic-form-field4 dynamic-form-text" placeholder="2018">
				</div>
				<div class="mf-home-lead__field">
					<label for="field6">Объём двигателя</label>
					<input type="text" name="field6" id="field6" maxlength="100" class="dynamic-form-field6 dynamic-form-text" placeholder="800 куб. см">
				</div>
			</div>

			<div class="mf-home-lead__field">
				<label for="field1">VIN номер (если знаете)</label>
				<input type="text" name="field1" id="field1" maxlength="100" class="dynamic-form-field1 dynamic-form-text" placeholder="VIN техники">
			</div>

			<div class="mf-home-lead__field">
				<label for="field5">Что нужно подобрать?</label>
				<textarea name="field5" id="field5" maxlength="1000" class="dynamic-form-required dynamic-form-field5 dynamic-form-textarea" rows="4" placeholder="Опишите, что нужно: артикулы, узел, фото..."></textarea>
			</div>

			<button id="lead_form-button" class="mf-home-lead__submit dynamic-form-button-3" type="submit">
				<?=function_exists('mf_icon') ? mf_icon('send', ['width' => 18, 'height' => 18]) : ''?>
				<span>Отправить заявку</span>
			</button>
			<p class="mf-home-lead__hint" data-mf="lead-form-result"></p>
		</form>
	</div>
</section>

<section class="mf-home-contacts">
	<div class="mf-home-contacts__inner">
		<div class="mf-home-contacts__head">
			<span class="mf-home-section-head__eyebrow">Контакты</span>
			<h2 class="mf-home-section-head__title">Мы&nbsp;на&nbsp;связи</h2>
			<p class="mf-home-section-head__lead">Звоните, пишите в&nbsp;мессенджеры или приезжайте — поможем подобрать запчасти и&nbsp;ответим на&nbsp;вопросы.</p>
		</div>
		<div class="mf-home-contacts__grid">
			<div class="mf-home-contact">
				<span class="mf-home-contact__ico" aria-hidden="true"><?=function_exists('mf_icon') ? mf_icon('map', ['width' => 22, 'height' => 22]) : ''?></span>
				<div>
					<div class="mf-home-contact__label">Адрес</div>
					<div class="mf-home-contact__val">Санкт-Петербург, ул.&nbsp;Салова, 57к1, литера&nbsp;Ч</div>
					<div class="mf-home-contact__sub">2-й этаж, офис&nbsp;1Н</div>
				</div>
			</div>
			<div class="mf-home-contact">
				<span class="mf-home-contact__ico" aria-hidden="true"><?=function_exists('mf_icon') ? mf_icon('clock', ['width' => 22, 'height' => 22]) : ''?></span>
				<div>
					<div class="mf-home-contact__label">Часы работы</div>
					<div class="mf-home-contact__val">Пн—Чт&nbsp;10:00–18:00, Пт&nbsp;10:00–17:00</div>
					<div class="mf-home-contact__sub">Сб—Вс — выходной. Обед 13:30–14:00</div>
				</div>
			</div>
			<div class="mf-home-contact">
				<span class="mf-home-contact__ico" aria-hidden="true"><?=function_exists('mf_icon') ? mf_icon('phone', ['width' => 22, 'height' => 22]) : ''?></span>
				<div>
					<div class="mf-home-contact__label">Магазин и&nbsp;ремонт</div>
					<a class="mf-home-contact__val mf-home-contact__val--link" href="tel:+78129864276">8 (812) 986-42-76</a>
					<a class="mf-home-contact__val mf-home-contact__val--link" href="tel:+79218837340">8 (921) 883-73-40</a>
				</div>
			</div>
			<div class="mf-home-contact">
				<span class="mf-home-contact__ico" aria-hidden="true"><?=function_exists('mf_icon') ? mf_icon('phone', ['width' => 22, 'height' => 22]) : ''?></span>
				<div>
					<div class="mf-home-contact__label">Прокат</div>
					<a class="mf-home-contact__val mf-home-contact__val--link" href="tel:+79934898464">8 (993) 489-84-64</a>
				</div>
			</div>
			<div class="mf-home-contact">
				<span class="mf-home-contact__ico" aria-hidden="true"><?=function_exists('mf_icon') ? mf_icon('mail', ['width' => 22, 'height' => 22]) : ''?></span>
				<div>
					<div class="mf-home-contact__label">E-mail</div>
					<a class="mf-home-contact__val mf-home-contact__val--link" href="mailto:andrey@motor-force.ru">andrey@motor-force.ru</a>
					<a class="mf-home-contact__val mf-home-contact__val--link" href="mailto:prokat@motor-force.ru">prokat@motor-force.ru</a>
				</div>
			</div>
			<div class="mf-home-contact">
				<span class="mf-home-contact__ico" aria-hidden="true"><?=function_exists('mf_icon') ? mf_icon('chat', ['width' => 22, 'height' => 22]) : ''?></span>
				<div>
					<div class="mf-home-contact__label">Мессенджеры</div>
					<div class="mf-home-contact__chips">
						<a href="https://wa.me/89218837340" target="_blank" rel="nofollow noopener">WhatsApp</a>
						<a href="https://t.me/89218837340" target="_blank" rel="nofollow noopener">Telegram</a>
						<a href="https://vk.ru/motor_force" target="_blank" rel="nofollow noopener">ВКонтакте</a>
					</div>
				</div>
			</div>
		</div>
		<div class="mf-home-contacts__cta">
			<a class="mf-btn mf-btn--accent mf-btn--lg" href="/contacts/">Все контакты и&nbsp;карта</a>
		</div>
	</div>
</section>

<section class="mf-home-subscribe" aria-labelledby="mf-home-subscribe-title">
	<div class="mf-home-subscribe__inner">
		<div class="mf-home-subscribe__copy">
			<span class="mf-home-section-head__eyebrow">Рассылка</span>
			<h2 id="mf-home-subscribe-title" class="mf-home-subscribe__title">Будьте в&nbsp;курсе новинок и&nbsp;акций</h2>
			<p class="mf-home-subscribe__lead">Раз в&nbsp;месяц&nbsp;— только новости компании, поступления редких узлов и&nbsp;спецпредложения. Без спама.</p>
		</div>
		<div class="mf-home-subscribe__form">
			<?php
			$APPLICATION->IncludeComponent(
				"bitrix:sender.subscribe",
				"mf_main_subscribe",
				[
					"SET_TITLE" => "N",
					"HIDE_MAILINGS" => "Y",
				],
				false
			);
			?>
		</div>
	</div>
</section>

<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>
