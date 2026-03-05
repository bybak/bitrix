<?php
declare(strict_types=1);

namespace MF\Delivery;

use Bitrix\Main\Localization\Loc;
use Bitrix\Sale\Delivery\CalculationResult;
use Bitrix\Sale\Delivery\Services\Base;
use Bitrix\Sale\Shipment;

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

		$base = (float)($config['BASE_PRICE'] ?? 0);
		$perKg = (float)($config['PRICE_PER_KG'] ?? 0);
		$min = (float)($config['MIN_PRICE'] ?? 0);
		$freeFrom = (float)($config['FREE_FROM_SUM'] ?? 0);
		$round = (string)($config['ROUND'] ?? 'Y');
		$useZones = (string)($config['USE_ZONES'] ?? 'N');

		$weight = (float)$shipment->getWeight(); // grams in Bitrix
		$weightKg = $weight > 0 ? ($weight / 1000.0) : 0.0;

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

		// Зона по индексу: СПб / Москва / РФ (прочее).
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
		if ($freeFrom > 0 && $orderSum >= $freeFrom)
		{
			$price = 0.0;
		}
		else
		{
			$price = $base + ($perKg * $weightKg);
			if ($min > 0)
			{
				$price = max($price, $min);
			}
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
		if ($desc)
		{
			$result->setDescription(implode('. ', $desc) . '.');
		}

		$fromDays = (int)($config['PERIOD_FROM_DAYS'] ?? 0);
		$toDays = (int)($config['PERIOD_TO_DAYS'] ?? 0);
		if ($fromDays > 0 || $toDays > 0)
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
						'NAME' => 'Использовать зонные тарифы по индексу (СПб/Москва)',
						'DEFAULT' => 'N',
						'OPTIONS' => [
							'Y' => 'Да',
							'N' => 'Нет',
						],
					],
					'BASE_PRICE_SPB' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'СПб: базовая стоимость (индекс 190000–199999)',
						'DEFAULT' => 0,
					],
					'PRICE_PER_KG_SPB' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'СПб: стоимость за 1 кг',
						'DEFAULT' => 0,
					],
					'MIN_PRICE_SPB' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'СПб: минимальная стоимость',
						'DEFAULT' => 0,
					],
					'FREE_FROM_SUM_SPB' => [
						'TYPE' => 'NUMBER',
						'NAME' => 'СПб: бесплатно от суммы заказа',
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

