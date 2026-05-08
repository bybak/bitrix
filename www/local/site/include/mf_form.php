<?php
/**
 * Minimal form handler for static pages.
 * - Uses Bitrix session protection (check_bitrix_sessid)
 * - Sends mail via \Bitrix\Main\Mail\Mail when available (fallback to mail())
 * - Returns status and keeps entered values
 */

if (!function_exists('mf_handle_static_form'))
{
	function mf_handle_static_form(string $formId, string $emailTo, string $subject, array $fields, array $required = []): array
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

		// Always provide current values for sticky forms
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

		$lines = [];
		$lines[] = 'Заявка с сайта';
		$lines[] = 'Форма: '.$formId;
		$lines[] = 'Дата: '.date('Y-m-d H:i:s');
		$lines[] = 'IP: '.($_SERVER['REMOTE_ADDR'] ?? '');
		$lines[] = '---';

		foreach ($fields as $name => $label)
		{
			$lines[] = $label.': '.$result['values'][$name];
		}

		$body = implode("\n", $lines)."\n";

		$sent = false;
		if (class_exists('\\Bitrix\\Main\\Mail\\Mail'))
		{
			$header = [];
			if (function_exists('mf_mail_default_from'))
			{
				$mfFrom = mf_mail_default_from();
				if ($mfFrom !== '' && filter_var($mfFrom, FILTER_VALIDATE_EMAIL))
				{
					$header['From'] = $mfFrom;
				}
			}
			$sent = (bool)\Bitrix\Main\Mail\Mail::send([
				'TO' => $emailTo,
				'SUBJECT' => $subject,
				'BODY' => $body,
				'CHARSET' => 'UTF-8',
				'CONTENT_TYPE' => 'text',
				'HEADER' => $header,
			]);
		}
		else
		{
			$headers = "Content-Type: text/plain; charset=UTF-8\r\n";
			if (function_exists('mf_mail_default_from'))
			{
				$mfFrom = mf_mail_default_from();
				if ($mfFrom !== '' && filter_var($mfFrom, FILTER_VALIDATE_EMAIL))
				{
					$headers .= 'From: ' . $mfFrom . "\r\n";
				}
			}
			$sent = @mail($emailTo, $subject, $body, $headers);
		}

		$result['sent'] = $sent;
		$result['ok'] = $sent;

		if (!$sent)
		{
			$result['errors'][] = 'Не удалось отправить сообщение. Попробуйте позже или свяжитесь с нами по телефону.';
		}

		return $result;
	}
}

