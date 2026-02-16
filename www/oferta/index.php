<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
if (function_exists("LocalRedirect"))
{
	LocalRedirect("/dogovor-oferti/", false, "301 Moved Permanently");
}
$APPLICATION->SetTitle("Договор оферты");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");
?>

<div class="mb-4">
	<p class="text-muted mb-0">Перенаправляем на актуальную страницу: <a href="/dogovor-oferti/">/dogovor-oferti/</a>.</p>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>

