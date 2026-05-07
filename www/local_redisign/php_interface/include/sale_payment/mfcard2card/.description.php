<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

$data = [
	'NAME' => Loc::getMessage('MF_HPS_CARD2CARD_NAME'),
	'SORT' => 200,
	'CODES' => [
		'CARD_TITLE' => [
			'NAME' => Loc::getMessage('MF_HPS_CARD2CARD_CARD_TITLE'),
			'SORT' => 100,
			'GROUP' => 'GENERAL_SETTINGS',
			'DEFAULT' => ['PROVIDER_VALUE' => 'Перевод с карты на карту', 'PROVIDER_KEY' => 'VALUE'],
		],
		'CARD_HOLDER' => [
			'NAME' => Loc::getMessage('MF_HPS_CARD2CARD_CARD_HOLDER'),
			'SORT' => 110,
			'GROUP' => 'GENERAL_SETTINGS',
		],
		'CARD_NUMBER' => [
			'NAME' => Loc::getMessage('MF_HPS_CARD2CARD_CARD_NUMBER'),
			'SORT' => 120,
			'GROUP' => 'GENERAL_SETTINGS',
		],
		'BANK_NAME' => [
			'NAME' => Loc::getMessage('MF_HPS_CARD2CARD_BANK_NAME'),
			'SORT' => 130,
			'GROUP' => 'GENERAL_SETTINGS',
		],
		'INSTRUCTIONS' => [
			'NAME' => Loc::getMessage('MF_HPS_CARD2CARD_INSTRUCTIONS'),
			'SORT' => 140,
			'GROUP' => 'GENERAL_SETTINGS',
			'INPUT' => ['TYPE' => 'TEXT'],
		],
	],
];

