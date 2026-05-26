<?php
declare(strict_types=1);

namespace Mf\SiteMail;

use Bitrix\Main\EventManager;

final class Bootstrap
{
	private static bool $inited = false;

	public static function init(): void
	{
		if (self::$inited)
		{
			return;
		}
		self::$inited = true;

		EventManager::getInstance()->addEventHandler(
			'main',
			'OnBeforeEventSend',
			[Handlers::class, 'onBeforeEventSend']
		);
	}
}

final class Handlers
{
	public static function onBeforeEventSend(array &$fields, array &$eventMessage, $context, array &$result): void
	{
		unset($context, $result);

		if (($eventMessage['EVENT_NAME'] ?? '') !== 'NEW_USER')
		{
			return;
		}

		$serverName = trim((string)($fields['SERVER_NAME'] ?? Renderer::SITE_HOST));
		if ($serverName === '' || $serverName === '_')
		{
			$serverName = Renderer::SITE_HOST;
		}

		$rows = [
			['ID пользователя', (string)($fields['USER_ID'] ?? '')],
			['Имя', (string)($fields['NAME'] ?? '')],
			['Фамилия', (string)($fields['LAST_NAME'] ?? '')],
			['E-Mail', (string)($fields['EMAIL'] ?? '')],
			['Login', (string)($fields['LOGIN'] ?? '')],
		];

		$html = Renderer::render(
			'Зарегистрировался новый пользователь',
			'На сайте ' . $serverName . ' успешно зарегистрирован новый пользователь.',
			$rows,
			['Письмо сгенерировано автоматически.'],
			false
		);

		$eventMessage['BODY_TYPE'] = 'html';
		$eventMessage['SITE_TEMPLATE_ID'] = '';
		$eventMessage['MESSAGE'] = $html;
		unset($eventMessage['MESSAGE_PHP']);
	}
}

final class Renderer
{
	public const SITE_HOST = 'motor-force.ru';

	private const COLOR_TITLE = '#1a73b8';
	private const COLOR_SECTION = '#e65100';
	private const COLOR_LINK = '#1a73b8';
	private const COLOR_BORDER = '#dddddd';

	/**
	 * @param list<string> $introLines
	 * @param list<array{0:string,1:string}> $rows
	 */
	public static function render(
		string $title,
		string $subtitle,
		array $rows,
		array $introLines = [],
		bool $withHoursFooter = true
	): string
	{
		$siteUrl = self::siteUrl();
		$siteLink = '<a href="' . self::esc($siteUrl) . '" style="color:' . self::COLOR_LINK . ';text-decoration:underline;">'
			. self::esc(self::SITE_HOST) . '</a>';

		$html = [];
		$html[] = self::wrapOpen();
		$html[] = self::block(
			'<div style="text-align:center;margin:0 0 18px 0;">'
			. '<div style="font-size:24px;font-weight:bold;color:' . self::COLOR_TITLE . ';margin:0 0 8px 0;">'
			. self::esc($title)
			. '</div>'
			. '<div style="font-size:14px;color:#333;">' . self::esc($subtitle) . '</div>'
			. '<div style="font-size:13px;color:#666;margin-top:6px;">Сообщение с сайта ' . $siteLink . '</div>'
			. '</div>'
		);

		if (!empty($introLines))
		{
			$introHtml = [];
			foreach ($introLines as $line)
			{
				$line = trim($line);
				if ($line === '')
				{
					continue;
				}
				$introHtml[] = self::esc($line);
			}
			if (!empty($introHtml))
			{
				$html[] = self::block(
					'<div style="font-size:14px;line-height:1.6;color:#333;">'
					. implode('<br>', $introHtml)
					. '</div>'
				);
			}
		}

		$html[] = self::sectionTitle('Данные');
		$html[] = self::kvTable($rows);

		if ($withHoursFooter)
		{
			$html[] = self::hoursFooter();
		}

		$html[] = self::wrapClose();

		return implode("\n", $html);
	}

