<?php
declare(strict_types=1);

namespace MF\Delivery;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Sale\Delivery\CalculationResult;
use Bitrix\Sale\Delivery\Services\Base;
use Bitrix\Sale\Shipment;

final class Edost
{
	public static function isConfigured(): bool
	{
		return self::shopId() !== '' && self::shopPass() !== '';
	}

	public static function endpointPrimary(): string
	{
		$env = getenv('MF_EDOST_ENDPOINT');
		if ($env !== false && trim((string)$env) !== '')
		{
			return trim((string)$env);
		}
		$opt = trim((string)Option::get('mf.edost', 'endpoint', 'http://api.edost.ru/api2.php'));
		return $opt !== '' ? $opt : 'http://api.edost.ru/api2.php';
	}

	public static function endpointFallback(): string
	{
		$env = getenv('MF_EDOST_ENDPOINT_FALLBACK');
		if ($env !== false && trim((string)$env) !== '')
		{
			return trim((string)$env);
		}
		$opt = trim((string)Option::get('mf.edost', 'endpoint_fallback', 'http://edost.net/api2.php'));
		return $opt !== '' ? $opt : 'http://edost.net/api2.php';
	}

	public static function shopId(): string
	{
		$env = getenv('MF_EDOST_ID');
		if ($env !== false && trim((string)$env) !== '')
		{
			return trim((string)$env);
		}
		return trim((string)Option::get('mf.edost', 'id', ''));
	}

	public static function shopPass(): string
	{
		$env = getenv('MF_EDOST_PASS');
		if ($env !== false && trim((string)$env) !== '')
		{
			return trim((string)$env);
		}
		return trim((string)Option::get('mf.edost', 'pass', ''));
	}

	public static function timeoutSeconds(): int
	{
		$env = getenv('MF_EDOST_TIMEOUT');
		if ($env !== false && is_numeric($env))
		{
			return max(1, (int)$env);
		}
		return max(1, (int)Option::get('mf.edost', 'timeout', '10'));
	}

	public static function defaultInsuranceRub(): float
	{
		$env = getenv('MF_EDOST_STRAH');
		if ($env !== false && is_numeric($env))
		{
			return max(0.0, (float)$env);
		}
		return max(0.0, (float)Option::get('mf.edost', 'strah', '0'));
	}

	/** Коды тарифов Почты России в eDost (см. edost.ru/kln/help.html#DeliveryCode). */
	public const RUSSIAN_POST_TARIFF_IDS = ['1', '2', '3', '68', '69', '70', '71', '72', '73', '74', '61', '62'];

	/**
	 * Человекочитаемые названия тарифов Почты России (если API вернул пустое name).
	 *
	 * @return array<string, string>
	 */
	public static function russianPostTariffLabels(): array
	{
		return [
			'1' => 'отправление 1-го класса',
			'2' => 'наземная посылка (обычная)',
			'3' => 'EMS',
			'68' => 'бандероль',
			'69' => 'мелкий пакет (наземная)',
			'70' => 'мелкий пакет (авиа)',
			'71' => 'мелкий пакет заказной (наземная)',
			'72' => 'мелкий пакет заказной (авиа)',
			'73' => 'посылка (наземная)',
			'74' => 'посылка (авиа)',
			'61' => 'посылка онлайн',
			'62' => 'курьер онлайн',
		];
	}

	/**
	 * Страховка для расчёта: по умолчанию сумма корзины (для тарифов «со страховкой» в eDost).
	 */
	public static function insuranceRubForBasketSum(float $basketSumRub): float
	{
		$mode = strtolower(trim((string)(getenv('MF_EDOST_STRAH_MODE') ?: 'basket')));
		if (in_array($mode, ['0', 'off', 'none', 'false', 'no'], true))
		{
			return self::defaultInsuranceRub();
		}
		if ($mode === 'fixed')
		{
			return self::defaultInsuranceRub();
		}

		return max(0.0, $basketSumRub);
	}

	/**
	 * Габариты посылки в метрах для ln/wd/hg (eDost API).
	 *
	 * @return array{ln: float, wd: float, hg: float}|null
	 */
	/** Минимальный вес посылки для eDost, если в корзине нет позиций с весом (кг). Env: MF_EDOST_PARCEL_MIN_WEIGHT_KG */
	public static function defaultParcelWeightKg(): float
	{
		$kg = (float)(getenv('MF_EDOST_PARCEL_MIN_WEIGHT_KG') ?: 1);
		if ($kg <= 0)
		{
			$kg = 1.0;
		}

		return $kg;
	}

	public static function defaultParcelDimensionsM(): ?array
	{
		$lCm = (float)(getenv('MF_EDOST_PARCEL_L_CM') ?: 27);
		$wCm = (float)(getenv('MF_EDOST_PARCEL_W_CM') ?: 17);
		$hCm = (float)(getenv('MF_EDOST_PARCEL_H_CM') ?: 5);
		if ($lCm <= 0 || $wCm <= 0 || $hCm <= 0)
		{
			return null;
		}

		return [
			'ln' => round($lCm / 100, 3),
			'wd' => round($wCm / 100, 3),
			'hg' => round($hCm / 100, 3),
		];
	}

	/**
	 * Суммарный вес корзины для eDost: только позиции с WEIGHT &gt; 0 (г × кол-во).
	 * Если таких нет — {@see defaultParcelWeightKg()} (по умолчанию 1 кг).
	 *
	 * @param iterable<mixed> $basketItems элементы корзины / отгрузки с getWeight(), getQuantity()
	 */
	public static function parcelWeightKgFromBasketItems(iterable $basketItems): float
	{
		$weightGrams = 0.0;

		foreach ($basketItems as $item)
		{
			if (!is_object($item) || !method_exists($item, 'getWeight'))
			{
				continue;
			}
			$w = (float)$item->getWeight();
			if ($w <= 0)
			{
				continue;
			}
			$q = method_exists($item, 'getQuantity') ? max(0.0, (float)$item->getQuantity()) : 1.0;
			$weightGrams += $w * $q;
		}

		if ($weightGrams > 0)
		{
			return round($weightGrams / 1000.0, 3);
		}

		return self::defaultParcelWeightKg();
	}

