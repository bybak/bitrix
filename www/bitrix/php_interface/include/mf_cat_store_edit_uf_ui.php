<?php

declare(strict_types=1);

/**
 * Карточка склада (cat_store_edit): при снятом «Внешний склад» отключает поля внешнего прайса по весу.
 */

if (!function_exists('mf_admin_cat_store_edit_uf_dependent_fields'))
{
	function mf_admin_cat_store_edit_uf_dependent_fields(): void
	{
		if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
		{
			return;
		}
		global $APPLICATION;
		if (!is_object($APPLICATION))
		{
			return;
		}
		$page = (string)$APPLICATION->GetCurPage();
		if (stripos($page, 'cat_store_edit.php') === false)
		{
			return;
		}

		$script = <<<'JS'
<script>
(function () {
	var MASTER = 'UF_MF_EXTERNAL_STORE';
	var DEP = ['UF_MF_EXT_WEIGHT_USE', 'UF_MF_EXT_WEIGHT_RUB_PER_KG', 'UF_MF_EXT_WEIGHT_MIN_RUB'];

	function extOn() {
		var cb = document.querySelector('input[type="checkbox"][name="' + MASTER + '"]');
		if (cb) {
			return cb.checked;
		}
		var sel = document.querySelector('select[name="' + MASTER + '"]');
		if (sel) {
			var v = String(sel.value || '').toUpperCase();
			return v === 'Y' || v === '1' || v === 'YES' || v === 'TRUE';
		}
		var radios = document.querySelectorAll('input[type="radio"][name="' + MASTER + '"]');
		if (radios.length) {
			for (var i = 0; i < radios.length; i++) {
				if (radios[i].checked) {
					var rv = String(radios[i].value || '').toUpperCase();
					return rv === 'Y' || rv === '1' || rv === 'YES' || rv === 'TRUE';
				}
			}
			return false;
		}
		return true;
	}

	function forEachDep(fn) {
		DEP.forEach(function (name) {
			document.querySelectorAll(
				'input[name="' + name + '"], select[name="' + name + '"], textarea[name="' + name + '"]'
			).forEach(fn);
		});
	}

	function sync() {
		var on = extOn();
		forEachDep(function (el) {
			el.disabled = !on;
		});
	}

	function bindMaster() {
		var cb = document.querySelector('input[type="checkbox"][name="' + MASTER + '"]');
		if (cb) {
			cb.addEventListener('change', sync);
			return;
		}
		var sel = document.querySelector('select[name="' + MASTER + '"]');
		if (sel) {
			sel.addEventListener('change', sync);
			return;
		}
		document.querySelectorAll('input[type="radio"][name="' + MASTER + '"]').forEach(function (r) {
			r.addEventListener('change', sync);
		});
	}

	function bindSubmit() {
		var form = document.forms.store_edit;
		if (!form) {
			return;
		}
		form.addEventListener(
			'submit',
			function () {
				forEachDep(function (el) {
					el.disabled = false;
				});
			},
			true
		);
	}

	function init() {
		if (!document.forms.store_edit) {
			return;
		}
		bindMaster();
		sync();
		bindSubmit();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
</script>
JS;

		if (method_exists($APPLICATION, 'AddHeadString'))
		{
			$APPLICATION->AddHeadString($script, true, \Bitrix\Main\Page\AssetLocation::BODY_END);
		}
	}
}

if (class_exists(\Bitrix\Main\EventManager::class) && !defined('MF_CAT_STORE_EDIT_UF_UI_REGISTERED'))
{
	define('MF_CAT_STORE_EDIT_UF_UI_REGISTERED', true);
	\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', 'OnEpilog', 'mf_admin_cat_store_edit_uf_dependent_fields');
}