	public static function siteUrl(): string
	{
		if (class_exists(\CSite::class) && defined('SITE_ID'))
		{
			$site = \CSite::GetByID((string)SITE_ID)->Fetch();
			if (is_array($site))
			{
				$serverName = trim((string)($site['SERVER_NAME'] ?? ''));
				if ($serverName !== '' && $serverName !== '_' && str_contains($serverName, '.'))
				{
					return 'https://' . $serverName;
				}
			}
		}

		return 'https://' . self::SITE_HOST;
	}

	public static function absoluteUrl(string $url): string
	{
		$url = trim($url);
		if ($url === '' || $url === '—')
		{
			return $url;
		}
		if (preg_match('~^https?://~i', $url))
		{
			return $url;
		}

		$base = rtrim(self::siteUrl(), '/');
		if ($url[0] !== '/')
		{
			$url = '/' . $url;
		}

		return $base . $url;
	}

	/** @param list<array{0:string,1:string}> $rows */
	private static function kvTable(array $rows): string
	{
		$body = '';
		foreach ($rows as $row)
		{
			if (!is_array($row) || count($row) < 2)
			{
				continue;
			}
			$label = trim((string)$row[0]);
			$value = trim((string)$row[1]);
			if ($label === '' || $value === '')
			{
				continue;
			}

			$valueHtml = self::valueHtml($label, $value);
			$body .= '<tr>'
				. '<td style="padding:8px 10px;border-bottom:1px solid ' . self::COLOR_BORDER . ';width:45%;color:#555;vertical-align:top;">'
				. self::esc($label)
				. '</td>'
				. '<td style="padding:8px 10px;border-bottom:1px solid ' . self::COLOR_BORDER . ';vertical-align:top;">'
				. $valueHtml
				. '</td></tr>';
		}

		if ($body === '')
		{
			$body = '<tr><td colspan="2" style="padding:10px;color:#666;">Нет данных</td></tr>';
		}

		return self::block(
			'<table cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;font-size:14px;">'
			. $body
			. '</table>'
		);
	}

	private static function valueHtml(string $label, string $value): string
	{
		if ($label === 'E-mail' && filter_var($value, FILTER_VALIDATE_EMAIL))
		{
			return '<a href="mailto:' . self::esc($value) . '" style="color:' . self::COLOR_LINK . ';">' . self::esc($value) . '</a>';
		}

		if (in_array($label, ['Ссылка', 'Страница'], true))
		{
			$value = self::absoluteUrl($value);
			if (preg_match('~^https?://~i', $value))
			{
				return '<a href="' . self::esc($value) . '" style="color:' . self::COLOR_LINK . ';text-decoration:underline;">'
					. self::esc($value) . '</a>';
			}
		}

		if (str_contains($value, "\n"))
		{
			return nl2br(self::esc($value));
		}

		return self::esc($value);
	}

	private static function sectionTitle(string $title): string
	{
		return self::block(
			'<div style="font-size:16px;font-weight:bold;color:' . self::COLOR_SECTION . ';margin:0 0 10px 0;">'
			. self::esc($title)
			. '</div>'
		);
	}

	private static function hoursFooter(): string
	{
		return self::block(
			'<div style="text-align:center;font-weight:bold;margin:0 0 6px 0;">Часы работы:</div>'
			. '<div style="text-align:center;line-height:1.5;margin:0;color:#333;">'
			. 'Пн-Чт с 10:00 до 18:00;<br>'
			. 'Пт с 10:00 до 17:00;<br>'
			. 'Сб-Вс - Выходной.'
			. '</div>'
		);
	}

	private static function wrapOpen(): string
	{
		return '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>'
			. '<body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#222;">'
			. '<table cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td align="center" style="padding:16px 8px;">'
			. '<table cellpadding="0" cellspacing="0" border="0" width="680" style="max-width:680px;width:100%;">';
	}

	private static function wrapClose(): string
	{
		return '</table></td></tr></table></body></html>';
	}

	private static function block(string $inner): string
	{
		return '<tr><td style="padding:0 0 16px 0;">' . $inner . '</td></tr>';
	}

	private static function esc(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}

if (!function_exists('mf_site_mail_send_html'))
{
	/**
	 * @param array<string, string> $header
	 */
	function mf_site_mail_send_html(string $to, string $subject, string $htmlBody, array $header = []): bool
	{
		if (!class_exists(\Bitrix\Main\Mail\Mail::class))
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
