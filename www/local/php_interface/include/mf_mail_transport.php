<?php
/**
 * Транспорт почты: msmtp + Mail.ru — согласованный envelope, EHLO (docker-entrypoint),
 * фильтрация кривых адресов из шаблонов Битрикса (например sale@_) до отправки.
 */

declare(strict_types=1);

if (!function_exists('mf_mail_ini_sendmail_from'))
{
	function mf_mail_ini_sendmail_from(): void
	{
		static $done = false;
		if ($done)
		{
			return;
		}
		$done = true;
		$from = function_exists('mf_mail_default_from_client')
			? mf_mail_default_from_client()
			: trim((string)getenv('MF_SMTP_FROM_ANDREY'));
		if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL))
		{
			@ini_set('sendmail_from', $from);
		}
	}
}
mf_mail_ini_sendmail_from();

// Упрощённые From/To для внешнего SMTP (как у «Microsoft SMTP» в ядре Bitrix).
if (trim((string)getenv('MF_SMTP_HOST')) !== '' && !defined('BX_MS_SMTP'))
{
	define('BX_MS_SMTP', true);
}

if (!function_exists('mf_mail_env_email'))
{
	function mf_mail_env_email(string $name): string
	{
		$val = trim((string)getenv($name));

		return ($val !== '' && filter_var($val, FILTER_VALIDATE_EMAIL)) ? $val : '';
	}
}

if (!function_exists('mf_mail_smtp_profile_ids'))
{
	/** @return list<string> */
	function mf_mail_smtp_profile_ids(): array
	{
		return ['andrey', 'robot'];
	}
}

if (!function_exists('mf_mail_smtp_msmtp_account'))
{
	function mf_mail_smtp_msmtp_account(string $profileId): string
	{
		return $profileId === 'robot' ? 'robot' : 'andrey';
	}
}

if (!function_exists('mf_mail_default_from_client'))
{
	/** From для писем клиентам (andrey@). */
	function mf_mail_default_from_client(): string
	{
		$from = mf_mail_env_email('MF_SMTP_FROM_ANDREY');
		if ($from !== '')
		{
			return $from;
		}
		$from = mf_mail_env_email('MF_SMTP_FROM');
		if ($from !== '')
		{
			return $from;
		}
		if (class_exists(\Bitrix\Main\Config\Option::class))
		{
			try
			{
				$from = trim((string)\Bitrix\Main\Config\Option::get('main', 'email_from', ''));
				if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL))
				{
					return $from;
				}
			}
			catch (\Throwable $e)
			{
			}
		}

		return '';
	}
}

if (!function_exists('mf_mail_default_from_admin'))
{
	/** From для писем администратору (robot@). */
	function mf_mail_default_from_admin(): string
	{
		$from = mf_mail_env_email('MF_SMTP_FROM_ROBOT');
		if ($from !== '')
		{
			return $from;
		}

		return mf_mail_default_from_client();
	}
}

if (!function_exists('mf_mail_default_from'))
{
	function mf_mail_default_from(): string
	{
		return mf_mail_default_from_client();
	}
}

if (!function_exists('mf_mail_set_active_smtp_profile'))
{
	function mf_mail_set_active_smtp_profile(string $profileId): void
	{
		$GLOBALS['MF_MAIL_ACTIVE_SMTP_PROFILE'] = ($profileId === 'robot') ? 'robot' : 'andrey';
	}
}

if (!function_exists('mf_mail_get_active_smtp_profile'))
{
	function mf_mail_get_active_smtp_profile(): string
	{
		$cur = (string)($GLOBALS['MF_MAIL_ACTIVE_SMTP_PROFILE'] ?? '');

		return $cur === 'robot' ? 'robot' : 'andrey';
	}
}

if (!function_exists('mf_mail_profile_from'))
{
	function mf_mail_profile_from(string $profileId): string
	{
		if ($profileId === 'robot')
		{
			return mf_mail_default_from_admin();
		}

		return mf_mail_default_from_client();
	}
}

