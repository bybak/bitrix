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
		mf_jivo_print_canonical_page_script();
	}
}

if (!function_exists('mf_jivo_print_canonical_page_script'))
{
	/**
	 * В письмах Jivo уходит document.location — приводим путь каталога к виду со слэшем.
	 */
	function mf_jivo_print_canonical_page_script(): void
	{
		?>
<script>
(function () {
	function mfCatalogPathNeedsSlash(path) {
		if (!path || path.charAt(path.length - 1) === '/') return false;
		if (path === '/products') return true;
		if (/^\/products\/search\/?$/.test(path)) return false;
		if (/^\/products\/category\/[^/]+$/.test(path)) return true;
		if (/^\/products\/[^/]+$/.test(path)) return true;
		return false;
	}
	function mfCanonicalCatalogUrl() {
		var loc = window.location;
		var path = loc.pathname || '/';
		if (!mfCatalogPathNeedsSlash(path)) {
			return loc.href;
		}
		return loc.origin + path + '/' + loc.search + loc.hash;
	}
	try {
		var path = window.location.pathname || '/';
		if (mfCatalogPathNeedsSlash(path)) {
			var fixed = path + '/' + (window.location.search || '') + (window.location.hash || '');
			history.replaceState(null, '', fixed);
		}
	} catch (e) {}
	function mfJivoPatchPageUrl() {
		try {
			if (!window.jivo_api || typeof window.jivo_api.setCustomData !== 'function') {
				return;
			}
			var url = mfCanonicalCatalogUrl();
			if (!url) return;
			window.jivo_api.setCustomData([{title: 'Страница', content: url}]);
		} catch (e2) {}
	}
	window.jivo_onLoadCallback = (function (prev) {
		return function () {
			if (typeof prev === 'function') {
				try { prev(); } catch (e3) {}
			}
			mfJivoPatchPageUrl();
		};
	})(window.jivo_onLoadCallback);
	if (window.jivo_api) {
		mfJivoPatchPageUrl();
	}
})();
</script>
<?php
	}
}
