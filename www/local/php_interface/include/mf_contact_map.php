<?php
/**
 * Карта офиса Motor-Force без <iframe> в HTML (иначе веб-антивирус Bitrix режет страницу).
 */
if (!function_exists('mf_contact_map_render'))
{
	function mf_contact_map_render(string $mapId = 'mf-contact-map'): void
	{
		static $apiScriptAdded = false;

		$mapId = preg_replace('~[^a-zA-Z0-9_-]~', '', $mapId);
		if ($mapId === '')
		{
			$mapId = 'mf-contact-map';
		}

		// Проверенные координаты здания «ул. Салова, 57к1Ч» (Яндекс.Карты).
		$lat = 59.887598;
		$lon = 30.371510;
		$address = 'Санкт-Петербург, ул. Салова, 57к1Ч';
		$balloon = 'Motor-Force, ул. Салова, д. 57, к. 1, литера Ч, оф. 1Н';

		$apiKey = '';
		if (class_exists(\Bitrix\Main\Config\Option::class))
		{
			$apiKey = trim((string)\Bitrix\Main\Config\Option::get('fileman', 'yandex_map_api_key', ''));
		}

		echo '<div id="' . htmlspecialcharsbx($mapId) . '" class="mf-contact-map" style="width:100%;height:100%;" aria-label="Карта проезда"></div>';

		if (!$apiScriptAdded)
		{
			$apiScriptAdded = true;
			$apiParam = $apiKey !== '' ? '&amp;apikey=' . htmlspecialcharsbx(rawurlencode($apiKey)) : '';
			echo '<script src="https://api-maps.yandex.ru/2.1/?lang=ru_RU' . $apiParam . '"></script>';
		}
		?>
<script>
(function () {
	var mapId = <?= json_encode($mapId, JSON_UNESCAPED_UNICODE) ?>;
	var center = [<?= $lat ?>, <?= $lon ?>];
	var address = <?= json_encode($address, JSON_UNESCAPED_UNICODE) ?>;
	var balloon = <?= json_encode($balloon, JSON_UNESCAPED_UNICODE) ?>;

	function initMap() {
		if (typeof ymaps === 'undefined') {
			return;
		}
		ymaps.ready(function () {
			var el = document.getElementById(mapId);
			if (!el || el.dataset.mfMapReady === '1') {
				return;
			}
			el.dataset.mfMapReady = '1';

			function renderAt(coords) {
				var map = new ymaps.Map(el, {
					center: coords,
					zoom: 18,
					controls: ['zoomControl', 'fullscreenControl']
				});
				map.behaviors.disable('scrollZoom');
				map.geoObjects.add(new ymaps.Placemark(coords, {
					balloonContent: balloon,
					hintContent: address
				}, {
					preset: 'islands#redDotIcon'
				}));
			}

			// Фиксированная точка здания 57к1Ч: геокодер часто попадает в соседний корпус.
			renderAt(center);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initMap);
	} else {
		initMap();
	}
})();
</script>
		<?php
	}
}
