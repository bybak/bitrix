<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * Обёртка над стандартным шаблоном: result_modifier.php (в этой папке) подставляет backurl.
 */
?>
<div class="mf-auth-card mf-auth-card--system-form">
<?php
include $_SERVER['DOCUMENT_ROOT'] . '/bitrix/components/bitrix/system.auth.form/templates/.default/template.php';
?>
</div>
