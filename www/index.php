<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Motor-Force — запчасти для мототехники");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");
?>
<div class="mb-4">
	<form action="/search/" method="get" class="form-inline">
		<label class="sr-only" for="mf-search">Найти</label>
		<input id="mf-search" class="form-control flex-grow-1 mr-2" type="search" name="q" placeholder="Найти" />
		<button class="btn btn-success" type="submit">Найти</button>
	</form>
</div>

<div class="mb-5">
	<h2 class="mb-4">Запчасти для мототехники</h2>

	<div class="row">
		<div class="col-md-6 col-lg-4 mb-4">
			<div class="card h-100">
				<div class="card-body">
					<h3 class="h5 card-title">Запчасти для квадроциклов</h3>
					<p class="card-text">Широкий выбор оригинальных и неоригинальных запчастей для квадроциклов.</p>
				</div>
				<div class="card-footer bg-white border-0 pt-0">
					<a class="btn btn-outline-success" href="/products/category/zapchasti-dlya-kvadrociklov">Подробнее</a>
				</div>
			</div>
		</div>

		<div class="col-md-6 col-lg-4 mb-4">
			<div class="card h-100">
				<div class="card-body">
					<h3 class="h5 card-title">Запчасти для снегоходов</h3>
					<p class="card-text">Широкий выбор оригинальных и неоригинальных запчастей для снегоходов.</p>
				</div>
				<div class="card-footer bg-white border-0 pt-0">
					<a class="btn btn-outline-success" href="/products/category/zapchasti-dlya-snegohodov">Подробнее</a>
				</div>
			</div>
		</div>

		<div class="col-md-6 col-lg-4 mb-4">
			<div class="card h-100">
				<div class="card-body">
					<h3 class="h5 card-title">Запчасти для мотоциклов</h3>
					<p class="card-text">Широкий выбор запчастей для мотоциклов.</p>
				</div>
				<div class="card-footer bg-white border-0 pt-0">
					<a class="btn btn-outline-success" href="/products/category/zapchasti-dlya-motociklov">Подробнее</a>
				</div>
			</div>
		</div>

		<div class="col-md-6 col-lg-4 mb-4">
			<div class="card h-100">
				<div class="card-body">
					<h3 class="h5 card-title">Запчасти для гидроциклов</h3>
					<p class="card-text">Широкий выбор оригинальных и неоригинальных запчастей для гидроциклов.</p>
				</div>
				<div class="card-footer bg-white border-0 pt-0">
					<a class="btn btn-outline-success" href="/products/category/zapchasti-dlya-gidrociklov">Подробнее</a>
				</div>
			</div>
		</div>

		<div class="col-md-6 col-lg-4 mb-4">
			<div class="card h-100">
				<div class="card-body">
					<h3 class="h5 card-title">Запчасти для лодочных моторов</h3>
					<p class="card-text">Широкий выбор запчастей для лодочных моторов.</p>
				</div>
				<div class="card-footer bg-white border-0 pt-0">
					<a class="btn btn-outline-success" href="/products/category/zapchasti-dlya-lodochnih-motorov">Подробнее</a>
				</div>
			</div>
		</div>

		<div class="col-md-6 col-lg-4 mb-4">
			<div class="card h-100">
				<div class="card-body">
					<h3 class="h5 card-title">Прокат квадроциклов</h3>
					<p class="card-text">Квадроциклы в прокат без инструкторов и сопровождающих в Санкт‑Петербурге и Ленинградской области.</p>
				</div>
				<div class="card-footer bg-white border-0 pt-0">
					<a class="btn btn-outline-success" href="/prokat/">Подробнее</a>
				</div>
			</div>
		</div>
	</div>
</div>

<div class="mb-5">
	<div class="d-flex align-items-center justify-content-between mb-3">
		<h2 class="mb-0">Новости</h2>
		<a href="/news/" class="btn btn-link p-0">Все новости</a>
	</div>

	<div class="list-group">
		<a class="list-group-item list-group-item-action" href="/news/">
			<div class="d-flex w-100 justify-content-between">
				<h3 class="h6 mb-1">Поздравляем C Новым Годом 2026 и Рождеством! | График работы</h3>
				<small class="text-muted">29.12.2025</small>
			</div>
			<p class="mb-1 text-muted">Информируем о графике работы в праздничные дни. Отправки заказов возобновятся с 12-го января.</p>
		</a>
		<a class="list-group-item list-group-item-action" href="/news/">
			<div class="d-flex w-100 justify-content-between">
				<h3 class="h6 mb-1">Мы на связи в Telegram</h3>
				<small class="text-muted">04.12.2025</small>
			</div>
			<p class="mb-1 text-muted">Пишите нам в Telegram по наличию и подбору запчастей, стоимости и доставке, статусу заказов.</p>
		</a>
		<a class="list-group-item list-group-item-action" href="/news/">
			<div class="d-flex w-100 justify-content-between">
				<h3 class="h6 mb-1">Поздравляем с днем народного единства | График работы</h3>
				<small class="text-muted">31.10.2025</small>
			</div>
			<p class="mb-1 text-muted">1–4 ноября — выходные. 5 ноября работаем в обычном режиме.</p>
		</a>
		<a class="list-group-item list-group-item-action" href="/news/">
			<div class="d-flex w-100 justify-content-between">
				<h3 class="h6 mb-1">Поздравляем C России! | График работы</h3>
				<small class="text-muted">11.06.2025</small>
			</div>
			<p class="mb-1 text-muted">11 июня до 17:00, 12–15 июня выходные, 16 июня работаем в обычном режиме.</p>
		</a>
	</div>
</div>

<div class="mb-5">
	<h2 class="mb-4">Контакты</h2>

	<div class="row">
		<div class="col-lg-6 mb-4">
			<div class="card h-100">
				<div class="card-body">
					<h3 class="h5">Адрес</h3>
					<p class="mb-0">Санкт‑Петербург, ул. Салова, д. 57, к. 1 Литера Ч<br>2‑ой этаж, офис № 1Н (Motor‑Force)</p>
				</div>
			</div>
		</div>
		<div class="col-lg-6 mb-4">
			<div class="card h-100">
				<div class="card-body">
					<h3 class="h5">Телефоны</h3>
					<ul class="mb-0 pl-3">
						<li>8 (812) 986‑42‑76 — Магазин/Ремонт</li>
						<li>8 (921) 883‑73‑40 — Мегафон (Магазин/Ремонт)</li>
					</ul>
				</div>
			</div>
		</div>
		<div class="col-lg-6 mb-4">
			<div class="card h-100">
				<div class="card-body">
					<h3 class="h5">Часы работы</h3>
					<ul class="mb-0 pl-3">
						<li>Пн–Чт: 10:00–18:00 (обед 13:30–14:00)</li>
						<li>Пт: 10:00–17:00 (обед 13:30–14:00)</li>
						<li>Сб–Вс: выходной</li>
					</ul>
				</div>
			</div>
		</div>
		<div class="col-lg-6 mb-4">
			<div class="card h-100">
				<div class="card-body">
					<h3 class="h5">E‑mail</h3>
					<ul class="mb-0 pl-3">
						<li><a href="mailto:andrey@motor-force.ru">andrey@motor-force.ru</a> — Магазин/Ремонт</li>
						<li><a href="mailto:prokat@motor-force.ru">prokat@motor-force.ru</a> — Прокат</li>
					</ul>
				</div>
			</div>
		</div>
	</div>
</div>
<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>