<?php

if (!function_exists('mf_sitemap_base_url'))
{
	function mf_sitemap_base_url(): string
	{
		$request = \Bitrix\Main\Context::getCurrent()->getRequest();
		$host = trim((string)$request->getHttpHost());
		if ($host === '')
		{
			return 'https://motor-force.ru';
		}

		$scheme = $request->isHttps() ? 'https' : 'http';

		return $scheme . '://' . $host;
	}
}

if (!function_exists('mf_sitemap_xml_escape'))
{
	function mf_sitemap_xml_escape(string $value): string
	{
		return htmlspecialcharsbx($value);
	}
}

if (!function_exists('mf_sitemap_lastmod'))
{
	function mf_sitemap_lastmod($timestamp): string
	{
		$ts = (int)$timestamp;
		if ($ts <= 0)
		{
			$ts = time();
		}

		return date('c', $ts);
	}
}

if (!function_exists('mf_sitemap_add_url'))
{
	function mf_sitemap_add_url(array &$urls, string $loc, string $lastmod = '', string $changefreq = '', string $priority = ''): void
	{
		$loc = trim($loc);
		if ($loc === '' || isset($urls[$loc]))
		{
			return;
		}

		$urls[$loc] = [
			'loc' => $loc,
			'lastmod' => $lastmod,
			'changefreq' => $changefreq,
			'priority' => $priority,
		];
	}
}

if (!function_exists('mf_sitemap_catalog_iblock_id'))
{
	function mf_sitemap_catalog_iblock_id(): int
	{
		return 4;
	}
}

if (!function_exists('mf_sitemap_products_per_file'))
{
	function mf_sitemap_products_per_file(): int
	{
		return 45000;
	}
}

if (!function_exists('mf_sitemap_static_pages'))
{
	function mf_sitemap_static_pages(string $baseUrl): array
	{
		$paths = [
			'/',
			'/products/',
			'/oplata/',
			'/delivery/',
			'/dogovor-oferti/',
			'/faq/',
			'/contacts/',
			'/remont_motorov/',
			'/vikup_mototehniki/',
			'/sotrudnichestvo/',
			'/blog/',
			'/posts/',
			'/search/map/',
		];

		$urls = [];
		foreach ($paths as $path)
		{
			mf_sitemap_add_url($urls, $baseUrl . $path, mf_sitemap_lastmod(time()), 'weekly', $path === '/' ? '1.0' : '0.6');
		}

		return $urls;
	}
}

if (!function_exists('mf_sitemap_catalog_sections'))
{
	function mf_sitemap_catalog_sections(string $baseUrl, int $iblockId): array
	{
		$urls = [];
		if ($iblockId <= 0)
		{
			return $urls;
		}

		$rs = CIBlockSection::GetList(
			['LEFT_MARGIN' => 'ASC'],
			[
				'IBLOCK_ID' => $iblockId,
				'GLOBAL_ACTIVE' => 'Y',
				'!CODE' => false,
			],
			false,
			['ID', 'CODE', 'TIMESTAMP_X']
		);

		while ($section = $rs->Fetch())
		{
			$code = trim((string)($section['CODE'] ?? ''));
			if ($code === '')
			{
				continue;
			}

			$ts = MakeTimeStamp((string)($section['TIMESTAMP_X'] ?? ''));
			mf_sitemap_add_url(
				$urls,
				$baseUrl . '/products/category/' . rawurlencode($code) . '/',
				mf_sitemap_lastmod($ts),
				'weekly',
				'0.7'
			);
		}

		return $urls;
	}
}

if (!function_exists('mf_sitemap_iblock_by_code'))
{
	function mf_sitemap_iblock_by_code(string $code): int
	{
		$code = trim($code);
		if ($code === '')
		{
			return 0;
		}

		$rs = \Bitrix\Iblock\IblockTable::getList([
			'filter' => ['=CODE' => $code, '=ACTIVE' => 'Y'],
			'select' => ['ID'],
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
			'cache' => ['ttl' => 3600],
		]);
		$row = $rs->fetch();

		return $row ? (int)$row['ID'] : 0;
	}
}

