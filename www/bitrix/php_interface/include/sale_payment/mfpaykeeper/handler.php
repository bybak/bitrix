<?php

namespace Sale\Handlers\PaySystem;

// Bitrix sanitizes handler folder name: `mf_paykeeper` -> `mfpaykeeper`.
// We keep original implementation in `mf_paykeeper/`, and provide this wrapper for Bitrix discovery.
require_once __DIR__ . '/../mf_paykeeper/handler.php';

if (!class_exists(__NAMESPACE__ . '\\mf_paykeeperHandler', false))
{
	class_alias(MfPaykeeperHandler::class, __NAMESPACE__ . '\\mf_paykeeperHandler');
}

