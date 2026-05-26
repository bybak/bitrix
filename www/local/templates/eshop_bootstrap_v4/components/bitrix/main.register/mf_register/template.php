<?php
use Bitrix\Main\Web\Json;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/** @global CMain $APPLICATION */
/** @global CUser $USER */
/** @var array $arParams */
/** @var array $arResult */

if (($arResult['SHOW_SMS_FIELD'] ?? false) === true)
{
	CJSCore::Init('phone_auth');
}

$personKind = ($arResult['MF_PERSON_KIND'] ?? 'fiz') === 'jur' ? 'jur' : 'fiz';
$company = is_array($arResult['MF_COMPANY'] ?? null) ? $arResult['MF_COMPANY'] : [];
$loginHref = '/login/?login=yes';
if (!empty($arResult['BACKURL']))
{
	$loginHref .= '&backurl=' . rawurlencode((string)$arResult['BACKURL']);
}
?>

<div class="mf-auth-head">
	<div class="mf-auth-title"><?=GetMessage('MF_REG_TITLE')?></div>
	<div class="mf-auth-subtitle"><?=GetMessage('MF_REG_SUBTITLE')?></div>
</div>

<?php if ($USER->IsAuthorized()): ?>
	<p><?=GetMessage('MAIN_REGISTER_AUTH')?></p>
<?php else: ?>

<?php if (!empty($arResult['ERRORS'])): ?>
	<div class="alert alert-danger mf-auth-alert">
		<?php
		foreach ($arResult['ERRORS'] as $key => $error)
		{
			if (intval($key) === 0 && $key !== 0)
			{
				$error = str_replace('#FIELD_NAME#', '&quot;' . GetMessage('REGISTER_FIELD_' . $key) . '&quot;', $error);
			}
			echo htmlspecialcharsbx($error) . '<br>';
		}
		?>
	</div>
<?php elseif (($arResult['USE_EMAIL_CONFIRMATION'] ?? 'N') === 'Y'): ?>
	<div class="alert alert-warning mf-auth-alert"><?=GetMessage('REGISTER_EMAIL_WILL_BE_SENT')?></div>
<?php endif; ?>

<?php if (($arResult['SHOW_SMS_FIELD'] ?? false) === true): ?>
	<form class="mf-auth-form" method="post" action="<?=POST_FORM_ACTION_URI?>" name="regform">
		<?php if (!empty($arResult['BACKURL'])): ?>
			<input type="hidden" name="backurl" value="<?=htmlspecialcharsbx($arResult['BACKURL'])?>" />
		<?php endif; ?>
		<input type="hidden" name="SIGNED_DATA" value="<?=htmlspecialcharsbx($arResult['SIGNED_DATA'] ?? '')?>" />
		<div class="form-group mf-auth-group">
			<label class="mf-auth-label" for="mfRegSms"><span class="mf-auth-req">*</span><?=GetMessage('main_register_sms')?></label>
			<input id="mfRegSms" class="form-control mf-auth-input" type="text" name="SMS_CODE" maxlength="255" value="<?=htmlspecialcharsbx($arResult['SMS_CODE'] ?? '')?>" autocomplete="off" />
		</div>
		<div class="mf-auth-actions">
			<button type="submit" class="btn btn-primary mf-auth-submit" name="code_submit_button"><?=GetMessage('main_register_sms_send')?></button>
		</div>
	</form>
	<script>
	new BX.PhoneAuth({
		containerId: 'bx_register_resend',
		errorContainerId: 'bx_register_error',
		interval: <?=(int)($arResult['PHONE_CODE_RESEND_INTERVAL'] ?? 60)?>,
		data: <?= Json::encode(['signedData' => $arResult['SIGNED_DATA'] ?? '']) ?>,
		onError: function(response) {
			var errorDiv = BX('bx_register_error');
			var errorNode = BX.findChildByClassName(errorDiv, 'errortext');
			errorNode.innerHTML = '';
			for (var i = 0; i < response.errors.length; i++) {
				errorNode.innerHTML = errorNode.innerHTML + BX.util.htmlspecialchars(response.errors[i].message) + '<br>';
			}
			errorDiv.style.display = '';
		}
	});
	</script>
	<div id="bx_register_error" style="display:none" class="alert alert-danger mf-auth-alert"></div>
	<div id="bx_register_resend"></div>