if (!function_exists('mf_mail_admin_recipient_emails'))
{
	/**
	 * @return string[]
	 */
	function mf_mail_admin_recipient_emails(): array
	{
		$raw = [
			getenv('MF_ORDER_NOTIFY_EMAIL'),
			getenv('MF_C2C_BCC'),
			'andrey@motor-force.ru',
		];
		$out = [];
		$seen = [];
		foreach ($raw as $item)
		{
			$email = strtolower(trim((string)$item));
			if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email]))
			{
				continue;
			}
			$seen[$email] = true;
			$out[] = $email;
		}

		return $out;
	}
}

if (!function_exists('mf_mail_is_admin_only_recipients'))
{
	function mf_mail_is_admin_only_recipients(array $recipientEmails): bool
	{
		if ($recipientEmails === [])
		{
			return false;
		}
		$admin = array_flip(mf_mail_admin_recipient_emails());
		foreach ($recipientEmails as $email)
		{
			$key = strtolower(trim((string)$email));
			if ($key === '' || !isset($admin[$key]))
			{
				return false;
			}
		}

		return true;
	}
}

if (!function_exists('mf_mail_client_order_event_names'))
{
	/** @return list<string> */
	function mf_mail_client_order_event_names(): array
	{
		return [
			'SALE_NEW_ORDER',
			'SALE_STATUS_CHANGED_N',
			'SALE_STATUS_CHANGED_F',
			'SALE_STATUS_CHANGED_P',
		];
	}
}

if (!function_exists('mf_mail_header_value'))
{
	function mf_mail_header_value(array $hdr, string $name): string
	{
		$want = strtolower($name);
		foreach ($hdr as $k => $v)
		{
			if (strtolower((string)$k) === $want)
			{
				return trim((string)$v);
			}
		}

		return '';
	}
}

if (!function_exists('mf_mail_is_client_order_mail'))
{
	/**
	 * @param array<string, mixed> $mail
	 */
	function mf_mail_is_client_order_mail(array $mail): bool
	{
		$hdr = isset($mail['HEADER']) && is_array($mail['HEADER']) ? $mail['HEADER'] : [];
		$eventName = strtoupper(mf_mail_header_value($hdr, 'X-EVENT_NAME'));
		if ($eventName === '')
		{
			$eventName = strtoupper(mf_mail_header_value($hdr, 'X-EVENT-NAME'));
		}

		return $eventName !== '' && in_array($eventName, mf_mail_client_order_event_names(), true);
	}
}

if (!function_exists('mf_mail_resolve_smtp_profile'))
{
	/**
	 * @param array<string, mixed> $mail
	 */
	function mf_mail_resolve_smtp_profile(array $mail): string
	{
		if (mf_mail_is_client_order_mail($mail))
		{
			return 'andrey';
		}

		$hdr = isset($mail['HEADER']) && is_array($mail['HEADER']) ? $mail['HEADER'] : [];
		foreach ($hdr as $name => $value)
		{
			if (strtolower((string)$name) !== 'x-mf-smtp-profile')
			{
				continue;
			}
			$marker = strtolower(trim((string)$value));
			if (in_array($marker, ['admin', 'robot'], true))
			{
				return 'robot';
			}
			if (in_array($marker, ['client', 'andrey', 'customer'], true))
			{
				return 'andrey';
			}
		}

		$rcpts = function_exists('mf_mail_collect_delivery_recipients')
			? mf_mail_collect_delivery_recipients($mail)
			: mf_mail_collect_recipient_emails($mail);

		if ($rcpts !== [] && mf_mail_targets_external_inbox($rcpts))
		{
			return 'andrey';
		}

		return mf_mail_is_admin_only_recipients($rcpts) ? 'robot' : 'andrey';
	}
}

if (!function_exists('mf_mail_parse_headers_string'))
{
	/**
	 * @return array<string, string>
	 */
	function mf_mail_parse_headers_string(string $headers): array
	{
		if ($headers === '')
		{
			return [];
		}
		$out = [];
		foreach (preg_split('/\r\n|\n|\r/', $headers) as $line)
		{
			if (preg_match('/^([^:\s]+)\s*:\s*(.*)$/', (string)$line, $m))
			{
				$name = trim((string)$m[1]);
				$value = trim((string)$m[2]);
				if ($name !== '')
				{
					$out[$name] = $value;
				}
			}
		}

		return $out;
	}
}

if (!function_exists('mf_mail_resolve_smtp_profile_from_php_mail'))
{
	function mf_mail_resolve_smtp_profile_from_php_mail(string $to, string $headersStr): string
	{
		$mail = [
			'TO' => $to,
			'HEADER' => mf_mail_parse_headers_string($headersStr),
		];

		return mf_mail_resolve_smtp_profile($mail);
	}
}

