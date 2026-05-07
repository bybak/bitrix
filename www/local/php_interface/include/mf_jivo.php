<?php
/**
 * JivoSite / Jivo: виджет онлайн-чата на сайте.
 *
 * Логин и пароль из .env — только для входа в https://app.jivosite.com (или app.jivo.ru).
 * На страницу подставляется только публичный ID виджета (фрагмент URL в коде установки).
 */
if (!function_exists('mf_jivo_get_widget_id'))
{
	function mf_jivo_get_widget_id(): string
	{
		$candidates = [
			(string)(getenv('JIVO_WIDGET_ID') ?: ''),
			(string)(getenv('JIVOSITE_WIDGET_ID') ?: ''),
			(string)($_ENV['JIVO_WIDGET_ID'] ?? ''),
			(string)($_SERVER['JIVO_WIDGET_ID'] ?? ''),
		];
		foreach ($candidates as $v)
		{
			$v = trim($v);
			if ($v !== '')
			{
				return $v;
			}
		}
		if (class_exists(\Bitrix\Main\Config\Option::class))
		{
			try
			{
				$v = trim((string)\Bitrix\Main\Config\Option::get('main', 'mf_jivo_widget_id', ''));
				if ($v !== '')
				{
					return $v;
				}
			}
			catch (\Throwable $e)
			{
				// ignore
			}
		}

		$url = trim((string)(getenv('JIVO_WIDGET_SCRIPT_URL') ?: ''));
		if ($url !== '' && preg_match('~/script/widget/([a-zA-Z0-9_-]+)~', $url, $m))
		{
			return (string)$m[1];
		}

		return '';
	}
}

if (!function_exists('mf_jivo_print_body_script'))
{
	function mf_jivo_print_body_script(): void
	{
		if (trim((string)(getenv('JIVO_DISABLED') ?: '')) === '1')
		{
			return;
		}

		$id = mf_jivo_get_widget_id();
		if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]{4,80}$/', $id))
		{
			return;
		}

		$src = 'https://code.jivosite.com/script/widget/' . rawurlencode($id);
		echo '<script async src="' . htmlspecialcharsbx($src) . '"></script>' . "\n";
	}
}