<?php else: ?>

<form class="mf-auth-form bx-auth-reg" method="post" action="<?=POST_FORM_ACTION_URI?>" name="regform" enctype="multipart/form-data" id="mfRegisterForm">
	<?php if (!empty($arResult['BACKURL'])): ?>
		<input type="hidden" name="backurl" value="<?=htmlspecialcharsbx($arResult['BACKURL'])?>" />
	<?php endif; ?>

	<div class="mf-reg-person-kind">
		<div class="mf-reg-person-kind__title"><?=GetMessage('MF_REG_PERSON_KIND')?></div>
		<div class="mf-reg-person-kind__cards">
			<label class="mf-reg-person-kind__item">
				<input type="radio" name="MF_PERSON_KIND" value="fiz"<?=$personKind === 'fiz' ? ' checked' : ''?> />
				<span class="mf-reg-person-kind__item-title"><?=GetMessage('MF_REG_PERSON_FIZ')?></span>
				<span class="mf-reg-person-kind__item-desc"><?=GetMessage('MF_REG_PERSON_FIZ_DESC')?></span>
			</label>
			<label class="mf-reg-person-kind__item">
				<input type="radio" name="MF_PERSON_KIND" value="jur"<?=$personKind === 'jur' ? ' checked' : ''?> />
				<span class="mf-reg-person-kind__item-title"><?=GetMessage('MF_REG_PERSON_JUR')?></span>
				<span class="mf-reg-person-kind__item-desc"><?=GetMessage('MF_REG_PERSON_JUR_DESC')?></span>
			</label>
		</div>
	</div>

	<div class="mf-reg-company-block" id="mfRegCompanyBlock"<?=$personKind === 'jur' ? '' : ' hidden'?>>
		<div class="mf-reg-company-block__title"><?=GetMessage('MF_REG_COMPANY_BLOCK')?></div>
		<div class="form-group mf-auth-group">
			<label class="mf-auth-label" for="mfRegCompany"><span class="mf-auth-req">*</span><?=GetMessage('MF_REG_COMPANY')?></label>
			<input id="mfRegCompany" class="form-control mf-auth-input" type="text" name="MF_COMPANY[COMPANY]" maxlength="255" value="<?=htmlspecialcharsbx($company['COMPANY'] ?? '')?>" />
		</div>
		<div class="form-group mf-auth-group">
			<label class="mf-auth-label" for="mfRegCompanyAdr"><span class="mf-auth-req">*</span><?=GetMessage('MF_REG_COMPANY_ADR')?></label>
			<input id="mfRegCompanyAdr" class="form-control mf-auth-input" type="text" name="MF_COMPANY[COMPANY_ADR]" maxlength="255" value="<?=htmlspecialcharsbx($company['COMPANY_ADR'] ?? '')?>" />
		</div>
		<div class="form-row">
			<div class="form-group col-md-6 mf-auth-group">
				<label class="mf-auth-label" for="mfRegInn"><span class="mf-auth-req">*</span><?=GetMessage('MF_REG_INN')?></label>
				<input id="mfRegInn" class="form-control mf-auth-input" type="text" name="MF_COMPANY[INN]" maxlength="12" inputmode="numeric" value="<?=htmlspecialcharsbx($company['INN'] ?? '')?>" />
			</div>
			<div class="form-group col-md-6 mf-auth-group">
				<label class="mf-auth-label" for="mfRegKpp"><?=GetMessage('MF_REG_KPP')?></label>
				<input id="mfRegKpp" class="form-control mf-auth-input" type="text" name="MF_COMPANY[KPP]" maxlength="9" inputmode="numeric" value="<?=htmlspecialcharsbx($company['KPP'] ?? '')?>" />
			</div>
		</div>
		<div class="form-group mf-auth-group">
			<label class="mf-auth-label" for="mfRegOgrn"><?=GetMessage('MF_REG_OGRN')?></label>
			<input id="mfRegOgrn" class="form-control mf-auth-input" type="text" name="MF_COMPANY[OGRN]" maxlength="15" inputmode="numeric" value="<?=htmlspecialcharsbx($company['OGRN'] ?? '')?>" />
		</div>
		<div class="form-group mf-auth-group">
			<label class="mf-auth-label" for="mfRegBank"><?=GetMessage('MF_REG_BANK_DETAILS')?></label>
			<textarea id="mfRegBank" class="form-control mf-auth-input" name="MF_COMPANY[BANK_DETAILS]" rows="3"><?=htmlspecialcharsbx($company['BANK_DETAILS'] ?? '')?></textarea>
		</div>
	</div>

	<?php foreach ($arResult['SHOW_FIELDS'] as $FIELD): ?>
		<?php if ($FIELD === 'AUTO_TIME_ZONE' && ($arResult['TIME_ZONE_ENABLED'] ?? false) === true): ?>
			<div class="form-group mf-auth-group">
				<label class="mf-auth-label"><?=GetMessage('main_profile_time_zones_auto')?><?=($arResult['REQUIRED_FIELDS_FLAGS'][$FIELD] ?? 'N') === 'Y' ? '<span class="mf-auth-req">*</span>' : ''?></label>
				<select class="form-control mf-auth-input" name="REGISTER[AUTO_TIME_ZONE]" onchange="this.form.elements['REGISTER[TIME_ZONE]'].disabled=(this.value != 'N')">
					<option value=""><?=GetMessage('main_profile_time_zones_auto_def')?></option>
					<option value="Y"<?=($arResult['VALUES'][$FIELD] ?? '') === 'Y' ? ' selected' : ''?>><?=GetMessage('main_profile_time_zones_auto_yes')?></option>
					<option value="N"<?=($arResult['VALUES'][$FIELD] ?? '') === 'N' ? ' selected' : ''?>><?=GetMessage('main_profile_time_zones_auto_no')?></option>
				</select>
			</div>
			<div class="form-group mf-auth-group">
				<label class="mf-auth-label"><?=GetMessage('main_profile_time_zones_zones')?></label>
				<select class="form-control mf-auth-input" name="REGISTER[TIME_ZONE]"<?=!isset($_REQUEST['REGISTER']['TIME_ZONE']) ? ' disabled' : ''?>>
					<?php foreach ($arResult['TIME_ZONE_LIST'] as $tz => $tzName): ?>
						<option value="<?=htmlspecialcharsbx($tz)?>"<?=($arResult['VALUES']['TIME_ZONE'] ?? '') === $tz ? ' selected' : ''?>><?=htmlspecialcharsbx($tzName)?></option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php else: ?>
			<div class="form-group mf-auth-group">
				<label class="mf-auth-label" for="mfRegField_<?=$FIELD?>">
					<?=GetMessage('REGISTER_FIELD_' . $FIELD)?>
					<?=($arResult['REQUIRED_FIELDS_FLAGS'][$FIELD] ?? 'N') === 'Y' ? '<span class="mf-auth-req">*</span>' : ''?>
				</label>
				<?php
				switch ($FIELD)
				{
					case 'PASSWORD':
					case 'CONFIRM_PASSWORD':
						?>
						<input id="mfRegField_<?=$FIELD?>" class="form-control mf-auth-input" type="password" name="REGISTER[<?=$FIELD?>]" value="<?=htmlspecialcharsbx($arResult['VALUES'][$FIELD] ?? '')?>" autocomplete="new-password" />
						<?php
						break;
					case 'PERSONAL_GENDER':
						?>
						<select id="mfRegField_<?=$FIELD?>" class="form-control mf-auth-input" name="REGISTER[<?=$FIELD?>]">
							<option value=""><?=GetMessage('USER_DONT_KNOW')?></option>
							<option value="M"<?=($arResult['VALUES'][$FIELD] ?? '') === 'M' ? ' selected' : ''?>><?=GetMessage('USER_MALE')?></option>
							<option value="F"<?=($arResult['VALUES'][$FIELD] ?? '') === 'F' ? ' selected' : ''?>><?=GetMessage('USER_FEMALE')?></option>
						</select>
						<?php
						break;
					default:
						$inputType = ($FIELD === 'EMAIL') ? 'email' : 'text';
						?>
						<input id="mfRegField_<?=$FIELD?>" class="form-control mf-auth-input" type="<?=$inputType?>" name="REGISTER[<?=$FIELD?>]" maxlength="255" value="<?=htmlspecialcharsbx($arResult['VALUES'][$FIELD] ?? '')?>" />
						<?php
						break;
				}
				?>
			</div>
		<?php endif; ?>
	<?php endforeach; ?>

	<?php if (($arResult['USER_PROPERTIES']['SHOW'] ?? 'N') === 'Y'): ?>
		<?php foreach ($arResult['USER_PROPERTIES']['DATA'] as $FIELD_NAME => $arUserField): ?>
			<div class="form-group mf-auth-group">
				<label class="mf-auth-label">
					<?=$arUserField['EDIT_FORM_LABEL']?>
					<?=($arUserField['MANDATORY'] ?? 'N') === 'Y' ? '<span class="mf-auth-req">*</span>' : ''?>
				</label>
				<?php
				$APPLICATION->IncludeComponent(
					'bitrix:system.field.edit',
					$arUserField['USER_TYPE']['USER_TYPE_ID'],
					[
						'bVarsFromForm' => $arResult['bVarsFromForm'],
						'arUserField' => $arUserField,
						'form_name' => 'regform',
					],
					null,
					['HIDE_ICONS' => 'Y']
				);
				?>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>

	<?php if (($arResult['USE_CAPTCHA'] ?? 'N') === 'Y'): ?>
		<div class="form-group mf-auth-group">
			<label class="mf-auth-label"><span class="mf-auth-req">*</span><?=GetMessage('REGISTER_CAPTCHA_PROMT')?></label>
			<div class="mf-auth-captcha">
				<input type="hidden" name="captcha_sid" value="<?=htmlspecialcharsbx($arResult['CAPTCHA_CODE'] ?? '')?>" />
				<img src="/bitrix/tools/captcha.php?captcha_sid=<?=htmlspecialcharsbx($arResult['CAPTCHA_CODE'] ?? '')?>" alt="" />
			</div>
			<input class="form-control mf-auth-input" type="text" name="captcha_word" maxlength="50" value="" autocomplete="off" />
		</div>
	<?php endif; ?>

	<div class="mf-auth-actions">
		<button type="submit" class="btn btn-primary mf-auth-submit" name="register_submit_button" value="Y"><?=GetMessage('MF_REG_SUBMIT')?></button>
	</div>

	<?php if (!empty($arResult['GROUP_POLICY']['PASSWORD_REQUIREMENTS'])): ?>
		<div class="mf-auth-hint"><?=$arResult['GROUP_POLICY']['PASSWORD_REQUIREMENTS']?></div>
	<?php endif; ?>

	<div class="mf-auth-links">
		<a class="mf-auth-link" href="<?=htmlspecialcharsbx($loginHref)?>" rel="nofollow"><b><?=GetMessage('MF_REG_LOGIN_LINK')?></b></a>
	</div>