	/**
	 * Габариты посылки: по позициям с DIMENSIONS — max(длина), max(ширина), max(высота).
	 * Позиции без габаритов пропускаются. Если ни одной — {@see defaultParcelDimensionsM()} (27×17×5 см).
	 *
	 * @param iterable<mixed> $basketItems
	 *
	 * @return array{ln: float, wd: float, hg: float}|null
	 */
	public static function parcelDimensionsMFromBasketItems(iterable $basketItems): ?array
	{
		$maxL = 0.0;
		$maxW = 0.0;
		$maxH = 0.0;
		$has = false;

		foreach ($basketItems as $item)
		{
			if (!is_object($item) || !method_exists($item, 'getField'))
			{
				continue;
			}
			$raw = $item->getField('DIMENSIONS');
			$dim = is_string($raw) ? @unserialize($raw, ['allowed_classes' => false]) : $raw;
			if (!is_array($dim))
			{
				continue;
			}
			$l = (float)($dim['LENGTH'] ?? $dim['length'] ?? 0);
			$w = (float)($dim['WIDTH'] ?? $dim['width'] ?? 0);
			$h = (float)($dim['HEIGHT'] ?? $dim['height'] ?? 0);
			if ($l <= 0 && $w <= 0 && $h <= 0)
			{
				continue;
			}
			$has = true;
			$maxL = max($maxL, $l);
			$maxW = max($maxW, $w);
			$maxH = max($maxH, $h);
		}

		if (!$has)
		{
			return self::defaultParcelDimensionsM();
		}

		// Bitrix DIMENSIONS — миллиметры.
		return [
			'ln' => round(max(1.0, $maxL) / 1000, 3),
			'wd' => round(max(1.0, $maxW) / 1000, 3),
			'hg' => round(max(1.0, $maxH) / 1000, 3),
		];
	}

