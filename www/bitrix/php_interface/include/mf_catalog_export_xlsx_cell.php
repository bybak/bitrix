<?php

declare(strict_types=1);

/**
 * Текст для inlineStr в XLSX: символы, с которыми Excel не ругается при открытии (SpreadsheetML + XML 1.0).
 *
 * Убираем: все управляющие (Cc), кроме сохранённых TAB/LF/CR; C1; разделители строк U+2028/U+2029;
 * маркеры направления текста U+202A–U+202E; U+FEFF внутри ячейки; не-символы FFFE/FFFF.
 *
 * Важно: при ошибке PCRE preg_replace возвращает null — нельзя подставлять '', иначе ячейка обнуляется.
 */
function mf_ce_xlsx_cell_xml(string $s): string
{
	if (function_exists('iconv'))
	{
		$clean = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
		if ($clean !== false)
		{
			$s = $clean;
		}
	}

	// U+FEFF как BOM/ZWNBSP внутри текста — часто ломают валидацию OOXML.
	$t = preg_replace('/\x{FEFF}/u', '', $s);
	if ($t !== null)
	{
		$s = $t;
	}

	$s = str_replace("\xC2\xA0", ' ', $s);

	// LINE/PARAGRAPH SEPARATOR — Excel часто «чинит» файл, если они в inlineStr.
	$t = preg_replace('/\x{2028}|\x{2029}/u', "\n", $s);
	if ($t !== null)
	{
		$s = $t;
	}

	// Вложенный текст: маркеры направления (BiDi) — лучше убрать.
	$t = preg_replace('/[\x{202A}-\x{202E}]/u', '', $s);
	if ($t !== null)
	{
		$s = $t;
	}

	// Сохраняем TAB/LF/CR: подмена на символы из supplementary PUA (конфликт с реальным текстом маловероятен).
	$phTab = "\u{10FFFA}";
	$phLf = "\u{10FFFB}";
	$phCr = "\u{10FFFC}";
	$s = str_replace(["\t", "\n", "\r"], [$phTab, $phLf, $phCr], $s);

	$t = preg_replace('/\p{Cc}/u', '', $s);
	if ($t !== null)
	{
		$s = $t;
	}

	$s = str_replace([$phTab, $phLf, $phCr], ["\t", "\n", "\r"], $s);

	$t = preg_replace('/\x{FFFE}|\x{FFFF}/u', '', $s);
	if ($t !== null)
	{
		$s = $t;
	}

	$max = 32767;
	if (function_exists('mb_strlen') && mb_strlen($s, 'UTF-8') > $max)
	{
		$s = mb_substr($s, 0, $max, 'UTF-8');
	}
	elseif (!function_exists('mb_strlen') && strlen($s) > $max * 4)
	{
		$s = substr($s, 0, $max * 4);
	}

	return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
