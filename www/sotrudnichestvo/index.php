<?php
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
	<div class="mr-block"><div class=" mrb-row__content -inline-group_top mrb-row-index-0"><div class="mrb-small-18 mrb-medium-18
            mrb-col-index-0 mrb-large-18"><div class="-gd-content mrb-block-index-0"><div class="user-inner">
    <h2 style="text-align: left;">Сотрудничество с Motor-Force</h2>
<p>Приглашаем к партнёрству сервисные центры, магазины, салоны и другие компании, работающие с мототехникой и запчастями.</p>
<p>Мы предлагаем удобные условия для регулярных закупок, выгодные цены и профессиональное сопровождение на всех этапах.</p>
<p><br /></p>
<h3>Работаем по всей России, СНГ и миру</h3>
<ul>
<li>
<p>Базируемся в Санкт-Петербурге</p>
</li>
<li>
<p>Отправляем заказы по всей России, в страны СНГ и по миру</p>
</li>
<li>
<p>Самовывоз со склада <span>в Санкт-Петербурге</span></p>
</li>
</ul>
<p><br /></p>
<h3>Условия для партнёров</h3>
<ul>
<li>
<p>Индивидуальные скидки при оплате наличными и по безналу</p>
</li>
<li>
<p>Возможность подписки на рассылку остатков склада &mdash; всегда актуальная информация</p>
</li>
<li>
<p>Быстрая отгрузка со сторонних складов (1&ndash;4 дня до нашего офиса)</p>
</li>
<li>
<p>Прямые поставки из США и Европы (сроки от 1&ndash;2 месяцев, возможны ускоренные варианты)</p>
</li>
<li>
<p>Работа по системе дропшиппинга &mdash; отправляем напрямую вашим клиентам без наших документов или с вашими</p>
</li>
<li>
<p>Удобная оплата &mdash; наличные, перевод на карту, безналичный перевод</p>
</li>
</ul>
<p><br /></p>
<h3>Широкий ассортимент</h3>
<p>Мы поставляем оригинальные и качественные аналоговые запчасти и аксессуары для:</p>
<ul>
<li>
<p>Квадроциклов</p>
</li>
<li>
<p>Снегоходов</p>
</li>
<li>
<p>Мотоциклов</p>
</li>
<li>
<p>Гидроциклов</p>
</li>
<li>
<p>Лодочных моторов и другой мототехники</p>
</li>
</ul>
<p>Бренды: BRP (Can-Am, Ski-Doo, Sea-Doo, Lynx), Polaris, Yamaha, Arctic Cat, Kawasaki, Honda, Suzuki и многие другие.</p>
<p><br /></p>
<h3>Как начать сотрудничество</h3>
<p>1. Напишите нам на почту или через сайт &mdash; расскажите о вашем бизнесе (салон, магазин, сервис, прокат).<br />2.&nbsp;Пришлите ссылку на ваш сайт или краткое описание.<br />3. Зарегистрируйтесь на нашем оптовом сайте для доступа к ценам и заказам в любое время:&nbsp;<span style="font-weight: bold;"><a href="https://opt.motor-force.ru/" class="">opt.motor-force.ru</a></span></p>
</div>
</div></div></div><div class=" mrb-row__content -inline-group_top mrb-row-index-1"><div class="mrb-small-18 mrb-medium-18
            mrb-col-index-0 mrb-large-18"><div class="-gd-content mrb-block-index-0"><div id="mrb-button-block"
     class="user-inner mrb-button-block theme-default"
     style="text-align: center">
    <a href="https://opt.motor-force.ru/"
       target="_blank"               class="mrb-btn-medium mrb-btn-item a">
        <span class="mrb-btn-item-text">Посетить оптовый сайт</span>
    </a>
</div>
</div></div></div><div class=" mrb-row__content -inline-group_top mrb-row-index-2"><div class="mrb-small-18 mrb-medium-18
            mrb-col-index-0 mrb-large-18"><div class="-gd-content mrb-block-index-0">                            
<div id="mrb-triggers" class="mrb-triggers theme-default-circle"><div class="bl-trigger-title user-inner">
            <p>Выгода от сотрудничества</p>
        </div><div class="bl-triggers-list bl-triggers_small bl-triggers_3"><div class="bl-trigger">
                                    <div class="blr-trigger-media link-empty"><div class="bl-trigger-icon  -triggers-ft-like"
                                 style="color: #000000; background: #F0C419; border-color: #F0C419;"></div>
                        </div>
                            <div class="bl-trigger-text user-inner hide-for-mrb-large">
                <p><span style="color: #000000; font-size: 18px;">Наличие и Под заказ</span></p>
<p><span style="color: #333300;">Товары&nbsp;есть в&nbsp;наличии и под заказ</span></p>
            </div>
            </div><div class="bl-trigger">
                                    <div class="blr-trigger-media link-empty"><div class="bl-trigger-icon  -triggers-ft-wallet"
                                 style="color: #000000; background: #F0C419; border-color: #F0C419;"></div>
                        </div>
                            <div class="bl-trigger-text user-inner hide-for-mrb-large">
                <p><span style="color: #000000; font-size: 18px;">Скидки</span></p>
