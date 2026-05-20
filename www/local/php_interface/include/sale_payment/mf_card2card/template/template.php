<?php
/**
 * @var array $params
 * @var \Bitrix\Sale\Payment $payment
 */
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Mf\Card2Card\TemplateRenderer;

$order = null;
try
{
	$collection = $payment->getCollection();
	if ($collection)
	{
		$order = $collection->getOrder();
	}
}
catch (\Throwable $e)
{
}

$html = '';
if ($order instanceof \Bitrix\Sale\Order)
{
	$html = TemplateRenderer::renderForOrder($order, $payment);
}

if ($html !== '')
{
	echo '<div class="mf-pay mf-pay-card2card mf-pay-card2card--event-template">' . $html . '</div>';
	return;
}
?>
<div class="alert alert-warning">
	Инструкция по оплате временно недоступна. Проверьте шаблон почтового события
	<code>MF_CARD2CARD_INSTRUCTIONS</code> в админке Bitrix.
</div>
