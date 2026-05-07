<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * Подставляем backurl из запроса, чтобы после входа вернуться (например на оформление заказа).
 *
 * @var array $arResult
 */

$back = isset($_REQUEST['backurl']) ? (string)$_REQUEST['backurl'] : '';
if ($back !== '' && preg_match('#^/\w#', $back))
{
	$arResult['~BACKURL'] = $back;
	$arResult['BACKURL'] = htmlspecialcharsbx($back);

	$enc = rawurlencode($back);
	$base = '/login/';

	$arResult['~AUTH_REGISTER_URL'] = $base . '?register=yes&backurl=' . $enc;
	$arResult['AUTH_REGISTER_URL'] = htmlspecialcharsbx($arResult['~AUTH_REGISTER_URL']);

	$arResult['~AUTH_FORGOT_PASSWORD_URL'] = $base . '?forgot_password=yes&backurl=' . $enc;
	$arResult['AUTH_FORGOT_PASSWORD_URL'] = htmlspecialcharsbx($arResult['~AUTH_FORGOT_PASSWORD_URL']);
}

// Не выводить блок «Войти с помощью соцсетей» (шаблон .default рендерит его при AUTH_SERVICES).
$arResult['AUTH_SERVICES'] = false;
$arResult['~AUTH_SERVICES'] = false;
$arResult['CURRENT_SERVICE'] = false;
$arResult['~CURRENT_SERVICE'] = false;
