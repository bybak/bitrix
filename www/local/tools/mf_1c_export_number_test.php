<?php
/**
 * Проверка подмены <Номер> в CommerceML: php local/tools/mf_1c_export_number_test.php [order_id]
 */
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

require $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_1c_export.php';

$orderId = (int)($argv[1] ?? 0);
if ($orderId <= 0 && class_exists(\Bitrix\Main\Loader::class) && \Bitrix\Main\Loader::includeModule('sale'))
{
	$row = \Bitrix\Sale\Internals\OrderTable::getList([
		'select' => ['ID'],
		'order' => ['ID' => 'DESC'],
		'limit' => 1,
	])->fetch();
	$orderId = (int)($row['ID'] ?? 0);
}

if ($orderId <= 0)
{
	fwrite(STDERR, "No order found\n");
	exit(1);
}

$_REQUEST['mode'] = 'query';
$display = mf_1c_export_order_display_number_by_id($orderId);
$sample = '<Документ>'
	. '<ХозОперация>Заказ товара</ХозОперация>'
	. '<Номер>s1' . htmlspecialchars($display) . '</Номер>'
	. '<Ид>' . $orderId . '</Ид>'
	. '<Товары><Товар><Ид>1</Ид></Товар></Товары>'
	. '</Документ>';

$out = mf_1c_rewrite_order_numbers_xml_export($sample);
echo "order_id={$orderId}\n";
echo "expected_number={$display}\n";
echo "result_contains_expected=" . (strpos($out, '<Номер>' . $display . '</Номер>') !== false ? 'Y' : 'N') . "\n";
echo "has_nomer1c=" . (strpos($out, '<Номер1С>' . $display . '</Номер1С>') !== false ? 'Y' : 'N') . "\n";
echo "---\n" . $out . "\n";
