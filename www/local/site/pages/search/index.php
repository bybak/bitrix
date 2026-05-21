<?php
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';
$APPLICATION->SetTitle('Поиск');
$APPLICATION->AddChainItem('Поиск', SITE_DIR . 'search/');
if (trim((string)($_GET['q'] ?? '')) !== '')
{
	$APPLICATION->AddHeadString('<script>document.documentElement.classList.add("mf-search-page-pending");</script>', true);
}
?>

<?php $APPLICATION->IncludeComponent('mf:catalog.search', 'mf_search', [
	'IBLOCK_ID' => '4',
	'PAGE_RESULT_COUNT' => '25',
	'DISPLAY_TOP_PAGER' => 'N',
	'DISPLAY_BOTTOM_PAGER' => 'Y',
], false); ?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'; ?>
