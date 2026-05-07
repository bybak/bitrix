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
<div class="mf-profile">
	<form class="mf-profile-form" method="post" name="form1" action="<?=$arResult["FORM_TARGET"]?>?" enctype="multipart/form-data">
		<?=$arResult["BX_SESSION_CHECK"]?>
		<input type="hidden" name="lang" value="<?=LANGUAGE_ID?>" />
		<input type="hidden" name="ID" value="<?=(int)$arResult["ID"]?>" />
		<input type="hidden" name="LOGIN" value="<?=htmlspecialcharsbx($arResult["arUser"]["LOGIN"])?>" />
		<input type="hidden" name="EMAIL" value="<?=htmlspecialcharsbx($arResult["arUser"]["EMAIL"])?>" />

		<section class="mf-profile-section">
			<header class="mf-profile-section__head">
				<div class="mf-profile-section__icon" aria-hidden="true">
					<?=function_exists('mf_icon') ? mf_icon('user', ['width' => 22, 'height' => 22]) : ''?>
				</div>
				<div>
					<h2 class="mf-profile-section__title"><?=GetMessage("LEGEND_PROFILE")?></h2>
					<p class="mf-profile-section__hint">Имя и контактные данные используются для оформления заказов и обращения к вам.</p>
				</div>
			</header>

			<div class="mf-profile-grid">
				<div class="mf-profile-field">
					<label class="mf-profile-label" for="profile_name"><?=GetMessage('NAME')?></label>
					<input id="profile_name" class="mf-profile-input" type="text" name="NAME" maxlength="50" value="<?=htmlspecialcharsbx($arResult["arUser"]["NAME"])?>" />
				</div>
				<div class="mf-profile-field">
					<label class="mf-profile-label" for="profile_last_name"><?=GetMessage('LAST_NAME')?></label>
					<input id="profile_last_name" class="mf-profile-input" type="text" name="LAST_NAME" maxlength="50" value="<?=htmlspecialcharsbx($arResult["arUser"]["LAST_NAME"])?>" />
				</div>
				<div class="mf-profile-field mf-profile-field--full">
					<label class="mf-profile-label" for="profile_second_name"><?=GetMessage('SECOND_NAME')?></label>
					<input id="profile_second_name" class="mf-profile-input" type="text" name="SECOND_NAME" maxlength="50" value="<?=htmlspecialcharsbx($arResult["arUser"]["SECOND_NAME"])?>" />
				</div>
			</div>
		</section>

		<section class="mf-profile-section">
			<header class="mf-profile-section__head">
				<div class="mf-profile-section__icon" aria-hidden="true">
					<?=function_exists('mf_icon') ? mf_icon('lock', ['width' => 22, 'height' => 22]) : ''?>
				</div>
				<div>
					<h2 class="mf-profile-section__title"><?=GetMessage("MAIN_PSWD")?></h2>
					<p class="mf-profile-section__hint">Чтобы оставить текущий пароль, оставьте оба поля пустыми.</p>
				</div>
			</header>

			<div class="mf-profile-grid">
				<div class="mf-profile-field">
					<label class="mf-profile-label" for="profile_new_password"><?=GetMessage('NEW_PASSWORD_REQ')?></label>
					<input id="profile_new_password" class="mf-profile-input" type="password" name="NEW_PASSWORD" maxlength="50" value="" autocomplete="new-password" />
				</div>
				<div class="mf-profile-field">
					<label class="mf-profile-label" for="profile_new_password_confirm"><?=GetMessage('NEW_PASSWORD_CONFIRM')?></label>
					<input id="profile_new_password_confirm" class="mf-profile-input" type="password" name="NEW_PASSWORD_CONFIRM" maxlength="50" value="" autocomplete="new-password" />
				</div>
			</div>
		</section>

		<?php if ($arResult["USER_PROPERTIES"]["SHOW"] === "Y"): ?>
			<section class="mf-profile-section">
				<header class="mf-profile-section__head">
					<div class="mf-profile-section__icon" aria-hidden="true">
						<?=function_exists('mf_icon') ? mf_icon('document', ['width' => 22, 'height' => 22]) : ''?>
					</div>
					<div>
						<h2 class="mf-profile-section__title"><?=trim($arParams["USER_PROPERTY_NAME"]) <> '' ? htmlspecialcharsbx($arParams["USER_PROPERTY_NAME"]) : GetMessage("USER_TYPE_EDIT_TAB")?></h2>
						<p class="mf-profile-section__hint">Дополнительные сведения о вас как клиенте.</p>
					</div>
				</header>

				<div class="mf-profile-grid">
					<?php foreach ($arResult["USER_PROPERTIES"]["DATA"] as $FIELD_NAME => $arUserField): ?>
						<div class="mf-profile-field mf-profile-field--full">
							<label class="mf-profile-label">
								<?=htmlspecialcharsbx($arUserField["EDIT_FORM_LABEL"])?>
								<?=$arUserField["MANDATORY"] === "Y" ? '<span class="mf-profile-req">*</span>' : ''?>
							</label>
							<?php
							$APPLICATION->IncludeComponent(
								"bitrix:system.field.edit",
								$arUserField["USER_TYPE"]["USER_TYPE_ID"],
								[
									"bVarsFromForm" => $arResult["bVarsFromForm"],
									"arUserField" => $arUserField,
								],
								null,
								["HIDE_ICONS" => "Y"]
							);
							?>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<div class="mf-profile-actions">
			<button type="submit" name="save" class="mf-profile-submit">
				<?=function_exists('mf_icon') ? mf_icon('check', ['width' => 18, 'height' => 18]) : ''?>
				<span><?=GetMessage("MAIN_SAVE")?></span>
			</button>
		</div>
	</form>
</div>
