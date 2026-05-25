<?php
declare(strict_types=1);

if (!function_exists('mf_ajax_session_release'))
{
	/**
	 * Снимает блокировку сессии, чтобы параллельные AJAX не ждали друг друга.
	 * Вызывать после чтения данных из сессии (Fuser и т.п.).
	 */
	function mf_ajax_session_release(): void
	{
		if (function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE)
		{
			session_write_close();
		}
	}
}