if (!function_exists('mf_sitemap_iblock_elements'))
{
	function mf_sitemap_iblock_elements(string $baseUrl, int $iblockId, string $pathPrefix, string $changefreq = 'monthly', string $priority = '0.5'): array
	{
		$urls = [];
		if ($iblockId <= 0)
		{
			return $urls;
		}

		$pathPrefix = '/' . trim($pathPrefix, '/') . '/';

		$rs = CIBlockElement::GetList(
			['ID' => 'ASC'],
			[
				'IBLOCK_ID' => $iblockId,
				'ACTIVE' => 'Y',
				'!CODE' => false,
			],
			false,
			false,
			['ID', 'CODE', 'TIMESTAMP_X', 'DATE_ACTIVE_FROM', 'DATE_CREATE']
		);

		while ($item = $rs->Fetch())
		{
			$code = trim((string)($item['CODE'] ?? ''));
			if ($code === '')
			{
				continue;
			}

			$date = (string)($item['DATE_ACTIVE_FROM'] ?: $item['DATE_CREATE']);
			$ts = MakeTimeStamp($date);
			if ($ts <= 0)
			{
				$ts = MakeTimeStamp((string)($item['TIMESTAMP_X'] ?? ''));
			}

			mf_sitemap_add_url(
				$urls,
				$baseUrl . $pathPrefix . rawurlencode($code) . '/',
				mf_sitemap_lastmod($ts),
				$changefreq,
				$priority
			);
		}

		return $urls;
	}
}

if (!function_exists('mf_sitemap_build_pages_urls'))
{
	function mf_sitemap_build_pages_urls(): array
	{
		$baseUrl = rtrim(mf_sitemap_base_url(), '/');
		$urls = mf_sitemap_static_pages($baseUrl);
		$urls = array_replace($urls, mf_sitemap_catalog_sections($baseUrl, mf_sitemap_catalog_iblock_id()));

		$blogIblockId = mf_sitemap_iblock_by_code('blog_motor_force');
		$urls = array_replace($urls, mf_sitemap_iblock_elements($baseUrl, $blogIblockId, 'blog', 'monthly', '0.6'));

		$postsIblockId = 1;
		$urls = array_replace($urls, mf_sitemap_iblock_elements($baseUrl, $postsIblockId, 'posts', 'monthly', '0.5'));

		return $urls;
	}
}

if (!function_exists('mf_sitemap_count_catalog_products'))
{
	function mf_sitemap_count_catalog_products(): int
	{
		$iblockId = mf_sitemap_catalog_iblock_id();
		if ($iblockId <= 0)
		{
			return 0;
		}

		$rs = CIBlockElement::GetList(
			[],
			[
				'IBLOCK_ID' => $iblockId,
				'ACTIVE' => 'Y',
				'!CODE' => false,
			],
			[],
			false,
			['ID']
		);

		return (int)$rs;
	}
}

if (!function_exists('mf_sitemap_build_product_urls'))
{
	function mf_sitemap_build_product_urls(int $page): array
	{
		$page = max(1, $page);
		$perFile = mf_sitemap_products_per_file();
		$offset = ($page - 1) * $perFile;
		$iblockId = mf_sitemap_catalog_iblock_id();
		$baseUrl = rtrim(mf_sitemap_base_url(), '/');
		$urls = [];

		if ($iblockId <= 0)
		{
			return $urls;
		}

		$rs = CIBlockElement::GetList(
			['ID' => 'ASC'],
			[
				'IBLOCK_ID' => $iblockId,
				'ACTIVE' => 'Y',
				'!CODE' => false,
			],
			false,
			['nPageSize' => $perFile, 'iNumPage' => $page],
			['ID', 'CODE', 'TIMESTAMP_X']
		);

		while ($item = $rs->Fetch())
		{
			$code = trim((string)($item['CODE'] ?? ''));
			if ($code === '')
			{
				continue;
			}

			$ts = MakeTimeStamp((string)($item['TIMESTAMP_X'] ?? ''));
			mf_sitemap_add_url(
				$urls,
				$baseUrl . '/products/' . rawurlencode($code) . '/',
				mf_sitemap_lastmod($ts),
				'weekly',
				'0.8'
			);
		}

		return $urls;
	}
}

if (!function_exists('mf_sitemap_product_files_count'))
{
	function mf_sitemap_product_files_count(): int
	{
		$total = mf_sitemap_count_catalog_products();
		if ($total <= 0)
		{
			return 0;
		}

		return (int)ceil($total / mf_sitemap_products_per_file());
	}
}

