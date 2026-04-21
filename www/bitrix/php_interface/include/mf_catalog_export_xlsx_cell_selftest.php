<?php

declare(strict_types=1);

/**
 * CLI: php mf_catalog_export_xlsx_cell_selftest.php
 * Проверяет, что санитайзер ячеек XLSX не обнуляет текст при длинных строках и битом UTF-8.
 */

require __DIR__ . '/mf_catalog_export_xlsx_cell.php';

$long = str_repeat('Тест наименование ', 800);
$out = mf_ce_xlsx_cell_xml($long);
if (strlen($out) < 8000)
{
	fwrite(STDERR, "FAIL: long Cyrillic text was wiped (len=" . strlen($out) . ")\n");
	exit(1);
}

// Невалидная последовательность UTF-8 — не должна превращать ячейку в пустую строку целиком.
$bad = "prefix\xa0\xc3\x28suffix";
$outBad = mf_ce_xlsx_cell_xml($bad);
if ($outBad === '')
{
	fwrite(STDERR, "FAIL: invalid UTF-8 became empty cell\n");
	exit(1);
}

// PCRE с /u на битой строке: результат не должен быть пустым из-за ?? '' (регрессия).
$edge = "\xED\xA0\x80"; // lone surrogate-like bytes
$outEdge = mf_ce_xlsx_cell_xml($edge);
if ($outEdge === '' && $edge !== '')
{
	fwrite(STDERR, "FAIL: edge bytes became empty (possible preg null coalesce bug)\n");
	exit(1);
}

echo "mf_catalog_export_xlsx_cell_selftest: OK\n";
