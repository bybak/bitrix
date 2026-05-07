<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

$data = [
	'NAME' => Loc::getMessage('MF_HPS_PAYKEEPER_NAME'),
	'SORT' => 100,
	'CODES' => [
		'PK_SERVER' => [
			'NAME' => Loc::getMessage('MF_HPS_PAYKEEPER_SERVER'),
			'SORT' => 100,
			'GROUP' => 'GENERAL_SETTINGS',
		],
		'PK_USER' => [
			'NAME' => Loc::getMessage('MF_HPS_PAYKEEPER_USER'),
			'SORT' => 110,
			'GROUP' => 'GENERAL_SETTINGS',
		],
		'PK_PASSWORD' => [
			'NAME' => Loc::getMessage('MF_HPS_PAYKEEPER_PASSWORD'),
			'SORT' => 120,
			'GROUP' => 'GENERAL_SETTINGS',
		],
		'PK_SECRET' => [
			'NAME' => Loc::getMessage('MF_HPS_PAYKEEPER_SECRET'),
			'SORT' => 130,
			'GROUP' => 'GENERAL_SETTINGS',
		],
		'PK_USE_SBP' => [
			'NAME' => Loc::getMessage('MF_HPS_PAYKEEPER_USE_SBP'),
			'SORT' => 140,
			'GROUP' => 'GENERAL_SETTINGS',
			'INPUT' => ['TYPE' => 'Y/N'],
			'DEFAULT' => ['PROVIDER_VALUE' => 'Y', 'PROVIDER_KEY' => 'VALUE'],
		],
		'PK_PSID' => [
			'NAME' => Loc::getMessage('MF_HPS_PAYKEEPER_PSID'),
			'SORT' => 150,
			'GROUP' => 'GENERAL_SETTINGS',
			'DEFAULT' => ['PROVIDER_VALUE' => '', 'PROVIDER_KEY' => 'VALUE'],
		],
		'PK_TTL' => [
			'NAME' => Loc::getMessage('MF_HPS_PAYKEEPER_TTL'),
			'SORT' => 160,
			'GROUP' => 'GENERAL_SETTINGS',
			'DEFAULT' => ['PROVIDER_VALUE' => '5', 'PROVIDER_KEY' => 'VALUE'],
		],
		'PK_PURPOSE' => [
			'NAME' => Loc::getMessage('MF_HPS_PAYKEEPER_PURPOSE'),
			'SORT' => 170,
			'GROUP' => 'GENERAL_SETTINGS',
			'DEFAULT' => ['PROVIDER_VALUE' => 'Оплата заказа', 'PROVIDER_KEY' => 'VALUE'],
		],
		'PK_SEND_RECEIPT' => [
			'NAME' => Loc::getMessage('MF_HPS_PAYKEEPER_SEND_RECEIPT'),
			'SORT' => 180,
			'GROUP' => 'GENERAL_SETTINGS',
			'INPUT' => ['TYPE' => 'Y/N'],
			'DEFAULT' => ['PROVIDER_VALUE' => 'Y', 'PROVIDER_KEY' => 'VALUE'],
		],
		'PK_VAT' => [
			'NAME' => Loc::getMessage('MF_HPS_PAYKEEPER_VAT'),
			'SORT' => 190,
			'GROUP' => 'GENERAL_SETTINGS',
			'DEFAULT' => ['PROVIDER_VALUE' => 'none', 'PROVIDER_KEY' => 'VALUE'],
		],
	],
];

return $data;

