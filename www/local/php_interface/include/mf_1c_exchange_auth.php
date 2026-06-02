<?php
/**
 * Обмен с 1С: HTTP Basic Auth (логин/пароль из настроек 1С).
 * На nginx + php-fpm заголовок Authorization часто не попадает в PHP_AUTH_* — без этого checkauth даёт failure.
 */

if (!function_exists('mf_1c_exchange_restore_basic_auth_server_vars'))
{
	function mf_1c_exchange_restore_basic_auth_server_vars(): void
	{
		if (!empty($_SERVER['PHP_AUTH_USER']))
		{
			return;
		}

		$authHeader = '';
		if (!empty($_SERVER['HTTP_AUTHORIZATION']))
		{
			$authHeader = (string)$_SERVER['HTTP_AUTHORIZATION'];
		}
		elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']))
		{
			$authHeader = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}
		elseif (!empty($_SERVER['REMOTE_USER']) && preg_match('/^\s*Basic\s+/i', (string)$_SERVER['REMOTE_USER']))
		{
			$authHeader = (string)$_SERVER['REMOTE_USER'];
		}
		elseif (!empty($_SERVER['REDIRECT_REMOTE_USER']) && preg_match('/^\s*Basic\s+/i', (string)$_SERVER['REDIRECT_REMOTE_USER']))
		{
			$authHeader = (string)$_SERVER['REDIRECT_REMOTE_USER'];
		}

		if ($authHeader === '' || !preg_match('/^\s*Basic\s+([A-Za-z0-9+\/=]+)\s*$/i', $authHeader, $matches))
		{
			return;
		}

		$decoded = base64_decode($matches[1], true);
		if ($decoded === false || !str_contains($decoded, ':'))
		{
			return;
		}

		[$user, $password] = explode(':', $decoded, 2);
		$_SERVER['PHP_AUTH_USER'] = $user;
		$_SERVER['PHP_AUTH_PW'] = $password;
	}
}

if (!function_exists('mf_1c_exchange_ensure_user_authorized'))
{
	function mf_1c_exchange_ensure_user_authorized(): void
	{
		global $USER;

		if (!is_object($USER) || !method_exists($USER, 'IsAuthorized') || $USER->IsAuthorized())
		{
			return;
		}

		if (!class_exists(\Bitrix\Main\Context::class))
		{
			return;
		}

		$auth = \Bitrix\Main\Context::getCurrent()->getServer()->parseAuthRequest();
		if (!isset($auth['basic']['username'], $auth['basic']['password']))
		{
			return;
		}

		$login = trim((string)$auth['basic']['username']);
		$password = (string)$auth['basic']['password'];
		if ($login === '')
		{
			return;
		}

		$USER->Login($login, $password, 'N', 'N');
	}
}
