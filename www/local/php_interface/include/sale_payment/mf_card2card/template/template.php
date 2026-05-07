<?php
/**
 * @var array $params
 * @var \Bitrix\Sale\Payment $payment
 */
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

$title = trim((string)($params['TITLE'] ?? 'Перевод с карты на карту'));
$holder = trim((string)($params['CARD_HOLDER'] ?? ''));
$number = trim((string)($params['CARD_NUMBER'] ?? ''));
$bank = trim((string)($params['BANK_NAME'] ?? ''));
$instr = (string)($params['INSTRUCTIONS'] ?? '');
?>

<div class="mf-pay mf-pay-card2card">
	<h3><?=htmlspecialcharsbx($title)?></h3>
	<p>Сумма к оплате: <strong><?=SaleFormatCurrency($payment->getSum(), $payment->getField('CURRENCY'))?></strong></p>

	<?php if ($holder !== '' || $number !== '' || $bank !== ''): ?>
		<div class="alert alert-info">
			<?php if ($bank !== ''): ?><div><strong>Банк:</strong> <?=htmlspecialcharsbx($bank)?></div><?php endif; ?>
			<?php if ($holder !== ''): ?><div><strong>Получатель:</strong> <?=htmlspecialcharsbx($holder)?></div><?php endif; ?>
			<?php if ($number !== ''): ?><div><strong>Карта:</strong> <?=htmlspecialcharsbx($number)?></div><?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if (trim($instr) !== ''): ?>
		<div class="mf-pay-instructions">
			<?=nl2br(htmlspecialcharsbx($instr))?>
		</div>
	<?php else: ?>
		<p class="text-muted">После перевода сообщите нам об оплате (номер заказа и время перевода) — мы подтвердим оплату вручную.</p>
	<?php endif; ?>
</div>

