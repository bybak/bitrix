<?php

namespace Sale\Handlers\PaySystem;

// Bitrix sanitizes handler folder name: `mf_card2card` -> `mfcard2card`.
// We keep original implementation in `mf_card2card/`, and provide this wrapper for Bitrix discovery.
require_once __DIR__ . '/../mf_card2card/handler.php';

if (!class_exists(__NAMESPACE__ . '\\mf_card2cardHandler', false))
{
	class_alias(MfCard2CardHandler::class, __NAMESPACE__ . '\\mf_card2cardHandler');
}