if (!function_exists('mf_mail_mark_smtp_profile'))
{
	/**
	 * @param array<string, mixed> $mail
	 *
	 * @return array<string, mixed>
	 */
	function mf_mail_mark_smtp_profile(array $mail, string $profileId): array
	{
		$profileId = ($profileId === 'robot') ? 'robot' : 'andrey';
		$hdr = isset($mail['HEADER']) && is_array($mail['HEADER']) ? $mail['HEADER'] : [];
		$hdr['X-MF-SMTP-Profile'] = $profileId;
		$mail['HEADER'] = $hdr;

		return $mail;
	}
}

if (!function_exists('mf_mail_strip_internal_headers'))
{
	/**
	 * @param array<string, mixed> $hdr
	 *
	 * @return array<string, mixed>
	 */
	function mf_mail_strip_internal_headers(array $hdr): array
	{
		foreach (array_keys($hdr) as $name)
		{
			if (strtolower((string)$name) === 'x-mf-smtp-profile')
			{
				unset($hdr[$name]);
			}
		}

		return $hdr;
	}
}

if (!function_exists('mf_mail_apply_smtp_profile'))
{
	/**
	 * @param array<string, mixed> $mail
	 *
	 * @return array<string, mixed>
	 */
	function mf_mail_apply_smtp_profile(array $mail): array
	{
		$profileId = mf_mail_resolve_smtp_profile($mail);
		mf_mail_set_active_smtp_profile($profileId);

		$smtpFrom = mf_mail_profile_from($profileId);
		$smtpHost = trim((string)getenv('MF_SMTP_HOST'));
		if ($smtpHost !== '' && $smtpFrom !== '')
		{
			$h = isset($mail['HEADER']) && is_array($mail['HEADER']) ? $mail['HEADER'] : [];
			foreach (array_keys($h) as $kn)
			{
				$l = strtolower((string)$kn);
				if (in_array($l, ['sender', 'return-path', 'errors-to'], true))
				{
					unset($h[$kn]);
				}
			}
			$h['From'] = $smtpFrom;
			if ($profileId === 'andrey')
			{
				$h['Reply-To'] = $smtpFrom;
			}
			$h = mf_mail_strip_internal_headers($h);
			$mail['HEADER'] = $h;
		}
		else
		{
			$hdr = isset($mail['HEADER']) && is_array($mail['HEADER']) ? $mail['HEADER'] : [];
			$mail['HEADER'] = mf_mail_strip_internal_headers($hdr);
		}

		return $mail;
	}
}

if (!function_exists('mf_mail_admin_inbox'))
{
	function mf_mail_admin_inbox(): string
	{
		foreach ([getenv('MF_ORDER_NOTIFY_EMAIL')] as $raw)
		{
			$t = trim((string)$raw);
			if ($t !== '' && filter_var($t, FILTER_VALIDATE_EMAIL))
			{
				return $t;
			}
		}
		if (class_exists(\Bitrix\Main\Config\Option::class))
		{
			try
			{
				$t = trim((string)\Bitrix\Main\Config\Option::get('main', 'email_from', ''));
				if ($t !== '' && filter_var($t, FILTER_VALIDATE_EMAIL))
				{
					return $t;
				}
			}
			catch (\Throwable $e)
			{
			}
		}

		return 'andrey@motor-force.ru';
	}
}

if (!function_exists('mf_mail_collect_recipient_emails'))
{
	/**
	 * @param array<string, mixed> $mail
	 *
	 * @return string[]
	 */
	function mf_mail_collect_recipient_emails(array $mail): array
	{
		$raw = [];
		if (isset($mail['TO']))
		{
			$raw = array_merge($raw, mf_mail_normalize_recipient_list((string)$mail['TO']));
		}
		if (isset($mail['HEADER']) && is_array($mail['HEADER']))
		{
			foreach ($mail['HEADER'] as $k => $v)
			{
				if (!is_string($v))
				{
					continue;
				}
				$lk = strtolower((string)$k);
				if (!in_array($lk, ['cc', 'bcc', 'to', 'reply-to'], true))
				{
					continue;
				}
				$raw = array_merge($raw, mf_mail_normalize_recipient_list($v));
			}
		}

		return array_values(array_unique($raw));
	}
}

