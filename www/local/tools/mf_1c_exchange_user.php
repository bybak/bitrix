<?php
/**
 * Диагностика пользователя обмена с 1С (только CLI на сервере).
 *
 *   php local/tools/mf_1c_exchange_user.php check rikko 'ваш_пароль'
 *   php local/tools/mf_1c_exchange_user.php set rikko 'новый_пароль'
 */
if (PHP_SAPI !== 'cli')
{
	fwrite(STDERR, "Запускайте только из консоли на сервере.\n");
	exit(1);
}

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../..');
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

$action = (string)($argv[1] ?? '');
$login = trim((string)($argv[2] ?? ''));
$password = (string)($argv[3] ?? '');

if ($login === '' || !in_array($action, ['check', 'set'], true))
{
	fwrite(STDERR, "Использование:\n");
	fwrite(STDERR, "  php local/tools/mf_1c_exchange_user.php check LOGIN 'PASSWORD'\n");
	fwrite(STDERR, "  php local/tools/mf_1c_exchange_user.php set LOGIN 'NEW_PASSWORD'\n");
	exit(1);
}

$byLogin = CUser::GetByLogin($login);
$user = $byLogin->Fetch();
if (!$user)
{
	$byEmail = CUser::GetList('ID', 'ASC', ['=EMAIL' => $login, 'ACTIVE' => 'Y']);
	$user = $byEmail->Fetch();
	if ($user)
	{
		echo "Пользователь найден по EMAIL, LOGIN={$user['LOGIN']}\n";
	}
}

if (!$user)
{
	fwrite(STDERR, "Пользователь «{$login}» не найден.\n");
	exit(2);
}

echo "ID={$user['ID']} LOGIN={$user['LOGIN']} ACTIVE={$user['ACTIVE']} EXTERNAL_AUTH_ID={$user['EXTERNAL_AUTH_ID']}\n";
$groups = CUser::GetUserGroup($user['ID']);
echo 'GROUPS=' . implode(',', $groups) . "\n";
echo 'IN_ADMIN_GROUP=' . (in_array(1, array_map('intval', $groups), true) ? 'Y' : 'N') . "\n";

if ($action === 'set')
{
	if ($password === '')
	{
		fwrite(STDERR, "Укажите новый пароль третьим аргументом.\n");
		exit(1);
	}
	$cu = new CUser();
	$ok = $cu->Update($user['ID'], ['PASSWORD' => $password, 'CONFIRM_PASSWORD' => $password]);
	if (!$ok)
	{
		fwrite(STDERR, 'Ошибка смены пароля: ' . $cu->LAST_ERROR . "\n");
		exit(3);
	}
	echo "Пароль обновлён. Проверка:\n";
}

if ($password === '')
{
	fwrite(STDERR, "Для check укажите пароль третьим аргументом.\n");
	exit(1);
}

global $USER;
$authLogin = (string)$user['LOGIN'];
$result = $USER->Login($authLogin, $password, 'N', 'Y');
if ($result === true)
{
	echo "LOGIN_OK — Bitrix принял пароль, checkauth должен вернуть success.\n";
	exit(0);
}

echo "LOGIN_FAIL\n";
if (is_array($result) && !empty($result['MESSAGE']))
{
	echo strip_tags((string)$result['MESSAGE']) . "\n";
}

if (class_exists(\Bitrix\Main\Security\Password::class))
{
	$storedHash = (string)($user['PASSWORD'] ?? '');
	if ($storedHash !== '' && \Bitrix\Main\Security\Password::equals($storedHash, $password))
	{
		echo "HASH_MATCH но Login() не прошёл — смотрите EXTERNAL_AUTH, OTP, политику паролей.\n";
	}
	else
	{
		echo "HASH_MISMATCH — введённый пароль не совпадает с хешем в БД (в 1С другой пароль).\n";
	}
}

exit(4);
