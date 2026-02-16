<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Прокат");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");

require_once($_SERVER["DOCUMENT_ROOT"]."/include/mf_form.php");
$mfFields = [
	"NAME" => "Ваше имя",
	"PHONE" => "Контактный телефон",
	"EMAIL" => "E-mail",
	"DATE" => "Дата аренды",
	"TIME" => "Желаемое время",
	"DURATION" => "Срок аренды",
	"DELIVERY" => "Доставка/Самовывоз/Прицеп",
	"COMMENT" => "Комментарий",
];
$mfForm = mf_handle_static_form(
	"prokat",
	"prokat@motor-force.ru",
	"Motor-Force: заявка на прокат",
	$mfFields,
	["NAME", "PHONE"]
);
?>

<div class="mb-4">
	<h2 class="h4">Прокат квадроциклов в Санкт-Петербурге и Лен. области</h2>
	<p class="mb-1"><strong>Телефон:</strong> 8-993-489-84-64</p>
	<p class="mb-4"><strong>Контакты:</strong> <a href="mailto:prokat@motor-force.ru">prokat@motor-force.ru</a></p>

	<p class="mb-4">
		Мы предоставляем в прокат в собственное пользование квадроциклы. Квадроциклы предоставляются в прокат без инструкторов и
		сопровождающих. Вы можете использовать технику в любой точке Ленинградской области.
	</p>

	<h3 class="h5 mt-4">Техника и оборудование</h3>
	<p class="mb-0">
		В прокате представлены двухместные квадроциклы CF MOTO X5 (чёрный и камо). Квадроциклы оборудованы грязевой резиной,
		лебёдками, площадками для перевозки вещей, герметичными кофрами и всем необходимым для бездорожья.
	</p>

	<h3 class="h5 mt-4">Экипировка</h3>
	<p class="mb-0">При аренде квадроциклов предоставляем шлемы и резиновые сапоги.</p>

	<h3 class="h5 mt-4">Маршруты и места для катания</h3>
	<p class="mb-0">Можем подсказать интересные маршруты и места для катания.</p>

	<h3 class="h5 mt-4">Доставка и перевозка техники</h3>
	<p class="mb-2">
		При необходимости организуем доставку техники до места катания. Также есть возможность аренды прицепа для перевозки техники
		или самовывоза.
	</p>
	<p class="mb-2"><strong>Стоимость доставки одного квадроцикла:</strong></p>
	<ul>
		<li>По городу — 3 000 руб.</li>
		<li>Область 0–50 км от КАД — 5 000 руб.</li>
		<li>Область 50–75 км от КАД — 7 000 руб.</li>
		<li>Область 75–100 км от КАД — 9 000 руб.</li>
		<li>Область 100–125 км от КАД — 10 000 руб.</li>
		<li>Область 125–150 км от КАД — 15 000 руб.</li>
	</ul>
	<p class="mb-2">2 квадроцикла — +50%, 3 квадроцикла — +75% к стоимости доставки одного квадроцикла.</p>

	<h3 class="h5 mt-4">Договор и условия аренды</h3>
	<p class="mb-2">
		При аренде заключается договор аренды. С условиями договора, актом приёма‑передачи и правилами проката можно ознакомиться:
	</p>
	<ul>
		<li><a href="https://s.siteapi.org/ccdb0156d66a088.ru/docs/1fucfkiriq3osoo4wgkg4wcosokswc" rel="nofollow noopener" target="_blank">Договор аренды</a></li>
		<li><a href="https://s.siteapi.org/ccdb0156d66a088.ru/docs/3zdm6lyqt1a84okc0gcwg0ossk4kkc" rel="nofollow noopener" target="_blank">Акт приёма‑передачи</a></li>
		<li><a href="https://s.siteapi.org/ccdb0156d66a088.ru/docs/np2w5uw9vc0kgc0c8gw4w8088gwo0c" rel="nofollow noopener" target="_blank">Правила проката</a></li>
	</ul>

	<h3 class="h5 mt-4">Фотогалерея</h3>
	<p class="mb-0">
		Посмотреть фото: <a href="/photoalbums/446386">/photoalbums/446386</a>
	</p>

	<h3 class="h5 mt-4">Стоимость аренды одного квадроцикла</h3>
	<div class="table-responsive">
		<table class="table table-bordered">
			<thead class="thead-light">
				<tr>
					<th>Длительность проката</th>
					<th>Стоимость проката</th>
					<th>Сумма залога</th>
				</tr>
			</thead>
			<tbody>
				<tr><td>1 час (до 3-х часов)</td><td>3 500 руб./ч</td><td>5 000 руб.</td></tr>
				<tr><td>1 час (от 4-х до 12 часов)</td><td>3 000 руб./ч</td><td>50 000 руб.</td></tr>
				<tr><td>1 сутки (будние дни)</td><td>12 500 руб./сут</td><td>50 000 руб.</td></tr>
				<tr><td>2 суток и более (будние дни)</td><td>10 000 руб./сут</td><td>50 000 руб.</td></tr>
				<tr><td>1 сутки (выходные и праздничные дни)</td><td>15 000 руб./сут</td><td>50 000 руб.</td></tr>
				<tr><td>2 суток и более (выходные и праздничные дни)</td><td>12 500 руб./сут</td><td>50 000 руб.</td></tr>
				<tr><td>Более 2-х суток</td><td>обсуждается индивидуально</td><td>50 000 руб.</td></tr>
			</tbody>
		</table>
	</div>

	<h3 class="h5 mt-4">Стоимость аренды прицепа (для 1 квадроцикла)</h3>
	<div class="table-responsive">
		<table class="table table-bordered">
			<thead class="thead-light">
				<tr>
					<th>Длительность проката</th>
					<th>Стоимость проката</th>
					<th>Сумма залога</th>
				</tr>
			</thead>
			<tbody>
				<tr><td>1 сутки (будние дни)</td><td>1 200 руб./сут</td><td>5 000 руб.</td></tr>
				<tr><td>2 суток и более (будние дни)</td><td>1 000 руб./сут</td><td>5 000 руб.</td></tr>
				<tr><td>1 сутки (выходные и праздничные дни)</td><td>1 500 руб./сут</td><td>5 000 руб.</td></tr>
				<tr><td>2 суток и более (выходные и праздничные дни)</td><td>1 200 руб./сут</td><td>5 000 руб.</td></tr>
				<tr><td>Более 2-х суток</td><td>обсуждается индивидуально</td><td>5 000 руб.</td></tr>
			</tbody>
		</table>
	</div>

	<h3 class="h5 mt-4">Условия и ответственность</h3>
	<ul>
		<li>Арендатор несёт полную ответственность за арендованное транспортное средство.</li>
		<li>Топливо/мойка — за счёт арендатора. Страхование ответственности — на стороне арендатора.</li>
		<li>Повреждения и неисправности по вине арендатора возмещаются, ущерб удерживается из суммы залога.</li>
		<li>Ответственность за вред себе и третьим лицам несёт арендатор.</li>
	</ul>

	<h3 class="h5 mt-5">Заявка на аренду</h3>

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
		<input type="hidden" name="mf_form_id" value="prokat">

		<div class="form-group">
			<label for="mf-prokat-name">Ваше имя</label>
			<input id="mf-prokat-name" class="form-control" name="NAME" value="<?=htmlspecialchars($mfForm["values"]["NAME"] ?? "", ENT_QUOTES, "UTF-8")?>">
		</div>
		<div class="form-group">
			<label for="mf-prokat-phone">Контактный телефон</label>
			<input id="mf-prokat-phone" class="form-control" name="PHONE" value="<?=htmlspecialchars($mfForm["values"]["PHONE"] ?? "", ENT_QUOTES, "UTF-8")?>">
		</div>
		<div class="form-group">
			<label for="mf-prokat-email">E-mail</label>
			<input id="mf-prokat-email" type="email" class="form-control" name="EMAIL" value="<?=htmlspecialchars($mfForm["values"]["EMAIL"] ?? "", ENT_QUOTES, "UTF-8")?>">
		</div>
		<div class="form-row">
			<div class="form-group col-md-4">
				<label for="mf-prokat-date">Дата аренды</label>
				<input id="mf-prokat-date" class="form-control" name="DATE" placeholder="например 2026-03-01" value="<?=htmlspecialchars($mfForm["values"]["DATE"] ?? "", ENT_QUOTES, "UTF-8")?>">
			</div>
			<div class="form-group col-md-4">
				<label for="mf-prokat-time">Желаемое время</label>
				<input id="mf-prokat-time" class="form-control" name="TIME" placeholder="например с 10:00 до 13:00" value="<?=htmlspecialchars($mfForm["values"]["TIME"] ?? "", ENT_QUOTES, "UTF-8")?>">
			</div>
			<div class="form-group col-md-4">
				<label for="mf-prokat-duration">Срок аренды</label>
				<input id="mf-prokat-duration" class="form-control" name="DURATION" placeholder="например 3 часа" value="<?=htmlspecialchars($mfForm["values"]["DURATION"] ?? "", ENT_QUOTES, "UTF-8")?>">
			</div>
		</div>
		<div class="form-group">
			<label for="mf-prokat-delivery">Доставка/Самовывоз/Прицеп</label>
			<input id="mf-prokat-delivery" class="form-control" name="DELIVERY" value="<?=htmlspecialchars($mfForm["values"]["DELIVERY"] ?? "", ENT_QUOTES, "UTF-8")?>">
		</div>
		<div class="form-group">
			<label for="mf-prokat-comment">Комментарий</label>
			<textarea id="mf-prokat-comment" class="form-control" name="COMMENT" rows="4"><?=htmlspecialchars($mfForm["values"]["COMMENT"] ?? "", ENT_QUOTES, "UTF-8")?></textarea>
		</div>

		<button class="btn btn-success" type="submit">Отправить</button>
	</form>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>

