<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

$target = '/personal/cart/';
if (!empty($_SERVER['QUERY_STRING']))
{
	$target .= '?' . $_SERVER['QUERY_STRING'];
}

LocalRedirect($target, true, '301 Moved Permanently');

