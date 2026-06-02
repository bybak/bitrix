<?php
/**
 * Обмен с 1С: HTTP Basic Auth и явный Login() до checkauth.
 */

if (!function_exists('mf_1c_exchange_get_authorization_header'))
{
	function mf_1c_exchange_get_authorization_header(): string
	{
		if (!empty($_SERVER['HTTP_AUTHORIZATION']))
		{
			return (string)$_SERVER['HTTP_AUTHORIZATION'];
		}
		if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']))
		{
			return (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}

		if (function_exists('getallheaders'))
		{
			$headers = getallheaders();
			foreach ($headers as $name => $value)
			{
				if (strcasecmp((string)$name, 'Authorization') === 0)
				{
					return (string)$value;
				}
			}
		}

		if (!empty($_SERVER['REMOTE_USER']) && preg_match('/^\s*Basic\s+/i', (string)$_SERVER['REMOTE_USER']))
		{
			return (string)$_SERVER['REMOTE_USER'];
		}
		if (!empty($_SERVER['REDIRECT_REMOTE_USER']) && preg_match('/^\s*Basic\s+/i', (string)$_SERVER['REDIRECT_REMOTE_USER']))
		{
			return (string)$_SERVER['REDIRECT_REMOTE_USER'];
		}

		return '';
	}
}

if (!function_exists('mf_1c_exchange_restore_basic_auth_server_vars'))
{
	function mf_1c_exchange_restore_basic_auth_server_vars(): void
	{
		if (!empty($_SERVER['PHP_AUTH_USER']))
		{
			return;
		}

		$authHeader = mf_1c_exchange_get_authorization_header();
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

if (!function_exists('mf_1c_exchange_find_user_by_login_or_email'))
{
	function mf_1c_exchange_find_user_by_login_or_email(string $login): ?array
	{
		$login = trim($login);
		if ($login === '')
		{
			return null;
		}

		$row = CUser::GetByLogin($login)->Fetch();
		if (is_array($row))
		{
			return $row;
		}

		$byEmail = CUser::GetList('ID', 'ASC', ['=EMAIL' => $login, 'ACTIVE' => 'Y']);
		$row = $byEmail->Fetch();

		return is_array($row) ? $row : null;
	}
}

if (!function_exists('mf_1c_exchange_store_login_error'))
{
	function mf_1c_exchange_store_login_error($result): void
	{
		$message = '';
		if (is_array($result) && !empty($result['MESSAGE']))
		{
			$message = trim(strip_tags((string)$result['MESSAGE']));
		}
		elseif (is_string($result) && $result !== '')
		{
			$message = trim(strip_tags($result));
		}

		if ($message !== '')
		{
			$GLOBALS['MF_1C_EXCHANGE_LOGIN_ERROR'] = $message;
		}
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

		$login = '';
		$password = '';

		if (class_exists(\Bitrix\Main\Context::class))
		{
			$auth = \Bitrix\Main\Context::getCurrent()->getServer()->parseAuthRequest();
			if (isset($auth['basic']['username'], $auth['basic']['password']))
			{
				$login = trim((string)$auth['basic']['username']);
				$password = (string)$auth['basic']['password'];
			}
		}

		if ($login === '' && !empty($_SERVER['PHP_AUTH_USER']))
		{
			$login = trim((string)$_SERVER['PHP_AUTH_USER']);
			$password = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
		}

		if ($login === '')
		{
			$GLOBALS['MF_1C_EXCHANGE_LOGIN_ERROR'] = 'Заголовок Authorization не передан в PHP (настройка nginx fastcgi_param HTTP_AUTHORIZATION).';
			return;
		}

		$userRow = mf_1c_exchange_find_user_by_login_or_email($login);
		if (!$userRow)
		{
			$GLOBALS['MF_1C_EXCHANGE_LOGIN_ERROR'] = 'Пользователь «' . $login . '» не найден (проверьте логин, не email).';
			return;
		}

		if (($userRow['ACTIVE'] ?? 'Y') !== 'Y')
		{
			$GLOBALS['MF_1C_EXCHANGE_LOGIN_ERROR'] = 'Пользователь «' . $userRow['LOGIN'] . '» неактивен.';
			return;
		}

		if (!empty($userRow['EXTERNAL_AUTH_ID']))
		{
			$GLOBALS['MF_1C_EXCHANGE_LOGIN_ERROR'] = 'У пользователя внешняя авторизация (' . $userRow['EXTERNAL_AUTH_ID'] . '). Для 1С нужен локальный пароль Bitrix или пароль приложения.';
		}

		$authLogin = (string)$userRow['LOGIN'];
		$result = $USER->Login($authLogin, $password, 'N', 'Y');
		if ($result === true)
		{
			return;
		}

		mf_1c_exchange_store_login_error($result);

		if (class_exists(\Bitrix\Main\Authentication\ApplicationPasswordTable::class))
		{
			$appPassword = \Bitrix\Main\Authentication\ApplicationPasswordTable::findPassword(
				(int)$userRow['ID'],
				$password,
				true
			);
			if ($appPassword !== false)
			{
				$USER->Authorize((int)$userRow['ID'], false, true);
				unset($GLOBALS['MF_1C_EXCHANGE_LOGIN_ERROR']);
			}
		}
	}
}

if (!function_exists('mf_1c_exchange_append_login_error'))
{
	function mf_1c_exchange_append_login_error(string $body): string
	{
		if (empty($GLOBALS['MF_1C_EXCHANGE_LOGIN_ERROR']))
		{
			return $body;
		}

		return $body . "\n" . $GLOBALS['MF_1C_EXCHANGE_LOGIN_ERROR'];
	}
}
