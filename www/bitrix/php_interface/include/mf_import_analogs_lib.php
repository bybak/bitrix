<?php

declare(strict_types=1);

/**
 * Shared helpers for analog imports (admin pages, CLI).
 * No admin guards here by design.
 */

function mf_analogs_to_utf8(string $s): string
{
	$s = (string)$s;
	if ($s === '')
	{
		return '';
	}
	if (mb_check_encoding($s, 'UTF-8'))
	{
		return $s;
	}
	$converted = @iconv('CP1251', 'UTF-8//IGNORE', $s);
	return is_string($converted) && $converted !== '' ? $converted : $s;
}

function mf_analogs_norm_article(string $s): string
{
	$s = mb_strtoupper(trim((string)$s));
	$s = preg_replace('~[^A-Z0-9]+~', '', $s) ?? '';
	return $s;
}

function mf_analogs_norm_brand(string $s): string
{
	$s = mb_strtoupper(trim((string)$s));
	$s = str_replace('Ё', 'Е', $s);
	$s = preg_replace('~[^A-ZА-Я0-9]+~u', '', $s) ?? '';
	return $s;
}

function mf_analogs_find_product_id_by_brand_article(int $iblockId, string $brand, string $article): ?int
{
	$brandNorm = mf_analogs_norm_brand($brand);
	$articleNorm = mf_analogs_norm_article($article);
	if ($brandNorm === '' || $articleNorm === '')
	{
		return null;
	}

	// 1) Strict match by normalized properties.
	$r = \CIBlockElement::GetList(
		[],
		[
			'=IBLOCK_ID' => $iblockId,
			'=ACTIVE' => 'Y',
			'=PROPERTY_MF_BRAND_NORM' => $brandNorm,
			'=PROPERTY_MF_ARTICLE_NORM' => $articleNorm,
		],
		false,
		['nTopCount' => 1],
		['ID']
	)->Fetch();
	if ($r && (int)$r['ID'] > 0)
	{
		return (int)$r['ID'];
	}

	// 2) Fallback: match by article only, then choose best candidate by brand "contains".
	$candidates = [];
	$rs = \CIBlockElement::GetList(
		['ID' => 'ASC'],
		[
			'=IBLOCK_ID' => $iblockId,
			'=ACTIVE' => 'Y',
			'=PROPERTY_MF_ARTICLE_NORM' => $articleNorm,
		],
		false,
		['nTopCount' => 5],
		['ID', 'PROPERTY_MF_BRAND', 'PROPERTY_MF_BRAND_NORM', 'PROPERTY_CML2_ARTICLE']
	);
	while ($x = $rs->Fetch())
	{
		$id = (int)($x['ID'] ?? 0);
		if ($id <= 0) continue;
		$candidates[] = $x;
	}
	if (empty($candidates))
	{
		$rs = \CIBlockElement::GetList(
			['ID' => 'ASC'],
			[
				'=IBLOCK_ID' => $iblockId,
				'=ACTIVE' => 'Y',
				'=PROPERTY_CML2_ARTICLE' => $article,
			],
			false,
			['nTopCount' => 5],
			['ID', 'PROPERTY_MF_BRAND', 'PROPERTY_MF_BRAND_NORM', 'PROPERTY_CML2_ARTICLE']
		);
		while ($x = $rs->Fetch())
		{
			$id = (int)($x['ID'] ?? 0);
			if ($id <= 0) continue;
			$candidates[] = $x;
		}
	}

	if (!empty($candidates))
	{
		$brandLower = mb_strtolower(trim($brand));
		$bestId = 0;
		$bestScore = -1;
		foreach ($candidates as $x)
		{
			$id = (int)($x['ID'] ?? 0);
			if ($id <= 0) continue;
			$bn = (string)($x['PROPERTY_MF_BRAND_NORM_VALUE'] ?? '');
			$br = (string)($x['PROPERTY_MF_BRAND_VALUE'] ?? '');
			$score = 0;
			if ($bn !== '' && str_contains($bn, $brandNorm)) $score += 2;
			if ($brandLower !== '' && $br !== '' && str_contains(mb_strtolower($br), $brandLower)) $score += 1;
			if ($score > $bestScore || ($score === $bestScore && ($bestId === 0 || $id < $bestId)))
			{
				$bestScore = $score;
				$bestId = $id;
			}
		}
		if ($bestId > 0)
		{
			return $bestId;
		}
	}

	return null;
}

function mf_analogs_generate_unique_code(int $iblockId, string $base): string
{
	$base = trim($base);
	if ($base === '')
	{
		$base = 'analog';
	}

	if (class_exists('CUtil'))
	{
		$code = (string)\CUtil::translit($base, 'ru', [
			'change_case' => 'L',
			'replace_space' => '-',
			'replace_other' => '-',
			'delete_repeat_replace' => true,
			'use_google' => false,
		]);
	}
	else
	{
		$code = strtolower(preg_replace('~[^a-z0-9]+~i', '-', $base) ?? $base);
	}

	$code = trim($code, '-');
	if ($code === '')
	{
		$code = 'analog';
	}

	$try = $code;
	$i = 1;
	while (true)
	{
		$exists = \CIBlockElement::GetList(
			[],
			['=IBLOCK_ID' => $iblockId, '=CODE' => $try],
			false,
			['nTopCount' => 1],
			['ID']
		)->Fetch();
		if (!$exists)
		{
			return $try;
		}
		$i++;
		$try = $code . '-' . $i;
		if ($i > 50)
		{
			return $code . '-' . time();
		}
	}
}

function mf_analogs_create_product_stub(int $iblockId, string $brand, string $article, array $images = []): ?int
{
	$brand = trim($brand);
	$article = trim($article);
	if ($brand === '' || $article === '')
	{
		return null;
	}

	$brandNorm = mf_analogs_norm_brand($brand);
	$articleNorm = mf_analogs_norm_article($article);

	$name = $brand . ' ' . $article;
	$codeBase = $brandNorm . $articleNorm;
	$code = mf_analogs_generate_unique_code($iblockId, $codeBase !== '' ? $codeBase : $name);

	$el = new \CIBlockElement();
	$newId = (int)$el->Add([
		'IBLOCK_ID' => $iblockId,
		'ACTIVE' => 'Y',
		'NAME' => $name,
		'CODE' => $code,
		'XML_ID' => 'ANALOG_' . $brandNorm . '_' . $articleNorm,
	]);
	if ($newId <= 0)
	{
		return null;
	}

	$props = [
		'MF_BRAND' => $brand,
		'CML2_ARTICLE' => $article,
		'MF_BRAND_NORM' => $brandNorm,
		'MF_ARTICLE_NORM' => $articleNorm,
		'MF_SHOW_IN_CATALOG' => 'N',
	];
	$images = array_values(array_filter(array_map('trim', $images), static fn($s) => $s !== ''));
	if (!empty($images))
	{
		$props['MF_EXT_IMAGES'] = $images;
	}
	\CIBlockElement::SetPropertyValuesEx($newId, $iblockId, $props);

	// Best effort: ensure searchable
	try
	{
		if (class_exists('\\Bitrix\\Main\\Loader') && \Bitrix\Main\Loader::includeModule('search'))
		{
			\CIBlockElement::UpdateSearch($newId, true);
		}
	}
	catch (\Throwable $e)
	{
		// ignore
	}

	return $newId;
}

