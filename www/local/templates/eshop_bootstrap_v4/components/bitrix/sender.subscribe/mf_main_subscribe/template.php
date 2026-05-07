<?if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();
/** @var array $arParams */
/** @var array $arResult */
/** @global CMain $APPLICATION */

$emailValue = $arResult["EMAIL"] ?? "";
?>

<?if(isset($arResult['MESSAGE']) && is_array($arResult['MESSAGE'])):?>
	<div class="subscribe-add-form__message subscribe-add-form__message--<?=htmlspecialcharsbx(mb_strtolower($arResult['MESSAGE']['TYPE'] ?? 'note'))?>">
		<?=htmlspecialcharsbx($arResult['MESSAGE']['TEXT'] ?? '')?>
	</div>
<?endif;?>

<form
		id="subscriber-add-form"
		method="post"
		action="<?=$arResult["FORM_ACTION"]?>"
		class=""
>
	<?=bitrix_sessid_post()?>
	<input type="hidden" name="sender_subscription" value="add">

	<div class="hide">
		<input type="text" name="lastname" id="lastname" value="">
	</div>

	<?php
	// Ensure at least one rubric is posted even when we hide mailings:
	// by default the component marks all rubrics as CHECKED for new users.
	if (!empty($arResult["RUBRICS"]) && is_array($arResult["RUBRICS"])):?>
		<?foreach($arResult["RUBRICS"] as $itemValue):?>
			<?if(!empty($itemValue["CHECKED"])):?>
				<input type="hidden" name="SENDER_SUBSCRIBE_RUB_ID[]" value="<?=htmlspecialcharsbx($itemValue["ID"])?>">
			<?endif;?>
		<?endforeach;?>
	<?endif;?>

	<div class="subscribe-add-form__container">
		<div class="inline-block">
			<input
					id="username"
					type="text"
					class="input"
					name="name"
					maxlength="255"
					required="required"
					placeholder="Представьтесь, пожалуйста"
					data-default="Представьтесь, пожалуйста"
			>
		</div>

		<div class="inline-block">
			<input
					type="text"
					id="useremail"
					class="input"
					name="SENDER_SUBSCRIBE_EMAIL"
					maxlength="128"
					required="required"
					placeholder="Введите адрес эл. почты"
					data-default="Email"
					value="<?=htmlspecialcharsbx($emailValue)?>"
			>
		</div>

		<button
				id="subscriber-add-form-button"
				type="submit"
				class="button button_for_subscriber-add-form subscribe-add-form__button"
		>Подписаться</button>
	</div>
</form>

