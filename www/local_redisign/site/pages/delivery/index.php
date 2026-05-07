<?php
const MF_HIDE_TITLEBAR = true;
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Доставка");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");
$APPLICATION->AddChainItem("Доставка", "/delivery/");
?>

<div class="mf-delivery mf-text-page mb-4">

	<section class="mf-delivery-hero">
		<div class="mf-delivery-hero__icon" aria-hidden="true"><?= mf_icon('truck', ['class' => 'mf-icon mf-icon--xl']) ?></div>
		<div class="mf-delivery-hero__body">
			<h1 class="mf-delivery-hero__title">Доставка</h1>
			<p class="mf-delivery-hero__lead">Мы доставляем заказы по всей России и миру. Стоимость и сроки зависят от габаритов, веса, типа товара и адреса получателя. Доставка оплачивается клиентом отдельно.</p>
			<p class="mf-delivery-hero__lead">Даже если вы не нашли нужный вариант в списке — мы почти всегда сможем его организовать. Просто уточните при заказе — мы подберём для вас оптимальное решение!</p>
		</div>
	</section>

	<section class="mf-delivery-section">
		<header class="mf-delivery-section__head">
			<span class="mf-delivery-section__icon" aria-hidden="true"><?= mf_icon('home') ?></span>
			<h2>Самовывоз</h2>
		</header>
		<div class="mf-delivery-pickup">
			<div class="mf-delivery-pickup__info">
				<p>Вы можете самостоятельно забрать заказ из нашего офиса в Санкт-Петербурге.</p>
				<dl class="mf-delivery-meta">
					<dt><?= mf_icon('pin') ?> Адрес</dt>
					<dd>Россия, Санкт-Петербург, ул. Салова, д. 57, к. 1, Литера Ч, 2-й этаж, Офис № 1Н (Motor-Force)</dd>
					<dt><?= mf_icon('clock') ?> Время работы</dt>
					<dd>
						<ul class="mf-delivery-hours">
							<li><span>Пн&ndash;Чт</span><b>10:00&ndash;18:00</b></li>
							<li><span>Пт</span><b>10:00&ndash;17:00</b></li>
							<li><span>Обед</span><b>13:30&ndash;14:00</b></li>
							<li><span>Сб, Вс</span><b>Выходной</b></li>
						</ul>
						<p class="mf-delivery-note"><em>В праздничные дни режим работы может меняться.</em></p>
					</dd>
				</dl>
			</div>
			<figure class="mf-delivery-media">
				<figcaption>Схема проезда</figcaption>
				<img src="//i.siteapi.org/KkXI67E_5GCGC-S33tGsV8SbBhg=/0x0:917x657/s.siteapi.org/ccdb0156d66a088.ru/img/8hnyinr6a7ksg084s0ck88g0c8owoo" class="fancy-img-post" data-eval="//i.siteapi.org/vQ1mGyycm7iKBBed0e81wc53I9Y=/s.siteapi.org/ccdb0156d66a088.ru/img/8hnyinr6a7ksg084s0ck88g0c8owoo" alt="Схема проезда" loading="lazy">
			</figure>
		</div>
	</section>

	<section class="mf-delivery-section">
		<header class="mf-delivery-section__head">
			<span class="mf-delivery-section__icon" aria-hidden="true"><?= mf_icon('route') ?></span>
			<h2>Доставка по Санкт-Петербургу</h2>
		</header>
		<ul class="mf-delivery-options">
			<li><span class="mf-delivery-options__name">Курьер Motor-Force</span><span class="mf-delivery-options__hint">уточняется возможность доставки</span></li>
			<li><span class="mf-delivery-options__name">Яндекс Доставка</span><span class="mf-delivery-options__hint">стоимость и срок в реальном времени</span></li>
			<li><span class="mf-delivery-options__name">Такси</span><span class="mf-delivery-options__hint">стоимость и срок в реальном времени</span></li>
			<li><span class="mf-delivery-options__name">Курьер СДЭК, КЭС, Пони Экспресс, Boxberry и др.</span><span class="mf-delivery-options__hint">на следующий день или день в день</span></li>
			<li><span class="mf-delivery-options__name">Пункты выдачи СДЭК, КЭС, Пони Экспресс, Boxberry и др.</span><span class="mf-delivery-options__hint">по согласованию, день в день или на следующий</span></li>
		</ul>
	</section>

	<section class="mf-delivery-section">
		<header class="mf-delivery-section__head">
			<span class="mf-delivery-section__icon" aria-hidden="true"><?= mf_icon('box') ?></span>
			<h2>Доставка в Москву и регионы России</h2>
		</header>
		<div class="mf-delivery-grid">
			<article class="mf-delivery-card">
				<h4>Почта России</h4>
				<ul>
					<li>EMS Почты России (до 31,5 кг; сумма измерений трёх сторон не более 300 см; любая сторона не более 150 см)</li>
					<li>1-й класс Почты России (до 5 кг; сумма трёх измерений не более 70 см; любое измерение не более 36 см)</li>
					<li>Обычная посылка Почты России (до 20 кг; сумма измерений трёх сторон не более 300 см; любая сторона не более 150 см)</li>
				</ul>
			</article>
			<article class="mf-delivery-card">
				<h4>Транспортные компании</h4>
				<ul class="mf-delivery-card__chips">
					<li>Возовоз</li><li>DPD</li><li>Энергия</li><li>КИТ</li><li>ПЭК</li><li>Деловые Линии</li><li>И другие — по согласованию</li>
				</ul>
			</article>
			<article class="mf-delivery-card">
				<h4>Курьерские службы</h4>
				<ul class="mf-delivery-card__chips">
					<li>Boxberry</li><li>Пони Экспресс</li><li>Курьер Экспресс Сервис</li><li>СДЭК</li><li>И другие — по согласованию</li>
				</ul>
			</article>
		</div>
	</section>

	<section class="mf-delivery-section">
		<header class="mf-delivery-section__head">
			<span class="mf-delivery-section__icon" aria-hidden="true"><?= mf_icon('globe') ?></span>
			<h2>Доставка в другие страны</h2>
		</header>
		<ul class="mf-delivery-card__chips mf-delivery-card__chips--big">
			<li>DHL</li><li>Энергия</li><li>КИТ</li><li>ПЭК</li><li>Деловые Линии</li><li>СДЭК</li><li>Почта России</li><li>EMS</li><li>И другие — по согласованию</li>
		</ul>
	</section>

	<section class="mf-delivery-section">
		<header class="mf-delivery-section__head">
			<span class="mf-delivery-section__icon" aria-hidden="true"><?= mf_icon('cash') ?></span>
			<h2>Наложенный платёж</h2>
		</header>
		<p>Вы можете оплатить заказ наложенным платежом:</p>
		<ul class="mf-delivery-list">
			<li>Наложенным платежом в СДЭКе</li>
			<li>Наложенным платежом в EMS</li>
			<li>Наложенным платежом в Почте России</li>
			<li>И другие варианты — по согласованию</li>
		</ul>
		<p class="mf-delivery-note">Другие ТК и прочие варианты доставки в основном доступны только по предоплате.</p>
	</section>

	<section class="mf-delivery-section">
		<header class="mf-delivery-section__head">
			<span class="mf-delivery-section__icon" aria-hidden="true"><?= mf_icon('info') ?></span>
			<h2>Важно знать</h2>
		</header>
		<ul class="mf-delivery-bullets">
			<li>Доставка всегда оплачивается отдельно и рассчитывается индивидуально</li>
			<li>Итоговая стоимость зависит от веса, объёма, направления и выбранной службы доставки</li>
			<li>Мы всегда согласовываем стоимость доставки с клиентом перед отправкой</li>
			<li>При необходимости можем предложить несколько вариантов на выбор</li>
		</ul>
	</section>

	<section class="mf-delivery-section mf-delivery-cta">
		<div class="mf-delivery-cta__head">
			<h2>Остались вопросы?</h2>
			<p>Свяжитесь с нами — поможем выбрать лучший способ доставки и рассчитаем стоимость.</p>
		</div>
		<div class="mf-delivery-cta__contacts">
			<a class="mf-delivery-cta__item" href="tel:+78129864276">
				<?= mf_icon('phone', ['class' => 'mf-icon mf-icon--lg']) ?>
				<span><strong>8 (812) 986-42-76</strong><em>Звонок по будням</em></span>
			</a>
			<a class="mf-delivery-cta__item" href="mailto:andrey@motor-force.ru">
				<?= mf_icon('mail', ['class' => 'mf-icon mf-icon--lg']) ?>
				<span><strong>andrey@motor-force.ru</strong><em>Ответим в&nbsp;течение дня</em></span>
			</a>
			<div class="mf-delivery-cta__item">
				<?= mf_icon('whatsapp', ['class' => 'mf-icon mf-icon--lg']) ?>
				<span><strong>Чат Jivosite справа</strong><em>Ответ в реальном времени</em></span>
			</div>
		</div>
	</section>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>
