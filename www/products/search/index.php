<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

$text = trim((string)($_GET["text"] ?? ""));
if ($text !== "")
{
	LocalRedirect("/search/?q=".urlencode($text));
}

LocalRedirect("/search/");