if (!function_exists('mf_sitemap_render_urlset'))
{
	function mf_sitemap_render_urlset(array $urls): string
	{
		$lines = [];
		$lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

		foreach ($urls as $entry)
		{
			$lines[] = '  <url>';
			$lines[] = '    <loc>' . mf_sitemap_xml_escape((string)$entry['loc']) . '</loc>';
			if (!empty($entry['lastmod']))
			{
				$lines[] = '    <lastmod>' . mf_sitemap_xml_escape((string)$entry['lastmod']) . '</lastmod>';
			}
			if (!empty($entry['changefreq']))
			{
				$lines[] = '    <changefreq>' . mf_sitemap_xml_escape((string)$entry['changefreq']) . '</changefreq>';
			}
			if (!empty($entry['priority']))
			{
				$lines[] = '    <priority>' . mf_sitemap_xml_escape((string)$entry['priority']) . '</priority>';
			}
			$lines[] = '  </url>';
		}

		$lines[] = '</urlset>';

		return implode("\n", $lines) . "\n";
	}
}

if (!function_exists('mf_sitemap_render_index'))
{
	function mf_sitemap_render_index(array $entries): string
	{
		$lines = [];
		$lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$lines[] = '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

		foreach ($entries as $entry)
		{
			$lines[] = '  <sitemap>';
			$lines[] = '    <loc>' . mf_sitemap_xml_escape((string)$entry['loc']) . '</loc>';
			if (!empty($entry['lastmod']))
			{
				$lines[] = '    <lastmod>' . mf_sitemap_xml_escape((string)$entry['lastmod']) . '</lastmod>';
			}
			$lines[] = '  </sitemap>';
		}

		$lines[] = '</sitemapindex>';

		return implode("\n", $lines) . "\n";
	}
}

if (!function_exists('mf_sitemap_cache_get'))
{
	function mf_sitemap_cache_get(string $cacheId, callable $builder): string
	{
		$cache = \Bitrix\Main\Data\Cache::createInstance();
		$cacheTtl = 3600;
		$cacheDir = '/mf/sitemap';

		if ($cache->initCache($cacheTtl, $cacheId, $cacheDir))
		{
			$vars = $cache->getVars();
			if (!empty($vars['xml']) && is_string($vars['xml']))
			{
				return $vars['xml'];
			}
		}

		if (!$cache->startDataCache())
		{
			return (string)$builder();
		}

		$xml = (string)$builder();
		$cache->endDataCache(['xml' => $xml]);

		return $xml;
	}
}

if (!function_exists('mf_sitemap_generate_index_xml'))
{
	function mf_sitemap_generate_index_xml(): string
	{
		return mf_sitemap_cache_get('mf_sitemap_index_v1', static function (): string {
			\Bitrix\Main\Loader::includeModule('iblock');

			$baseUrl = rtrim(mf_sitemap_base_url(), '/');
			$now = mf_sitemap_lastmod(time());
			$entries = [
				['loc' => $baseUrl . '/sitemap-pages.xml', 'lastmod' => $now],
			];

			$fileCount = mf_sitemap_product_files_count();
			for ($page = 1; $page <= $fileCount; $page++)
			{
				$entries[] = [
					'loc' => $baseUrl . '/sitemap-products-' . $page . '.xml',
					'lastmod' => $now,
				];
			}

			return mf_sitemap_render_index($entries);
		});
	}
}

if (!function_exists('mf_sitemap_generate_pages_xml'))
{
	function mf_sitemap_generate_pages_xml(): string
	{
		return mf_sitemap_cache_get('mf_sitemap_pages_v1', static function (): string {
			\Bitrix\Main\Loader::includeModule('iblock');

			return mf_sitemap_render_urlset(mf_sitemap_build_pages_urls());
		});
	}
}

if (!function_exists('mf_sitemap_generate_products_xml'))
{
	function mf_sitemap_generate_products_xml(int $page): string
	{
		$page = max(1, $page);

		return mf_sitemap_cache_get('mf_sitemap_products_v1_' . $page, static function () use ($page): string {
			\Bitrix\Main\Loader::includeModule('iblock');

			return mf_sitemap_render_urlset(mf_sitemap_build_product_urls($page));
		});
	}
}

if (!function_exists('mf_sitemap_generate_xml'))
{
	function mf_sitemap_generate_xml(string $type = 'index', int $page = 1): string
	{
		if ($type === 'pages')
		{
			return mf_sitemap_generate_pages_xml();
		}

		if ($type === 'products')
		{
			return mf_sitemap_generate_products_xml($page);
		}

		return mf_sitemap_generate_index_xml();
	}
}