if (!function_exists('mf_mail_collect_delivery_recipients'))
{
	/**
	 * Получатели доставки (To/Cc/Bcc), без Reply-To — для выбора SMTP-профиля.
	 *
	 * @param array<string, mixed> $mail
	 *
	 * @return string[]
	 */
	function mf_mail_collect_delivery_recipients(array $mail): array
	{
		$raw = [];
		if (isset($mail['TO']))
		{
			$raw = array_merge($raw, mf_mail_normalize_recipient_list((string)$mail['TO']));
		}
		if (isset($mail['HEADER']) && is_array($mail['HEADER']))
		{
			foreach ($mail['HEADER'] as $k => $v)
			{
				if (!is_string($v))
				{
					continue;
				}
				$lk = strtolower((string)$k);
				if (!in_array($lk, ['cc', 'bcc', 'to'], true))
				{
					continue;
				}
				$raw = array_merge($raw, mf_mail_normalize_recipient_list($v));
			}
		}

		return array_values(array_unique($raw));
	}
}

if (!function_exists('mf_mail_targets_external_inbox'))
{
	function mf_mail_targets_external_inbox(array $recipientEmails): bool
	{
		foreach ($recipientEmails as $em)
		{
			$em = strtolower((string)$em);
			if ($em === '')
			{
				continue;
			}
			if (str_ends_with($em, '@motor-force.ru') || str_ends_with($em, '@mail.ru'))
			{
				continue;
			}

			return true;
		}

		return false;
	}
}

if (!function_exists('mf_mail_targets_gmail'))
{
	function mf_mail_targets_gmail(array $recipientEmails): bool
	{
		foreach ($recipientEmails as $em)
		{
			$em = strtolower((string)$em);
			if (str_ends_with($em, '@gmail.com') || str_ends_with($em, '@googlemail.com'))
			{
				return true;
			}
		}

		return false;
	}
}

if (!function_exists('mf_mail_canonicalize_header_names'))
{
	/**
	 * В getHeaders() ядро сравнивает имя заголовка буквально: только «CC» идёт через encodeHeaderFrom;
	 * «Cc» попадает в encodeMimeString и может испортить «Имя» <mail@…> — Mail.ru даёт 550 на релей в Gmail.
	 *
	 * @param array<string, mixed> $hdr
	 *
	 * @return array<string, mixed>
	 */
	function mf_mail_canonicalize_header_names(array $hdr): array
	{
		$rename = [
			'from' => 'From',
			'to' => 'To',
			'cc' => 'CC',
			'bcc' => 'Bcc',
			'reply-to' => 'Reply-To',
		];
		$mergeAddr = ['CC' => true, 'Bcc' => true, 'Reply-To' => true];
		$out = [];
		foreach ($hdr as $name => $value)
		{
			$cn = $rename[strtolower((string)$name)] ?? $name;
			if (isset($mergeAddr[$cn]) && isset($out[$cn]) && is_string($out[$cn]) && is_string($value))
			{
				$out[$cn] = trim((string)$out[$cn] . ', ' . $value);
			}
			else
			{
				$out[$cn] = $value;
			}
		}

		return $out;
	}
}

