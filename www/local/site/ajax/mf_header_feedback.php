<?php
declare(strict_types=1);

define('STOP_STATISTICS', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

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
	$formType = trim((string)$request->getPost('form_type'));
	if (!in_array($formType, ['write_us', 'callback'], true))
	{
		throw new RuntimeException('Неизвестный тип формы.');
	}

	$name = trim((string)$request->getPost('name'));
	$email = trim((string)$request->getPost('email'));
	$phone = trim((string)$request->getPost('phone'));
	$message = trim((string)$request->getPost('message'));
	$pageUrl = trim((string)$request->getPost('page_url'));

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
		if ($phone === '' && method_exists($USER, 'GetByID'))
		{
			$u = \CUser::GetByID((int)$USER->GetID())->Fetch();
			if (is_array($u))
			{
				$phone = trim((string)($u['PERSONAL_PHONE'] ?? ($u['PERSONAL_MOBILE'] ?? '')));
			}
		}
	}

	$name = preg_replace('~\s+~u', ' ', $name ?? '') ?? '';
	$email = preg_replace('~[\r\n]+~', '', $email ?? '');
	$phone = preg_replace('~[\r\n]+~', '', $phone ?? '');
	$message = trim((string)preg_replace('~\r\n?~', "\n", $message ?? ''));
	$pageUrl = preg_replace('~[\r\n]+~', '', $pageUrl ?? '');

	$errors = [];
	if ($name === '')
	{
		$errors[] = 'Укажите имя.';
	}

	if ($formType === 'write_us')
	{
		if ($email === '')
		{
			$errors[] = 'Укажите e-mail.';
		}
		elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))
		{
			$errors[] = 'Укажите корректный e-mail.';
		}
		if ($message === '')
		{
			$errors[] = 'Напишите сообщение.';
		}
	}
	else
	{
		$phoneDigits = preg_replace('~\D+~', '', $phone) ?? '';
		if ($phone === '')
		{
			$errors[] = 'Укажите телефон.';
		}
		elseif (strlen($phoneDigits) < 10)
		{
			$errors[] = 'Укажите корректный телефон.';
		}
	}

	$toPrimary = function_exists('mf_mail_admin_inbox')
		? mf_mail_admin_inbox()
		: trim((string)getenv('MF_ORDER_NOTIFY_EMAIL'));
	if ($toPrimary === '' || !filter_var($toPrimary, FILTER_VALIDATE_EMAIL))
	{
		$toPrimary = 'andrey@motor-force.ru';
	}
	if (!filter_var($toPrimary, FILTER_VALIDATE_EMAIL))
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
	if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL))
	{
		$from = $toPrimary;
	}

	$lines = [];
	if ($formType === 'write_us')
	{
		$subject = 'Сообщение с сайта: ' . $name;
		$lines[] = 'Напишите нам — форма в шапке сайта';
	}
	else
	{
		$subject = 'Заявка на обратный звонок: ' . $name;
		$lines[] = 'Обратный звонок — форма в шапке сайта';
	}
	$lines[] = 'Дата: ' . date('Y-m-d H:i:s');
	$lines[] = 'IP: ' . (string)($_SERVER['REMOTE_ADDR'] ?? '');
	$lines[] = 'Страница: ' . ($pageUrl !== '' ? $pageUrl : '—');
	$lines[] = 'Авторизован: ' . ($isAuthorized ? 'да' : 'нет');
	if ($isAuthorized && method_exists($USER, 'GetID'))
	{
		$lines[] = 'ID пользователя: ' . (string)$USER->GetID();
	}
	$lines[] = '---';
	$lines[] = 'Имя: ' . $name;
	if ($formType === 'write_us')
	{
		$lines[] = 'E-mail: ' . $email;
		$lines[] = 'Сообщение:';
		$lines[] = $message;
	}
	else
	{
		$lines[] = 'Телефон: ' . $phone;
		if ($message !== '')
		{
			$lines[] = 'Комментарий:';
			$lines[] = $message;
		}
	}
	$body = implode("\n", $lines) . "\n";

	$header = ['From' => $from];
	if ($formType === 'write_us' && filter_var($email, FILTER_VALIDATE_EMAIL))
	{
		$header['Reply-To'] = $email;
	}

	$subject = preg_replace('~[\r\n]+~u', ' ', $subject) ?? $subject;
	$subject = trim(preg_replace('~\s+~u', ' ', $subject) ?? $subject);
	if (function_exists('mb_substr') && mb_strlen($subject) > 900)
	{
		$subject = mb_substr($subject, 0, 900) . '…';
	}

	$sent = class_exists(Mail::class) && Mail::send([
		'TO' => $toPrimary,
		'SUBJECT' => $subject,
		'BODY' => $body,
		'CHARSET' => 'UTF-8',
		'CONTENT_TYPE' => 'text',
		'HEADER' => $header,
	]);
	if (!$sent)
	{
		throw new RuntimeException('Не удалось отправить сообщение. Попробуйте позже.');
	}

	echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e)
{
	http_response_code(200);
	echo json_encode([
		'ok' => false,
		'error' => $e->getMessage(),
	], JSON_UNESCAPED_UNICODE);
}
