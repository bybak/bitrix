<?php

declare(strict_types=1);

/**
 * Лог обмена 1С — только local/, не зависит от правок в bitrix/modules.
 */

if (!function_exists('mf1c_exchange_www_root'))
{
	function mf1c_exchange_www_root(): string
	{
		static $root = null;
		if (is_string($root))
		{
			return $root;
		}

		$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
		$localRoot = dirname(__DIR__, 3);
		$root = ($docRoot !== '' && is_dir($docRoot)) ? $docRoot : $localRoot;

		return $root;
	}
}

if (!function_exists('mf1c_exchange_upload_file_candidates'))
{
	function mf1c_exchange_upload_file_candidates(string $exchangeFilename): array
	{
		$exchangeFilename = basename($exchangeFilename);
		$candidates = [];
		$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
		if ($docRoot !== '')
		{
			$candidates[] = $docRoot . '/upload/1c_exchange/' . $exchangeFilename;
		}
		$candidates[] = dirname(__DIR__, 3) . '/upload/1c_exchange/' . $exchangeFilename;
		$candidates[] = '/var/www/html/upload/1c_exchange/' . $exchangeFilename;
		$candidates[] = '/www/bitrix_motor_force/www/upload/1c_exchange/' . $exchangeFilename;
		$candidates[] = mf1c_exchange_www_root() . '/upload/1c_exchange/' . $exchangeFilename;

		return array_values(array_unique(array_filter($candidates)));
	}
}

if (!function_exists('mf1c_exchange_resolve_upload_file'))
{
	function mf1c_exchange_resolve_upload_file(string $exchangeFilename): string
	{
		foreach (mf1c_exchange_upload_file_candidates($exchangeFilename) as $candidate)
		{
			if (is_file($candidate))
			{
				return $candidate;
			}
		}

		return '';
	}
}

if (!function_exists('mf_1c_exchange_debug_log_path'))
{
	function mf_1c_exchange_debug_log_path(): string
	{
		if (function_exists('mf1c_exchange_debug_log_file'))
		{
			return mf1c_exchange_debug_log_file();
		}

		$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
		$root = ($docRoot !== '' && is_dir($docRoot)) ? $docRoot : dirname(__DIR__, 3);

		return $root . '/upload/1c_exchange_debug.log';
	}
}

if (!function_exists('mf_1c_exchange_debug_write'))
{
	function mf_1c_exchange_debug_write(string $message): void
	{
		if (function_exists('mf1c_exchange_debug_log'))
		{
			mf1c_exchange_debug_log($message);
			return;
		}

		$line = date('Y-m-d H:i:s') . ' ' . $message . "\n";
		$logFile = mf_1c_exchange_debug_log_path();
		$dir = dirname($logFile);
		if (!is_dir($dir))
		{
			@mkdir($dir, 0775, true);
		}
		if (is_file($logFile) && !is_writable($logFile))
		{
			@chmod($logFile, 0666);
		}
		if (file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX) === false)
		{
			file_put_contents(dirname($logFile) . '/1c_exchange_debug.fpm.log', $line, FILE_APPEND);
		}
	}
}

if (!function_exists('mf_1c_import_log'))
{
	function mf_1c_import_log(string $message): void
	{
		if (function_exists('mf1c_exchange_debug_log'))
		{
			mf1c_exchange_debug_log($message);
			return;
		}
		mf_1c_exchange_debug_write($message);
	}
}

if (!function_exists('mf_1c_exchange_debug_hit'))
{
	function mf_1c_exchange_debug_hit(string $stage): void
	{
		$type = (string)($_REQUEST['type'] ?? '');
		$mode = (string)($_REQUEST['mode'] ?? '');
		$filename = (string)($_REQUEST['filename'] ?? '');
		$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');

		mf_1c_exchange_debug_write(
			'HIT[' . $stage . '] ' . $method
			. ' type=' . $type
			. ' mode=' . $mode
			. ' filename=' . $filename
			. ' script=' . (string)($_SERVER['SCRIPT_NAME'] ?? '')
		);
	}
}