if (!function_exists('mf_mail_fixup_additional_headers_string'))
{
	/**
	 * Последняя линия до mail(): правки, которые другие обработчики могли вернуть в строку заголовков.
	 */
	function mf_mail_fixup_additional_headers_string(string $headers, string $to = ''): string
	{
		if ($headers === '')
		{
			return $headers;
		}
		$smtpHost = trim((string)getenv('MF_SMTP_HOST'));
		$profileId = function_exists('mf_mail_resolve_smtp_profile_from_php_mail')
			? mf_mail_resolve_smtp_profile_from_php_mail($to, $headers)
			: (function_exists('mf_mail_get_active_smtp_profile') ? mf_mail_get_active_smtp_profile() : 'andrey');
		if (function_exists('mf_mail_set_active_smtp_profile'))
		{
			mf_mail_set_active_smtp_profile($profileId);
		}
		$smtpFrom = function_exists('mf_mail_profile_from')
			? mf_mail_profile_from($profileId)
			: trim((string)getenv('MF_SMTP_FROM_ANDREY'));
		if ($smtpHost !== '' && $smtpFrom !== '' && filter_var($smtpFrom, FILTER_VALIDATE_EMAIL))
		{
			// Mail.ru: «From» в DATA должен совпадать с пользователем SMTP; иначе 550 (часто только на внешние домены, напр. Gmail).
			$headers = preg_replace('~^From:\s*[^\r\n]+~mi', 'From: ' . $smtpFrom, $headers, 1) ?? $headers;
			$headers = preg_replace('~^(?i)(Sender|Return-Path|Errors-To):\s*[^\r\n]+\R?~m', '', $headers) ?? $headers;
		}
		$headers = preg_replace('~^X-Priority:\s*3\s*\([^)]*\)\s*(\R|$)~mi', 'X-Priority: 3$1', $headers) ?? $headers;
		if (str_contains($headers, "\n") && !str_contains($headers, "\r\n"))
		{
			$headers = str_replace("\n", "\r\n", $headers);
		}

		return $headers;
	}
}

if (!function_exists('mf_mail_normalize_recipient_list'))
{
	/**
	 * Разбор строки To/Cc/Bcc: только валидные e-mail, без «sale@_» и мусора из шаблонов.
	 *
	 * @return string[]
	 */
	function mf_mail_normalize_recipient_list(?string $raw): array
	{
		if ($raw === null || trim($raw) === '')
		{
			return [];
		}
		$raw = str_replace(["\r", "\n", ';'], [',', ',', ','], (string)$raw);
		$parts = preg_split('~\s*,\s*~', $raw, -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($parts))
		{
			return [];
		}
		$out = [];
		$seen = [];
		foreach ($parts as $p)
		{
			$p = trim((string)$p);
			if ($p === '')
			{
				continue;
			}
			if (preg_match('~<([^>]+@[^>]+)>~', $p, $m))
			{
				$p = trim($m[1]);
			}
			if (str_contains($p, '@_'))
			{
				continue;
			}
			if (!filter_var($p, FILTER_VALIDATE_EMAIL))
			{
				continue;
			}
			$k = strtolower($p);
			if (isset($seen[$k]))
			{
				continue;
			}
			$seen[$k] = true;
			$out[] = $p;
		}

		return $out;
	}
}

if (!function_exists('mf_mail_sanitize_outgoing_params'))
{
	/**
	 * @param array<string, mixed> $mail
	 *
	 * @return array<string, mixed>
	 */
	function mf_mail_sanitize_outgoing_params(array $mail): array
	{
		$out = $mail;
		if (isset($out['HEADER']) && is_array($out['HEADER']))
		{
			$out['HEADER'] = mf_mail_canonicalize_header_names($out['HEADER']);
		}

		if (isset($out['SUBJECT']))
		{
			$out['SUBJECT'] = trim(preg_replace('~[\r\n]+~u', ' ', (string)$out['SUBJECT']) ?? '');
		}

		$admin = mf_mail_admin_inbox();
		$toList = mf_mail_normalize_recipient_list(isset($out['TO']) ? (string)$out['TO'] : '');
		if ($toList === [] && !mf_mail_is_client_order_mail($out))
		{
			$toList = [$admin];
		}
		if (count($toList) === 1)
		{
			$out['TO'] = $toList[0];
		}
		else
		{
			$out['TO'] = $toList[0];
			$hdr = isset($out['HEADER']) && is_array($out['HEADER']) ? $out['HEADER'] : [];
			$extra = array_slice($toList, 1);
			$bccAdd = implode(', ', $extra);
			$prev = '';
			foreach (['Bcc', 'BCC', 'bcc'] as $bk)
			{
				if (!empty($hdr[$bk]))
				{
					$prev = trim((string)$hdr[$bk]);
					break;
				}
			}
			$mergedList = mf_mail_normalize_recipient_list(
				($prev !== '' ? $prev . ',' : '') . $bccAdd
			);
			if ($mergedList !== [])
			{
				$hdr['Bcc'] = implode(', ', $mergedList);
			}
			foreach (['BCC', 'bcc'] as $bk)
			{
				unset($hdr[$bk]);
			}
			$out['HEADER'] = $hdr;
		}

		if (isset($out['HEADER']) && is_array($out['HEADER']))
		{
			foreach ($out['HEADER'] as $k => $v)
			{
				if (!is_string($v))
				{
					continue;
				}
				$lk = strtolower((string)$k);
				if (!in_array($lk, ['cc', 'bcc', 'to', 'reply-to'], true))
				{
					continue;
				}
				$norm = mf_mail_normalize_recipient_list($v);
				if ($norm === [])
				{
					unset($out['HEADER'][$k]);
					continue;
				}
				// To задаётся только через $mail['TO'] / аргумент mail(); дубли в HEADER ломают часть MTA при релее.
				if ($lk === 'to')
				{
					unset($out['HEADER'][$k]);
					continue;
				}
				$out['HEADER'][$k] = implode(', ', $norm);
			}
		}

		$hdrFinal = isset($out['HEADER']) && is_array($out['HEADER']) ? $out['HEADER'] : [];
		foreach (array_keys($hdrFinal) as $hk)
		{
			if (strtolower((string)$hk) === 'to')
			{
				unset($hdrFinal[$hk]);
			}
		}
		// Bitrix по умолчанию пишет «3 (Normal)» — Mail.ru при релее на Gmail часто отвечает 550 invalid headers.
		$hdrFinal['X-Priority'] = '3';
		$out['HEADER'] = $hdrFinal;

		$rcpts = mf_mail_collect_recipient_emails($out);
		if (mf_mail_targets_gmail($rcpts))
		{
			unset($out['TRACK_READ'], $out['TRACK_CLICK']);
		}

		return mf_mail_apply_smtp_profile($out);
	}
}

