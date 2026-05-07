<?php
/**
 * Inline SVG icon library for Motor-Force redesign.
 * Usage: echo mf_icon('bike', ['class' => 'mf-icon mf-icon--lg']);
 *
 * Все иконки stroke-based, currentColor — наследуют цвет родителя.
 * 24x24 viewBox.
 */

if (!function_exists('mf_icons_lib'))
{
	function mf_icons_lib(): array
	{
		// Все иконки на 24x24, stroke=currentColor; адаптируются по размеру и цвету.
		return [
			// Транспорт / мото
			'bike' => '<path d="M5.5 17a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" /><path d="M18.5 17a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" /><path d="M14 5h2l1.5 4M5.5 13.5 9 7.5h6L18 14" />',
			'snow' => '<path d="M12 2v20M5 5l14 14M19 5 5 19M2 12h20" /><path d="M9 4l3 3 3-3M9 20l3-3 3 3M4 9l3 3-3 3M20 9l-3 3 3 3" />',
			'jetski' => '<path d="M3 16c2 1 4 1 6 0s4-1 6 0 4 1 6 0" /><path d="M5 12h14l-2-3H7l-2 3Z" /><path d="M12 9V5l-3-2 6 0-3 2v4" />',
			'engine' => '<rect x="6" y="8" width="12" height="10" rx="1" /><path d="M6 12H3v3h3M18 12h3v3h-3M9 8V5M15 8V5M9 5h6" />',
			'wrench' => '<path d="M15 7a3 3 0 1 1-3.59 3.59L4 18l2 2 7.41-7.41A3 3 0 0 0 15 7Z" /><path d="M15 7l3-3 2 2-3 3" />',
			'cog' => '<circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 0 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 0 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 0 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h0a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 0 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v0a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z" />',

			// Сервис/доставка
			'truck' => '<path d="M3 7h11v10H3z" /><path d="M14 10h4l3 3v4h-7" /><circle cx="7" cy="18" r="2" /><circle cx="17" cy="18" r="2" />',
			'box' => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8Z" /><path d="M3 8l9 5 9-5M12 13v8" />',
			'shield' => '<path d="M12 2 4 5v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V5l-8-3Z" />',
			'check' => '<path d="M5 13l4 4L19 7" />',
			'star' => '<path d="m12 2 3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7l3-7Z" />',
			'lightning' => '<path d="M13 2 4 14h7l-2 8 9-12h-7l2-8Z" />',
			'flag' => '<path d="M4 21V4M4 4h13l-2 4 2 4H4" />',

			// Контакты / коммуникации
			'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.91.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.9a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92Z" />',
			'mail' => '<rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" />',
			'pin' => '<path d="M12 22s8-7.5 8-13a8 8 0 1 0-16 0c0 5.5 8 13 8 13Z" /><circle cx="12" cy="9" r="3" />',
			'clock' => '<circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" />',
			'whatsapp' => '<path d="M21 12a9 9 0 0 1-13.5 7.8L3 21l1.3-4.5A9 9 0 1 1 21 12Z" /><path d="M9 10c0 4 3 7 7 7l1-2-2-1-1 1c-1 0-3-1-3-2l1-1-1-2-2 1Z" fill="currentColor" stroke="none" />',
			'telegram' => '<path d="M21 4 3 11l6 2 2 6 3-4 5 4 2-15Z" />',
			'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5" /><circle cx="12" cy="12" r="4" /><circle cx="17" cy="7" r="1" fill="currentColor" stroke="none" />',
			'vk' => '<rect x="3" y="3" width="18" height="18" rx="3" /><path d="M7 9c.5 4 3 6 6 6 0-1 .5-2 1-2 1 0 2 2 3 2h1c-.5-1-1.5-2-2.5-3 0-.5 2-2 2.5-3h-1c-1 0-2 2-3 2 0-1 0-2-1-2H10" fill="currentColor" stroke="none" />',

			// UI
			'arrow-right' => '<path d="M5 12h14M13 5l7 7-7 7" />',
			'arrow-left' => '<path d="M19 12H5M11 5l-7 7 7 7" />',
			'arrow-down' => '<path d="M12 5v14M5 13l7 7 7-7" />',
			'plus' => '<path d="M12 5v14M5 12h14" />',
			'minus' => '<path d="M5 12h14" />',
			'close' => '<path d="M18 6 6 18M6 6l12 12" />',
			'search' => '<circle cx="11" cy="11" r="7" /><path d="m21 21-4.3-4.3" />',
			'menu' => '<path d="M3 6h18M3 12h18M3 18h18" />',
			'cart' => '<circle cx="9" cy="20" r="1.5" /><circle cx="18" cy="20" r="1.5" /><path d="M3 4h2l3 12h11l2-8H6" />',
			'user' => '<circle cx="12" cy="8" r="4" /><path d="M4 21c0-4 4-7 8-7s8 3 8 7" />',
			'lock' => '<rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 1 1 8 0v3" />',
			'document' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /><path d="M9 13h6M9 17h4" />',
			'shield' => '<path d="M12 3l8 3v6c0 4-3 7-8 9-5-2-8-5-8-9V6l8-3z" />',
			'cards' => '<rect x="3" y="6" width="18" height="13" rx="2" /><path d="M3 10h18" />',
			'star' => '<path d="m12 3 2.6 6 6.4.6-4.9 4.3 1.5 6.4L12 17.7 6.4 20.3l1.5-6.4L3 9.6 9.4 9z" />',
			'orders' => '<rect x="4" y="5" width="16" height="15" rx="2" /><path d="M8 9h8M8 13h8M8 17h5" />',
			'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.8Z" />',
			'eye' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="3" />',

			// Финансы / документы
			'card' => '<rect x="2" y="6" width="20" height="13" rx="2" /><path d="M2 11h20M6 16h6" />',
			'cash' => '<rect x="3" y="6" width="18" height="12" rx="1" /><circle cx="12" cy="12" r="3" /><path d="M3 10h2M19 10h2M3 14h2M19 14h2" />',
			'wallet' => '<path d="M20 7H4a2 2 0 0 0-2 2v10c0 1.1.9 2 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2Z" /><path d="M16 14h-2a2 2 0 0 1 0-4h2v4ZM4 7V5a2 2 0 0 1 2-2h12" />',
			'invoice' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z" /><path d="M14 2v6h6M9 14h6M9 18h6M9 10h2" />',
			'doc' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z" /><path d="M14 2v6h6" />',
			'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="M7 10l5 5 5-5M12 15V3" />',

			// Информация
			'info' => '<circle cx="12" cy="12" r="9" /><path d="M12 16v-4M12 8h.01" />',
			'question' => '<circle cx="12" cy="12" r="9" /><path d="M9.1 9a3 3 0 1 1 5.7 1.4c-.3.7-.8 1.2-1.4 1.6-.7.4-1.4.7-1.4 2M12 17h.01" />',
			'warning' => '<path d="M10.3 3.9a2 2 0 0 1 3.4 0l8 13.6A2 2 0 0 1 20 21H4a2 2 0 0 1-1.7-3.5l8-13.6Z" /><path d="M12 9v4M12 17h.01" />',
			'sparkle' => '<path d="m12 3 2 5 5 2-5 2-2 5-2-5-5-2 5-2 2-5Z" />',
			'globe' => '<circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3a13 13 0 0 1 0 18M12 3a13 13 0 0 0 0 18" />',
			'route' => '<circle cx="6" cy="19" r="3" /><circle cx="18" cy="5" r="3" /><path d="M9 19h6a3 3 0 0 0 0-6h-6a3 3 0 0 1 0-6h6" />',
			'price-tag' => '<path d="M20 12 12 20 3 11V3h8l9 9Z" /><circle cx="7" cy="7" r="1.5" />',
			'home' => '<path d="m3 12 9-9 9 9v9a2 2 0 0 1-2 2h-4v-7H9v7H5a2 2 0 0 1-2-2v-9Z" />',
			'rocket' => '<path d="M5 13c1-7 8-11 16-11 0 8-4 15-11 16l-3-2-2-3Z" /><path d="M9 14H5a2 2 0 0 0-2 2l2 5 5 2a2 2 0 0 0 2-2v-4" /><circle cx="14" cy="10" r="2" />',
			'wave' => '<path d="M2 12c2-2 4-2 6 0s4 2 6 0 4-2 6 0 2 2 2 2" /><path d="M2 17c2-2 4-2 6 0s4 2 6 0 4-2 6 0 2 2 2 2" /><path d="M2 7c2-2 4-2 6 0s4 2 6 0 4-2 6 0 2 2 2 2" />',
			'boat' => '<path d="M3 16c2 1 4 1 6 0s4-1 6 0 4 1 6 0" /><path d="M5 13l1.5-4h11L19 13" /><path d="M12 9V4M9 4h6" />',
			'anchor' => '<circle cx="12" cy="5" r="2" /><path d="M12 7v15M5 14a7 7 0 0 0 14 0M3 14h4M21 14h-4" />',
			'tools' => '<path d="M14.7 6.3a4 4 0 0 1 5 5l-2.5-1-1.5 1.5 1 2.5a4 4 0 0 1-5-5l1 2.5 1.5-1.5-1-2.5Z" /><path d="M3 21l8-8" /><path d="M14.7 6.3 3 18l3 3 11.7-11.7" />',
			'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2" /><path d="M16 3v4M8 3v4M3 11h18" />',
			'handshake' => '<path d="M11 17 8 14l-3 3 3 3 3-3Z" /><path d="M5 14 2 11l4-7h6l3 3M22 11l-4-7h-3" /><path d="m11 17 4 4 3-3-2-2 3-3-2-2" />',
			'map' => '<path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3V6Z" /><path d="M9 3v15M15 6v15" />',
			'chat' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10Z" />',
			'send' => '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7Z" />',
		];
	}
}

if (!function_exists('mf_icon'))
{
	/**
	 * Render inline SVG icon.
	 *
	 * @param string $name Icon name from mf_icons_lib().
	 * @param array  $attrs Optional <svg> attributes (class, width, height, aria-label).
	 */
	function mf_icon(string $name, array $attrs = []): string
	{
		$lib = mf_icons_lib();
		if (!isset($lib[$name]))
		{
			return '';
		}
		$attrs += [
			'class' => 'mf-icon',
			'width' => 24,
			'height' => 24,
			'viewBox' => '0 0 24 24',
			'fill' => 'none',
			'stroke' => 'currentColor',
			'stroke-width' => 2,
			'stroke-linecap' => 'round',
			'stroke-linejoin' => 'round',
			'aria-hidden' => 'true',
			'focusable' => 'false',
		];

		$attrStr = '';
		foreach ($attrs as $k => $v)
		{
			$attrStr .= ' ' . htmlspecialchars($k, ENT_QUOTES) . '="' . htmlspecialchars((string)$v, ENT_QUOTES) . '"';
		}

		return '<svg' . $attrStr . '>' . $lib[$name] . '</svg>';
	}
}
