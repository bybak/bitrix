<?php
declare(strict_types=1);

define('STOP_STATISTICS', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Mail\Mail;

header('Content-Type: application/json; charset=UTF-8');

try
{
	if (!function_exists('check_bitrix_sessid') || !check_bitrix_sessid())
	{
		throw new RuntimeException('Сессия истекла. Обновите страницу и попробуйте снова.');
	}

	$request = Context::getCurrent()->getRequest();

	$productId = (int)$request->getPost('product_id');
	$productName = trim((string)$request->getPost('product_name'));
	$productUrl = trim((string)$request->getPost('product_url'));
	$name = trim((string)$request->getPost('name'));
	$email = trim((string)$request->getPost('email'));
	$comment = trim((string)$request->getPost('comment'));

	global $USER;
	$isAuthorized = is_object($USER) && method_exists($USER, 'IsAuthorized') && $USER->IsAuthorized();
	if ($isAuthorized)
	{
		if ($name === '')
		{
			$name = trim((string)$USER->GetFirstName() . ' ' . (string)$USER->GetLastName());
			if ($name === '')
			{
				$name = trim((string)$USER->GetLogin());
			}
		}
		if ($email === '')
		{
			$email = trim((string)$USER->GetEmail());
		}
	}

	$productName = preg_replace('~\s+~u', ' ', $productName ?? '');
	$productUrl = preg_replace('~[\r\n]+~', '', $productUrl ?? '');
	$name = preg_replace('~\s+~u', ' ', $name ?? '');
	$email = preg_replace('~[\r\n]+~', '', $email ?? '');

	$errors = [];
	if ($productName === '')
	{
		$errors[] = 'Не удалось определить товар.';
	}
	if ($name === '')
	{
		$errors[] = 'Укажите имя.';
	}
	if ($email === '')
	{
		$errors[] = 'Укажите e-mail.';
	}
	elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))
	{
		$errors[] = 'Укажите корректный e-mail.';
	}

	$to = trim((string)getenv('MF_C2C_BCC'));
	if ($to === '')
	{
		$errors[] = 'Не настроен адрес получателя.';
	}

	if (!empty($errors))
	{
		throw new RuntimeException(implode("\n", $errors));
	}

	$from = trim((string)getenv('MF_SMTP_FROM'));
	if ($from === '')
	{
		$from = trim((string)Option::get('main', 'email_from', ''));
	}

	$lines = [];
	$lines[] = 'Запрос цены с поиска';
	$lines[] = 'Дата: ' . date('Y-m-d H:i:s');
	$lines[] = 'IP: ' . (string)($_SERVER['REMOTE_ADDR'] ?? '');
	$lines[] = 'Авторизован: ' . ($isAuthorized ? 'да' : 'нет');
	if ($isAuthorized && method_exists($USER, 'GetID'))
	{
		$lines[] = 'ID пользователя: ' . (string)$USER->GetID();
	}
	$lines[] = '---';
	$lines[] = 'Товар ID: ' . ($productId > 0 ? (string)$productId : '—');
	$lines[] = 'Товар: ' . $productName;
	$lines[] = 'Ссылка: ' . ($productUrl !== '' ? $productUrl : '—');
	$lines[] = 'Имя: ' . $name;
	$lines[] = 'E-mail: ' . $email;
	$lines[] = 'Комментарий:';
	$lines[] = ($comment !== '' ? $comment : '—');
	$body = implode("\n", $lines) . "\n";

	$headers = [
		'Content-Type: text/plain; charset=UTF-8',
		'Reply-To: ' . $email,
	];

	$params = [
		'TO' => $to,
		'SUBJECT' => 'Запрос цены: ' . $productName,
		'BODY' => $body,
		'HEADER' => implode("\r\n", $headers) . "\r\n",
	];
	if ($from !== '')
	{
		$params['FROM'] = $from;
	}

	$sent = class_exists(Mail::class) ? (bool)Mail::send($params) : false;
	if (!$sent)
	{
		throw new RuntimeException('Не удалось отправить сообщение. Попробуйте позже.');
	}

	echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e)
{
	http_response_code(400);
	echo json_encode([
		'ok' => false,
		'error' => $e->getMessage(),
	], JSON_UNESCAPED_UNICODE);
}

