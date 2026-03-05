<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * @global CMain $APPLICATION
 * @var array $arParams
 * @var array $arResult
 */

$authResultMessage = (string)($arParams["~AUTH_RESULT"]["MESSAGE"] ?? '');
$errorMessage = (string)($arResult["ERROR_MESSAGE"] ?? '');
?>

<div class="mf-auth">
	<div class="mf-auth-card">
		<div class="mf-auth-head">
			<div class="mf-auth-title">Вход</div>
			<div class="mf-auth-subtitle">Авторизуйтесь, чтобы перейти в личный кабинет и оформить заказ.</div>
			<ul class="mf-auth-points" aria-label="Преимущества личного кабинета">
				<li>История и статус заказов</li>
				<li>Быстрое оформление покупок</li>
				<li>Доступ к личным данным и профилям</li>
			</ul>
		</div>

		<?php if ($authResultMessage !== ''): ?>
			<?php $text = str_replace(["<br>", "<br />"], "\n", $authResultMessage); ?>
			<div class="alert alert-danger mf-auth-alert"><?=nl2br(htmlspecialcharsbx($text))?></div>
		<?php endif; ?>

		<?php if ($errorMessage !== ''): ?>
			<?php $text = str_replace(["<br>", "<br />"], "\n", $errorMessage); ?>
			<div class="alert alert-danger mf-auth-alert"><?=nl2br(htmlspecialcharsbx($text))?></div>
		<?php endif; ?>

		<form class="mf-auth-form" name="form_auth" method="post" target="_top" action="<?=$arResult["AUTH_URL"]?>">
			<input type="hidden" name="AUTH_FORM" value="Y" />
			<input type="hidden" name="TYPE" value="AUTH" />
			<?php if (($arResult["BACKURL"] ?? '') !== ''): ?>
				<input type="hidden" name="backurl" value="<?=$arResult["BACKURL"]?>" />
			<?php endif; ?>
			<?php foreach (($arResult["POST"] ?? []) as $key => $value): ?>
				<input type="hidden" name="<?=$key?>" value="<?=$value?>" />
			<?php endforeach; ?>

			<div class="form-group mf-auth-group">
				<label class="mf-auth-label" for="mfAuthLogin">Логин</label>
				<input
					id="mfAuthLogin"
					class="form-control mf-auth-input"
					type="text"
					name="USER_LOGIN"
					maxlength="255"
					value="<?=$arResult["LAST_LOGIN"]?>"
					autocomplete="username"
					inputmode="text"
				/>
			</div>

			<div class="form-group mf-auth-group">
				<label class="mf-auth-label" for="mfAuthPassword">Пароль</label>
				<div class="mf-auth-password">
					<input
						id="mfAuthPassword"
						class="form-control mf-auth-input"
						type="password"
						name="USER_PASSWORD"
						maxlength="255"
						autocomplete="current-password"
					/>
				</div>
				<?php if (!empty($arResult["SECURE_AUTH"])): ?>
					<div class="mf-auth-secure">Пароль будет передан в зашифрованном виде.</div>
				<?php endif; ?>
			</div>

			<?php if (!empty($arResult["CAPTCHA_CODE"])): ?>
				<input type="hidden" name="captcha_sid" value="<?=$arResult["CAPTCHA_CODE"]?>" />
				<div class="form-group mf-auth-group">
					<label class="mf-auth-label"><?=GetMessage("AUTH_CAPTCHA_PROMT")?></label>
					<div class="mf-auth-captcha">
						<img
							src="/bitrix/tools/captcha.php?captcha_sid=<?=$arResult["CAPTCHA_CODE"]?>"
							width="180"
							height="40"
							alt="CAPTCHA"
							loading="lazy"
						/>
					</div>
					<input class="form-control mf-auth-input" type="text" name="captcha_word" maxlength="50" value="" autocomplete="off" />
				</div>
			<?php endif; ?>

			<?php if (($arResult["STORE_PASSWORD"] ?? "N") === "Y"): ?>
				<div class="form-group mf-auth-group">
					<div class="custom-control custom-checkbox">
						<input class="custom-control-input" type="checkbox" id="mfAuthRemember" name="USER_REMEMBER" value="Y" />
						<label class="custom-control-label" for="mfAuthRemember">Запомнить меня</label>
					</div>
				</div>
			<?php endif; ?>

			<div class="mf-auth-actions">
				<button type="submit" class="btn btn-primary mf-auth-submit" name="Login">Войти</button>
			</div>

			<?php if (($arParams["NOT_SHOW_LINKS"] ?? "N") !== "Y"): ?>
				<div class="mf-auth-links">
					<noindex>
						<a class="mf-auth-link" href="<?=$arResult["AUTH_FORGOT_PASSWORD_URL"]?>" rel="nofollow">Забыли свой пароль?</a>
					</noindex>

					<?php if (($arResult["NEW_USER_REGISTRATION"] ?? "N") === "Y"): ?>
						<noindex>
							<a class="mf-auth-link" href="<?=$arResult["AUTH_REGISTER_URL"]?>" rel="nofollow">Зарегистрироваться</a>
						</noindex>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</form>
	</div>
</div>

<script>
try{
	<?php if (($arResult["LAST_LOGIN"] ?? '') !== ''): ?>
	document.form_auth.USER_PASSWORD.focus();
	<?php else: ?>
	document.form_auth.USER_LOGIN.focus();
	<?php endif; ?>
}catch(e){}
</script>