<p><span style="color: #333300;">Предоставляем скидки для партнеров</span></p>
            </div>
            </div><div class="bl-trigger">
                                    <div class="blr-trigger-media link-empty"><div class="bl-trigger-icon  -triggers-ft-truck"
                                 style="color: #000000; background: #F0C419; border-color: #F0C419;"></div>
                        </div>
                            <div class="bl-trigger-text user-inner hide-for-mrb-large">
                <p><span style="color: #000000; font-size: 18px;">Дропшиппинг</span></p>
<p><span style="color: #000000;">Отправки напрямую Вашим клиентам</span></p>
            </div>
            </div></div>
    <div class="bl-triggers-list show-for-mrb-large bl-triggers_small bl-triggers_3"><div class="bl-trigger">
                <div class="bl-trigger-text user-inner">
                    <p><span style="color: #000000; font-size: 18px;">Наличие и Под заказ</span></p>
<p><span style="color: #333300;">Товары&nbsp;есть в&nbsp;наличии и под заказ</span></p>
                </div>
            </div><div class="bl-trigger">
                <div class="bl-trigger-text user-inner">
                    <p><span style="color: #000000; font-size: 18px;">Скидки</span></p>
<p><span style="color: #333300;">Предоставляем скидки для партнеров</span></p>
                </div>
            </div><div class="bl-trigger">
                <div class="bl-trigger-text user-inner">
                    <p><span style="color: #000000; font-size: 18px;">Дропшиппинг</span></p>
<p><span style="color: #000000;">Отправки напрямую Вашим клиентам</span></p>
                </div>
            </div></div>
</div>
</div></div></div><div class="mrb-row_leadform mrb-row__content -inline-group_top mrb-row-index-3"><div class="mrb-small-18 mrb-medium-18
            mrb-col-index-0 mrb-large-18"><div class="-gd-content mrb-block-index-0">

	<div class="mrb-form mrb-form_leadform mrb-form_leadform_theme_dark lazyload"
		style="background-position: top left; background-repeat: no-repeat; background-size: auto; background-color: #F0C419;">
		<header class="mrb-form__title">Заявка на сотрудничество</header>
		<div class="mrb-form__content">
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

			<form id="dynamic-form-sotrudnichestvo" method="post" action="" class="js-no-tooltip dynamic-form dynamic-form-5">
				<?=bitrix_sessid_post()?>
				<input type="hidden" name="mf_form_id" value="sotrudnichestvo" />

				<div class="mrb-form__field">
					<input required data-rule-maxlength="100" maxlength="100" type="text" id="mf-sotr-name" name="NAME" placeholder="Ваше имя" class="dynamic-form-text" value="<?=htmlspecialchars($mfForm["values"]["NAME"] ?? "", ENT_QUOTES, "UTF-8")?>" />
					<label for="mf-sotr-name">Ваше имя</label>
				</div>

				<div class="mrb-form__field">
					<input required data-rule-maxlength="100" maxlength="100" type="tel" id="mf-sotr-phone" name="PHONE" placeholder="Контактный телефон" class="dynamic-form-text" value="<?=htmlspecialchars($mfForm["values"]["PHONE"] ?? "", ENT_QUOTES, "UTF-8")?>" />
					<label for="mf-sotr-phone">Контактный телефон</label>
				</div>

				<div class="mrb-form__field">
					<input required data-rule-maxlength="100" maxlength="100" type="email" id="mf-sotr-email" name="EMAIL" placeholder="E-mail" class="dynamic-form-text" value="<?=htmlspecialchars($mfForm["values"]["EMAIL"] ?? "", ENT_QUOTES, "UTF-8")?>" />
					<label for="mf-sotr-email">E-mail</label>
				</div>

				<div class="mrb-form__field">
					<input data-rule-maxlength="100" maxlength="100" type="text" id="mf-sotr-company" name="COMPANY" placeholder="Название (магазина, сервиса, организации)" class="dynamic-form-text" value="<?=htmlspecialchars($mfForm["values"]["COMPANY"] ?? "", ENT_QUOTES, "UTF-8")?>" />
					<label for="mf-sotr-company">Название (магазина, сервиса, организации)</label>
				</div>

				<div class="mrb-form__field">
					<input data-rule-maxlength="100" maxlength="100" type="text" id="mf-sotr-site" name="SITE" placeholder="Ссылка на сайт (если есть)" class="dynamic-form-text" value="<?=htmlspecialchars($mfForm["values"]["SITE"] ?? "", ENT_QUOTES, "UTF-8")?>" />
					<label for="mf-sotr-site">Ссылка на сайт (если есть)</label>
				</div>

				<div class="mrb-form__field">
					<input data-rule-maxlength="100" maxlength="100" type="text" id="mf-sotr-interest" name="INTEREST" placeholder="Напишите, что Вас интересует" class="dynamic-form-text" value="<?=htmlspecialchars($mfForm["values"]["INTEREST"] ?? "", ENT_QUOTES, "UTF-8")?>" />
					<label for="mf-sotr-interest">Напишите, что Вас интересует</label>
				</div>

				<button id="lead_form-button" class="dynamic-form-button-5 -btn -btn-complete" type="submit">Отправить</button>
			</form>
		</div>
	</div>

</div></div></div></div></div>
	</div>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>

