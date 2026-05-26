<?if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();?>
				</div><!--end .bx-content -->

			</div><!--end row-->
			<?php
			$curPage = $APPLICATION->GetCurPage(true);
			$isHome = ($curPage === SITE_DIR."index.php");
			?>
			<?if (!$isHome):?>
				<?$APPLICATION->IncludeComponent(
					"bitrix:main.include",
					"",
					Array(
						"AREA_FILE_SHOW" => "sect",
						"AREA_FILE_SUFFIX" => "bottom",
						"AREA_FILE_RECURSIVE" => "N",
						"EDIT_MODE" => "html",
					),
					false,
					Array('HIDE_ICONS' => 'Y')
				);?>
			<?endif;?>
			</div><!--end .container-->
		</div><!--end .bx-content-section-->
	</div><!--end .workarea-->

	<?php
	$mfYearFrom = 2015;
	$mfYearTo = (int)date('Y');
	$mfYearLabel = ($mfYearTo > $mfYearFrom) ? ($mfYearFrom . '–' . $mfYearTo) : (string)$mfYearFrom;
	?>
	<footer id="footer" class="mf-footer">
		<div class="container">
			<div class="row mf-footer-top">
				<div class="col-12 col-md-4 col-lg-3 mb-4 mb-lg-0">
					<div class="mf-footer-brand">Motor-Force</div>
					<div class="mf-footer-tagline">Запчасти и аксессуары для мототехники</div>
					<ul class="mf-footer-contact">
						<li><a href="tel:+78129864276">8 (812) 986-42-76</a></li>
						<li><a href="tel:+79218837340">8 (921) 883-73-40</a></li>
						<li><a href="mailto:andrey@motor-force.ru">andrey@motor-force.ru</a></li>
						<li class="mf-footer-muted">Санкт-Петербург, ул. Салова, д. 57, к. 1 Литера Ч</li>
					</ul>
				</div>

				<div class="col-6 col-md-4 col-lg-3 mb-4 mb-lg-0">
					<div class="mf-footer-title">Покупателям</div>
					<ul class="mf-footer-links">
						<li><a href="/products/">Магазин</a></li>
						<li><a href="/oplata/">Оплата</a></li>
						<li><a href="/delivery/">Доставка</a></li>
						<li><a href="/faq/">FAQ</a></li>
						<li><a href="/contacts/">Контакты</a></li>
					</ul>
				</div>

				<div class="col-6 col-md-4 col-lg-3 mb-4 mb-lg-0">
					<div class="mf-footer-title">Услуги</div>
					<ul class="mf-footer-links">
						<li><a href="/remont_motorov/">Ремонт моторов</a></li>
						<li><a href="/vikup_mototehniki/">Выкуп мототехники</a></li>
						<li><a href="/sotrudnichestvo/">Сотрудничество</a></li>
					</ul>
				</div>

				<div class="col-12 col-lg-3">
					<div class="mf-footer-title">Медиа</div>
					<ul class="mf-footer-links">
						<li><a href="/posts/">Новости</a></li>
						<li><a href="/blog/">Блог</a></li>
						<li><a href="/dogovor-oferti/">Договор оферты</a></li>
					</ul>

					<div class="mf-footer-title mt-4">Мы в соцсетях</div>
					<ul class="mf-footer-social">
						<li><a href="https://vk.com/motor_force" target="_blank" rel="nofollow noopener">ВКонтакте</a></li>
						<li><a href="https://t.me/motor_force" target="_blank" rel="nofollow noopener">Telegram</a></li>
						<li><a href="https://www.instagram.com/motor_force.ru/" target="_blank" rel="nofollow noopener">Instagram</a></li>
					</ul>
				</div>
			</div>

			<div class="mf-footer-divider"></div>

			<div class="row align-items-center mf-footer-bottom">
				<div class="col-12 col-md-7">
					<div class="mf-footer-copy">
						<span class="mf-footer-year"><?=$mfYearLabel?></span> © Motor‑Force
						<span class="mf-footer-dot">•</span>
						<span class="mf-footer-muted">Запчасти и аксессуары для мототехники</span>
					</div>
				</div>
				<div class="col-12 col-md-5 mt-3 mt-md-0 text-md-right">
					<div class="mf-footer-bottom-links">
						<a href="/contacts/">Контакты</a>
						<a href="/delivery/">Доставка</a>
						<a href="/oplata/">Оплата</a>
					</div>
				</div>
			</div>
		</div>
	</footer>
	<div class="col d-sm-none">
		<?$APPLICATION->IncludeComponent("bitrix:sale.basket.basket.line", "bootstrap_v4", array(
				"PATH_TO_BASKET" => SITE_DIR."personal/cart/",
				"PATH_TO_PERSONAL" => SITE_DIR."personal/",
				"SHOW_PERSONAL_LINK" => "N",
				"SHOW_NUM_PRODUCTS" => "Y",
				"SHOW_TOTAL_PRICE" => "Y",
				"SHOW_PRODUCTS" => "N",
				"POSITION_FIXED" =>"Y",
				"POSITION_HORIZONTAL" => "center",
				"POSITION_VERTICAL" => "bottom",
				"SHOW_AUTHOR" => "Y",
				"PATH_TO_REGISTER" => SITE_DIR."login/",
				"PATH_TO_PROFILE" => SITE_DIR."personal/"
			),
			false,
			array()
		);?>
	</div>
</div> <!-- //bx-wrapper -->
<?php
$mfDocRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
$mfJivoInc = $mfDocRoot !== '' ? ($mfDocRoot . '/local/php_interface/include/mf_jivo.php') : '';
if ($mfJivoInc !== '' && is_file($mfJivoInc))
{
	require_once $mfJivoInc;
	mf_jivo_print_body_script();
}
?>
</body>
</html>