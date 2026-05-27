<?php
/**
 * Minimal form handler for static pages.
 * - Uses Bitrix session protection (check_bitrix_sessid)
 * - Sends HTML mail via Mf\SiteMail\Renderer (same layout as header feedback)
 * - Returns status and keeps entered values
 */

if (!function_exists('mf_form_mail_meta'))
{
	function mf_form_mail_meta(string $formId, string $subject, array $override = []): array
	{
		$defaults = [
			'sotrudnichestvo' => [
				'title' => 'Заявка на сотрудничество',
				'subtitle' => 'Заявка на сотрудничество — форма на странице сайта',
			],
			'remont_motorov_feedback' => [
				'title' => 'Сообщение с сайта',
				'subtitle' => 'Напишите нам — форма на странице «Ремонт моторов»',
			],
			'vikup_mototehniki' => [
				'title' => 'Предложение по выкупу',
				'subtitle' => 'Отправить предложение — форма на странице «Выкуп мототехники»',
			],
			'prokat_rent' => [
				'title' => 'Заявка на прокат',
				'subtitle' => 'Заявка на аренду техники — форма на странице сайта',
			],
		];

		$meta = $defaults[$formId] ?? [
			'title' => $subject !== '' ? $subject : 'Заявка с сайта',
			'subtitle' => 'Форма на сайте',
		];

		if (!empty($override['title']))
		{
			$meta['title'] = (string)$override['title'];
		}
		if (!empty($override['subtitle']))
		{
			$meta['subtitle'] = (string)$override['subtitle'];
		}

		return $meta;
	}
}

if (!function_exists('mf_form_resolve_page_url'))
{
	function mf_form_resolve_page_url($request = null): string
	{
		$pageUrl = '';
		if ($request && method_exists($request, 'getPost'))
		{
			$pageUrl = trim((string)$request->getPost('page_url'));
		}
		if ($pageUrl === '')
		{
			$pageUrl = trim((string)($_POST['page_url'] ?? ''));
		}
		$pageUrl = preg_replace('~[\r\n]+~', '', $pageUrl) ?? '';

		if ($pageUrl !== '')
		{
			if (class_exists(\Mf\SiteMail\Renderer::class))
			{
				return \Mf\SiteMail\Renderer::absoluteUrl($pageUrl);
			}

			return $pageUrl;
		}

		if ($request && method_exists($request, 'getHttpHost'))
		{
			$host = trim((string)$request->getHttpHost());
			if ($host !== '')
			{
				$scheme = (method_exists($request, 'isHttps') && $request->isHttps()) ? 'https' : 'http';
				$path = method_exists($request, 'getRequestedPage')
					? (string)$request->getRequestedPage()
					: (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
				if ($path === '')
				{
					$path = '/';
				}

				return $scheme . '://' . $host . $path;
			}
		}

		$ref = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
		if ($ref !== '' && preg_match('~^https?://~i', $ref))
		{
			return $ref;
		}

		return '—';
	}
}

if (!function_exists('mf_form_normalize_mail_subject'))
{
	function mf_form_normalize_mail_subject(string $subject): string
	{
		$subject = preg_replace('~[\r\n]+~u', ' ', $subject) ?? $subject;
		$subject = trim(preg_replace('~\s+~u', ' ', $subject) ?? $subject);
		if (function_exists('mb_substr') && mb_strlen($subject) > 900)
		{
			$subject = mb_substr($subject, 0, 900) . '…';
		}

		return $subject;
	}
}

if (!function_exists('mf_form_resolve_recipient'))
{
	function mf_form_resolve_recipient(string $emailTo): string
	{
		$emailTo = trim($emailTo);
		if ($emailTo !== '' && filter_var($emailTo, FILTER_VALIDATE_EMAIL))
		{
			return $emailTo;
		}

		if (function_exists('mf_mail_admin_inbox'))
		{
			$inbox = trim((string)mf_mail_admin_inbox());
			if ($inbox !== '' && filter_var($inbox, FILTER_VALIDATE_EMAIL))
			{
				return $inbox;
			}
		}

		return 'andrey@motor-force.ru';
	}
}

if (!function_exists('mf_form_resolve_from'))
{
	function mf_form_resolve_from(string $fallbackTo): string
	{
		$from = '';
		if (function_exists('mf_mail_default_from_admin'))
		{
			$from = trim((string)mf_mail_default_from_admin());
		}
		elseif (function_exists('mf_mail_default_from'))
		{
			$from = trim((string)mf_mail_default_from());
		}
		if ($from === '' && class_exists('\\Bitrix\\Main\\Config\\Option'))
		{
			$from = trim((string)\Bitrix\Main\Config\Option::get('main', 'email_from', ''));
		}
		if ($from === '' && function_exists('getenv'))
		{
			$from = trim((string)getenv('MF_SMTP_FROM_ROBOT'));
		}
		if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL))
		{
			$from = $fallbackTo;
		}

		return $from;
	}
}