if (!function_exists('mf_mail_register_transport_handlers'))
{
	function mf_mail_register_transport_handlers(): void
	{
		static $registered = false;
		if ($registered || !class_exists(\Bitrix\Main\EventManager::class))
		{
			return;
		}
		$registered = true;

		\Bitrix\Main\EventManager::getInstance()->addEventHandler(
			'main',
			'OnBeforePhpMail',
			static function (\Bitrix\Main\Event $event): void {
				$args = $event->getParameter('arguments');
				if (!is_object($args))
				{
					return;
				}
				$to = (string)($args->to ?? '');
				$headersStr = isset($args->additional_headers) ? (string)$args->additional_headers : '';
				$profileId = function_exists('mf_mail_resolve_smtp_profile_from_php_mail')
					? mf_mail_resolve_smtp_profile_from_php_mail($to, $headersStr)
					: 'andrey';
				if (function_exists('mf_mail_set_active_smtp_profile'))
				{
					mf_mail_set_active_smtp_profile($profileId);
				}
				$from = function_exists('mf_mail_profile_from')
					? mf_mail_profile_from($profileId)
					: mf_mail_default_from_client();
				$msmtpAccount = function_exists('mf_mail_smtp_msmtp_account')
					? mf_mail_smtp_msmtp_account($profileId)
					: 'andrey';
				if ($from !== '')
				{
					$args->additional_parameters = '-f' . $from . ' -a ' . $msmtpAccount;
				}
				if (isset($args->additional_headers))
				{
					$args->additional_headers = mf_mail_fixup_additional_headers_string($headersStr, $to);
				}
				if (trim((string)getenv('MF_MAIL_DEBUG_HEADERS')) === '1' && isset($args->additional_headers))
				{
					$dump = date('c') . "\n--- mail() to ---\n" . (string)($args->to ?? '')
						. "\n--- additional_parameters ---\n" . (string)($args->additional_parameters ?? '')
						. "\n--- additional_headers ---\n" . (string)$args->additional_headers;
					@file_put_contents(rtrim(sys_get_temp_dir(), '/') . '/mf_mail_last_headers.txt', $dump, LOCK_EX);
				}
			}
		);

		\Bitrix\Main\EventManager::getInstance()->addEventHandler(
			'main',
			'OnBeforeMailSend',
			static function (\Bitrix\Main\Event $event) {
				$params = $event->getParameters();
				$mail = is_array($params) ? array_shift($params) : null;
				if (!is_array($mail))
				{
					return null;
				}

				return new \Bitrix\Main\EventResult(
					\Bitrix\Main\EventResult::SUCCESS,
					mf_mail_sanitize_outgoing_params($mail)
				);
			},
			false,
			100000
		);
	}
}
