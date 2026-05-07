<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';

/** @var CUser $USER */
/** @var CMain $APPLICATION */

$userName = '';
if (is_object($USER) && $USER->IsAuthorized())
{
	$userName = (string)$USER->GetFullName();
	if ($userName === '')
	{
		$userName = (string)$USER->GetLogin();
	}
}

$backurl = '';
if (isset($_REQUEST['backurl']) && is_string($_REQUEST['backurl']) && preg_match('#^/\w#', $_REQUEST['backurl']))
{
	$backurl = $_REQUEST['backurl'];
}

if (is_object($USER) && $USER->IsAuthorized())
{
	?>
	<script>
	<?php if ($userName !== ''): ?>
	BX.localStorage.set("eshop_user_name", "<?= CUtil::JSEscape($userName) ?>", 604800);
	<?php else: ?>
	BX.localStorage.remove("eshop_user_name");
	<?php endif; ?>
	</script>
	<?php
	if ($backurl !== '')
	{
		LocalRedirect($backurl);
	}

	$APPLICATION->SetTitle('Вы авторизованы');
	?>
	<div class="mf-auth container">
		<p class="notetext">Вы успешно вошли на сайт.</p>
		<p><a href="/personal/">Личный кабинет</a> · <a href="/">На главную</a></p>
	</div>
	<?php
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';

	return;
}
?>
<script>BX.localStorage.remove("eshop_user_name");</script>
<?php
$APPLICATION->SetTitle('Вход на сайт');

$isRegister = (isset($_GET['register']) && (string)$_GET['register'] === 'yes');
$isForgot = (isset($_GET['forgot_password']) && (string)$_GET['forgot_password'] === 'yes');

?>
<div class="mf-auth container">
<?php
if ($isRegister && COption::GetOptionString('main', 'new_user_registration', 'N') === 'Y')
{
	?>
	<div class="mf-auth-card mf-auth-card--register">
	<?php
	$APPLICATION->IncludeComponent(
		'bitrix:main.register',
		'',
		[
			'USE_BACKURL' => 'Y',
		],
		false
	);
	?>
	</div>
	<?php
}
elseif ($isRegister)
{
	$loginHref = '/login/?login=yes' . ($backurl !== '' ? ('&backurl=' . rawurlencode($backurl)) : '');
	?>
	<div class="mf-auth-card">
	<p>Регистрация недоступна.</p>
	<p><a href="<?= htmlspecialcharsbx($loginHref) ?>">Перейти ко входу</a></p>
	</div>
	<?php
}
elseif ($isForgot)
{
	?>
	<div class="mf-auth-card mf-auth-card--forgot">
	<?php
	$APPLICATION->IncludeComponent(
		'bitrix:system.auth.forgotpasswd',
		'',
		[],
		false
	);
	?>
	</div>
	<?php
}
else
{
	$APPLICATION->IncludeComponent(
		'bitrix:system.auth.form',
		'mf_login',
		[
			'REGISTER_URL' => '/login/',
			'FORGOT_PASSWORD_URL' => '/login/',
			'PROFILE_URL' => '/personal/',
			'SHOW_ERRORS' => 'Y',
		],
		false
	);
}
?>
</div>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
