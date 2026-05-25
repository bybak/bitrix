<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * @global CMain $APPLICATION
 * @var array $arParams
 * @var array $arResult
 */

ShowError($arResult["strProfileError"]);

if ($arResult['DATA_SAVED'] === 'Y')
{
	ShowNote(GetMessage('PROFILE_DATA_SAVED'));
}
?>
<div class="bx_profile bx_<?=$arResult["THEME"]?>">
	<form method="post" name="form1" action="<?=$arResult["FORM_TARGET"]?>?" enctype="multipart/form-data">
		<?=$arResult["BX_SESSION_CHECK"]?>
		<input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>" />
		<input type="hidden" name="ID" value=<?=$arResult["ID"]?> />
		<input type="hidden" name="LOGIN" value=<?=$arResult["arUser"]["LOGIN"]?> />
		<input type="hidden" name="EMAIL" value=<?=$arResult["arUser"]["EMAIL"]?> />

		<h2><?=GetMessage("LEGEND_PROFILE")?></h2>

		<div class="mf-profile-field">
			<strong><?=GetMessage('NAME')?></strong>
			<input type="text" name="NAME" maxlength="50" value="<?=$arResult["arUser"]["NAME"]?>" />
		</div>

		<div class="mf-profile-field">
			<strong><?=GetMessage('LAST_NAME')?></strong>
			<input type="text" name="LAST_NAME" maxlength="50" value="<?=$arResult["arUser"]["LAST_NAME"]?>" />
		</div>

		<div class="mf-profile-field">
			<strong><?=GetMessage('SECOND_NAME')?></strong>
			<input type="text" name="SECOND_NAME" maxlength="50" value="<?=$arResult["arUser"]["SECOND_NAME"]?>" />
		</div>

		<div class="mf-profile-field">
			<strong><?=GetMessage('MF_PERSONAL_PHONE')?></strong>
			<input type="tel" name="PERSONAL_PHONE" maxlength="255" autocomplete="tel" value="<?=htmlspecialcharsbx((string)($arResult["arUser"]["PERSONAL_PHONE"] ?? ''))?>" />
		</div>

		<div class="mf-profile-field">
			<strong><?=GetMessage('MF_PERSONAL_CITY_REGION')?></strong>
			<input type="text" name="PERSONAL_CITY" maxlength="255" value="<?=htmlspecialcharsbx((string)($arResult['MF_CITY_REGION'] ?? $arResult["arUser"]["PERSONAL_CITY"] ?? ''))?>" />
			<input type="hidden" name="PERSONAL_STATE" value="" />
		</div>

		<div class="mf-profile-field">
			<strong><?=GetMessage('MF_PERSONAL_STREET')?></strong>
			<input type="text" name="PERSONAL_STREET" maxlength="255" value="<?=htmlspecialcharsbx((string)($arResult["arUser"]["PERSONAL_STREET"] ?? ''))?>" />
		</div>

		<h2><?=GetMessage("MAIN_PSWD")?></h2>

		<div class="mf-profile-field">
			<strong><?=GetMessage('NEW_PASSWORD_REQ')?></strong>
			<input type="password" name="NEW_PASSWORD" maxlength="50" value="" autocomplete="off" />
		</div>

		<div class="mf-profile-field">
			<strong><?=GetMessage('NEW_PASSWORD_CONFIRM')?></strong>
			<input type="password" name="NEW_PASSWORD_CONFIRM" maxlength="50" value="" autocomplete="off" />
		</div>

		<?php
		if($arResult["USER_PROPERTIES"]["SHOW"] === "Y"):
			?>
			<h2><?=trim($arParams["USER_PROPERTY_NAME"]) <> '' ? $arParams["USER_PROPERTY_NAME"] : GetMessage("USER_TYPE_EDIT_TAB")?></h2>
			<?php
			foreach ($arResult["USER_PROPERTIES"]["DATA"] as $FIELD_NAME => $arUserField):
				?>
				<div class="mf-profile-field">
					<strong><?=$arUserField["EDIT_FORM_LABEL"]?><?= ($arUserField["MANDATORY"] === "Y" ? '<span class="starrequired">*</span>' : '') ?></strong>
					<?php
					$APPLICATION->IncludeComponent(
						"bitrix:system.field.edit",
						$arUserField["USER_TYPE"]["USER_TYPE_ID"],
						[
							"bVarsFromForm" => $arResult["bVarsFromForm"],
							"arUserField" => $arUserField,
						],
						null,
						[
							"HIDE_ICONS" => "Y",
						]
					);?>
				</div>
			<?php
			endforeach;
		endif;
		?>

		<div class="mf-profile-actions">
			<input name="save" value="<?=GetMessage("MAIN_SAVE")?>" class="bx_bt_button bx_big shadow" type="submit">
		</div>
	</form>
</div>