if (!function_exists('mf_form_find_reply_to_email'))
{
	function mf_form_find_reply_to_email(array $fields, array $values): string
	{
		foreach (['EMAIL', 'email', 'Email'] as $key)
		{
			if (!isset($values[$key]))
			{
				continue;
			}
			$email = trim((string)$values[$key]);
			if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
			{
				return $email;
			}
		}

		foreach ($fields as $name => $label)
		{
			if (!is_string($label) || stripos($label, 'mail') === false)
			{
				continue;
			}
			$email = trim((string)($values[$name] ?? ''));
			if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
			{
				return $email;
			}
		}

		return '';
	}
}

if (!function_exists('mf_form_send_html_mail'))
{
	/**
	 * @param list<array{0:string,1:string}> $dataRows
	 * @param array<string, string> $header
	 */
	function mf_form_send_html_mail(
		string $to,
		string $subject,
		string $title,
		string $subtitle,
		array $dataRows,
		array $header = []
	): bool
	{
		if (!class_exists('\\Mf\\SiteMail\\Renderer'))
		{
			return false;
		}

		$htmlBody = \Mf\SiteMail\Renderer::render($title, $subtitle, $dataRows);

		if (function_exists('mf_site_mail_send_html'))
		{
			return mf_site_mail_send_html($to, $subject, $htmlBody, $header);
		}

		if (!class_exists('\\Bitrix\\Main\\Mail\\Mail'))
		{
			return false;
		}

		return (bool)\Bitrix\Main\Mail\Mail::send([
			'TO' => $to,
			'SUBJECT' => $subject,
			'BODY' => $htmlBody,
			'CHARSET' => 'UTF-8',
			'CONTENT_TYPE' => 'html',
			'HEADER' => $header,
		]);
	}
}

if (!function_exists('mf_handle_static_form'))
{
	function mf_handle_static_form(
		string $formId,
		string $emailTo,
		string $subject,
		array $fields,
		array $required = [],
		array $mailMeta = []
	): array
	{
		$result = [
			'ok' => false,
			'sent' => false,
			'errors' => [],
			'values' => [],
		];

		$request = null;
		if (class_exists('\\Bitrix\\Main\\Context'))
		{
			$request = \Bitrix\Main\Context::getCurrent()->getRequest();
		}

		$isPost = $request ? $request->isPost() : ($_SERVER['REQUEST_METHOD'] === 'POST');
		$postFormId = $request ? (string)$request->getPost('mf_form_id') : (string)($_POST['mf_form_id'] ?? '');

		foreach ($fields as $name => $_label)
		{
			$val = $request ? (string)$request->getPost($name) : (string)($_POST[$name] ?? '');
			$result['values'][$name] = trim($val);
		}

		if (!$isPost || $postFormId !== $formId)
		{
			return $result;
		}

		if (!function_exists('check_bitrix_sessid') || !check_bitrix_sessid())
		{
			$result['errors'][] = 'Сессия истекла. Обновите страницу и попробуйте снова.';
			return $result;
		}

		foreach ($required as $name)
		{
			if (!isset($result['values'][$name]) || $result['values'][$name] === '')
			{
				$label = $fields[$name] ?? $name;
				$result['errors'][] = 'Поле «'.$label.'» обязательно для заполнения.';
			}
		}

		if (!empty($result['errors']))
		{
			return $result;
		}

		$recipient = mf_form_resolve_recipient($emailTo);
		if (!filter_var($recipient, FILTER_VALIDATE_EMAIL))
		{
			$result['errors'][] = 'Не настроен адрес получателя.';
			return $result;
		}

		$meta = mf_form_mail_meta($formId, $subject, $mailMeta);
		$dataRows = [
			['Дата', date('Y-m-d H:i:s')],
			['Страница', mf_form_resolve_page_url($request)],
		];

		foreach ($fields as $name => $label)
		{
			$value = trim((string)($result['values'][$name] ?? ''));
			if ($value === '')
			{
				continue;
			}
			$dataRows[] = [(string)$label, $value];
		}

		$header = [
			'From' => mf_form_resolve_from($recipient),
			'X-MF-SMTP-Profile' => 'robot',
		];
		$replyTo = mf_form_find_reply_to_email($fields, $result['values']);
		if ($replyTo !== '')
		{
			$header['Reply-To'] = $replyTo;
		}

		$sent = mf_form_send_html_mail(
			$recipient,
			mf_form_normalize_mail_subject($subject),
			(string)$meta['title'],
			(string)$meta['subtitle'],
			$dataRows,
			$header
		);

		$result['sent'] = $sent;
		$result['ok'] = $sent;

		if (!$sent)
		{
			$result['errors'][] = 'Не удалось отправить сообщение. Попробуйте позже или свяжитесь с нами по телефону.';
		}

		return $result;
	}
}
