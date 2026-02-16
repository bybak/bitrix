<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
if (function_exists("LocalRedirect"))
{
	LocalRedirect("/prokat/", false, "301 Moved Permanently");
}
$APPLICATION->SetTitle("Прокат");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");
?>

<div class="mb-4">
	<p class="text-muted mb-0">
		Если вы видите этот текст, переходите в раздел <a href="/prokat/">Прокат</a>.
	</p>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>

