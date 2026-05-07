<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) {
	die();
}

/** @var CBitrixComponent $this */
/** @var array $arParams */
/** @var array $arResult */

use Bitrix\Main\Loader;

global $USER, $CACHE_MANAGER;

$arParams["IBLOCK_ID"] = (int)($arParams["IBLOCK_ID"] ?? 0);
$arParams["TOP_DEPTH"] = (int)($arParams["TOP_DEPTH"] ?? 6);
if ($arParams["TOP_DEPTH"] < 1) {
	$arParams["TOP_DEPTH"] = 1;
}
$arParams["SECTION_URL"] = trim((string)($arParams["SECTION_URL"] ?? ""));
if ($arParams["SECTION_URL"] === "") {
	$arParams["SECTION_URL"] = "/products/category/#SECTION_CODE#/";
}
$arParams["MF_CURRENT_SECTION_ID"] = (int)($arParams["MF_CURRENT_SECTION_ID"] ?? 0);

if (!isset($arParams["CACHE_TIME"])) {
	$arParams["CACHE_TIME"] = 36000000;
}
if (!isset($arParams["CACHE_TYPE"])) {
	$arParams["CACHE_TYPE"] = "A";
}
$arParams["CACHE_GROUPS"] = (($arParams["CACHE_GROUPS"] ?? "Y") === "N") ? "N" : "Y";

if (!Loader::includeModule("iblock")) {
	return;
}

if ($arParams["IBLOCK_ID"] <= 0) {
	return;
}

$additionalCacheId = array(
	"mf_tree_v1",
	$arParams["IBLOCK_ID"],
	$arParams["TOP_DEPTH"],
	$arParams["SECTION_URL"],
	$arParams["MF_CURRENT_SECTION_ID"],
	($arParams["CACHE_GROUPS"] === "N" ? false : $USER->GetGroups()),
);

if ($this->startResultCache(false, $additionalCacheId)) {
	$arResult = array(
		"SECTIONS" => array(),
		"OPEN_SECTION_IDS" => array(),
	);
	$iblockOk = CIBlock::GetList(
		array(),
		array(
			"ID" => $arParams["IBLOCK_ID"],
			"SITE_ID" => SITE_ID,
			"ACTIVE" => "Y",
		)
	)->Fetch();
	if (empty($iblockOk)) {
		$this->abortResultCache();
		return;
	}

	$currentId = $arParams["MF_CURRENT_SECTION_ID"];
	if ($currentId > 0) {
		$nav = CIBlockSection::GetNavChain($arParams["IBLOCK_ID"], $currentId, array("ID"));
		while ($n = $nav->Fetch()) {
			$arResult["OPEN_SECTION_IDS"][(int)$n["ID"]] = true;
		}
		$arResult["OPEN_SECTION_IDS"][$currentId] = true;
	}

	$filter = array(
		"IBLOCK_ID" => $arParams["IBLOCK_ID"],
		"ACTIVE" => "Y",
		"GLOBAL_ACTIVE" => "Y",
		"<=" . "DEPTH_LEVEL" => $arParams["TOP_DEPTH"],
	);

	$select = array(
		"ID",
		"NAME",
		"CODE",
		"IBLOCK_SECTION_ID",
		"DEPTH_LEVEL",
		"LEFT_MARGIN",
		"RIGHT_MARGIN",
		"SECTION_PAGE_URL",
	);

	$rs = CIBlockSection::GetList(
		array("LEFT_MARGIN" => "ASC", "SORT" => "ASC", "ID" => "ASC"),
		$filter,
		false,
		$select
	);
	$rs->SetUrlTemplates("", $arParams["SECTION_URL"]);

	while ($row = $rs->GetNext()) {
		$arResult["SECTIONS"][] = $row;
	}

	if (defined("BX_COMP_MANAGED_CACHE")) {
		$CACHE_MANAGER->RegisterTag("iblock_id_" . $arParams["IBLOCK_ID"]);
	}

	$this->setResultCacheKeys(array("SECTIONS", "OPEN_SECTION_IDS"));
	$this->endResultCache();
}

$this->IncludeComponentTemplate();
