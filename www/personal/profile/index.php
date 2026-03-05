<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Профиль");

// alias: многие темы ведут на /personal/profile/, а компонент ожидает /personal/profiles/
LocalRedirect('/personal/profiles/');

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");

