<?php
const MF_HIDE_TITLEBAR = true;
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Прокат");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");

require_once($_SERVER["DOCUMENT_ROOT"]."/include/mf_form.php");
$mfFields = [
	"NAME" => "Ваше имя",
	"PHONE" => "Контактный телефон",
	"EMAIL" => "E-mail",
	"DATE" => "Дата аренды",
	"TIME" => "Желаемое время (например с 10.00 до 13.00)",
	"TERM" => "Срок аренды (например 3 часа или 1 сутки)",
	"DELIVERY" => "Доставка(место доставки)/Самовывоз/Необходимость прицепа для перевозки",
	"COMMENT" => "Комментарий",
];
$mfForm = mf_handle_static_form(
	"prokat_rent",
	"andrey@motor-force.ru",
	"Motor-Force: прокат — заявка",
	$mfFields,
	["NAME", "PHONE"]
);
?>

<div class="mf-prokat mf-text-page mb-4">

	<header class="mf-prokat-hero">
		<div class="mf-prokat-hero__body">
			<span class="mf-prokat-hero__label">Прокат техники в СПб</span>
			<h1>Прокат квадроциклов</h1>
			<p class="mf-prokat-hero__lead">Санкт-Петербург и Ленинградская область. Двухместные CF MOTO X5 (чёрный и камо), полная экипировка, доставка до места катания.</p>
			<div class="mf-prokat-hero__actions">
				<a class="mf-btn mf-btn--accent mf-btn--lg" href="#prokat-form">Оставить заявку <?= mf_icon('arrow-right') ?></a>
				<a class="mf-btn mf-btn--ghost" href="tel:+79934898464"><?= mf_icon('phone') ?> 8-993-489-84-64</a>
			</div>
		</div>
		<figure class="mf-prokat-hero__media">
			<img src="//i.siteapi.org/JSsawtipnwLP_DxfK0GRrfu35FM=/fit-in/1400x1000/center/top/s.siteapi.org/ccdb0156d66a088.ru/img/6z1eiioyb0w8gcos4ogwgkso8ckoc0" alt="Прокат квадроциклов" loading="lazy">
		</figure>
	</header>

	<p class="mf-prokat-intro">Мы предоставляем в прокат в собственное пользование квадроциклы. Квадроциклы предоставляются в прокат без инструкторов и сопровождающих. Вы можете использовать технику в любой точке Ленинградской области и получать незабываемые эмоции от техники активного отдыха!</p>

	<section class="mf-prokat-section mf-prokat-section--split">
		<div class="mf-prokat-content">
			<header class="mf-prokat-section__head">
				<span class="mf-prokat-section__icon" aria-hidden="true"><?= mf_icon('bike') ?></span>
				<h2>Техника и оборудование</h2>
			</header>
			<p>В нашем прокате представлены двухместные квадроциклы <strong>CF MOTO X5 (чёрный и камо)</strong>. Данные квадроциклы отлично подходят для путешествий, активного отдыха, поездок на рыбалку и охоту. Наши квадроциклы оборудованы грязевой резиной, лебёдками, площадками для перевозки вещей, герметичными кофрами и всем необходимым для преодоления бездорожья.</p>

			<header class="mf-prokat-section__head mf-prokat-section__head--sub">
				<span class="mf-prokat-section__icon" aria-hidden="true"><?= mf_icon('shield') ?></span>
				<h3>Экипировка</h3>
			</header>
			<p>При аренде квадроциклов предоставляем шлемы и резиновые сапоги.</p>

			<header class="mf-prokat-section__head mf-prokat-section__head--sub">
				<span class="mf-prokat-section__icon" aria-hidden="true"><?= mf_icon('route') ?></span>
				<h3>Маршруты и места для катания</h3>
			</header>
			<p>Мы можем подсказать интересные маршруты и места для катания.</p>
		</div>
	</section>

	<section class="mf-prokat-section mf-prokat-section--split">
		<div class="mf-prokat-content">
			<header class="mf-prokat-section__head">
				<span class="mf-prokat-section__icon" aria-hidden="true"><?= mf_icon('truck') ?></span>
				<h2>Доставка и перевозка техники</h2>
			</header>
			<p>При необходимости организуем доставку техники до места катания. Также есть возможность аренды прицепа для перевозки техники, либо вы можете забрать квадроциклы на своём транспорте самовывозом.</p>

			<h4 class="mf-prokat-subtitle">Стоимость доставки одного квадроцикла</h4>
			<ul class="mf-prokat-zones">
				<li><span>По городу</span><b>3&nbsp;000&nbsp;₽</b></li>
				<li><span>Область 0–50&nbsp;км от КАД</span><b>5&nbsp;000&nbsp;₽</b></li>
				<li><span>Область 50–75&nbsp;км от КАД</span><b>7&nbsp;000&nbsp;₽</b></li>
				<li><span>Область 75–100&nbsp;км от КАД</span><b>9&nbsp;000&nbsp;₽</b></li>
				<li><span>Область 100–125&nbsp;км от КАД</span><b>10&nbsp;000&nbsp;₽</b></li>
				<li><span>Область 125–150&nbsp;км от КАД</span><b>15&nbsp;000&nbsp;₽</b></li>
			</ul>
			<p><strong>Стоимость доставки 2 квадроциклов</strong> — плюс 50% к стоимости доставки одного.</p>
			<p><strong>Стоимость доставки 3 квадроциклов</strong> — плюс 75% к стоимости доставки одного.</p>
		</div>
		<figure class="mf-prokat-side-media">
			<img src="//i.siteapi.org/peHKwqojaHjYpf3iy81bNxqKPOc=/fit-in/1400x1000/center/top/s.siteapi.org/ccdb0156d66a088.ru/img/jp3ywwjtyko4co80w00s400084wosw" alt="Доставка квадроциклов" loading="lazy">
		</figure>
	</section>

	<section class="mf-prokat-section">
		<header class="mf-prokat-section__head">
			<span class="mf-prokat-section__icon" aria-hidden="true"><?= mf_icon('doc') ?></span>
			<h2>Договор и условия аренды</h2>
		</header>
		<p>При аренде техники заключается договор аренды. С условиями договора, актом приёма-передачи и правилами проката можете ознакомиться по ссылкам ниже.</p>
		<div class="mf-prokat-docs">
			<a class="mf-prokat-docs__item" target="_blank" rel="noopener noreferrer" href="https://s.siteapi.org/ccdb0156d66a088.ru/docs/1fucfkiriq3osoo4wgkg4wcosokswc">
				<?= mf_icon('doc', ['class' => 'mf-icon mf-icon--lg']) ?>
				<span>Договор аренды</span>
				<?= mf_icon('download') ?>
			</a>
			<a class="mf-prokat-docs__item" target="_blank" rel="noopener noreferrer" href="https://s.siteapi.org/ccdb0156d66a088.ru/docs/3zdm6lyqt1a84okc0gcwg0ossk4kkc">
				<?= mf_icon('doc', ['class' => 'mf-icon mf-icon--lg']) ?>
				<span>Акт приёма-передачи</span>
				<?= mf_icon('download') ?>
			</a>
			<a class="mf-prokat-docs__item" target="_blank" rel="noopener noreferrer" href="https://s.siteapi.org/ccdb0156d66a088.ru/docs/np2w5uw9vc0kgc0c8gw4w8088gwo0c">
				<?= mf_icon('doc', ['class' => 'mf-icon mf-icon--lg']) ?>
				<span>Правила проката</span>
				<?= mf_icon('download') ?>
			</a>
		</div>
	</section>

	<section class="mf-prokat-section">
		<header class="mf-prokat-section__head">
			<span class="mf-prokat-section__icon" aria-hidden="true"><?= mf_icon('mail') ?></span>
			<h2>Заявка на аренду</h2>
		</header>
		<p><strong>Заявку на аренду техники Вы можете оставить ниже, заполнив форму внизу страницы.</strong> Просьба заполнять данные как можно более подробно, желательно все поля — это ускоряет процесс оформления договора и сокращает время выдачи техники.</p>
		<p>Также Вы можете написать на электронную почту.</p>
		<aside class="mf-prokat-contact">
			<?= mf_icon('phone', ['class' => 'mf-icon mf-icon--lg']) ?>
			<div>
				<strong>Контакты</strong>
				<a href="mailto:prokat@motor-force.ru">prokat@motor-force.ru</a>
				<a href="tel:+79934898464">8-993-489-84-64 (WhatsApp, Telegram, Viber)</a>
			</div>
		</aside>
		<p><strong>Форма заявки на аренду техники — внизу страницы.</strong></p>
		<p><a href="https://motor-force.ru/photoalbums/446386" target="_blank" rel="noopener noreferrer" class="mf-prokat-photolink"><?= mf_icon('eye') ?> Посмотреть фотогалерею</a></p>
	</section>

	<section class="mf-prokat-section">
		<header class="mf-prokat-section__head">
			<span class="mf-prokat-section__icon" aria-hidden="true"><?= mf_icon('price-tag') ?></span>
			<h2>Стоимость аренды одного квадроцикла</h2>
		</header>
		<div class="mf-prokat-table-wrap" role="region" aria-label="Стоимость аренды одного квадроцикла">
			<table class="mf-prokat-table">
				<thead>
					<tr><th>Длительность проката</th><th>Стоимость проката</th><th>Сумма залога</th></tr>
				</thead>
				<tbody>
					<tr><td>1 час (до 3-х часов)</td><td>3&nbsp;500&nbsp;₽/ч</td><td>5&nbsp;000&nbsp;₽</td></tr>
					<tr><td>1 час (от 4-х до 12 часов)</td><td>3&nbsp;000&nbsp;₽/ч</td><td>50&nbsp;000&nbsp;₽</td></tr>
					<tr><td>1 сутки (будние дни)</td><td>12&nbsp;500&nbsp;₽/сут</td><td>50&nbsp;000&nbsp;₽</td></tr>
					<tr><td>2 суток и более (будние дни)</td><td>10&nbsp;000&nbsp;₽/сут</td><td>50&nbsp;000&nbsp;₽</td></tr>
					<tr><td>1 сутки (выходные и праздничные дни)</td><td>15&nbsp;000&nbsp;₽/сут</td><td>50&nbsp;000&nbsp;₽</td></tr>
					<tr><td>2 суток и более (выходные и праздничные дни)</td><td>12&nbsp;500&nbsp;₽/сут</td><td>50&nbsp;000&nbsp;₽</td></tr>
					<tr><td>Более 2-х суток</td><td>обсуждается индивидуально</td><td>50&nbsp;000&nbsp;₽</td></tr>
				</tbody>
			</table>
		</div>
	</section>

	<section class="mf-prokat-section">
		<header class="mf-prokat-section__head">
			<span class="mf-prokat-section__icon" aria-hidden="true"><?= mf_icon('truck') ?></span>
			<h2>Стоимость аренды прицепа для перевозки одного квадроцикла</h2>
		</header>
		<div class="mf-prokat-table-wrap" role="region" aria-label="Стоимость аренды прицепа">
			<table class="mf-prokat-table">
				<thead>
					<tr><th>Длительность проката</th><th>Стоимость проката</th><th>Сумма залога</th></tr>
				</thead>
				<tbody>
					<tr><td>1 сутки (будние дни)</td><td>1&nbsp;200&nbsp;₽/сут</td><td>5&nbsp;000&nbsp;₽</td></tr>
					<tr><td>2 суток и более (будние дни)</td><td>1&nbsp;000&nbsp;₽/сут</td><td>5&nbsp;000&nbsp;₽</td></tr>
					<tr><td>1 сутки (выходные и праздничные дни)</td><td>1&nbsp;500&nbsp;₽/сут</td><td>5&nbsp;000&nbsp;₽</td></tr>
					<tr><td>2 суток и более (выходные и праздничные дни)</td><td>1&nbsp;200&nbsp;₽/сут</td><td>5&nbsp;000&nbsp;₽</td></tr>
					<tr><td>Более 2-х суток</td><td>обсуждается индивидуально</td><td>5&nbsp;000&nbsp;₽</td></tr>
				</tbody>
			</table>
		</div>
	</section>

	<section class="mf-prokat-section mf-prokat-conditions">
		<header class="mf-prokat-section__head">
			<span class="mf-prokat-section__icon" aria-hidden="true"><?= mf_icon('warning') ?></span>
			<h2>Условия и ответственность</h2>
		</header>
		<p>Арендатор несёт полную ответственность за арендованное транспортное средство. Расходы на содержание арендованного транспортного средства (топливо и мойка), страхование своей ответственности лежат на арендаторе.</p>
		<p>Ухудшения технического состояния квадроцикла, повреждения и неисправности, возникшие по вине арендатора, должны быть возмещены арендатором. Стоимость ущерба удерживается из суммы залога.</p>
		<p>Ответственность за вред, причинённый себе и третьим лицам, несёт арендатор.</p>
	</section>

	<section class="mf-cooperation-form" id="prokat-form" aria-label="Заявка на аренду техники">
		<header class="mf-cooperation-form__head">
			<h2>Оставить заявку на аренду техники</h2>
			<p>Чем подробнее вы заполните форму — тем быстрее мы оформим договор и выдадим технику.</p>
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

		<form id="dynamic-form-prokat" method="post" action="" class="mf-form mf-form--grid js-no-tooltip">
			<?= bitrix_sessid_post() ?>
			<input type="hidden" name="mf_form_id" value="prokat_rent">

			<label class="mf-field"><input required maxlength="100" type="text" name="NAME" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["NAME"] ?? "", ENT_QUOTES, "UTF-8") ?>"><span>Ваше имя <em>*</em></span></label>
			<label class="mf-field"><input required maxlength="100" type="tel" name="PHONE" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["PHONE"] ?? "", ENT_QUOTES, "UTF-8") ?>"><span>Контактный телефон <em>*</em></span></label>
			<label class="mf-field"><input maxlength="100" type="email" name="EMAIL" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["EMAIL"] ?? "", ENT_QUOTES, "UTF-8") ?>"><span>E-mail</span></label>
			<label class="mf-field"><input maxlength="100" type="text" name="DATE" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["DATE"] ?? "", ENT_QUOTES, "UTF-8") ?>"><span>Дата аренды</span></label>
			<label class="mf-field"><input maxlength="100" type="text" name="TIME" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["TIME"] ?? "", ENT_QUOTES, "UTF-8") ?>"><span>Желаемое время (например с 10.00 до 13.00)</span></label>
			<label class="mf-field"><input maxlength="100" type="text" name="TERM" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["TERM"] ?? "", ENT_QUOTES, "UTF-8") ?>"><span>Срок аренды (например 3 часа или 1 сутки)</span></label>
			<label class="mf-field mf-field--full"><input maxlength="100" type="text" name="DELIVERY" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["DELIVERY"] ?? "", ENT_QUOTES, "UTF-8") ?>"><span>Доставка (место доставки) / Самовывоз / Нужен ли прицеп</span></label>
			<label class="mf-field mf-field--full"><input maxlength="100" type="text" name="COMMENT" placeholder=" " value="<?= htmlspecialchars($mfForm["values"]["COMMENT"] ?? "", ENT_QUOTES, "UTF-8") ?>"><span>Комментарий</span></label>

			<button class="mf-btn mf-btn--accent mf-btn--lg mf-form__submit" type="submit">Отправить заявку <?= mf_icon('arrow-right') ?></button>
		</form>
	</section>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>
