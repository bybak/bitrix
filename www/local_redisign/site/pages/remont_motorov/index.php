<?php
const MF_HIDE_TITLEBAR = true;
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Ремонт ДВС");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");

require_once($_SERVER["DOCUMENT_ROOT"]."/include/mf_form.php");
$mfFields = [
	"NAME" => "Ваше имя",
	"PHONE" => "Контактный телефон",
	"EMAIL" => "E-mail",
	"MESSAGE" => "Сообщение",
];
$mfForm = mf_handle_static_form(
	"remont_motorov_feedback",
	"andrey@motor-force.ru",
	"Motor-Force: ремонт ДВС — сообщение",
	$mfFields,
	["NAME", "PHONE", "MESSAGE"]
);
?>

<div class="mf-repair mf-text-page mb-4">

	<header class="mf-repair-hero">
		<div class="mf-repair-hero__body">
			<span class="mf-repair-hero__label">Сервис в Санкт-Петербурге</span>
			<h1>Ремонт двигателей внутреннего сгорания</h1>
			<p class="mf-repair-hero__lead">Квадроциклы, снегоходы и подвесные лодочные моторы. Полный цикл — от дефектовки до сборки и испытаний.</p>
			<p>Наша компания организует ремонт моторов для квадроциклов, снегоходов и подвесных лодочных моторов.</p>
			<div class="mf-repair-hero__brands">
				<strong>Все основные бренды:</strong>
				<span>BRP (Bombardier, Ski-Doo, Lynx, Can-Am, Evinrude, Johnson, Rotax)</span>
				<span>Polaris · Arctic Cat · Yamaha · Kawasaki · Suzuki · Honda</span>
				<span>Tohatsu · Mercury · Mariner</span>
			</div>
			<div class="mf-repair-hero__actions">
				<a class="mf-btn mf-btn--accent mf-btn--lg" href="#feedback">Оставить заявку <?= mf_icon('arrow-right') ?></a>
				<a class="mf-btn mf-btn--ghost" href="tel:+78129864276"><?= mf_icon('phone') ?> 8-812-986-42-76</a>
			</div>
		</div>
		<figure class="mf-repair-hero__media">
			<img src="//i.siteapi.org/q3qPiFbXkKvmG0vLgSKAz-tzcFo=/fit-in/1400x1000/center/top/s.siteapi.org/ccdb0156d66a088.ru/img/3ssiz044mq04goc8g8kcc808gs8so8" alt="Ремонт мотора" loading="lazy">
		</figure>
	</header>

	<p class="mf-repair-intro">Наши специалисты выполнят весь перечень работ по ремонту моторов, восстановлению цилиндро-поршневой группы и коленвалов. Благодаря большому опыту и ремонтным мощностям, находящимся в Санкт-Петербурге, можем организовать качественный ремонт коленчатых валов, цилиндров, головок блока цилиндра.</p>

	<section class="mf-repair-section">
		<header class="mf-repair-section__head">
			<span class="mf-repair-section__icon" aria-hidden="true"><?= mf_icon('wrench') ?></span>
			<h2>Перечень выполняемых работ</h2>
		</header>
		<ol class="mf-repair-list">
			<li><strong>Ремонт цилиндров.</strong> Гильзовка цилиндров, расточка цилиндров, нанесение хона.</li>
			<li><strong>Ремонт ГБЦ.</strong> Головка блока цилиндра.</li>
			<li><strong>Ремонт коленчатых валов.</strong> Замена подшипников, вкладышей, шатунов, щёк, сальников и других элементов.</li>
		</ol>
	</section>

	<section class="mf-repair-section">
		<header class="mf-repair-section__head">
			<span class="mf-repair-section__icon" aria-hidden="true"><?= mf_icon('flag') ?></span>
			<h2>Порядок и этапы выполнения работ по ремонту ДВС</h2>
		</header>

		<div class="mf-repair-flow">
			<article class="mf-repair-flow__col">
				<h4>Для клиентов из Санкт-Петербурга</h4>
				<ol class="mf-repair-steps">
					<li>Снятие ДВС (самостоятельное или нашими специалистами).</li>
					<li>Дефектовка ДВС.</li>
					<li>Составление предварительного сметного расчёта с указанием работ и запчастей.</li>
					<li>Согласование стоимости работ и запчастей и выставление счёта.</li>
					<li>Закупка и поставка запчастей.</li>
					<li>Ремонт ДВС.</li>
					<li>Установка ДВС (самостоятельное или нашими специалистами).</li>
					<li>Приёмка выполненных работ.</li>
				</ol>
			</article>
			<article class="mf-repair-flow__col">
				<h4>Для клиентов из других регионов</h4>
				<ol class="mf-repair-steps">
					<li>Вы оставляете заявку у нас на сайте или по телефону.</li>
					<li>Согласование возможности ремонта по электронной почте или по телефону.</li>
					<li>Вы самостоятельно снимаете ДВС со своей техники.</li>
					<li>Упаковываете двигатель для отправки транспортной компанией или почтой.</li>
					<li>Отправляете двигатель к нам в Санкт-Петербург. Перевозку (туда и обратно) оплачивает заказчик.</li>
					<li>Дефектовка ДВС.</li>
					<li>Составление предварительного сметного расчёта.</li>
					<li>Согласование стоимости работ и запчастей и выставление счёта.</li>
					<li>Закупка и поставка запчастей.</li>
					<li>Ремонт ДВС.</li>
					<li>Отправка ДВС транспортной компанией обратно вам.</li>
					<li>Вы получаете ДВС и устанавливаете его на технику.</li>
				</ol>
			</article>
		</div>

		<aside class="mf-repair-contact">
			<?= mf_icon('phone', ['class' => 'mf-icon mf-icon--lg']) ?>
			<div>
				<strong>По всем вопросам можете обращаться:</strong>
				<a href="mailto:andrey@motor-force.ru">andrey@motor-force.ru</a>
				<a href="tel:+78129864276">8-812-986-42-76</a>
				<a href="tel:+79218837340">8-921-883-73-40</a>
			</div>
		</aside>
	</section>

	<section class="mf-repair-section">
		<header class="mf-repair-section__head">
			<span class="mf-repair-section__icon" aria-hidden="true"><?= mf_icon('price-tag') ?></span>
			<h2>Прайс-лист на основные виды работ</h2>
		</header>

		<div class="mf-repair-table-wrap" role="region" aria-label="Прайс-лист на основные виды работ">
			<table class="mf-repair-table">
				<thead>
					<tr>
						<th colspan="3">Наименование ремонта</th>
						<th class="mf-repair-table__price">Стоимость, руб.</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<th rowspan="2" class="mf-repair-table__group">Разборка / сборка ДВС</th>
						<td colspan="2">4-тактные</td>
						<td class="mf-repair-table__price">от 15&nbsp;000</td>
					</tr>
					<tr>
						<td colspan="2">2-тактные</td>
						<td class="mf-repair-table__price">от 10&nbsp;000</td>
					</tr>

					<tr>
						<th rowspan="5" class="mf-repair-table__group">Ремонт цилиндро-поршневой группы</th>
						<td>4-тактные</td>
						<td>Гильзовка (за 1 цилиндр). Расточка под нужный размер и нанесение хона.</td>
						<td class="mf-repair-table__price">12&nbsp;000</td>
					</tr>
					<tr>
						<td>&nbsp;</td>
						<td>Расточка (за 1 цилиндр). Расточка под нужный размер и нанесение хона.</td>
						<td class="mf-repair-table__price">7&nbsp;500</td>
					</tr>
					<tr>
						<td rowspan="2">2-тактные</td>
						<td>Гильзовка (за 1 цилиндр, с использованием готовой гильзы). Расточка и нанесение хона.</td>
						<td class="mf-repair-table__price">15&nbsp;000</td>
					</tr>
					<tr>
						<td>Гильзовка (за 1 цилиндр, с изготовлением гильзы). Расточка и нанесение хона.</td>
						<td class="mf-repair-table__price">26&nbsp;000</td>
					</tr>
					<tr>
						<td>&nbsp;</td>
						<td>Расточка (за 1 цилиндр). Расточка и нанесение хона.</td>
						<td class="mf-repair-table__price">7&nbsp;500</td>
					</tr>

					<tr>
						<th rowspan="2" class="mf-repair-table__group">Ремонт коленвалов</th>
						<td>4-тактные</td>
						<td>&nbsp;</td>
						<td class="mf-repair-table__price">от 7&nbsp;500</td>
					</tr>
					<tr>
						<td>2-тактные (разбор/замена подшипников)</td>
						<td>&nbsp;</td>
						<td class="mf-repair-table__price">от 10&nbsp;000</td>
					</tr>

					<tr class="mf-repair-table__sep"><td colspan="4">Дополнительные работы</td></tr>

					<tr>
						<th rowspan="2" class="mf-repair-table__group">Снятие / установка ДВС</th>
						<td>4-тактные</td>
						<td>&nbsp;</td>
						<td class="mf-repair-table__price">от 15&nbsp;000</td>
					</tr>
					<tr>
						<td>2-тактные</td>
						<td>&nbsp;</td>
						<td class="mf-repair-table__price">от 10&nbsp;000</td>
					</tr>
				</tbody>
			</table>
		</div>
	</section>

	<section class="mf-repair-section">
		<header class="mf-repair-section__head">
			<span class="mf-repair-section__icon" aria-hidden="true"><?= mf_icon('engine') ?></span>
			<h2>На какую технику делаем ремонт</h2>
		</header>

		<div class="mf-repair-types">
			<article class="mf-repair-type">
				<div class="mf-repair-type__media">
					<img src="//i.siteapi.org/9U15y19c63MOmIX6UcnoUCVH4Ec=/fit-in/1400x1000/center/top/s.siteapi.org/ccdb0156d66a088.ru/img/r4t2ohth1asos4088gko8o0og4k4so" alt="Ремонт квадроциклов" loading="lazy">
				</div>
				<h4>Двигатели квадроциклов</h4>
				<ul>
					<li>BRP Bombardier</li><li>Can-Am</li><li>Polaris</li><li>Arctic Cat</li>
					<li>Yamaha</li><li>Kawasaki</li><li>Suzuki</li><li>Honda</li>
				</ul>
			</article>
			<article class="mf-repair-type">
				<div class="mf-repair-type__media">
					<img src="//i.siteapi.org/PCLR3x5_Lt0sn5tDlBAX7KMo-gE=/fit-in/1400x1000/center/top/s.siteapi.org/ccdb0156d66a088.ru/img/22hi66szes9w8cckckkksgc4wwww04" alt="Ремонт снегоходов" loading="lazy">
				</div>
				<h4>Двигатели снегоходов</h4>
				<ul>
					<li>BRP Bombardier</li><li>Ski-Doo</li><li>Lynx</li>
					<li>Yamaha</li><li>Polaris</li><li>Arctic Cat</li>
				</ul>
			</article>
			<article class="mf-repair-type">
				<div class="mf-repair-type__media">
					<img src="//i.siteapi.org/-w8VPZASuchrX9CTiKsOSgPyWP8=/fit-in/1400x1000/center/top/s.siteapi.org/ccdb0156d66a088.ru/img/qmho6yx1yio0go00k88sgcgc88kcgs" alt="Ремонт лодочных моторов" loading="lazy">
				</div>
				<h4>Лодочные моторы</h4>
				<ul>
					<li>Evinrude</li><li>Johnson</li><li>Yamaha</li><li>Suzuki</li>
					<li>Honda</li><li>Tohatsu</li><li>Mercury</li><li>Mariner</li>
				</ul>
			</article>
		</div>
	</section>

	<section class="mf-repair-benefits">
		<h2>Ждём ваших заявок на ремонт моторов!</h2>
		<div class="mf-repair-benefits__grid">
			<article class="mf-repair-benefit">
				<div class="mf-repair-benefit__icon"><?= mf_icon('star', ['class' => 'mf-icon mf-icon--lg']) ?></div>
				<h4>Качество ремонта</h4>
			</article>
			<article class="mf-repair-benefit">
				<div class="mf-repair-benefit__icon"><?= mf_icon('shield', ['class' => 'mf-icon mf-icon--lg']) ?></div>
				<h4>Гарантия на ремонт</h4>
			</article>
			<article class="mf-repair-benefit">
				<div class="mf-repair-benefit__icon"><?= mf_icon('lightning', ['class' => 'mf-icon mf-icon--lg']) ?></div>
				<h4>Обратная связь</h4>
			</article>
		</div>
	</section>

	<section class="mf-feedback" id="feedback" aria-label="Форма обратной связи">
		<header class="mf-feedback__head">
			<h2 class="mf-feedback__title">Напишите нам</h2>
			<p>Мы свяжемся с вами в ближайшее время и предложим оптимальное решение.</p>
		</header>

		<?php if ($mfForm["ok"]): ?>
			<div class="mf-alert mf-alert--success">Сообщение отправлено. Мы свяжемся с вами.</div>
		<?php elseif (!empty($mfForm["errors"])): ?>
			<div class="mf-alert mf-alert--danger">
				<ul class="mb-0">
					<?php foreach ($mfForm["errors"] as $e): ?>
						<li><?= htmlspecialchars($e, ENT_QUOTES, "UTF-8") ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form method="post" action="" class="mf-form mf-form--grid">
			<?= bitrix_sessid_post() ?>
			<input type="hidden" name="mf_form_id" value="remont_motorov_feedback">

			<label class="mf-field">
				<input id="mf-remont-name" name="NAME" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["NAME"] ?? "", ENT_QUOTES, "UTF-8") ?>">
				<span>Ваше имя</span>
			</label>
			<label class="mf-field">
				<input id="mf-remont-phone" name="PHONE" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["PHONE"] ?? "", ENT_QUOTES, "UTF-8") ?>">
				<span>Контактный телефон</span>
			</label>
			<label class="mf-field mf-field--full">
				<input id="mf-remont-email" type="email" name="EMAIL" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["EMAIL"] ?? "", ENT_QUOTES, "UTF-8") ?>">
				<span>E-mail</span>
			</label>
			<label class="mf-field mf-field--full">
				<textarea id="mf-remont-message" name="MESSAGE" rows="6" placeholder=" "><?= htmlspecialchars($mfForm["values"]["MESSAGE"] ?? "", ENT_QUOTES, "UTF-8") ?></textarea>
				<span>Сообщение</span>
			</label>
			<button class="mf-btn mf-btn--accent mf-btn--lg mf-form__submit" type="submit">
				Отправить
				<?= mf_icon('arrow-right') ?>
			</button>
		</form>
	</section>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>
