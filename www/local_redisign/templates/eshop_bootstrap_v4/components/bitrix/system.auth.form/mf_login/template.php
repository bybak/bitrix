<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

CJSCore::Init();

/**
 * @var array $arResult
 * @var array $arParams
 * @var CMain $APPLICATION
 */

$rnd = $arResult["RND"];
$formType = $arResult["FORM_TYPE"] ?? '';
?>
<div class="mf-auth">
	<div class="mf-auth-card mf-auth-card--system-form">
		<div class="mf-auth-head">
			<div class="mf-auth-eyebrow">Личный кабинет Motor-Force</div>
			<div class="mf-auth-title">Вход в личный кабинет</div>
			<div class="mf-auth-subtitle">Авторизуйтесь, чтобы оформлять заказы быстрее, отслеживать историю покупок и получать персональные цены.</div>
			<ul class="mf-auth-points" aria-label="Преимущества личного кабинета">
				<li>История и статусы всех заказов</li>
				<li>Быстрое оформление новых покупок</li>
				<li>Адресная книга и сохранённые карты</li>
			</ul>
		</div>

		<?php
		if ($arResult['SHOW_ERRORS'] === 'Y' && $arResult['ERROR'] && !empty($arResult['ERROR_MESSAGE']))
		{
			echo '<div class="alert alert-danger mf-auth-alert">';
			ShowMessage($arResult['ERROR_MESSAGE']);
			echo '</div>';
		}
		?>

		<?php if ($formType === "login"): ?>
			<form class="mf-auth-form" name="system_auth_form<?=$rnd?>" method="post" target="_top" action="<?=$arResult["AUTH_URL"]?>">
				<?php if (!empty($arResult["BACKURL"])): ?>
					<input type="hidden" name="backurl" value="<?=$arResult["BACKURL"]?>" />
				<?php endif; ?>
				<?php foreach (($arResult["POST"] ?? []) as $key => $value): ?>
					<input type="hidden" name="<?=$key?>" value="<?=$value?>" />
				<?php endforeach; ?>
				<input type="hidden" name="AUTH_FORM" value="Y" />
				<input type="hidden" name="TYPE" value="AUTH" />
				<?= bitrix_sessid_post(); ?>

				<div class="mf-auth-group">
					<label class="mf-auth-label" for="mfAuthLogin">Логин или E-mail</label>
					<input id="mfAuthLogin" class="mf-auth-input" type="text" name="USER_LOGIN" maxlength="50" autocomplete="username" placeholder="Введите логин или e-mail" />
					<script>
						BX.ready(function () {
							var loginCookie = BX.getCookie("<?=CUtil::JSEscape($arResult["~LOGIN_COOKIE_NAME"])?>");
							if (loginCookie) {
								var form = document.forms["system_auth_form<?=$rnd?>"];
								var loginInput = form.elements["USER_LOGIN"];
								if (loginInput) loginInput.value = loginCookie;
							}
						});
					</script>
				</div>

				<div class="mf-auth-group">
					<label class="mf-auth-label" for="mfAuthPwd">Пароль</label>
					<div class="mf-auth-password">
						<input id="mfAuthPwd" class="mf-auth-input" type="password" name="USER_PASSWORD" maxlength="255" autocomplete="current-password" placeholder="Введите пароль" />
					</div>
					<?php if ($arResult["SECURE_AUTH"]): ?>
						<div class="mf-auth-secure" id="bx_auth_secure<?=$rnd?>" style="display:none">
							Пароль будет передан в зашифрованном виде.
						</div>
						<script>document.getElementById('bx_auth_secure<?=$rnd?>').style.display = 'block';</script>
					<?php endif; ?>
				</div>

				<?php if (!empty($arResult["CAPTCHA_CODE"])): ?>
					<div class="mf-auth-group">
						<label class="mf-auth-label">Введите символы с картинки</label>
						<input type="hidden" name="captcha_sid" value="<?=$arResult["CAPTCHA_CODE"]?>" />
						<div class="mf-auth-captcha">
							<img src="/bitrix/tools/captcha.php?captcha_sid=<?=$arResult["CAPTCHA_CODE"]?>" width="180" height="40" alt="CAPTCHA" loading="lazy" />
						</div>
						<input class="mf-auth-input" type="text" name="captcha_word" maxlength="50" value="" autocomplete="off" />
					</div>
				<?php endif; ?>

				<?php if ($arResult["STORE_PASSWORD"] === "Y"): ?>
					<div class="mf-auth-group">
						<label class="mf-auth-check" for="USER_REMEMBER_frm">
							<input id="USER_REMEMBER_frm" type="checkbox" name="USER_REMEMBER" value="Y" />
							<span>Запомнить меня на этом устройстве</span>
						</label>
					</div>
				<?php endif; ?>

				<div class="mf-auth-actions">
					<button type="submit" class="mf-auth-submit" name="Login">Войти</button>
				</div>

				<div class="mf-auth-links">
					<?php if ($arResult["NEW_USER_REGISTRATION"] === "Y"): ?>
						<noindex>
							<a class="mf-auth-link" href="<?=$arResult["AUTH_REGISTER_URL"]?>" rel="nofollow">Зарегистрироваться</a>
						</noindex>
					<?php endif; ?>
					<noindex>
						<a class="mf-auth-link" href="<?=$arResult["AUTH_FORGOT_PASSWORD_URL"]?>" rel="nofollow">Забыли пароль?</a>
					</noindex>
				</div>

				<?php if (!empty($arResult["AUTH_SERVICES"])): ?>
					<div class="mf-auth-socserv">
						<div class="mf-auth-socserv__lbl">Войти через социальные сети:</div>
						<?php
						$APPLICATION->IncludeComponent(
							"bitrix:socserv.auth.form", "icons",
							array(
								"AUTH_SERVICES" => $arResult["AUTH_SERVICES"],
								"SUFFIX" => "form",
							),
							$component,
							array("HIDE_ICONS" => "Y")
						);
						?>
					</div>
				<?php endif; ?>
			</form>

		<?php elseif ($formType === "otp"): ?>
			<form class="mf-auth-form" name="system_auth_form<?=$rnd?>" method="post" target="_top" action="<?=$arResult["AUTH_URL"]?>">
				<?php if (!empty($arResult["BACKURL"])): ?>
					<input type="hidden" name="backurl" value="<?=$arResult["BACKURL"]?>" />
				<?php endif; ?>
				<input type="hidden" name="AUTH_FORM" value="Y" />
				<input type="hidden" name="TYPE" value="OTP" />
				<?= bitrix_sessid_post(); ?>
				<div class="mf-auth-group">
					<label class="mf-auth-label" for="mfAuthOtp">Одноразовый пароль</label>
					<input id="mfAuthOtp" class="mf-auth-input" type="text" name="USER_OTP" maxlength="50" autocomplete="off" />
				</div>
				<?php if (!empty($arResult["CAPTCHA_CODE"])): ?>
					<div class="mf-auth-group">
						<label class="mf-auth-label">Введите символы с картинки</label>
						<input type="hidden" name="captcha_sid" value="<?=$arResult["CAPTCHA_CODE"]?>" />
						<div class="mf-auth-captcha">
							<img src="/bitrix/tools/captcha.php?captcha_sid=<?=$arResult["CAPTCHA_CODE"]?>" width="180" height="40" alt="CAPTCHA" loading="lazy" />
						</div>
						<input class="mf-auth-input" type="text" name="captcha_word" maxlength="50" value="" autocomplete="off" />
					</div>
				<?php endif; ?>
				<?php if ($arResult["REMEMBER_OTP"] === "Y"): ?>
					<div class="mf-auth-group">
						<label class="mf-auth-check" for="OTP_REMEMBER_frm">
							<input id="OTP_REMEMBER_frm" type="checkbox" name="OTP_REMEMBER" value="Y" />
							<span>Запомнить это устройство</span>
						</label>
					</div>
				<?php endif; ?>
				<div class="mf-auth-actions">
					<button type="submit" class="mf-auth-submit" name="Login">Подтвердить</button>
				</div>
				<div class="mf-auth-links">
					<noindex><a class="mf-auth-link" href="<?=$arResult["AUTH_LOGIN_URL"]?>" rel="nofollow">Назад к авторизации</a></noindex>
				</div>
			</form>

		<?php else: /* logout state */ ?>
			<div class="mf-auth-logged">
				<div class="mf-auth-logged__name"><?=$arResult["USER_NAME"]?></div>
				<div class="mf-auth-logged__login">[<?=$arResult["USER_LOGIN"]?>]</div>
				<a class="mf-auth-link" href="<?=$arResult["PROFILE_URL"]?>">Личный кабинет</a>
			</div>
			<form class="mf-auth-form" action="<?=$arResult["AUTH_URL"]?>" method="post">
				<?php foreach (($arResult["GET"] ?? []) as $key => $value): ?>
					<input type="hidden" name="<?=$key?>" value="<?=$value?>" />
				<?php endforeach; ?>
				<?= bitrix_sessid_post(); ?>
				<input type="hidden" name="logout" value="yes" />
				<div class="mf-auth-actions">
					<button type="submit" name="logout_butt" class="mf-auth-submit mf-auth-submit--ghost">Выйти из аккаунта</button>
				</div>
			</form>
		<?php endif; ?>
	</div>
</div>
