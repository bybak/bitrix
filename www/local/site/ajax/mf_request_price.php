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
	if ($productUrl !== '' && class_exists(\Mf\SiteMail\Renderer::class))
	{
		$productUrl = \Mf\SiteMail\Renderer::absoluteUrl($productUrl);
	}
	elseif ($productId > 0 && $productUrl === '' && \Bitrix\Main\Loader::includeModule('iblock'))
	{
		$rsEl = \CIBlockElement::GetByID($productId);
		if ($el = $rsEl->GetNext(false, false))
		{
			$detailUrl = trim((string)($el['DETAIL_PAGE_URL'] ?? ''));
			if ($detailUrl !== '' && class_exists(\Mf\SiteMail\Renderer::class))
			{
				$productUrl = \Mf\SiteMail\Renderer::absoluteUrl($detailUrl);
			}
		}
	}
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

	$dataRows = [
		['Дата', date('Y-m-d H:i:s')],
		['Авторизован', $isAuthorized ? 'да' : 'нет'],
	];
	if ($isAuthorized && method_exists($USER, 'GetID'))
	{
		$dataRows[] = ['ID пользователя', (string)$USER->GetID()];
	}
	$dataRows = array_merge($dataRows, [
		['Товар ID', $productId > 0 ? (string)$productId : '—'],
		['Товар', $productName],
		['Ссылка', $productUrl !== '' ? $productUrl : '—'],
		['Имя', $name],
		['E-mail', $email],
		['Комментарий', $comment !== '' ? $comment : '—'],
	]);

	$htmlBody = \Mf\SiteMail\Renderer::render(
		'Запрос цены',
		'Запрос цены с поиска',
		$dataRows
	);

	// Bitrix\Main\Mail\Mail: HEADER — массив. Несколько адресов в To ломают часть MTA (Mail.ru + Gmail).
	// Один основной получатель в To, остальные — Bcc.
	$header = [
		'From' => $from,
	];
	if (filter_var($email, FILTER_VALIDATE_EMAIL))
	{
		$header['Reply-To'] = $email;
	}

	$subject = 'Запрос цены: ' . $productName;
	$subject = preg_replace('~[\r\n]+~u', ' ', $subject) ?? $subject;
	$subject = trim(preg_replace('~\s+~u', ' ', $subject) ?? $subject);
	if (function_exists('mb_substr') && mb_strlen($subject) > 900)
	{
		$subject = mb_substr($subject, 0, 900) . '…';
	}

	$params = [
		'TO' => $toPrimary,
		'SUBJECT' => $subject,
		'BODY' => $htmlBody,
		'CHARSET' => 'UTF-8',
		'CONTENT_TYPE' => 'html',
		'HEADER' => $header,
	];

	$sent = class_exists(Mail::class) ? (bool)Mail::send($params) : false;
	if (!$sent)
	{
		throw new RuntimeException('Не удалось отправить сообщение. Попробуйте позже.');
	}

	echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e)
{
	// Всегда 200 + JSON: иначе BX.ajax считает ответ «ошибкой сети» и не показывает поле error из тела ответа.
	http_response_code(200);
	echo json_encode([
		'ok' => false,
		'error' => $e->getMessage(),
	], JSON_UNESCAPED_UNICODE);
}

