<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Mail\Mail;
use Bitrix\Main\Web\Json;

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
	// Honeypot triggered: pretend success
	if (mf_is_ajax_request())
	{
		mf_json(["ok" => true]);
	}
	LocalRedirect("/#lead-form");
}

$fields = [
	"fio" => trim((string)($_POST["fio"] ?? "")),
	"phone" => trim((string)($_POST["phone"] ?? "")),
	"email" => trim((string)($_POST["email"] ?? "")),
	"field1" => trim((string)($_POST["field1"] ?? "")),
	"field2" => trim((string)($_POST["field2"] ?? "")),
	"field3" => trim((string)($_POST["field3"] ?? "")),
	"field6" => trim((string)($_POST["field6"] ?? "")),
	"field4" => trim((string)($_POST["field4"] ?? "")),
	"field5" => trim((string)($_POST["field5"] ?? "")),
];

$errors = [];
if ($fields["fio"] === "") $errors[] = "Укажите имя.";
if ($fields["phone"] === "") $errors[] = "Укажите телефон.";
if ($fields["email"] === "") $errors[] = "Укажите e-mail.";

if (!empty($errors))
{
	if (mf_is_ajax_request())
	{
		mf_json(["ok" => false, "errors" => $errors]);
	}
	LocalRedirect("/?lead_form=error#lead-form");
}

$emailTo = "";
if (class_exists("COption"))
{
	$emailTo = (string)COption::GetOptionString("main", "email_from", "");
}
if ($emailTo === "")
{
	$emailTo = "admin@".$_SERVER["HTTP_HOST"];
}

$subject = "Заявка с сайта (Оставьте заявку)";
$lines = [];
$lines[] = "Заявка с сайта";
$lines[] = "Форма: lead_form";
$lines[] = "Дата: ".date("Y-m-d H:i:s");
$lines[] = "IP: ".($_SERVER["REMOTE_ADDR"] ?? "");
$lines[] = "---";
$lines[] = "Имя: ".$fields["fio"];
$lines[] = "Телефон: ".$fields["phone"];
$lines[] = "E-mail: ".$fields["email"];
$lines[] = "VIN Номер: ".$fields["field1"];
$lines[] = "Марка: ".$fields["field2"];
$lines[] = "Модель: ".$fields["field3"];
$lines[] = "Объем двигателя: ".$fields["field6"];
$lines[] = "Модельный год: ".$fields["field4"];
$lines[] = "Заявка: ".$fields["field5"];
$body = implode("\n", $lines)."\n";

$sent = false;
if (class_exists(Mail::class))
{
	$from = $emailTo;
	$sent = (bool)Mail::send([
		"TO" => $emailTo,
		"SUBJECT" => $subject,
		"BODY" => $body,
		"HEADER" => "Content-Type: text/plain; charset=UTF-8\r\nFrom: ".$from."\r\n",
	]);
}
else
{
	$headers = "Content-Type: text/plain; charset=UTF-8\r\n";
	$sent = @mail($emailTo, $subject, $body, $headers);
}

if (mf_is_ajax_request())
{
	mf_json([
		"ok" => $sent,
		"errors" => $sent ? [] : ["Не удалось отправить заявку. Попробуйте позже."],
	]);
}

LocalRedirect($sent ? "/?lead_form=ok#lead-form" : "/?lead_form=error#lead-form");

