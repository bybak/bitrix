<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Контакты");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");
?>

<div class="mb-4">
	<h2 class="h4">Наименование компании</h2>
	<div class="card mb-4">
		<div class="card-body">
			<p class="mb-0"><strong>Полное наименование:</strong> ИП Никитин Андрей Александрович</p>
		</div>
	</div>

	<h2 class="h4">Регистрационная информация</h2>
	<div class="card mb-4">
		<div class="card-body">
			<p class="mb-2"><strong>Фактический адрес:</strong> г. Санкт-Петербург, ул. Салова, д. 57, к. 1, Литера Ч, оф. 1Н</p>
			<p class="mb-0"><strong>ИНН:</strong> 471803332762</p>
		</div>
	</div>

	<h2 class="h4">Банковские реквизиты</h2>
	<div class="card">
		<div class="card-body">
			<ul class="mb-0 pl-3">
				<li><strong>Банк получателя:</strong> СЕВЕРО-ЗАПАДНЫЙ БАНК ПАО СБЕРБАНК</li>
				<li><strong>Расчётный счёт:</strong> 40802810755000477709</li>
				<li><strong>Корреспондентский счёт:</strong> 30101810500000000653</li>
				<li><strong>БИК:</strong> 044030653</li>
			</ul>
		</div>
	</div>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>

