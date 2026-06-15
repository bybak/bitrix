<?php
/**
 * @var array $params
 * @var \Bitrix\Sale\Payment $payment
 */
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

$error = trim((string)($params['ERROR'] ?? ''));
$sumLabel = function_exists('mf_sale_format_currency')
	? mf_sale_format_currency($payment->getSum(), $payment->getField('CURRENCY'))
	: SaleFormatCurrency($payment->getSum(), $payment->getField('CURRENCY'));
?>

<div class="mf-pay mf-pay-paykeeper">
	<h3>Оплата заказа</h3>

	<?php if ($error !== ''): ?>
		<div class="alert alert-danger">
			<strong>Ошибка:</strong> <?=htmlspecialcharsbx($error)?>
		</div>
	<?php endif; ?>

	<?php
	if (class_exists(\Sale\Handlers\PaySystem\mf_paykeeperHandler::class, false))
	{
		echo \Sale\Handlers\PaySystem\mf_paykeeperHandler::awaitingPaymentEmailMessageHtml($sumLabel);
	}
	else
	{
		?>
		<p><strong>Заказ успешно оформлен.</strong></p>
		<p>В течение нескольких минут на вашу электронную почту придёт письмо со ссылкой на оплату.</p>
		<p>Сумма к оплате: <strong><?=htmlspecialcharsbx($sumLabel)?></strong>.</p>
		<p class="text-muted">Если письмо не пришло в течение 10–15 минут, проверьте папку «Спам» или свяжитесь с менеджером магазина.</p>
		<?php
	}
	?>
</div>

