<?php
/**
 * Однократное обновление шаблонов писем SALE_NEW_ORDER и SALE_STATUS_CHANGED_*.
 * php -d short_open_tag=1 local/tools/mf_update_order_mail_templates.php
 */
$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../..');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!class_exists(\CEventMessage::class))
{
	fwrite(STDERR, "CEventMessage not available\n");
	exit(1);
}

$templates = [
	'SALE_NEW_ORDER' => [
		'SUBJECT' => 'Заказ: №#MF_ORDER_DISPLAY#',
		'MESSAGE' => '#MF_ORDER_MAIL_BODY#',
	],
	'SALE_STATUS_CHANGED_N' => [
		'SUBJECT' => 'Статус заказа №#MF_ORDER_DISPLAY#',
		'MESSAGE' => '#MF_ORDER_MAIL_BODY#',
	],
	'SALE_STATUS_CHANGED_F' => [
		'SUBJECT' => 'Статус заказа №#MF_ORDER_DISPLAY#',
		'MESSAGE' => '#MF_ORDER_MAIL_BODY#',
	],
	'SALE_STATUS_CHANGED_P' => [
		'SUBJECT' => 'Статус заказа №#MF_ORDER_DISPLAY#',
		'MESSAGE' => '#MF_ORDER_MAIL_BODY#',
	],
];

$updated = 0;
foreach ($templates as $eventName => $data)
{
	$res = \CEventMessage::GetList('id', 'asc', ['EVENT_NAME' => $eventName]);
	while ($row = $res->Fetch())
	{
		$id = (int)($row['ID'] ?? 0);
		if ($id <= 0)
		{
			continue;
		}
		$em = new \CEventMessage();
		$ok = $em->Update($id, [
			'SUBJECT' => $data['SUBJECT'],
			'MESSAGE' => $data['MESSAGE'],
			'BODY_TYPE' => 'html',
			'ACTIVE' => 'Y',
		]);
		if ($ok)
		{
			$updated++;
			echo "Updated #{$id} {$eventName}\n";
		}
		else
		{
			echo "Failed #{$id} {$eventName}: " . $em->LAST_ERROR . "\n";
		}
	}
}

echo "Done, updated {$updated} template(s).\n";
