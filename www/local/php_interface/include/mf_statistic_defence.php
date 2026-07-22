<?php

declare(strict_types=1);

/**
 * Защита от активности Bitrix (модуль statistic): не блокировать краулеры и не слать письма о лимите.
 */

if (!function_exists('mf_statistic_crawler_needles'))
{
	/** @return list<string> */
	function mf_statistic_crawler_needles(): array
	{
		return [
			'GPTBot',
			'Googlebot',
			'YandexBot',
			'YandexImages',
			'YandexMobileBot',
			'YandexAccessibilityBot',
			'YandexRenderResourcesBot',
			'bingbot',
			'Applebot',
			'facebookexternalhit',
			'AhrefsBot',
			'SemrushBot',
			'PetalBot',
			'Bytespider',
			'CCBot',
			'ClaudeBot',
			'anthropic-ai',
		];
	}
}

if (!function_exists('mf_statistic_user_agent_is_crawler'))
{
	function mf_statistic_user_agent_is_crawler(string $userAgent): bool
	{
		$userAgent = trim($userAgent);
		if ($userAgent === '')
		{
			return false;
		}

		foreach (mf_statistic_crawler_needles() as $needle)
		{
			if (stripos($userAgent, $needle) !== false)
			{
				return true;
			}
		}

		return false;
	}
}

if (!function_exists('mf_statistic_apply_crawler_activity_skip'))
{
	/** STATISTIC_SKIP_ACTIVITY_CHECK — до расчёта лимита в CStatistics::BlockVisitorActivity(). */
	function mf_statistic_apply_crawler_activity_skip(): void
	{
		if (defined('STATISTIC_SKIP_ACTIVITY_CHECK'))
		{
			return;
		}

		$userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
		if ($userAgent !== '' && mf_statistic_user_agent_is_crawler($userAgent))
		{
			define('STATISTIC_SKIP_ACTIVITY_CHECK', true);
		}
	}
}

if (!function_exists('mf_statistic_disable_keep_statistics'))
{
	/** Не писать в b_stat_page / b_stat_hit на этом запросе. */
	function mf_statistic_disable_keep_statistics(): void
	{
		if (!defined('NO_KEEP_STATISTIC'))
		{
			define('NO_KEEP_STATISTIC', true);
		}
		if (!defined('STOP_STATISTICS'))
		{
			define('STOP_STATISTICS', true);
		}
		if (!defined('NO_AGENT_STATISTIC'))
		{
			define('NO_AGENT_STATISTIC', true);
		}
		mf_statistic_apply_crawler_activity_skip();
	}
}

if (!function_exists('mf_statistic_request_path'))
{
	function mf_statistic_request_path(): string
	{
		$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
		$path = (string)(parse_url($uri, PHP_URL_PATH) ?? '/');
		if ($path === '')
		{
			return '/';
		}

		return $path;
	}
}

if (!function_exists('mf_statistic_should_skip_keep_statistics'))
{
	/**
	 * Карточки каталога и краулеры — не собирать статистику Bitrix (b_stat_page).
	 * Иначе боты по /products/* создают сотни тысяч строк/день и убивают MySQL.
	 */
	function mf_statistic_should_skip_keep_statistics(): bool
	{
		$userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
		if ($userAgent !== '' && mf_statistic_user_agent_is_crawler($userAgent))
		{
			return true;
		}

		$path = mf_statistic_request_path();
		if ($path === '/products' || str_starts_with($path, '/products/'))
		{
			return true;
		}

		return false;
	}
}

if (!function_exists('mf_statistic_apply_request_skip'))
{
	function mf_statistic_apply_request_skip(): void
	{
		if (!mf_statistic_should_skip_keep_statistics())
		{
			mf_statistic_apply_crawler_activity_skip();

			return;
		}

		mf_statistic_disable_keep_statistics();
	}
}

if (!function_exists('mf_statistic_ensure_bot_searchers_no_activity_check'))
{
	/**
	 * Один раз: для обобщённого поисковика «bot» (ID 185) и GPTBot — как у Google/Yandex, без лимита активности.
	 */
	function mf_statistic_ensure_bot_searchers_no_activity_check(): void
	{
		if (!class_exists(\Bitrix\Main\Config\Option::class))
		{
			return;
		}

		$optKey = 'mf_stat_bot_activity_check_v1';
		if (\Bitrix\Main\Config\Option::get('main', $optKey, '') === 'Y')
		{
			return;
		}

		global $DB;
		if (!is_object($DB) || !method_exists($DB, 'Query'))
		{
			return;
		}

		$DB->Query("
			UPDATE b_stat_searcher
			SET CHECK_ACTIVITY = 'N'
			WHERE ID = 185
				OR NAME LIKE '%GPTBot%'
				OR USER_AGENT LIKE '%GPTBot%'
		");

		\Bitrix\Main\Config\Option::set('main', $optKey, 'Y');
	}
}

if (!function_exists('mf_statistic_should_suppress_activity_exceeding_mail'))
{
	/**
	 * @param array<string, mixed> $fields
	 */
	function mf_statistic_should_suppress_activity_exceeding_mail(array $fields): bool
	{
		$userAgent = (string)($fields['USER_AGENT'] ?? '');
		if ($userAgent !== '' && mf_statistic_user_agent_is_crawler($userAgent))
		{
			return true;
		}

		$searcherId = (int)($fields['SERACHER_ID'] ?? $fields['SEARCHER_ID'] ?? 0);
		if ($searcherId > 0)
		{
			return true;
		}

		$searcherName = mb_strtolower(trim((string)($fields['SEARCHER_NAME'] ?? '')));
		if ($searcherName === 'bot' || strpos($searcherName, 'gptbot') !== false)
		{
			return true;
		}

		return false;
	}
}
