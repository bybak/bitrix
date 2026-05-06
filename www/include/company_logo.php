<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
	die();
}
$mfLogo = SITE_TEMPLATE_PATH . '/images/mf-brand-logo.png';
$mfLogoVer = (is_file($_SERVER['DOCUMENT_ROOT'] . $mfLogo)) ? (int)@filemtime($_SERVER['DOCUMENT_ROOT'] . $mfLogo) : 1;
?>
<img
	src="<?= htmlspecialcharsbx($mfLogo . '?v=' . $mfLogoVer) ?>"
	alt="Motor-Force"
	loading="eager"
	decoding="async"
	width="215"
	height="84"
/>
