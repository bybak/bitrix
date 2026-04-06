<?php
declare(strict_types=1);

// Safety net for sporadic third-party/core redirects to /license_restriction.php.
// Some hits unexpectedly bounce here with 302; in practice repeating the original
// action usually works, so we retry once via Referer and otherwise send user home.

$referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
$currentHost = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
$retryFlag = 'mf_license_retry';

$target = '/';
if ($referer !== '')
{
	$parts = @parse_url($referer);
	$host = strtolower((string)($parts['host'] ?? ''));
	$path = (string)($parts['path'] ?? '');
	$query = [];
	if (!empty($parts['query']))
	{
		parse_str((string)$parts['query'], $query);
	}

	$sameHost = ($host === '' || strtolower($currentHost) === $host);
	$isSelf = ($path === '/license_restriction.php');
	$alreadyRetried = isset($query[$retryFlag]) && (string)$query[$retryFlag] === '1';

	if ($sameHost && !$isSelf && !$alreadyRetried)
	{
		$query[$retryFlag] = '1';
		$qs = http_build_query($query);
		$target = $path !== '' ? $path : '/';
		if ($qs !== '')
		{
			$target .= '?' . $qs;
		}
	}
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $target, true, 302);
exit;

