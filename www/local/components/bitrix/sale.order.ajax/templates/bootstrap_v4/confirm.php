<? if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Localization\Loc;

/**
 * @var array $arParams
 * @var array $arResult
 * @var $APPLICATION CMain
 */

if ($arParams["SET_TITLE"] == "Y")
{
	$APPLICATION->SetTitle(Loc::getMessage("SOA_ORDER_COMPLETE"));
}

$mfHidePaymentOnConfirm = false;
$mfPersonTypeId = (int)($arResult['ORDER']['PERSON_TYPE_ID'] ?? 0);
if ($mfPersonTypeId > 0 && function_exists('mf_checkout_person_type_map'))
{
	$mfPtMap = mf_checkout_person_type_map();
	$mfJurPersonTypeId = (int)($mfPtMap['jur'] ?? 0);
	$mfHidePaymentOnConfirm = ($mfJurPersonTypeId > 0 && $mfPersonTypeId === $mfJurPersonTypeId);
}
?>

<? if (!empty($arResult["ORDER"])): ?>

	<div class="row mb-5">
		<div class="col">
			<?php
			$mfOrderConfirmNum = (string)($arResult['ORDER']['ACCOUNT_NUMBER_DISPLAY'] ?? $arResult['ORDER']['ACCOUNT_NUMBER']);
			?>
			<?=Loc::getMessage("SOA_ORDER_SUC", array(
				"#ORDER_DATE#" => $arResult["ORDER"]["DATE_INSERT"]->toUserTime()->format('d.m.Y H:i'),
				"#ORDER_ID#" => htmlspecialcharsbx($mfOrderConfirmNum)
			))?>
			<? if (!$mfHidePaymentOnConfirm && !empty($arResult['ORDER']["PAYMENT_ID"])): ?>
				<?=Loc::getMessage("SOA_PAYMENT_SUC", array(
					"#PAYMENT_ID#" => htmlspecialcharsbx($arResult['PAYMENT'][$arResult['ORDER']["PAYMENT_ID"]]['ACCOUNT_NUMBER'])
				))?>
			<? endif ?>
		</div>
	</div>

	<? if ($arParams['NO_PERSONAL'] !== 'Y'): ?>
		<div class="row mb-5">
			<div class="col">
				<?=Loc::getMessage('SOA_ORDER_SUC1', ['#LINK#' => $arParams['PATH_TO_PERSONAL']])?>
			</div>
		</div>
	<? endif; ?>

	<?
	if (!$mfHidePaymentOnConfirm && $arResult["ORDER"]["IS_ALLOW_PAY"] === 'Y')
	{
		if (!empty($arResult["PAYMENT"]))
		{
			foreach ($arResult["PAYMENT"] as $payment)
			{
				if ($payment["PAID"] != 'Y')
				{
					if (!empty($arResult['PAY_SYSTEM_LIST'])
						&& array_key_exists($payment["PAY_SYSTEM_ID"], $arResult['PAY_SYSTEM_LIST'])
					)
					{
						$arPaySystem = $arResult['PAY_SYSTEM_LIST_BY_PAYMENT_ID'][$payment["ID"]];

						if (empty($arPaySystem["ERROR"]))
						{
							$mfIsCard2Card = class_exists(\Mf\Card2Card\TemplateRenderer::class)
								&& \Mf\Card2Card\TemplateRenderer::isCard2CardPaySystemId((int)($payment['PAY_SYSTEM_ID'] ?? 0));
							?>

							<? if (!$mfIsCard2Card): ?>
							<div class="row mb-2">
								<div class="col">
									<h3 class="pay_name"><?=Loc::getMessage("SOA_PAY") ?></h3>
								</div>
							</div>
							<div class="row mb-2 align-items-center">
								<div class="col-auto"><strong><?=$arPaySystem["NAME"] ?></strong></div>
								<div class="col"><?=CFile::ShowImage($arPaySystem["LOGOTIP"], 100, 100, "border=0\" style=\"width:100px\"", "", false) ?></div>
							</div>
							<? endif; ?>
							<div class="row mb-2">
								<div class="col<?=$mfIsCard2Card ? ' mf-order-confirm-card2card' : ''?>">
									<? if ($arPaySystem["ACTION_FILE"] <> '' && $arPaySystem["NEW_WINDOW"] == "Y" && $arPaySystem["IS_CASH"] != "Y"): ?>
									<?
										$orderAccountNumber = urlencode(urlencode($arResult["ORDER"]["ACCOUNT_NUMBER"]));
										$paymentAccountNumber = $payment["ACCOUNT_NUMBER"];
									?>
									<script>
										window.open('<?=$arParams["PATH_TO_PAYMENT"]?>?ORDER_ID=<?=$orderAccountNumber?>&PAYMENT_ID=<?=$paymentAccountNumber?>');
									</script>
									<?=Loc::getMessage("SOA_PAY_LINK", array("#LINK#" => $arParams["PATH_TO_PAYMENT"]."?ORDER_ID=".$orderAccountNumber."&PAYMENT_ID=".$paymentAccountNumber))?>
									<? if (CSalePdf::isPdfAvailable() && $arPaySystem['IS_AFFORD_PDF']): ?>
									<br/>
										<?=Loc::getMessage("SOA_PAY_PDF", array("#LINK#" => $arParams["PATH_TO_PAYMENT"]."?ORDER_ID=".$orderAccountNumber."&pdf=1&DOWNLOAD=Y"))?>
									<? endif ?>
									<? else: ?>
										<?=$arPaySystem["BUFFERED_OUTPUT"]?>
									<? endif ?>
								</div>
							</div>



							<?
						}
						else
						{
							?>
							<div class="alert alert-danger" role="alert"><?=Loc::getMessage("SOA_ORDER_PS_ERROR")?></div>
							<?
						}
					}
					else
					{
						?>
						<div class="alert alert-danger" role="alert"><?=Loc::getMessage("SOA_ORDER_PS_ERROR")?></div>
						<?
					}
				}
			}
		}
	}
	elseif (!$mfHidePaymentOnConfirm)
	{
		?>
		<div class="alert alert-danger" role="alert"><?=$arParams['MESS_PAY_SYSTEM_PAYABLE_ERROR']?></div>
		<?
	}
	?>

<? else: ?>


	<div class="row mb-2">
		<div class="col">
			<div class="alert alert-danger" role="alert"><strong><?=Loc::getMessage("SOA_ERROR_ORDER")?></strong><br />
				<?=Loc::getMessage("SOA_ERROR_ORDER_LOST", ["#ORDER_ID#" => htmlspecialcharsbx((string)($arResult["ACCOUNT_NUMBER_DISPLAY"] ?? $arResult["ACCOUNT_NUMBER"] ?? ''))])?><br />
				<?=Loc::getMessage("SOA_ERROR_ORDER_LOST1")?></div>
		</div>
	</div>

<? endif ?>