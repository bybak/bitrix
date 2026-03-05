<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

// Use our redesigned `.default` catalog.item templates (cards/line) while keeping the
// component template name `bootstrap_v4` used by `catalog.section`.
include($_SERVER['DOCUMENT_ROOT'] . SITE_TEMPLATE_PATH . '/components/bitrix/catalog.item/.default/template.php');
return;