if (!function_exists('mf_1c_exchange_log_request'))
{
	function mf_1c_exchange_log_request(): void
	{
		static $done = false;
		if ($done)
		{
			return;
		}
		$done = true;

		$request = $_REQUEST;
		foreach (['USER_PASSWORD', 'PASSWORD', 'USER_PASS'] as $secretKey)
		{
			if (isset($request[$secretKey]))
			{
				$request[$secretKey] = '***';
			}
		}

		mf_1c_exchange_debug_write('EXCHANGE REQUEST: ' . print_r($request, true));

		if (!empty($_FILES))
		{
			mf_1c_exchange_debug_write('EXCHANGE FILES: ' . print_r($_FILES, true));
		}
	}
}

if (!function_exists('mf_1c_import_log_xml_dump'))
{
	function mf_1c_import_log_xml_dump(string $filePath, ?string $xmlString = null): void
	{
		static $dumped = [];
		$filePath = trim($filePath);
		$dumpKey = $filePath . '|' . ($xmlString === null ? 'file' : 'mem');
		if (isset($dumped[$dumpKey]))
		{
			return;
		}
		$dumped[$dumpKey] = true;
		if ($xmlString === null)
		{
			if ($filePath === '' || !is_file($filePath))
			{
				mf_1c_exchange_debug_write('XML DUMP: file not found ' . $filePath);
				return;
			}
			$xmlString = file_get_contents($filePath);
		}

		if ($xmlString === false || trim((string)$xmlString) === '')
		{
			mf_1c_exchange_debug_write('XML DUMP: empty content for ' . basename($filePath));
			return;
		}

		$maxLen = 512000;
		$size = strlen((string)$xmlString);
		$body = (string)$xmlString;
		if ($size > $maxLen)
		{
			$body = substr($body, 0, $maxLen) . "\n... [truncated, total " . $size . " bytes]";
		}

		mf_1c_exchange_debug_write(
			"==================== XML DUMP: " . basename($filePath) . " (" . $size . " bytes) ====================\n"
			. $body
			. "\n================== END XML DUMP =================="
		);
	}
}

if (!function_exists('mf_1c_exchange_log_file_shutdown'))
{
	function mf_1c_exchange_log_file_shutdown(string $filePath): void
	{
		register_shutdown_function(static function () use ($filePath): void {
			mf_1c_import_log_xml_dump($filePath);
		});
	}
}

if (!function_exists('mf_on_1c_exchange_debug_log'))
{
	/**
	 * OnBeforeProlog: лог каждого запроса к 1c_exchange.php (деплой через local/).
	 */
	function mf_on_1c_exchange_debug_log(): void
	{
		if (PHP_SAPI === 'cli')
		{
			return;
		}

		$script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
		if (stripos($script, '1c_exchange.php') === false)
		{
			return;
		}

		mf_1c_exchange_debug_hit('prolog');
		mf_1c_exchange_log_request();

		$mode = (string)($_REQUEST['mode'] ?? '');
		$filename = !empty($_REQUEST['filename']) ? basename((string)$_REQUEST['filename']) : '';
		if ($filename === '')
		{
			return;
		}

		if ($mode === 'file' || $mode === 'import')
		{
			register_shutdown_function(static function () use ($filename, $mode): void {
				if (!function_exists('mf1c_exchange_resolve_upload_file'))
				{
					return;
				}
				$exchangeFile = mf1c_exchange_resolve_upload_file($filename);
				if ($exchangeFile === '')
				{
					mf_1c_exchange_debug_write('XML DUMP shutdown: not found mode=' . $mode . ' filename=' . $filename);
					return;
				}
				mf_1c_import_log_xml_dump($exchangeFile);
			});
		}

	}
}
