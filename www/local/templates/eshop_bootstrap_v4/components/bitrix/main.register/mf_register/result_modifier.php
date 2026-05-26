<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$arResult['MF_PERSON_KIND'] = function_exists('mf_checkout_register_person_kind')
	? mf_checkout_register_person_kind()
	: ((trim((string)($_REQUEST['MF_PERSON_KIND'] ?? 'fiz')) === 'jur') ? 'jur' : 'fiz');

$arResult['MF_COMPANY'] = function_exists('mf_checkout_register_company_data_from_request')
	? mf_checkout_register_company_data_from_request()
	: [];

if (empty($arResult['MF_COMPANY']['EMAIL']) && !empty($arResult['VALUES']['EMAIL']))
{
	$arResult['MF_COMPANY']['EMAIL'] = (string)$arResult['VALUES']['EMAIL'];
}
if (empty($arResult['MF_COMPANY']['PHONE']) && !empty($arResult['VALUES']['PHONE_NUMBER']))
{
	$arResult['MF_COMPANY']['PHONE'] = (string)$arResult['VALUES']['PHONE_NUMBER'];
}
