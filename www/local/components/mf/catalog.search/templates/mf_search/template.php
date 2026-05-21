<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$tpl = (string)($_SERVER['DOCUMENT_ROOT'] ?? '')
	. '/local/templates/eshop_bootstrap_v4/components/bitrix/search.page/mf_search/template.php';
if (is_file($tpl))
{
	include $tpl;
}
