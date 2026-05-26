<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Web\Json;

require_once $_SERVER['DOCUMENT_ROOT'] . '/include/mf_form.php';

function mf_is_ajax_request(): bool
{
	$xhr = (string)($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "");
	$accept = (string)($_SERVER["HTTP_ACCEPT"] ?? "");
	return (strcasecmp($xhr, "XMLHttpRequest") === 0) || (stripos($accept, "application/json") !== false);
}

function mf_json(array $data): void
{
	header("Content-Type: application/json; charset=UTF-8");
	echo Json::encode($data, JSON_UNESCAPED_UNICODE);
	exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST")
{
	if (mf_is_ajax_request())
	{
		mf_json(["ok" => false, "error" => "Метод не поддерживается"]);
	}
	LocalRedirect("/#lead-form");
}

$lastname = trim((string)($_POST["lastname"] ?? ""));
if ($lastname !== "")
{
	if (mf_is_ajax_request())
	{
		mf_json(["ok" => true]);
	}
	LocalRedirect("/#lead-form");
}

$fields = [
	"fio" => "Имя",
	"phone" => "Телефон",
	"email" => "E-mail",
	"field1" => "VIN номер",
	"field2" => "Марка",
	"field3" => "Модель",
	"field6" => "Объём двигателя",
	"field4" => "Модельный год",
	"field5" => "Заявка",
];

$values = [];
foreach ($fields as $name => $_label)
{
	$values[$name] = trim((string)($_POST[$name] ?? ""));
}

$errors = [];
if ($values["fio"] === "") $errors[] = "Укажите имя.";
if ($values["phone"] === "") $errors[] = "Укажите телефон.";
if ($values["email"] === "") $errors[] = "Укажите e-mail.";

if (!empty($errors))
{
	if (mf_is_ajax_request())
	{
		mf_json(["ok" => false, "errors" => $errors]);
	}
	LocalRedirect("/?lead_form=error#lead-form");
}

$recipient = mf_form_resolve_recipient("");
$subject = mf_form_normalize_mail_subject("Заявка с сайта (Оставьте заявку)");

$dataRows = [
	["Дата", date("Y-m-d H:i:s")],
	["Страница", mf_form_resolve_page_url()],
];
foreach ($fields as $name => $label)
{
	$value = trim((string)($values[$name] ?? ""));
	if ($value === "")
	{
		continue;
	}
	$dataRows[] = [$label, $value];
}

$header = ["From" => mf_form_resolve_from($recipient)];
if (filter_var($values["email"], FILTER_VALIDATE_EMAIL))
{
	$header["Reply-To"] = $values["email"];
}

$sent = mf_form_send_html_mail(
	$recipient,
	$subject,
	"Заявка с сайта",
	"Оставьте заявку — форма на главной странице",
	$dataRows,
	$header
);

if (mf_is_ajax_request())
{
	mf_json([
		"ok" => $sent,
		"errors" => $sent ? [] : ["Не удалось отправить заявку. Попробуйте позже."],
	]);
}

LocalRedirect($sent ? "/?lead_form=ok#lead-form" : "/?lead_form=error#lead-form");