</form>

<script>
(function() {
	var form = document.getElementById('mfRegisterForm');
	if (!form) {
		return;
	}
	var companyBlock = document.getElementById('mfRegCompanyBlock');
	var personInputs = form.querySelectorAll('input[name="MF_PERSON_KIND"]');
	var requiredCompanyFields = companyBlock ? companyBlock.querySelectorAll('[name^="MF_COMPANY["]') : [];

	function syncCompanyBlock() {
		if (!companyBlock) {
			return;
		}
		var isJur = false;
		for (var i = 0; i < personInputs.length; i++) {
			if (personInputs[i].checked && personInputs[i].value === 'jur') {
				isJur = true;
				break;
			}
		}
		companyBlock.hidden = !isJur;
		for (var j = 0; j < requiredCompanyFields.length; j++) {
			var field = requiredCompanyFields[j];
			var name = field.getAttribute('name') || '';
			var required = isJur && (name.indexOf('[COMPANY]') > -1 || name.indexOf('[COMPANY_ADR]') > -1 || name.indexOf('[INN]') > -1);
			field.required = required;
		}
	}

	for (var k = 0; k < personInputs.length; k++) {
		personInputs[k].addEventListener('change', syncCompanyBlock);
	}
	syncCompanyBlock();
})();
</script>

<?php endif; ?>
<?php endif; ?>