	/**
	 * @return list<\Bitrix\Sale\BasketItem|\Bitrix\Sale\ShipmentItem|\Bitrix\Sale\BasketItemBase|object>
	 */
	public static function collectBasketLikeItemsFromShipment(\Bitrix\Sale\Shipment $shipment): array
	{
		$out = [];
		try
		{
			$coll = $shipment->getShipmentItemCollection();
			if ($coll)
			{
				foreach ($coll as $shipmentItem)
				{
					if (!is_object($shipmentItem) || !method_exists($shipmentItem, 'getBasketItem'))
					{
						continue;
					}
					$basketItem = $shipmentItem->getBasketItem();
					if ($basketItem)
					{
						$out[] = $basketItem;
					}
				}
			}
		}
		catch (\Throwable $e)
		{
		}

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $offers
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function normalizeOfferLabels(array $offers): array
	{
		$labels = self::russianPostTariffLabels();
		foreach ($offers as $i => $offer)
		{
			if (!is_array($offer))
			{
				continue;
			}
			$id = trim((string)($offer['id'] ?? ''));
			$name = trim((string)($offer['name'] ?? ''));
			if ($name === '' && isset($labels[$id]))
			{
				$offers[$i]['name'] = $labels[$id];
			}
		}

		return $offers;
	}

	/**
	 * @param array<int, array<string, mixed>> ...$lists
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function mergeOffers(array ...$lists): array
	{
		$byId = [];
		foreach ($lists as $list)
		{
			foreach ($list as $offer)
			{
				if (!is_array($offer))
				{
					continue;
				}
				$id = trim((string)($offer['id'] ?? ''));
				if ($id === '')
				{
					continue;
				}
				if (!isset($byId[$id]))
				{
					$byId[$id] = $offer;
				}
			}
		}

		return array_values($byId);
	}

	/**
	 * Наценка на тариф eDost в checkout: (расчётная стоимость + 50 ₽) × 1,15,
	 * затем округление вверх до 10 ₽ (mf_round_price).
	 */
	public static function applyCheckoutMarkup(float $rawPrice): float
	{
		if (!is_finite($rawPrice) || $rawPrice <= 0)
		{
			return max(0.0, $rawPrice);
		}

		$marked = ($rawPrice + 50.0) * 1.15;

		if (function_exists('mf_round_price'))
		{
			return mf_round_price($marked);
		}

		return (float)(ceil($marked / 10.0) * 10.0);
	}

	/**
	 * @param array{ln?:float,wd?:float,hg?:float}|null $dimensionsM
	 *
	 * @return array{ok:bool, stat?:int, warning?:int, error?:string, offers?:array<int, array{id:string,price:float,days_from:int,days_to:int,company:string,name:string,strah:int}>}
	 */
	public static function calculate(
		string $toCityRuUtf8,
		float $weightKg,
		float $insuranceRub,
		string $zipDigits = '',
		?array $dimensionsM = null
	): array {
		$toCityRuUtf8 = trim($toCityRuUtf8);
		$zipDigits = preg_replace('~\\D+~', '', $zipDigits);
		$weightKg = max(0.001, $weightKg);
		$insuranceRub = max(0.0, $insuranceRub);

		if (!self::isConfigured())
		{
			return ['ok' => false, 'error' => 'eDost credentials are not configured'];
		}
		if ($toCityRuUtf8 === '')
		{
			return ['ok' => false, 'error' => 'empty to_city'];
		}

		$toCity1251 = @iconv('UTF-8', 'Windows-1251//IGNORE', $toCityRuUtf8);
		if (!is_string($toCity1251) || $toCity1251 === '')
		{
			return ['ok' => false, 'error' => 'cannot convert to_city to windows-1251'];
		}

		$post = [
			'id' => self::shopId(),
			'p' => self::shopPass(),
			'to_city' => $toCity1251,
			'weight' => (string)round($weightKg, 3),
			'strah' => (string)round($insuranceRub, 2),
			'headerutf' => '1',
		];
		if ($zipDigits !== '')
		{
			$post['zip'] = $zipDigits;
		}
		if (is_array($dimensionsM))
		{
			foreach (['ln' => 'ln', 'wd' => 'wd', 'hg' => 'hg'] as $key => $param)
			{
				$val = (float)($dimensionsM[$key] ?? 0);
				if ($val > 0)
				{
					$post[$param] = (string)round($val, 3);
				}
			}
		}

		$payload = http_build_query($post, '', '&');

		$http = new HttpClient([
			'socketTimeout' => self::timeoutSeconds(),
			'streamTimeout' => self::timeoutSeconds(),
		]);
		$http->setHeader('Content-Type', 'application/x-www-form-urlencoded');

		$body = (string)$http->post(self::endpointPrimary(), $payload);
		if ($body === '' || $http->getStatus() <= 0)
		{
			$body = (string)$http->post(self::endpointFallback(), $payload);
		}

		$xml = @simplexml_load_string($body);
		if ($xml === false)
		{
			return ['ok' => false, 'error' => 'invalid xml from eDost'];
		}

		$stat = (int)($xml->stat ?? 0);
		if ($stat !== 1)
		{
			return ['ok' => false, 'stat' => $stat, 'warning' => (int)($xml->warning ?? 0), 'error' => 'eDost stat=' . (string)$stat];
		}

		$offers = [];
		foreach (($xml->tarif ?? []) as $tarif)
		{
			$id = trim((string)($tarif->id ?? ''));
			$price = (float)str_replace(',', '.', (string)($tarif->price ?? '0'));
			$price = self::applyCheckoutMarkup($price);
			$dayRaw = trim((string)($tarif->day ?? ''));
			[$dFrom, $dTo] = self::parseDaysRange($dayRaw);
			$company = trim((string)($tarif->company ?? ''));
			$name = trim((string)($tarif->name ?? ''));
			$strah = (int)($tarif->strah ?? 0);

			if ($id === '')
			{
				continue;
			}
			// Цена 0 допустима (акции, договорная доставка); иначе такие тарифы отбрасывались и список был пуст.

			$offers[] = [
				'id' => $id,
				'price' => $price,
				'days_from' => $dFrom,
				'days_to' => $dTo,
				'company' => $company,
				'name' => $name,
				'strah' => $strah,
			];
		}
		$offers = self::normalizeOfferLabels($offers);

		return [
			'ok' => true,
			'stat' => $stat,
			'warning' => (int)($xml->warning ?? 0),
			'offers' => $offers,
		];
	}

	/**
	 * @return array{0:int,1:int}
	 */
	private static function parseDaysRange(string $dayRaw): array
	{
		$dayRaw = trim(mb_strtolower($dayRaw));
		$dayRaw = preg_replace('~[^0-9\\-–]+~u', ' ', $dayRaw);
		$dayRaw = trim(preg_replace('~\\s+~', ' ', $dayRaw));

		if ($dayRaw === '')
		{
			return [0, 0];
		}

		if (preg_match('~^(\\d+)\\s*[-–]\\s*(\\d+)$~u', $dayRaw, $m))
		{
			$a = (int)$m[1];
			$b = (int)$m[2];
			$a = max(0, $a);
			$b = max($a, $b);
			return [$a, $b];
		}

		if (preg_match('~^(\\d+)$~u', $dayRaw, $m))
		{
			$a = max(0, (int)$m[1]);
			return [$a, $a];
		}

		// Fallback: extract up to 2 numbers anywhere.
		if (preg_match_all('~\\d+~u', $dayRaw, $mm) && !empty($mm[0]))
		{
			$nums = array_map('intval', array_slice($mm[0], 0, 2));
			$a = max(0, (int)($nums[0] ?? 0));
			$b = max($a, (int)($nums[1] ?? $a));
			return [$a, $b];
		}

		return [0, 0];
	}

	public static function normalizeSettlementName(string $name): string
	{
		$name = trim($name);

		return trim((string)preg_replace('~^г(?:ород)?\s+~iu', '', $name));
	}

	/**
	 * Название региона в формате eDost (см. edost.ru/kln/code.html).
	 * Пример: «Чукотский автономный округ» → «Чукотский АО».
	 */
	public static function normalizeRegionNameForEdost(string $raw): string
	{
		$s = trim(preg_replace('~\s+~u', ' ', $raw));
		if ($s === '')
		{
			return '';
		}

		$s = preg_replace('~[—–−]~u', '-', $s);
		$s = preg_replace('~\s*-\s*~u', ' - ', $s);

		if (preg_match('~^(.+?)\s+автономный\s+округ(?:\s*-\s*(.+))?$~ui', $s, $m))
		{
			$base = trim((string)$m[1]);
			$suffix = isset($m[2]) ? trim((string)$m[2]) : '';
			$out = $base . ' АО';
			if ($suffix !== '')
			{
				$out .= ' - ' . $suffix;
			}

			return $out;
		}

		if (preg_match('~^(.+?)\s+автономная\s+область$~ui', $s, $m))
		{
			return trim((string)$m[1]) . ' АО';
		}

		if (preg_match('~\sАО(\s*-\s*.+)?$~ui', $s))
		{
			return $s;
		}

		return $s;
	}

	/**
	 * @return array{settlement: string, region: string, zip: string}
	 */
	public static function resolveDestinationFromNominatim(array $nom, string $zipFallback = ''): array
	{
		$out = [
			'settlement' => '',
			'region' => '',
			'zip' => preg_replace('~\D+~', '', $zipFallback),
		];
		$addr = isset($nom['address']) && is_array($nom['address']) ? $nom['address'] : [];

		foreach (['city', 'town', 'village', 'locality', 'hamlet', 'municipality'] as $key)
		{
			$cand = trim((string)($addr[$key] ?? ''));
			if ($cand !== '' && preg_match('~\p{Cyrillic}~u', $cand))
			{
				$out['settlement'] = self::normalizeSettlementName($cand);
				break;
			}
		}
		if ($out['settlement'] === '')
		{
			foreach (['name'] as $key)
			{
				$cand = trim((string)($addr[$key] ?? ''));
				if ($cand !== '' && preg_match('~\p{Cyrillic}~u', $cand))
				{
					$out['settlement'] = self::normalizeSettlementName($cand);
					break;
				}
			}
		}
		if ($out['settlement'] === '')
		{
			$display = trim((string)($nom['display_name'] ?? ''));
			if ($display !== '' && preg_match('~[\p{Cyrillic}][^,;]*~u', $display, $m))
			{
				$out['settlement'] = self::normalizeSettlementName(trim((string)$m[0]));
			}
		}

		foreach (['state', 'region'] as $key)
		{
			$cand = trim((string)($addr[$key] ?? ''));
			if ($cand === '' || !preg_match('~\p{Cyrillic}~u', $cand))
			{
				continue;
			}
			$norm = self::normalizeRegionNameForEdost($cand);
			if ($norm !== '')
			{
				$out['region'] = $norm;
				break;
			}
		}

		$postcode = preg_replace('~\D+~', '', (string)($addr['postcode'] ?? ''));
		if ($postcode !== '')
		{
			$out['zip'] = $postcode;
		}

		return $out;
	}

	/**
	 * @return array{settlement: string, region: string, zip: string}
	 */
	public static function resolveDeliveryDestination(string $nomJsonRaw, string $mfEdostToCity, string $locationCode, string $zipFallback = ''): array
	{
		$dest = [
			'settlement' => self::normalizeSettlementName($mfEdostToCity),
			'region' => '',
			'zip' => preg_replace('~\D+~', '', $zipFallback),
		];

		if ($nomJsonRaw !== '')
		{
			$nom = json_decode($nomJsonRaw, true);
			if (is_array($nom))
			{
				$fromNom = self::resolveDestinationFromNominatim($nom, $dest['zip']);
				if ($fromNom['settlement'] !== '')
				{
					$dest['settlement'] = $fromNom['settlement'];
				}
				if ($fromNom['region'] !== '')
				{
					$dest['region'] = $fromNom['region'];
				}
				if ($fromNom['zip'] !== '')
				{
					$dest['zip'] = $fromNom['zip'];
				}
			}
		}

		if ($dest['settlement'] === '' && $locationCode !== '')
		{
			$dest['settlement'] = self::normalizeSettlementName(self::resolveCityNameRuByLocationCode($locationCode));
		}

		return $dest;
	}

	private static function responseHasOffers(array $resp): bool
	{
		if (!($resp['ok'] ?? false) || !isset($resp['offers']) || !is_array($resp['offers']))
		{
			return false;
		}

		foreach ($resp['offers'] as $offer)
		{
			if (is_array($offer) && trim((string)($offer['id'] ?? '')) !== '')
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array{ln?:float,wd?:float,hg?:float}|null $dimensionsM
	 *
	 * @return array<string, mixed>
	 */
	public static function calculateForDestination(
		string $settlement,
		string $region,
		float $weightKg,
		float $insuranceRub,
		string $zipDigits,
		?array $dimensionsM = null
	): array {
		$settlement = self::normalizeSettlementName($settlement);
		$region = self::normalizeRegionNameForEdost($region);
		$zipDigits = preg_replace('~\D+~', '', $zipDigits);

		$lastResp = ['ok' => false, 'error' => 'eDost: destination not resolved'];
		$mergedOffers = [];
		$modes = [];
		$toCityUsed = '';

		$attempts = [];
		if ($settlement !== '')
		{
			$attempts[] = ['mode' => 'settlement_zip', 'city' => $settlement, 'zip' => $zipDigits];
			if ($zipDigits !== '')
			{
				$attempts[] = ['mode' => 'settlement', 'city' => $settlement, 'zip' => ''];
			}
		}
		if ($region !== '' && $zipDigits !== '')
		{
			$attempts[] = ['mode' => 'region_zip', 'city' => $region, 'zip' => $zipDigits];
		}
		if ($region !== '')
		{
			$attempts[] = ['mode' => 'region', 'city' => $region, 'zip' => $zipDigits];
		}

		foreach ($attempts as $attempt)
		{
			$resp = self::calculate(
				(string)$attempt['city'],
				$weightKg,
				$insuranceRub,
				(string)$attempt['zip'],
				$dimensionsM
			);
			$lastResp = $resp;
			if (!self::responseHasOffers($resp))
			{
				continue;
			}
			$modes[] = (string)$attempt['mode'];
			if ($toCityUsed === '')
			{
				$toCityUsed = (string)$attempt['city'];
			}
			$mergedOffers = self::mergeOffers($mergedOffers, (array)$resp['offers']);
		}

		if ($mergedOffers !== [])
		{
			return [
				'ok' => true,
				'stat' => (int)($lastResp['stat'] ?? 1),
				'warning' => (int)($lastResp['warning'] ?? 0),
				'offers' => self::normalizeOfferLabels($mergedOffers),
				'destination_mode' => implode('+', $modes),
				'to_city_used' => $toCityUsed,
				'settlement_requested' => $settlement,
			];
		}

		return $lastResp;
	}

	public static function resolveCityNameRuByLocationCode(string $locationCode): string
	{
		$locationCode = trim($locationCode);
		if ($locationCode === '')
		{
			return '';
		}

		try
		{
			$conn = Application::getConnection();
			$h = $conn->getSqlHelper();
			$codeSql = $h->forSql($locationCode);
			$name = (string)$conn->queryScalar(
				"SELECT n.NAME
				 FROM b_sale_location l
				 JOIN b_sale_loc_name n ON n.LOCATION_ID = l.ID AND n.LANGUAGE_ID = 'ru'
				 WHERE l.CODE = '{$codeSql}'"
			);
			return trim($name);
		}
		catch (\Throwable $e)
		{
			return '';
		}
	}
}

/**
 * Простая тарифная доставка для carrier'ов (СДЭК/Почта/ТК и т.п.).
 *
 * Формула:
 * - если сумма заказа >= FREE_FROM_SUM → 0
 * - иначе: BASE_PRICE + PRICE_PER_KG * weightKg
 * - затем MIN_PRICE (если задан)
 * - затем округление (ROUND=Y → до целого)
 */
final class Tariffed extends Base
{
	/** @var array<string, array<int, array{id:string,price:float,days_from:int,days_to:int,company:string,name:string,strah:int}>> */
	private static $edostOffersHitCache = [];

	// Важно для sale.order.ajax: позволяет посчитать стоимость заранее (DELIVERY_NO_AJAX=H)
	// и показать цены у всех вариантов доставки до выбора.
	protected static $isCalculatePriceImmediately = true;

	public function isCalculatePriceImmediately()
	{
		// В этой версии Bitrix родительский метод использует self:: (без LSB),
		// поэтому флаг в наследнике сам по себе не учитывается.
		return true;
	}

	public static function getClassTitle(): string
	{
		return 'MF: Тарифная доставка (вес/сумма)';
	}

	public static function getClassDescription(): string
	{
		return 'Служба доставки Motor-Force: расчёт по весу и сумме заказа (настраиваемые тарифы).';
	}

	protected function calculateConcrete(Shipment $shipment): CalculationResult
	{
		$result = new CalculationResult();

		$config = is_array($this->config) ? $this->config : [];
		// Backward/forward compatibility: Bitrix can persist config either flat,
		// or grouped by sections returned from getConfigStructure() (MAIN/EDOST/PERIOD).
		// Prefer explicit (flat) values, but fall back to section values if present.
		if (isset($config['MAIN']) && is_array($config['MAIN']))
		{
			$config = array_merge($config['MAIN'], $config);
		}
		if (isset($config['EDOST']) && is_array($config['EDOST']))
		{
			$config = array_merge($config['EDOST'], $config);
		}
		if (isset($config['PERIOD']) && is_array($config['PERIOD']))
		{
			$config = array_merge($config['PERIOD'], $config);
		}

		$base = (float)($config['BASE_PRICE'] ?? 0);
		$perKg = (float)($config['PRICE_PER_KG'] ?? 0);
		$min = (float)($config['MIN_PRICE'] ?? 0);
		$freeFrom = (float)($config['FREE_FROM_SUM'] ?? 0);
		$round = (string)($config['ROUND'] ?? 'Y');
		$useZones = (string)($config['USE_ZONES'] ?? 'N');
		$useEdost = (string)($config['EDOST_ENABLED'] ?? 'N');

		$basketLikeItems = Edost::collectBasketLikeItemsFromShipment($shipment);
		$weightKg = Edost::parcelWeightKgFromBasketItems($basketLikeItems);
		$dimensionsM = Edost::parcelDimensionsMFromBasketItems($basketLikeItems);

		$order = $shipment->getCollection()->getOrder();
		$orderSum = (float)$order->getPrice();

		// Учитываем местоположение + индекс (как минимум для зонных тарифов).
		$locationCode = '';
		$zip = '';
		$props = $order->getPropertyCollection();
		if ($props)
		{
			$locProp = $props->getDeliveryLocation();
			$zipProp = $props->getDeliveryLocationZip();
			$locationCode = $locProp ? (string)$locProp->getValue() : '';
			$zip = $zipProp ? (string)$zipProp->getValue() : '';
		}
		$zipDigits = preg_replace('~\\D+~', '', $zip);
		$zipInt = (int)$zipDigits;

		// Зона по индексу: СПБ / Москва / РФ (прочее).
		$zone = 'RU';
		if ($zipInt >= 190000 && $zipInt <= 199999)
		{
			$zone = 'SPB';
		}
		elseif ($zipInt >= 101000 && $zipInt <= 129999)
		{
			$zone = 'MSK';
		}

		// Опциональные зонные переопределения тарифа (если заполнены в настройках службы доставки).
		$zoneKey = ($useZones === 'Y') ? ($zone === 'SPB' ? 'SPB' : ($zone === 'MSK' ? 'MSK' : '')) : '';
		if ($zoneKey !== '')
		{
			$baseZ = $config['BASE_PRICE_' . $zoneKey] ?? null;
			$perKgZ = $config['PRICE_PER_KG_' . $zoneKey] ?? null;
			$minZ = $config['MIN_PRICE_' . $zoneKey] ?? null;
			$freeFromZ = $config['FREE_FROM_SUM_' . $zoneKey] ?? null;

			// Переопределения включаем только если задано значение > 0,
			// чтобы дефолтные нули не “обнулили” доставку после сохранения в админке.
			if ($baseZ !== null && (float)$baseZ > 0)
			{
				$base = (float)$baseZ;
			}
			if ($perKgZ !== null && (float)$perKgZ > 0)
			{
				$perKg = (float)$perKgZ;
			}
			if ($minZ !== null && (float)$minZ > 0)
			{
				$min = (float)$minZ;
			}
			if ($freeFromZ !== null && (float)$freeFromZ > 0)
			{
				$freeFrom = (float)$freeFromZ;
			}
		}

		$price = 0.0;
		$edostOffer = null;
		if ($useEdost === 'Y' && Edost::isConfigured())
		{
			$isLocalHost = false;
			try
			{
				$host = (string)($_SERVER['HTTP_HOST'] ?? '');
				$isLocalHost = ($host === 'localhost' || $host === '127.0.0.1');
			}
			catch (\Throwable $e)
			{
				$isLocalHost = false;
			}

			$companyFilter = trim((string)($config['EDOST_COMPANY'] ?? ''));
			if ($companyFilter === '')
			{
				// По умолчанию пробуем по названию службы (СДЭК/Почта России/EMS/...)
				$companyFilter = trim((string)($config['CARRIER'] ?? ''));
			}
			if ($companyFilter === '')
			{
				$companyFilter = trim((string)$this->getName());
			}

			$toCityOverride = '';
			try
			{
				$cityProp = $props->getItemByOrderPropertyCode('MF_EDOST_TO_CITY');
				if ($cityProp)
				{
					$toCityOverride = trim((string)$cityProp->getValue());
				}
			}
			catch (\Throwable $e)
			{
				$toCityOverride = '';
			}

			$toCity = $toCityOverride !== '' ? $toCityOverride : Edost::resolveCityNameRuByLocationCode($locationCode);
			$insuranceMode = (string)($config['EDOST_INSURANCE'] ?? 'N');
			$insurance = $insuranceMode === 'Y' ? $orderSum : Edost::defaultInsuranceRub();

			$cacheTtl = (int)($config['EDOST_CACHE_TTL'] ?? 15);
			$cacheTtl = max(0, $cacheTtl);
			// Avoid stale cached "empty offers" while debugging locally.
			if ($isLocalHost)
			{
				$cacheTtl = 0;
			}

			$dimKey = '';
			if (is_array($dimensionsM))
			{
				$dimKey = implode('x', [
					(string)round((float)($dimensionsM['ln'] ?? 0), 3),
					(string)round((float)($dimensionsM['wd'] ?? 0), 3),
					(string)round((float)($dimensionsM['hg'] ?? 0), 3),
				]);
			}
			$cacheKey = implode('|', [
				'v2',
				mb_strtolower($toCity),
				(string)$zipDigits,
				(string)round($weightKg, 3),
				(string)round($insurance, 2),
				$dimKey,
			]);
			$cacheDir = 'mf/edost';

			$offers = null;
			$resp = null;

			// Per-hit in-memory cache to avoid N eDost HTTP calls for N delivery cards.
			if (isset(self::$edostOffersHitCache[$cacheKey]) && is_array(self::$edostOffersHitCache[$cacheKey]))
			{
				$offers = self::$edostOffersHitCache[$cacheKey];
			}

			if ($offers === null && $cacheTtl > 0 && class_exists(\Bitrix\Main\Data\Cache::class))
			{
				$cache = \Bitrix\Main\Data\Cache::createInstance();
				if ($cache->initCache($cacheTtl * 60, md5($cacheKey), $cacheDir))
				{
					$data = $cache->getVars();
					if (is_array($data) && isset($data['offers']) && is_array($data['offers']))
					{
						$offers = $data['offers'];
					}
				}
				elseif ($cache->startDataCache())
				{
					$resp = Edost::calculate($toCity, $weightKg, $insurance, (string)$zipDigits, $dimensionsM);
					$offers = (is_array($resp) && ($resp['ok'] ?? false) && isset($resp['offers']) && is_array($resp['offers'])) ? $resp['offers'] : [];
					$cache->endDataCache(['offers' => $offers]);
				}
			}
			elseif ($offers === null)
			{
				$resp = Edost::calculate($toCity, $weightKg, $insurance, (string)$zipDigits, $dimensionsM);
				$offers = (is_array($resp) && ($resp['ok'] ?? false) && isset($resp['offers']) && is_array($resp['offers'])) ? $resp['offers'] : [];
			}

			$offers = is_array($offers) ? $offers : [];
			self::$edostOffersHitCache[$cacheKey] = $offers;
			$norm = static function(string $s): string {
				$s = mb_strtolower(trim($s));
				// keep letters/digits only to make matching robust across punctuation/spaces.
				$s = preg_replace('~[^\\p{L}\\p{N}]+~u', '', $s);
				return (string)$s;
			};
			$companyNeedle = $norm((string)$companyFilter);
			$filtered = array_values(array_filter($offers, static function ($o) use ($companyNeedle, $norm) {
				if (!is_array($o))
				{
					return false;
				}
				if ($companyNeedle === '')
				{
					return true;
				}
				$company = $norm((string)($o['company'] ?? ''));
				if ($company === '')
				{
					return false;
				}
				// Allow partial match in either direction:
				// "Почта России" should match "EMS Почта России" if account returns only EMS.
				return (mb_strpos($company, $companyNeedle) !== false) || (mb_strpos($companyNeedle, $company) !== false);
			}));

			$pick = (string)($config['EDOST_PICK'] ?? 'CHEAPEST');
			$pick = strtoupper(trim($pick));
			$list = $filtered ?: $offers;

			// Optional: pick a specific eDost tariff within the (filtered) offers.
			$tariffIdNeedle = trim((string)($config['EDOST_TARIFF_ID'] ?? ''));
			$tariffNameNeedle = trim((string)($config['EDOST_TARIFF_NAME'] ?? ''));
			$templateOnly = (string)($config['EDOST_TEMPLATE_ONLY'] ?? 'N');
			// "Template-only" delivery is used as a single hidden delivery service on checkout.
			// It must NOT produce an error (otherwise sale.order.ajax shows "cannot calculate delivery"),
			// and its price must not affect the order total.
			if ($templateOnly === 'Y' && $tariffIdNeedle === '' && $tariffNameNeedle === '')
			{
				$result->setDeliveryPrice(0.0);
				return $result;
			}
			if ($tariffIdNeedle !== '' || $tariffNameNeedle !== '')
			{
				$tid = preg_replace('~\\D+~', '', $tariffIdNeedle);
				$tname = $norm($tariffNameNeedle);
				$byTariff = array_values(array_filter($list, static function ($o) use ($tid, $tname, $norm) {
					if (!is_array($o))
					{
						return false;
					}
					if ($tid !== '')
					{
						return (string)($o['id'] ?? '') === $tid;
					}
					if ($tname === '')
					{
						return false;
					}
					$name = $norm((string)($o['name'] ?? ''));
					return $name !== '' && (mb_strpos($name, $tname) !== false || mb_strpos($tname, $name) !== false);
				}));
				if (!empty($byTariff))
				{
					$edostOffer = $byTariff[0] ?? null;
				}
			}

			// If a fixed tariff was requested but not available for this destination,
			// make this delivery variant unavailable (so it won't be shown/selected).
			if (($tariffIdNeedle !== '' || $tariffNameNeedle !== '') && !is_array($edostOffer))
			{
				$result->addError(new \Bitrix\Main\Error('eDost tariff is not available for this destination'));
				return $result;
			}

			// Default: choose cheapest/fastest within company.
			if (!is_array($edostOffer) && !empty($list))
			{
				usort($list, static function ($a, $b) use ($pick) {
					$pa = (float)($a['price'] ?? 0);
					$pb = (float)($b['price'] ?? 0);
					$da = (int)($a['days_to'] ?? 0);
					$db = (int)($b['days_to'] ?? 0);

					if ($pick === 'FASTEST')
					{
						return ($da <=> $db) ?: ($pa <=> $pb);
					}
					return ($pa <=> $pb) ?: ($da <=> $db);
				});
				$edostOffer = $list[0] ?? null;
			}

			// Local debug log (no UI impact).
			if ($isLocalHost)
			{
				try
				{
					$line = json_encode([
						'ts' => date('c'),
						'delivery_id' => method_exists($this, 'getId') ? $this->getId() : null,
						'delivery_name' => method_exists($this, 'getName') ? $this->getName() : null,
						'use_edost' => $useEdost,
						'configured' => Edost::isConfigured(),
						'to_city' => $toCity,
						'zip' => (string)$zipDigits,
						'weightKg' => round(max(0.001, (float)$weightKg), 3),
						'company_filter' => $companyFilter,
						'resp_ok' => is_array($resp) ? (bool)($resp['ok'] ?? false) : null,
						'resp_stat' => is_array($resp) ? ($resp['stat'] ?? null) : null,
						'resp_error' => is_array($resp) ? ($resp['error'] ?? null) : null,
						'offers_total' => is_array($offers) ? count($offers) : null,
						'offers_filtered' => is_array($filtered) ? count($filtered) : null,
						'offer_pick' => $edostOffer,
					], JSON_UNESCAPED_UNICODE);
					@file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/.tmp_edost_calc.log', $line . PHP_EOL, FILE_APPEND);
				}
				catch (\Throwable $e)
				{
					// ignore
				}
			}
		}

		if (is_array($edostOffer) && (float)($edostOffer['price'] ?? 0) > 0)
		{
			$price = (float)$edostOffer['price'];
		}
		elseif (!($freeFrom > 0 && $orderSum >= $freeFrom))
		{
			$price = $base + ($perKg * $weightKg);
			if ($min > 0)
			{
				$price = max($price, $min);
			}
		}

		// Бесплатная доставка от суммы заказа — приоритетнее расчёта (и eDost, и тарифного).
		if ($freeFrom > 0 && $orderSum >= $freeFrom)
		{
			$price = 0.0;
		}

		if ($round !== 'N')
		{
			$price = (float)round($price);
		}

		if ($price < 0)
		{
			$price = 0.0;
		}

		$result->setDeliveryPrice($price);

		$carrier = (string)($config['CARRIER'] ?? '');
		$desc = [];
		$edostSetPeriod = false;
		if ($carrier !== '')
		{
			$desc[] = 'Служба: ' . $carrier;
		}
		if ($freeFrom > 0)
		{
			$desc[] = 'Бесплатно от: ' . (string)$freeFrom;
		}
		if ($base > 0 || $perKg > 0)
		{
			$desc[] = 'Тариф: ' . (string)$base . ' + ' . (string)$perKg . ' × кг';
		}
		if ($min > 0)
		{
			$desc[] = 'Минимум: ' . (string)$min;
		}
		if ($locationCode !== '' || $zip !== '')
		{
			$desc[] = 'Регион: ' . ($locationCode !== '' ? $locationCode : '—') . ', индекс: ' . ($zip !== '' ? $zip : '—');
		}
		if ($zone !== 'RU' && $useZones === 'Y')
		{
			$desc[] = 'Зона: ' . $zone;
		}
		if (is_array($edostOffer))
		{
			$edCompany = trim((string)($edostOffer['company'] ?? ''));
			$edName = trim((string)($edostOffer['name'] ?? ''));
			$edId = trim((string)($edostOffer['id'] ?? ''));
			$edDaysFrom = (int)($edostOffer['days_from'] ?? 0);
			$edDaysTo = (int)($edostOffer['days_to'] ?? 0);

			$label = 'eDost';
			if ($edCompany !== '')
			{
				$label .= ': ' . $edCompany;
			}
			if ($edName !== '')
			{
				$label .= ' — ' . $edName;
			}
			if ($edId !== '')
			{
				$label .= ' (тариф ' . $edId . ')';
			}
			$desc[] = $label;

			if ($edDaysFrom > 0 || $edDaysTo > 0)
			{
				$edDaysFrom = max(0, $edDaysFrom);
				$edDaysTo = max($edDaysFrom, $edDaysTo);
				$result->setPeriodFrom($edDaysFrom);
				$result->setPeriodTo($edDaysTo);
				$result->setPeriodDescription('Срок: ' . ($edDaysFrom > 0 ? $edDaysFrom : 0) . '–' . ($edDaysTo > 0 ? $edDaysTo : $edDaysFrom) . ' дн.');
				$edostSetPeriod = true;
			}
		}
		if ($desc)
		{
			$result->setDescription(implode('. ', $desc) . '.');
		}

		$fromDays = (int)($config['PERIOD_FROM_DAYS'] ?? 0);
		$toDays = (int)($config['PERIOD_TO_DAYS'] ?? 0);
		if (!$edostSetPeriod && ($fromDays > 0 || $toDays > 0))
		{
			$fromDays = max(0, $fromDays);
			$toDays = max($fromDays, $toDays);
			$result->setPeriodFrom($fromDays);
			$result->setPeriodTo($toDays);
			$result->setPeriodDescription('Срок: ' . ($fromDays > 0 ? $fromDays : 0) . '–' . ($toDays > 0 ? $toDays : $fromDays) . ' дн.');
		}

		return $result;
	}

	protected function getConfigStructure()
	{
		Loc::loadMessages(__FILE__);

		return [
			'MAIN' => [
				'TITLE' => 'Тариф',
				'DESCRIPTION' => '',
				'ITEMS' => [
					'CARRIER' => [
						'TYPE' => 'STRING',
						'NAME' => 'Код/название службы (для описания)',
						'DEFAULT' => '',
					],
					'BASE_PRICE' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'Базовая стоимость',
						'DEFAULT' => 0,
					],
					'PRICE_PER_KG' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'Стоимость за 1 кг',
						'DEFAULT' => 0,
					],
					'MIN_PRICE' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'Минимальная стоимость',
						'DEFAULT' => 0,
					],
					'FREE_FROM_SUM' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'Бесплатно от суммы заказа',
						'DEFAULT' => 0,
					],
					'ROUND' => [
						'TYPE' => 'ENUM',
						'NAME' => 'Округлять цену до целого',
						'DEFAULT' => 'Y',
						'OPTIONS' => [
							'Y' => 'Да',
							'N' => 'Нет',
						],
					],
					'USE_ZONES' => [
						'TYPE' => 'ENUM',
						'NAME' => 'Использовать зонные тарифы по индексу (СПБ/Москва)',
						'DEFAULT' => 'N',
						'OPTIONS' => [
							'Y' => 'Да',
							'N' => 'Нет',
						],
					],
					'BASE_PRICE_SPB' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'СПБ: базовая стоимость (индекс 190000–199999)',
						'DEFAULT' => 0,
					],
					'PRICE_PER_KG_SPB' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'СПБ: стоимость за 1 кг',
						'DEFAULT' => 0,
					],
					'MIN_PRICE_SPB' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'СПБ: минимальная стоимость',
						'DEFAULT' => 0,
					],
					'FREE_FROM_SUM_SPB' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'СПБ: бесплатно от суммы заказа',
						'DEFAULT' => 0,
					],
					'BASE_PRICE_MSK' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'Москва: базовая стоимость (индекс 101000–129999)',
						'DEFAULT' => 0,
					],
					'PRICE_PER_KG_MSK' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'Москва: стоимость за 1 кг',
						'DEFAULT' => 0,
					],
					'MIN_PRICE_MSK' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'Москва: минимальная стоимость',
						'DEFAULT' => 0,
					],
					'FREE_FROM_SUM_MSK' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'Москва: бесплатно от суммы заказа',
						'DEFAULT' => 0,
					],
				],
			],
			'EDOST' => [
				'TITLE' => 'eDost (онлайн-расчёт)',
				'DESCRIPTION' => 'Если включено и настроены MF_EDOST_ID/MF_EDOST_PASS, цена и срок берутся из eDost.',
				'ITEMS' => [
					'EDOST_ENABLED' => [
						'TYPE' => 'ENUM',
						'NAME' => 'Использовать eDost для расчёта',
						'DEFAULT' => 'N',
						'OPTIONS' => [
							'Y' => 'Да',
							'N' => 'Нет',
						],
					],
					'EDOST_COMPANY' => [
						'TYPE' => 'STRING',
						'NAME' => 'Фильтр company (например: СДЭК, Почта России, EMS, Деловые линии, ПЭК, Энергия)',
						'DEFAULT' => '',
					],
					'EDOST_TEMPLATE_ONLY' => [
						'TYPE' => 'ENUM',
						'NAME' => 'Шаблонная служба (скрыть базовую карточку, использовать только для генерации вариантов)',
						'DEFAULT' => 'N',
						'OPTIONS' => [
							'Y' => 'Да',
							'N' => 'Нет',
						],
					],
					'EDOST_PICK' => [
						'TYPE' => 'ENUM',
						'NAME' => 'Как выбирать тариф внутри company',
						'DEFAULT' => 'CHEAPEST',
						'OPTIONS' => [
							'CHEAPEST' => 'Самый дешёвый',
							'FASTEST' => 'Самый быстрый',
						],
					],
					'EDOST_TARIFF_ID' => [
						'TYPE' => 'STRING',
						'NAME' => 'Фиксированный tarif id (если задан — выбираем именно его)',
						'DEFAULT' => '',
					],
					'EDOST_TARIFF_NAME' => [
						'TYPE' => 'STRING',
						'NAME' => 'Фиксированный тариф по названию (подстрока name из eDost)',
						'DEFAULT' => '',
					],
					'EDOST_INSURANCE' => [
						'TYPE' => 'ENUM',
						'NAME' => 'Страховка = сумма заказа (параметр strah)',
						'DEFAULT' => 'N',
						'OPTIONS' => [
							'Y' => 'Да',
							'N' => 'Нет',
						],
					],
					'EDOST_CACHE_TTL' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'Кэш eDost (минут)',
						'DEFAULT' => 15,
					],
				],
			],
			'PERIOD' => [
				'TITLE' => 'Срок доставки',
				'DESCRIPTION' => '',
				'ITEMS' => [
					'PERIOD_FROM_DAYS' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'От (дней)',
						'DEFAULT' => 0,
					],
					'PERIOD_TO_DAYS' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'До (дней)',
						'DEFAULT' => 0,
					],
				],
			],
		];
	}
}

