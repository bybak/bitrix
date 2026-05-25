<?if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

$arResult["THEME"] = COption::GetOptionString("main", "wizard_eshop_bootstrap_theme_id", "blue", SITE_ID);

$city = trim((string)($arResult['arUser']['PERSONAL_CITY'] ?? ''));
$state = trim((string)($arResult['arUser']['PERSONAL_STATE'] ?? ''));
if ($state !== '' && ($city === '' || mb_stripos($city, $state) === false))
{
	$arResult['MF_CITY_REGION'] = trim($city . ($city !== '' && $state !== '' ? ', ' : '') . $state);
}
else
{
	$arResult['MF_CITY_REGION'] = $city;
}
?>

