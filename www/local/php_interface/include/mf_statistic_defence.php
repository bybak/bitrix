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
