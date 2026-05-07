<?php

namespace Sale\Handlers\PaySystem;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Sale\PaySystem;
use Bitrix\Sale\Payment;

Loc::loadMessages(__FILE__);

final class mf_card2cardHandler extends PaySystem\BaseServiceHandler
{
	public function initiatePay(Payment $payment, Request $request = null)
	{
		$params = [
			'TITLE' => (string)$this->getBusinessValue($payment, 'CARD_TITLE'),
			'CARD_HOLDER' => (string)$this->getBusinessValue($payment, 'CARD_HOLDER'),
			'CARD_NUMBER' => (string)$this->getBusinessValue($payment, 'CARD_NUMBER'),
			'BANK_NAME' => (string)$this->getBusinessValue($payment, 'BANK_NAME'),
			'INSTRUCTIONS' => (string)$this->getBusinessValue($payment, 'INSTRUCTIONS'),
		];

		$this->setExtraParams($params);
		return $this->showTemplate($payment, 'template');
	}

	public function getCurrencyList()
	{
		return ['RUB'];
	}
}

