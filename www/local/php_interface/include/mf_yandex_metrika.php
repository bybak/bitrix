<?php
/**
 * Yandex.Metrika — счётчик посещений.
 * ID счётчика: YANDEX_METRIKA_ID в .env (по умолчанию 31510103).
 * YANDEX_METRIKA_DISABLED=1 — не выводить код (удобно для локальной разработки).
 */
if (!function_exists('mf_yandex_metrika_get_counter_id'))
{
	function mf_yandex_metrika_get_counter_id(): int
	{
		$candidates = [
			(string)(getenv('YANDEX_METRIKA_ID') ?: ''),
			(string)($_ENV['YANDEX_METRIKA_ID'] ?? ''),
			(string)($_SERVER['YANDEX_METRIKA_ID'] ?? ''),
		];
		foreach ($candidates as $v)
		{
			$v = trim($v);
			if ($v !== '' && ctype_digit($v))
			{
				return (int)$v;
			}
		}

		return 31510103;
	}
}

if (!function_exists('mf_yandex_metrika_print_body_script'))
{
	function mf_yandex_metrika_print_body_script(): void
	{
		if (trim((string)(getenv('YANDEX_METRIKA_DISABLED') ?: '')) === '1')
		{
			return;
		}
		if (defined('ADMIN_SECTION') && ADMIN_SECTION === true)
		{
			return;
		}

		$counterId = mf_yandex_metrika_get_counter_id();
		if ($counterId <= 0)
		{
			return;
		}

		$counterIdJs = (int)$counterId;
		$watchUrl = 'https://mc.yandex.ru/watch/' . $counterIdJs;

		echo "<!-- Yandex.Metrika counter -->\n";
		echo "<script type=\"text/javascript\">\n";
		echo "    (function(m,e,t,r,i,k,a){\n";
		echo "        m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};\n";
		echo "        m[i].l=1*new Date();\n";
		echo "        for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}\n";
		echo "        k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)\n";
		echo "    })(window, document,'script','https://mc.yandex.ru/metrika/tag.js',\n";
		echo " 'ym');\n";
		echo "    ym(" . $counterIdJs . ", 'init', {trackHash:true, clickmap:true, referrer: document.referrer, url: location.href, accurateTrackBounce:true, trackLinks:true});\n";
		echo "</script>\n";
		echo '<noscript><div><img src="' . htmlspecialcharsbx($watchUrl) . '" style="position:absolute; left:-9999px;" alt="" /></div></noscript>' . "\n";
		echo "<!-- /Yandex.Metrika counter -->\n";
	}
}
