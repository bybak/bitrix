<?php
/**
 * @var array $params
 * @var \Bitrix\Sale\Payment $payment
 */
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

$payLink = trim((string)($params['PAY_LINK'] ?? ''));
$qrCode = trim((string)($params['QR_CODE'] ?? ''));
$qrLink = trim((string)($params['QR_LINK'] ?? ''));
$error = trim((string)($params['ERROR'] ?? ''));
?>

<div class="mf-pay mf-pay-paykeeper">
	<h3>Оплата PayKeeper</h3>

	<p>Сумма к оплате: <strong><?=function_exists('mf_sale_format_currency') ? mf_sale_format_currency($payment->getSum(), $payment->getField('CURRENCY')) : SaleFormatCurrency($payment->getSum(), $payment->getField('CURRENCY'))?></strong></p>

	<?php if ($error !== ''): ?>
		<div class="alert alert-danger">
			<strong>Ошибка PayKeeper:</strong> <?=htmlspecialcharsbx($error)?>
		</div>
	<?php endif; ?>

	<?php if ($payLink !== ''): ?>
		<p>
			<a class="btn btn-primary" href="<?=htmlspecialcharsbx($payLink)?>" target="_blank" rel="nofollow noopener">
				Оплатить по ссылке (карта)
			</a>
		</p>
		<p class="text-muted">Ссылка откроется в новом окне PayKeeper.</p>
	<?php endif; ?>

	<?php if ($qrCode !== ''): ?>
		<div class="mt-4">
			<h4>СБП (QR-код)</h4>
			<div class="alert alert-info">
				Отсканируйте QR‑код в приложении банка, чтобы оплатить через СБП.
			</div>
			<div style="max-width: 320px">
				<img src="<?=htmlspecialcharsbx($qrCode)?>" alt="QR СБП" style="max-width: 100%; height: auto; border-radius: 12px;">
			</div>
			<?php if ($qrLink !== ''): ?>
				<p class="mt-2">
					<a href="<?=htmlspecialcharsbx($qrLink)?>" target="_blank" rel="nofollow noopener">Открыть ссылку СБП</a>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>

