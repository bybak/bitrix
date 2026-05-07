<?php
const MF_HIDE_TITLEBAR = true;
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Контакты");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");
?>

<div class="mf-contacts-page">

	<header class="mf-contacts-hero">
		<div class="mf-contacts-hero__icon" aria-hidden="true"><?= mf_icon('phone', ['class' => 'mf-icon mf-icon--xl']) ?></div>
		<div class="mf-contacts-hero__body">
			<span class="mf-contacts-hero__label">Motor-Force</span>
			<h1>Контакты</h1>
			<p>Свяжитесь с нами удобным способом — звоните, пишите в почту или мессенджеры.</p>
		</div>
	</header>

	<section class="mf-contacts-grid" aria-label="Способы связи">
		<article class="mf-contact-card mf-contact-card--address">
			<div class="mf-contact-card__head">
				<span class="mf-contact-card__icon" aria-hidden="true"><?= mf_icon('pin', ['class' => 'mf-icon mf-icon--lg']) ?></span>
				<h3>Адрес</h3>
			</div>
			<address>
				<strong>Россия, Санкт-Петербург</strong>
				ул. Салова, д. 57, к. 1, Литера Ч<br>
				2-й этаж. Офис № 1Н (Motor-Force)
			</address>
			<div class="mf-contact-card__map">
				<iframe src="https://motor-force.ru/contacts/showmap/863360/1/ru_RU" id="ymap-1" loading="lazy" frameborder="0" data-host="nethouse.ru" data-lang="ru_RU" title="Карта"></iframe>
			</div>
		</article>

		<article class="mf-contact-card">
			<div class="mf-contact-card__head">
				<span class="mf-contact-card__icon" aria-hidden="true"><?= mf_icon('phone', ['class' => 'mf-icon mf-icon--lg']) ?></span>
				<h3>Телефоны</h3>
			</div>
			<ul class="mf-contact-card__list">
				<li><a href="tel:+78129864276"><strong>8 (812) 986-42-76</strong></a><em>Магазин / Ремонт</em></li>
				<li><a href="tel:+79218837340"><strong>8 (921) 883-73-40</strong></a><em>Мегафон (Магазин / Ремонт)</em></li>
				<li><a href="tel:+79667519752"><strong>8 (966) 751-97-52</strong></a><em>Билайн (Магазин / Ремонт)</em></li>
				<li><a href="tel:+79934898464"><strong>8 (993) 489-84-64</strong></a><em>Прокат</em></li>
			</ul>
		</article>

		<article class="mf-contact-card">
			<div class="mf-contact-card__head">
				<span class="mf-contact-card__icon" aria-hidden="true"><?= mf_icon('clock', ['class' => 'mf-icon mf-icon--lg']) ?></span>
				<h3>Часы работы</h3>
			</div>
			<ul class="mf-contact-card__list mf-contact-card__list--time">
				<li><span>Пн&ndash;Чт</span><b>10:00&ndash;18:00</b></li>
				<li><span>Пт</span><b>10:00&ndash;17:00</b></li>
				<li><span>Обед</span><b>13:30&ndash;14:00</b></li>
				<li><span>Сб&ndash;Вс</span><b>Выходной</b></li>
			</ul>
		</article>

		<article class="mf-contact-card">
			<div class="mf-contact-card__head">
				<span class="mf-contact-card__icon" aria-hidden="true"><?= mf_icon('mail', ['class' => 'mf-icon mf-icon--lg']) ?></span>
				<h3>E-mail</h3>
			</div>
			<ul class="mf-contact-card__list">
				<li><a href="mailto:andrey@motor-force.ru"><strong>andrey@motor-force.ru</strong></a><em>Магазин / Ремонт</em></li>
				<li><a href="mailto:prokat@motor-force.ru"><strong>prokat@motor-force.ru</strong></a><em>Прокат</em></li>
			</ul>
		</article>

		<article class="mf-contact-card">
			<div class="mf-contact-card__head">
				<span class="mf-contact-card__icon" aria-hidden="true"><?= mf_icon('whatsapp', ['class' => 'mf-icon mf-icon--lg']) ?></span>
				<h3>WhatsApp</h3>
			</div>
			<ul class="mf-contact-card__list">
				<li><a href="https://wa.me/89218837340" target="_blank" rel="noopener"><strong>8 (921) 883-73-40</strong></a><em>Магазин / Ремонт</em></li>
				<li><a href="https://wa.me/89667519752" target="_blank" rel="noopener"><strong>8 (966) 751-97-52</strong></a><em>Магазин / Ремонт</em></li>
				<li><a href="https://wa.me/89934898464" target="_blank" rel="noopener"><strong>8 (993) 489-84-64</strong></a><em>Прокат</em></li>
			</ul>
		</article>

		<article class="mf-contact-card">
			<div class="mf-contact-card__head">
				<span class="mf-contact-card__icon" aria-hidden="true"><?= mf_icon('phone', ['class' => 'mf-icon mf-icon--lg']) ?></span>
				<h3>Viber</h3>
			</div>
			<ul class="mf-contact-card__list">
				<li><span><strong>8 (921) 883-73-40</strong></span><em>Магазин / Ремонт</em></li>
				<li><span><strong>8 (966) 751-97-52</strong></span><em>Магазин / Ремонт</em></li>
				<li><span><strong>8 (993) 489-84-64</strong></span><em>Прокат</em></li>
			</ul>
		</article>

		<article class="mf-contact-card">
			<div class="mf-contact-card__head">
				<span class="mf-contact-card__icon" aria-hidden="true"><?= mf_icon('telegram', ['class' => 'mf-icon mf-icon--lg']) ?></span>
				<h3>Telegram</h3>
			</div>
			<ul class="mf-contact-card__list">
				<li><a href="https://t.me/89218837340" target="_blank" rel="noopener"><strong>8 (921) 883-73-40</strong></a><em>Магазин / Ремонт</em></li>
				<li><a href="https://t.me/89667519752" target="_blank" rel="noopener"><strong>8 (966) 751-97-52</strong></a><em>Магазин / Ремонт</em></li>
				<li><a href="https://t.me/89934898464" target="_blank" rel="noopener"><strong>8 (993) 489-84-64</strong></a><em>Прокат</em></li>
			</ul>
		</article>
	</section>

	<section class="mf-contacts-socnets">
		<h2>Мы в социальных сетях</h2>
		<div class="mf-contacts-socnets__list">
			<a class="mf-contacts-socnets__item" target="_blank" rel="nofollow noopener" href="https://vk.ru/motor_force" aria-label="VK">
				<?= mf_icon('vk', ['class' => 'mf-icon mf-icon--lg']) ?>
				<span>VK</span>
			</a>
			<a class="mf-contacts-socnets__item" target="_blank" rel="nofollow noopener" href="https://instagram.com/motor_force.ru/" aria-label="Instagram">
				<?= mf_icon('instagram', ['class' => 'mf-icon mf-icon--lg']) ?>
				<span>Instagram</span>
			</a>
			<a class="mf-contacts-socnets__item" target="_blank" rel="nofollow noopener" href="https://t.me/https://t.me/motor_force" aria-label="Telegram">
				<?= mf_icon('telegram', ['class' => 'mf-icon mf-icon--lg']) ?>
				<span>Telegram</span>
			</a>
		</div>
	</section>

	<section class="mf-contacts-details">
		<header class="mf-contacts-details__head">
			<span class="mf-contacts-details__icon" aria-hidden="true"><?= mf_icon('invoice', ['class' => 'mf-icon mf-icon--lg']) ?></span>
			<h2>Наши реквизиты</h2>
		</header>

		<article class="mf-contacts-details__group">
			<h3>Наименование компании</h3>
			<dl class="mf-contacts-details__rows">
				<dt>Полное наименование</dt><dd>ИП Никитин Андрей Александрович</dd>
			</dl>
		</article>

		<article class="mf-contacts-details__group">
			<h3>Регистрационная информация</h3>
			<dl class="mf-contacts-details__rows">
				<dt>Фактический адрес</dt><dd>г. Санкт-Петербург, ул. Салова, д. 57, к. 1, Литера Ч, оф. 1Н</dd>
				<dt>ИНН</dt><dd>471803332762</dd>
				<dt>Банк получателя</dt><dd>СЕВЕРО-ЗАПАДНЫЙ БАНК ПАО СБЕРБАНК</dd>
				<dt>Расчётный счёт</dt><dd>40802810755000477709</dd>
				<dt>Корреспондентский счёт</dt><dd>30101810500000000653</dd>
				<dt>БИК</dt><dd>044030653</dd>
			</dl>
		</article>
	</section>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>
