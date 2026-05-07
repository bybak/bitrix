<?php
use Bitrix\Main\Web\Json;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * @global CMain $APPLICATION
 * @var array $arParams
 * @var array $arResult
 * @var CBitrixComponentTemplate $this
 */

if (($arResult["SHOW_SMS_FIELD"] ?? false) === true)
{
	CJSCore::Init('phone_auth');
}

$authResultMessage = (string)($arParams["~AUTH_RESULT"]["MESSAGE"] ?? '');
?>

<div class="mf-auth">
	<div class="mf-auth-card">
		<div class="mf-auth-head">
			<div class="mf-auth-title">Регистрация</div>
			<div class="mf-auth-subtitle">Создайте аккаунт, чтобы отслеживать заказы и быстрее оформлять покупки.</div>
			<ul class="mf-auth-points" aria-label="Зачем нужна регистрация">
				<li>Сохранение данных для повторных заказов</li>
				<li>Отслеживание статуса и истории покупок</li>
				<li>Восстановление доступа по e-mail</li>
			</ul>
		</div>

		<?php if ($authResultMessage !== ''): ?>
			<?php $text = str_replace(["<br>", "<br />"], "\n", $authResultMessage); ?>
			<div class="alert <?=($arParams["~AUTH_RESULT"]["TYPE"] ?? "ERROR") === "OK" ? "alert-success" : "alert-danger"?> mf-auth-alert">
				<?=nl2br(htmlspecialcharsbx($text))?>
			</div>
		<?php endif; ?>

		<?php if (!empty($arResult["SHOW_EMAIL_SENT_CONFIRMATION"])): ?>
			<div class="alert alert-success mf-auth-alert">Письмо с подтверждением отправлено на ваш e-mail.</div>
		<?php endif; ?>

		<?php if (empty($arResult["SHOW_EMAIL_SENT_CONFIRMATION"]) && ($arResult["USE_EMAIL_CONFIRMATION"] ?? "N") === "Y"): ?>
			<div class="alert alert-warning mf-auth-alert">На указанный e-mail будет отправлено письмо с подтверждением регистрации.</div>
		<?php endif; ?>

		<?php if (($arResult["SHOW_SMS_FIELD"] ?? false) === true): ?>
			<form class="mf-auth-form" method="post" action="<?=$arResult["AUTH_URL"]?>" name="regform">
				<input type="hidden" name="SIGNED_DATA" value="<?=htmlspecialcharsbx($arResult["SIGNED_DATA"])?>" />

				<div class="form-group mf-auth-group">
					<label class="mf-auth-label" for="mfRegSms"><span class="mf-auth-req">*</span>Код из SMS</label>
					<input id="mfRegSms" class="form-control mf-auth-input" type="text" name="SMS_CODE" maxlength="255" value="<?=htmlspecialcharsbx($arResult["SMS_CODE"] ?? '')?>" autocomplete="off" />
				</div>

				<div class="mf-auth-actions">
					<button type="submit" class="btn btn-primary mf-auth-submit" name="code_submit_button">Подтвердить</button>
				</div>
			</form>

			<script>
			new BX.PhoneAuth({
				containerId: 'bx_register_resend',
				errorContainerId: 'bx_register_error',
				interval: <?=$arResult["PHONE_CODE_RESEND_INTERVAL"]?>,
				data: <?= Json::encode(['signedData' => $arResult["SIGNED_DATA"]]) ?>,
				onError: function(response)
				{
					var errorNode = BX('bx_register_error');
					errorNode.innerHTML = '';
					for (var i = 0; i < response.errors.length; i++)
					{
						errorNode.innerHTML = errorNode.innerHTML + BX.util.htmlspecialchars(response.errors[i].message) + '<br />';
					}
					errorNode.style.display = '';
				}
			});
			</script>

			<div id="bx_register_error" style="display:none" class="alert alert-danger mf-auth-alert"></div>
			<div id="bx_register_resend"></div>

		<?php elseif (empty($arResult["SHOW_EMAIL_SENT_CONFIRMATION"])): ?>
			<noindex>
			<form class="mf-auth-form" method="post" action="<?=$arResult["AUTH_URL"]?>" name="bform" enctype="multipart/form-data">
				<input type="hidden" name="AUTH_FORM" value="Y" />
				<input type="hidden" name="TYPE" value="REGISTRATION" />

				<div class="form-row">
					<div class="form-group col-md-6 mf-auth-group">
						<label class="mf-auth-label" for="mfRegName">Имя</label>
						<input id="mfRegName" class="form-control mf-auth-input" type="text" name="USER_NAME" maxlength="255" value="<?=$arResult["USER_NAME"]?>" autocomplete="given-name" />
					</div>
					<div class="form-group col-md-6 mf-auth-group">
						<label class="mf-auth-label" for="mfRegLast">Фамилия</label>
						<input id="mfRegLast" class="form-control mf-auth-input" type="text" name="USER_LAST_NAME" maxlength="255" value="<?=$arResult["USER_LAST_NAME"]?>" autocomplete="family-name" />
					</div>
				</div>

				<div class="form-group mf-auth-group">
					<label class="mf-auth-label" for="mfRegLogin"><span class="mf-auth-req">*</span>Логин (минимум 3 символа)</label>
					<input id="mfRegLogin" class="form-control mf-auth-input" type="text" name="USER_LOGIN" maxlength="255" value="<?=$arResult["USER_LOGIN"]?>" autocomplete="username" />
				</div>

				<div class="form-row">
					<div class="form-group col-md-6 mf-auth-group">
						<label class="mf-auth-label" for="mfRegPass"><span class="mf-auth-req">*</span>Пароль</label>
						<input id="mfRegPass" class="form-control mf-auth-input" type="password" name="USER_PASSWORD" maxlength="255" value="<?=$arResult["USER_PASSWORD"]?>" autocomplete="new-password" />
					</div>
					<div class="form-group col-md-6 mf-auth-group">
						<label class="mf-auth-label" for="mfRegPass2"><span class="mf-auth-req">*</span>Повторите пароль</label>
						<input id="mfRegPass2" class="form-control mf-auth-input" type="password" name="USER_CONFIRM_PASSWORD" maxlength="255" value="<?=$arResult["USER_CONFIRM_PASSWORD"]?>" autocomplete="new-password" />
					</div>
				</div>

				<?php if (!empty($arResult["EMAIL_REGISTRATION"])): ?>
					<div class="form-group mf-auth-group">
						<label class="mf-auth-label" for="mfRegEmail"><?php if (!empty($arResult["EMAIL_REQUIRED"])): ?><span class="mf-auth-req">*</span><?php endif; ?>E-mail</label>
						<input id="mfRegEmail" class="form-control mf-auth-input" type="email" name="USER_EMAIL" maxlength="255" value="<?=$arResult["USER_EMAIL"]?>" autocomplete="email" />
					</div>
				<?php endif; ?>

				<?php if (!empty($arResult["PHONE_REGISTRATION"])): ?>
					<div class="form-group mf-auth-group">
						<label class="mf-auth-label" for="mfRegPhone"><?php if (!empty($arResult["PHONE_REQUIRED"])): ?><span class="mf-auth-req">*</span><?php endif; ?>Телефон</label>
						<input id="mfRegPhone" class="form-control mf-auth-input" type="tel" name="USER_PHONE_NUMBER" maxlength="255" value="<?=$arResult["USER_PHONE_NUMBER"]?>" autocomplete="tel" />
					</div>
				<?php endif; ?>

				<?php if (($arResult["USER_PROPERTIES"]["SHOW"] ?? "N") === "Y"): ?>
					<?php foreach (($arResult["USER_PROPERTIES"]["DATA"] ?? []) as $FIELD_NAME => $arUserField): ?>
						<div class="form-group mf-auth-group">
							<label class="mf-auth-label"><?php if (($arUserField["MANDATORY"] ?? "N") === "Y"): ?><span class="mf-auth-req">*</span><?php endif; ?><?=$arUserField["EDIT_FORM_LABEL"]?></label>
							<?php
							$APPLICATION->IncludeComponent(
								"bitrix:system.field.edit",
								$arUserField["USER_TYPE"]["USER_TYPE_ID"],
								array(
									"bVarsFromForm" => $arResult["bVarsFromForm"],
									"arUserField" => $arUserField,
									"form_name" => "bform"
								),
								null,
								array("HIDE_ICONS" => "Y")
							);
							?>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>

				<?php if (($arResult["USE_CAPTCHA"] ?? "N") === "Y"): ?>
					<input type="hidden" name="captcha_sid" value="<?=$arResult["CAPTCHA_CODE"]?>" />
					<div class="form-group mf-auth-group">
						<label class="mf-auth-label"><span class="mf-auth-req">*</span>Введите слово на картинке</label>
						<div class="mf-auth-captcha">
							<img src="/bitrix/tools/captcha.php?captcha_sid=<?=$arResult["CAPTCHA_CODE"]?>" width="180" height="40" alt="CAPTCHA" loading="lazy" />
						</div>
						<input class="form-control mf-auth-input" type="text" name="captcha_word" maxlength="50" value="" autocomplete="off" />
					</div>
				<?php endif; ?>

				<div class="form-group mf-auth-group">
					<?php
					$APPLICATION->IncludeComponent("bitrix:main.userconsent.request", "",
						array(
							"ID" => COption::getOptionString("main", "new_user_agreement", ""),
							"IS_CHECKED" => "Y",
							"AUTO_SAVE" => "N",
							"IS_LOADED" => "Y",
							"ORIGINATOR_ID" => $arResult["AGREEMENT_ORIGINATOR_ID"],
							"ORIGIN_ID" => $arResult["AGREEMENT_ORIGIN_ID"],
							"INPUT_NAME" => $arResult["AGREEMENT_INPUT_NAME"],
							"REPLACE" => array(
								"button_caption" => "Зарегистрироваться",
								"fields" => array(
									"Имя",
									"Фамилия",
									"Логин",
									"Пароль",
									"E-mail",
								)
							),
						)
					);
					?>
				</div>

				<div class="mf-auth-actions">
					<button type="submit" class="btn btn-primary mf-auth-submit" name="Register">Зарегистрироваться</button>
				</div>

				<?php if (!empty($arResult["GROUP_POLICY"]["PASSWORD_REQUIREMENTS"])): ?>
					<div class="mf-auth-hint"><?=$arResult["GROUP_POLICY"]["PASSWORD_REQUIREMENTS"];?></div>
				<?php endif; ?>

				<div class="mf-auth-links">
					<a class="mf-auth-link" href="<?=$arResult["AUTH_AUTH_URL"]?>" rel="nofollow"><b>Войти</b></a>
				</div>
			</form>
			</noindex>

			<script>
			try{document.bform.USER_NAME.focus();}catch(e){}
			</script>
		<?php endif; ?>
	</div>
</div>

