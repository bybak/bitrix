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

		// Запасные координаты: ул. Салова, 57 (если геокодер не ответит).
		$lat = 59.886799;
		$lon = 30.374732;
		$address = 'Санкт-Петербург, ул. Салова, 57, корпус 1, литера Ч';
		$balloon = 'Motor-Force, ул. Салова, 57к1, оф. 1Н';

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
	var fallbackCenter = [<?= $lat ?>, <?= $lon ?>];
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
					zoom: 17,
					controls: ['zoomControl', 'fullscreenControl']
				});
				map.behaviors.disable('scrollZoom');
				map.geoObjects.add(new ymaps.Placemark(coords, {
					balloonContent: balloon
				}, {
					preset: 'islands#redDotIcon'
				}));
			}

			ymaps.geocode(address, { results: 1 }).then(function (res) {
				var first = res.geoObjects.get(0);
				var coords = first ? first.geometry.getCoordinates() : fallbackCenter;
				renderAt(coords);
			}, function () {
				renderAt(fallbackCenter);
			});
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
