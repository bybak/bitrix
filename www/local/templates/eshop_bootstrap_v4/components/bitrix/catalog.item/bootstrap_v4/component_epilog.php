<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

// Keep stock epilog behavior/assets.
$templateFolder = '/bitrix/components/bitrix/catalog.item/templates/bootstrap_v4';
include($_SERVER['DOCUMENT_ROOT'] . $templateFolder . '/component_epilog.php');

