<?php
/**
 * MF: синхронизация SEO из motor-force.ru для статических страниц верхнего меню (кроме /products/*).
 *
 * Режимы:
 * - dry-run (по умолчанию): печатает JSON с извлечёнными данными
 * - --apply: перезаписывает /mf_seo_map.php
 *
 * Запуск (в докере):
 * docker compose exec -T php php /var/www/html/mf_sync_seo_from_motorforce.php --apply
 */

declare(strict_types=1);

const MF_SEO_SOURCE_HOST = 'https://motor-force.ru';
const MF_SEO_MAP_FILE = __DIR__ . '/mf_seo_map.php';

function mf_arg_has(string $name): bool
{
	global $argv;
	return is_array($argv) && in_array($name, $argv, true);
}

function mf_http_get(string $url): string
{
	$cmd = [
		'curl',
		'-L',
		'-s',
		'--compressed',
		'-m',
		'25',
		'-H',
		'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
		$url,
	];
	$proc = proc_open($cmd, [
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	], $pipes);
	if (!is_resource($proc))
	{
		throw new RuntimeException('Не удалось запустить curl');
	}
	$stdout = stream_get_contents($pipes[1]) ?: '';
	$stderr = stream_get_contents($pipes[2]) ?: '';
	fclose($pipes[1]);
	fclose($pipes[2]);
	$code = proc_close($proc);
	if ($code !== 0)
	{
		throw new RuntimeException('curl завершился с кодом ' . $code . ': ' . trim($stderr));
	}
	return (string)$stdout;
}

function mf_html_unescape_iter(string $s): string
{
	$prev = null;
	for ($i = 0; $i < 5; $i++)
	{
		$next = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if ($next === $s)
		{
			break;
		}
		$s = $next;
	}
	return $s;
}

function mf_extract_title(string $html): string
{
	if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m))
	{
		return trim(mf_html_unescape_iter((string)$m[1]));
	}
	return '';
}

function mf_extract_link_rel(string $html, string $rel): string
{
	if (!preg_match_all('~<link\\b[^>]*>~is', $html, $mm))
	{
		return '';
	}
	foreach ($mm[0] as $tag)
	{
		if (!preg_match('~\\brel\\s*=\\s*("|\')([^"\']*)\\1~is', $tag, $rm))
		{
			continue;
		}
		$rels = preg_split('~\\s+~', strtolower((string)$rm[2])) ?: [];
		if (!in_array(strtolower($rel), $rels, true))
		{
			continue;
		}
		if (preg_match('~\\bhref\\s*=\\s*("|\')([^"\']*)\\1~is', $tag, $hm))
		{
			return trim(mf_html_unescape_iter((string)$hm[2]));
		}
	}
	return '';
}

function mf_extract_meta(string $html, string $attr, string $attrValue): string
{
	if (!preg_match_all('~<meta\\b[^>]*>~is', $html, $mm))
	{
		return '';
	}
	foreach ($mm[0] as $tag)
	{
		if (!preg_match('~\\b' . preg_quote($attr, '~') . '\\s*=\\s*("|\')' . preg_quote($attrValue, '~') . '\\1~is', $tag))
		{
			continue;
		}
		if (preg_match('~\\bcontent\\s*=\\s*("|\')([\\s\\S]*?)\\1~is', $tag, $cm))
		{
			// В OG часто бывают переводы строк внутри content="...".
			return trim(mf_html_unescape_iter((string)$cm[2]));
		}
	}
	return '';
}

function mf_extract_og_all(string $html): array
{
	$out = [];
	if (!preg_match_all('~<meta\\b[^>]*>~is', $html, $mm))
	{
		return $out;
	}
	foreach ($mm[0] as $tag)
	{
		if (!preg_match('~\\bproperty\\s*=\\s*("|\')(og:[^"\']+)\\1~is', $tag, $pm))
		{
			continue;
		}
		if (!preg_match('~\\bcontent\\s*=\\s*("|\')([\\s\\S]*?)\\1~is', $tag, $cm))
		{
			continue;
		}
		$prop = (string)$pm[2];
		$val = trim(mf_html_unescape_iter((string)$cm[2]));
		$out[$prop] = $val;
	}
	return $out;
}

function mf_target_map(): array
{
	// our_path => source_path
	return [
		'/' => '/',
		'/oplata/' => '/oplata',
		'/delivery/' => '/delivery',
		'/dogovor-oferti/' => '/dogovor-oferti',
		'/remont_motorov/' => '/remont_motorov',
		'/vikup_mototehniki/' => '/vikup_mototehniki',
		'/prokat/' => '/prokat',
		'/documents/' => '/documents',
		'/sotrudnichestvo/' => '/sotrudnichestvo',
		'/faq/' => '/faq',
		'/contacts/' => '/contacts',
		'/posts/' => '/posts',
		'/blog/' => '/articles', // у них это пункт меню “Блог”
	];
}

function mf_build_map(): array
{
	$map = [];
	foreach (mf_target_map() as $ourPath => $srcPath)
	{
		$srcUrl = rtrim(MF_SEO_SOURCE_HOST, '/') . $srcPath;
		$html = mf_http_get($srcUrl);
		$map[$ourPath] = [
			'source_url' => $srcUrl,
			'title' => mf_extract_title($html),
			'description' => mf_extract_meta($html, 'name', 'description'),
			'keywords' => mf_extract_meta($html, 'name', 'keywords'),
		];

		$og = mf_extract_og_all($html);
		if ($og)
		{
			$map[$ourPath]['og'] = $og;
		}
	}
	return $map;
}

function mf_php_export(array $map): string
{
	// Возвращаем как “return array (...)”, чтобы файл был валидным без доп. форматирования.
	$export = var_export($map, true);
	return "<?php\n/**\n * Автогенерируемая карта SEO для статических страниц верхнего меню (кроме /products/*).\n * Источник: motor-force.ru (title/description/keywords + OG, если есть).\n *\n * Файл перезаписывается скриптом /mf_sync_seo_from_motorforce.php.\n */\nreturn " . $export . ";\n";
}

function mf_main(): int
{
	$apply = mf_arg_has('--apply');
	$map = mf_build_map();

	if (!$apply)
	{
		echo json_encode($map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		return 0;
	}

	$php = mf_php_export($map);
	if (file_put_contents(MF_SEO_MAP_FILE, $php) === false)
	{
		fwrite(STDERR, "ОШИБКА: не удалось записать " . MF_SEO_MAP_FILE . "\n");
		return 2;
	}

	echo "OK: обновлён файл " . MF_SEO_MAP_FILE . "\n";
	return 0;
}

try
{
	exit(mf_main());
}
catch (Throwable $e)
{
	fwrite(STDERR, "ОШИБКА: " . $e->getMessage() . "\n");
	exit(1);
}

