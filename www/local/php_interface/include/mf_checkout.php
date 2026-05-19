<?php

if (!function_exists('mf_checkout_custom_flow_enabled'))
{
	function mf_checkout_custom_flow_enabled(array $arParams): bool
	{
		return (($arParams['MF_CUSTOM_GUEST_FLOW'] ?? 'N') === 'Y')
			|| (($arParams['MF_ORDER_MAKE_SIGNATURE'] ?? 'N') === 'Y');
	}
}

if (!function_exists('mf_checkout_person_type_map'))
{
	function mf_checkout_person_type_map(): array
	{
		static $map = null;
		if (is_array($map))
		{
			return $map;
		}

		$map = [
			'fiz' => 0,
			'jur' => 0,
		];

		if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return $map;
		}
		if (!class_exists(\Bitrix\Sale\Internals\PersonTypeTable::class))
		{
			return $map;
		}

		$rows = [];
		$rs = \Bitrix\Sale\Internals\PersonTypeTable::getList([
			'select' => ['ID', 'NAME', 'SORT'],
			'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
		]);
		while ($row = $rs->fetch())
		{
			$rows[] = $row;
			$name = mb_strtolower(trim((string)($row['NAME'] ?? '')));
			$id = (int)($row['ID'] ?? 0);
			if ($id <= 0)
			{
				continue;
			}

			if ($map['jur'] <= 0 && mb_strpos($name, 'юрид') !== false)
			{
				$map['jur'] = $id;
				continue;
			}
			if ($map['fiz'] <= 0 && (mb_strpos($name, 'физ') !== false || mb_strpos($name, 'част') !== false))
			{
				$map['fiz'] = $id;
			}
		}

		if ($map['fiz'] <= 0 && !empty($rows[0]['ID']))
		{
			$map['fiz'] = (int)$rows[0]['ID'];
		}
		if ($map['jur'] <= 0 && !empty($rows[1]['ID']))
		{
			$map['jur'] = (int)$rows[1]['ID'];
		}

		return $map;
	}
}

if (!function_exists('mf_checkout_invoice_pay_system_ids'))
{
	function mf_checkout_invoice_pay_system_ids(): array
	{
		static $ids = null;
		if (is_array($ids))
		{
			return $ids;
		}

		$ids = [];
		if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return $ids;
		}
		if (!class_exists(\Bitrix\Sale\PaySystem\Manager::class))
		{
			return $ids;
		}

		$rs = \Bitrix\Sale\PaySystem\Manager::getList([
			'filter' => ['=ACTIVE' => 'Y'],
			'select' => ['ID', 'CODE', 'ACTION_FILE', 'NAME'],
			'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
		]);
		while ($row = $rs->fetch())
		{
			$id = (int)($row['ID'] ?? 0);
			$actionFile = mb_strtolower(trim((string)($row['ACTION_FILE'] ?? '')));
			$code = mb_strtolower(trim((string)($row['CODE'] ?? '')));
			if ($id <= 0)
			{
				continue;
			}
			if ($actionFile === 'bill' || $code === 'mf_bill_jur')
			{
				$ids[] = $id;
			}
		}

		$ids = array_values(array_unique(array_map('intval', $ids)));
		return $ids;
	}
}

if (!function_exists('mf_checkout_apply_pay_system_filter'))
{
	function mf_checkout_apply_pay_system_filter(array &$paySystems, array $allowedIds): void
	{
		$allowedIds = array_values(array_unique(array_filter(array_map('intval', $allowedIds))));
		if (empty($allowedIds))
		{
			return;
		}

		$filtered = [];
		$hasChecked = false;
		foreach ($paySystems as $paySystem)
		{
			$id = (int)($paySystem['ID'] ?? 0);
			if ($id <= 0 || !in_array($id, $allowedIds, true))
			{
				continue;
			}

			if (($paySystem['CHECKED'] ?? 'N') === 'Y')
			{
				$hasChecked = true;
			}
			$filtered[] = $paySystem;
		}

		if (empty($filtered))
		{
			return;
		}

		if (!$hasChecked)
		{
			foreach ($filtered as $idx => $paySystem)
			{
				$filtered[$idx]['CHECKED'] = ($idx === 0 ? 'Y' : 'N');
			}
		}

		$paySystems = array_values($filtered);
	}
}

if (!function_exists('mf_checkout_props_version'))
{
	function mf_checkout_props_version(): string
	{
		return '2026-04-06-03';
	}
}

if (!function_exists('mf_checkout_ensure_confirm_channel_prop'))
{
	/**
	 * Поле «удобный способ подтверждения заказа» в блоке покупателя (физ / юр).
	 */
	function mf_checkout_ensure_confirm_channel_prop(): void
	{
		static $done = false;
		if ($done)
		{
			return;
		}
		$done = true;

		try
		{
			if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
			{
				return;
			}
			if (!class_exists(\Bitrix\Main\Config\Option::class) || !class_exists('CSaleOrderProps'))
			{
				return;
			}

			$version = mf_checkout_props_version() . '-confirm-channel-v2';
			try
			{
				if (\Bitrix\Main\Config\Option::get('main', 'mf_checkout_confirm_channel_setup', '') === $version)
				{
					return;
				}
			}
			catch (\Throwable $e)
			{
			}

			$personTypes = mf_checkout_person_type_map();
			$defs = [];
			foreach (['fiz', 'jur'] as $personKey)
			{
				$personTypeId = (int)($personTypes[$personKey] ?? 0);
				if ($personTypeId <= 0)
				{
					continue;
				}

				$propsGroupId = 1;
				$sample = \CSaleOrderProps::GetList(
					['SORT' => 'ASC'],
					[
						'PERSON_TYPE_ID' => $personTypeId,
						'CODE' => 'EMAIL',
					],
					false,
					false,
					['ID', 'PROPS_GROUP_ID']
				)->Fetch();
				if (is_array($sample) && (int)($sample['PROPS_GROUP_ID'] ?? 0) > 0)
				{
					$propsGroupId = (int)$sample['PROPS_GROUP_ID'];
				}

				$sort = 265;
				$phoneRow = \CSaleOrderProps::GetList(
					['SORT' => 'DESC'],
					[
						'PERSON_TYPE_ID' => $personTypeId,
						'CODE' => 'PHONE',
					],
					false,
					false,
					['SORT']
				)->Fetch();
				if (is_array($phoneRow) && (int)($phoneRow['SORT'] ?? 0) > 0)
				{
					$sort = (int)$phoneRow['SORT'] + 5;
				}

				$defs[] = [
					'PERSON_TYPE_ID' => $personTypeId,
					'NAME' => 'Удобный способ подтверждения заказа',
					'TYPE' => 'STRING',
					'SETTINGS' => [
						'SIZE' => '60',
						'MAXLENGTH' => '500',
					],
					'REQUIRED' => 'Y',
					'SORT' => $sort,
					'USER_PROPS' => 'Y',
					'PROPS_GROUP_ID' => $propsGroupId,
					'CODE' => 'MF_CONFIRM_CHANNEL',
					'ACTIVE' => 'Y',
					'UTIL' => 'N',
					'ENTITY_TYPE' => 'ORDER',
					'ENTITY_REGISTRY_TYPE' => 'ORDER',
					'DESCRIPTION' => 'Например: телефон, email, WhatsApp, Viber, Telegram, Max — укажите способ и контакт (номер, ник и т.д.).',
				];
			}

			if ($defs === [])
			{
				return;
			}

			foreach ($defs as $fields)
			{
				$current = \CSaleOrderProps::GetList(
					[],
					[
						'PERSON_TYPE_ID' => (int)$fields['PERSON_TYPE_ID'],
						'CODE' => (string)$fields['CODE'],
					],
					false,
					false,
					['ID', 'CODE', 'REQUIRED', 'PROPS_GROUP_ID', 'SORT', 'TYPE', 'NAME', 'DESCRIPTION', 'SETTINGS']
				)->Fetch();

				if (is_array($current) && (int)($current['ID'] ?? 0) > 0)
				{
					$updateFields = [];
					foreach (['NAME', 'TYPE', 'REQUIRED', 'SORT', 'PROPS_GROUP_ID', 'DESCRIPTION'] as $fieldName)
					{
						$currentValue = (string)($current[$fieldName] ?? '');
						$targetValue = (string)($fields[$fieldName] ?? '');
						if ($currentValue !== $targetValue)
						{
							$updateFields[$fieldName] = $fields[$fieldName];
						}
					}
					if (array_key_exists('SETTINGS', $fields) && is_array($fields['SETTINGS']))
					{
						$curSt = $current['SETTINGS'] ?? [];
						if (is_string($curSt))
						{
							$curSt = @unserialize($curSt, ['allowed_classes' => false]);
						}
						$curSt = is_array($curSt) ? $curSt : [];
						if ($curSt != $fields['SETTINGS'])
						{
							$updateFields['SETTINGS'] = $fields['SETTINGS'];
						}
					}
					if ($updateFields !== [])
					{
						\CSaleOrderProps::Update((int)$current['ID'], $updateFields);
					}
					continue;
				}

				\CSaleOrderProps::Add($fields);
			}

			if (class_exists(\Bitrix\Main\Application::class))
			{
				$sqlHelper = \Bitrix\Main\Application::getConnection()->getSqlHelper();
				foreach ($defs as $fields)
				{
					$updates = [];
					foreach (['NAME', 'TYPE', 'REQUIRED', 'SORT', 'USER_PROPS', 'PROPS_GROUP_ID', 'ACTIVE', 'UTIL', 'ENTITY_TYPE', 'ENTITY_REGISTRY_TYPE', 'DESCRIPTION'] as $fieldName)
					{
						if (!array_key_exists($fieldName, $fields))
						{
							continue;
						}
						$updates[] = $fieldName . " = '" . $sqlHelper->forSql((string)$fields[$fieldName]) . "'";
					}
					if (empty($updates))
					{
						continue;
					}

					\Bitrix\Main\Application::getConnection()->queryExecute(
						"UPDATE b_sale_order_props SET "
						. implode(', ', $updates)
						. " WHERE PERSON_TYPE_ID = " . (int)$fields['PERSON_TYPE_ID']
						. " AND CODE = '" . $sqlHelper->forSql((string)$fields['CODE']) . "'"
					);
				}
			}

			try
			{
				\Bitrix\Main\Config\Option::set('main', 'mf_checkout_confirm_channel_setup', $version);
			}
			catch (\Throwable $e)
			{
			}
		}
		catch (\Throwable $e)
		{
			// ignore
		}
	}
}

if (!function_exists('mf_checkout_ensure_payment_systems'))
{
	function mf_checkout_ensure_payment_systems(): void
	{
		static $done = false;
		if ($done)
		{
			return;
		}
		$done = true;

		if (
			!class_exists(\Bitrix\Main\Loader::class)
			|| !\Bitrix\Main\Loader::includeModule('sale')
			|| !class_exists(\Bitrix\Sale\PaySystem\Manager::class)
			|| !class_exists(\Bitrix\Sale\Internals\ServiceRestrictionTable::class)
			|| !class_exists(\Bitrix\Sale\Services\Base\RestrictionManager::class)
			|| !class_exists(\Bitrix\Main\Config\Option::class)
		)
		{
			return;
		}

		$version = mf_checkout_props_version() . '-pay';
		try
		{
			if (\Bitrix\Main\Config\Option::get('main', 'mf_checkout_pay_systems_setup', '') === $version)
			{
				return;
			}
		}
		catch (\Throwable $e)
		{
			// ignore and continue
		}

		$personTypes = mf_checkout_person_type_map();
		$fizPersonTypeId = (int)($personTypes['fiz'] ?? 0);
		if ($fizPersonTypeId <= 0)
		{
			return;
		}

		$code = 'MF_CASH_OFFICE_FIZ';
		$name = 'Наличными в офисе';
		$fields = [
			'CODE' => $code,
			'NAME' => $name,
			'PSA_NAME' => $name,
			'ACTIVE' => 'Y',
			'SORT' => 130,
			'ACTION_FILE' => 'cash',
			'NEW_WINDOW' => 'N',
			'PERSON_TYPE_ID' => $fizPersonTypeId,
			'ENTITY_REGISTRY_TYPE' => 'ORDER',
			'ALLOW_EDIT_PAYMENT' => 'Y',
			'HAVE_PAYMENT' => 'Y',
			'HAVE_RESULT_RECEIVE' => 'N',
		];

		$serviceId = 0;
		try
		{
			$row = \Bitrix\Sale\PaySystem\Manager::getByCode($code);
			if (is_array($row) && !empty($row['ID']))
			{
				$serviceId = (int)$row['ID'];
			}
		}
		catch (\Throwable $e)
		{
			// ignore and continue
		}

		if ($serviceId <= 0)
		{
			try
			{
				$rs = \Bitrix\Sale\PaySystem\Manager::getList([
					'filter' => ['=ACTION_FILE' => 'cash', '=ACTIVE' => 'Y'],
					'select' => ['ID', 'CODE', 'NAME'],
					'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
				]);
				while ($row = $rs->fetch())
				{
					$rowId = (int)($row['ID'] ?? 0);
					if ($rowId <= 0)
					{
						continue;
					}
					$rowCode = trim((string)($row['CODE'] ?? ''));
					$rowName = trim((string)($row['NAME'] ?? ''));
					if ($rowCode === '' || $rowCode === $code || $rowName === $name)
					{
						$serviceId = $rowId;
						break;
					}
				}
			}
			catch (\Throwable $e)
			{
				// ignore and continue
			}
		}

		try
		{
			if ($serviceId > 0)
			{
				$result = \Bitrix\Sale\PaySystem\Manager::update($serviceId, $fields);
			}
			else
			{
				$result = \Bitrix\Sale\PaySystem\Manager::add($fields);
				if ($result->isSuccess())
				{
					$serviceId = (int)$result->getId();
				}
			}
			if (!isset($result) || !$result->isSuccess() || $serviceId <= 0)
			{
				return;
			}
		}
		catch (\Throwable $e)
		{
			return;
		}

		try
		{
			$class = \Bitrix\Sale\Services\PaySystem\Restrictions\PersonType::class;
			$rs = \Bitrix\Sale\Internals\ServiceRestrictionTable::getList([
				'filter' => [
					'=SERVICE_ID' => $serviceId,
					'=SERVICE_TYPE' => \Bitrix\Sale\Services\Base\RestrictionManager::SERVICE_TYPE_PAYMENT,
					'=CLASS_NAME' => '\\' . ltrim($class, '\\'),
				],
				'select' => ['ID'],
			]);
			while ($row = $rs->fetch())
			{
				\Bitrix\Sale\Internals\ServiceRestrictionTable::delete((int)$row['ID']);
			}

			\Bitrix\Sale\Internals\ServiceRestrictionTable::add([
				'SERVICE_ID' => $serviceId,
				'SERVICE_TYPE' => \Bitrix\Sale\Services\Base\RestrictionManager::SERVICE_TYPE_PAYMENT,
				'SORT' => 100,
				'CLASS_NAME' => '\\' . ltrim($class, '\\'),
				'PARAMS' => ['PERSON_TYPE_ID' => [$fizPersonTypeId]],
			]);
		}
		catch (\Throwable $e)
		{
			// ignore
		}

		try
		{
			\Bitrix\Main\Config\Option::set('main', 'mf_checkout_pay_systems_setup', $version);
		}
		catch (\Throwable $e)
		{
			// ignore
		}
	}
}

if (!function_exists('mf_checkout_ensure_delivery_address_props'))
{
	function mf_checkout_ensure_delivery_address_props(): void
	{
		static $done = false;
		if ($done)
		{
			return;
		}
		$done = true;

		if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return;
		}
		if (!class_exists(\Bitrix\Main\Config\Option::class) || !class_exists('CSaleOrderProps'))
		{
			return;
		}

		$version = mf_checkout_props_version() . '-delivery-address';
		try
		{
			if (\Bitrix\Main\Config\Option::get('main', 'mf_checkout_delivery_address_setup', '') === $version)
			{
				return;
			}
		}
		catch (\Throwable $e)
		{
			// ignore and continue
		}

		$personTypes = mf_checkout_person_type_map();
		$defs = [];
		foreach (['fiz', 'jur'] as $personKey)
		{
			$personTypeId = (int)($personTypes[$personKey] ?? 0);
			if ($personTypeId <= 0)
			{
				continue;
			}

			$defs[] = [
				'PERSON_TYPE_ID' => $personTypeId,
				'NAME' => 'Город (Населенный пункт), Область, Край и т.д.',
				'TYPE' => 'STRING',
				'REQUIRED' => 'Y',
				'SORT' => 270,
				'USER_PROPS' => 'Y',
				'PROPS_GROUP_ID' => 4,
				'CODE' => 'DELIVERY_LOCATION_TEXT',
				'ACTIVE' => 'Y',
				'UTIL' => 'N',
				'ENTITY_TYPE' => 'ORDER',
				'ENTITY_REGISTRY_TYPE' => 'ORDER',
			];
			$defs[] = [
				'PERSON_TYPE_ID' => $personTypeId,
				'NAME' => 'Улица, Дом, Квартира',
				'TYPE' => 'STRING',
				'REQUIRED' => 'Y',
				'SORT' => 280,
				'USER_PROPS' => 'Y',
				'PROPS_GROUP_ID' => 4,
				'CODE' => 'DELIVERY_ADDRESS',
				'ACTIVE' => 'Y',
				'UTIL' => 'N',
				'ENTITY_TYPE' => 'ORDER',
				'ENTITY_REGISTRY_TYPE' => 'ORDER',
			];
			$defs[] = [
				'PERSON_TYPE_ID' => $personTypeId,
				'NAME' => 'Индекс',
				'TYPE' => 'STRING',
				'REQUIRED' => 'N',
				'SORT' => 290,
				'USER_PROPS' => 'Y',
				'PROPS_GROUP_ID' => 4,
				'CODE' => 'DELIVERY_ZIP',
				'ACTIVE' => 'Y',
				'UTIL' => 'N',
				'ENTITY_TYPE' => 'ORDER',
				'ENTITY_REGISTRY_TYPE' => 'ORDER',
			];
		}

		if (empty($defs))
		{
			return;
		}

		foreach ($defs as $fields)
		{
			$current = \CSaleOrderProps::GetList(
				[],
				[
					'PERSON_TYPE_ID' => (int)$fields['PERSON_TYPE_ID'],
					'CODE' => (string)$fields['CODE'],
				],
				false,
				false,
				['ID', 'CODE', 'REQUIRED', 'PROPS_GROUP_ID', 'SORT', 'TYPE', 'NAME']
			)->Fetch();

			if (is_array($current) && (int)($current['ID'] ?? 0) > 0)
			{
				$updateFields = [];
				foreach (['NAME', 'TYPE', 'REQUIRED', 'SORT', 'PROPS_GROUP_ID'] as $fieldName)
				{
					$currentValue = (string)($current[$fieldName] ?? '');
					$targetValue = (string)($fields[$fieldName] ?? '');
					if ($currentValue !== $targetValue)
					{
						$updateFields[$fieldName] = $fields[$fieldName];
					}
				}
				if (!empty($updateFields))
				{
					\CSaleOrderProps::Update((int)$current['ID'], $updateFields);
				}
				continue;
			}

			\CSaleOrderProps::Add($fields);
		}

		if (class_exists(\Bitrix\Main\Application::class))
		{
			$sqlHelper = \Bitrix\Main\Application::getConnection()->getSqlHelper();
			foreach ($defs as $fields)
			{
				$updates = [];
				foreach (['NAME', 'TYPE', 'REQUIRED', 'SORT', 'USER_PROPS', 'PROPS_GROUP_ID', 'ACTIVE', 'UTIL', 'ENTITY_TYPE', 'ENTITY_REGISTRY_TYPE'] as $fieldName)
				{
					if (!array_key_exists($fieldName, $fields))
					{
						continue;
					}
					$updates[] = $fieldName . " = '" . $sqlHelper->forSql((string)$fields[$fieldName]) . "'";
				}
				if (empty($updates))
				{
					continue;
				}

				\Bitrix\Main\Application::getConnection()->queryExecute(
					"UPDATE b_sale_order_props SET "
					. implode(', ', $updates)
					. " WHERE PERSON_TYPE_ID = " . (int)$fields['PERSON_TYPE_ID']
					. " AND CODE = '" . $sqlHelper->forSql((string)$fields['CODE']) . "'"
				);
			}
		}

		try
		{
			\Bitrix\Main\Config\Option::set('main', 'mf_checkout_delivery_address_setup', $version);
		}
		catch (\Throwable $e)
		{
			// ignore
		}
	}
}

if (!function_exists('mf_checkout_ensure_nominatim_geo_props'))
{
	function mf_checkout_ensure_nominatim_geo_props(): void
	{
		static $done = false;
		if ($done)
		{
			return;
		}
		$done = true;

		try
		{
			if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
			{
				return;
			}
			if (!class_exists(\Bitrix\Main\Config\Option::class) || !class_exists('CSaleOrderProps'))
			{
				return;
			}

			// v2: TYPE STRING + SETTINGS[MULTILINE] — в Sale\Internals\Input\Manager нет типа TEXTAREA (только STRING и др.).
			$version = mf_checkout_props_version() . '-nominatim-geo-v2';
			try
			{
				if (\Bitrix\Main\Config\Option::get('main', 'mf_checkout_nominatim_geo_setup', '') === $version)
				{
					return;
				}
			}
			catch (\Throwable $e)
			{
			}

			$personTypes = mf_checkout_person_type_map();
			$defs = [];
			foreach (['fiz', 'jur'] as $personKey)
			{
				$personTypeId = (int)($personTypes[$personKey] ?? 0);
				if ($personTypeId <= 0)
				{
					continue;
				}

				$propsGroupId = 4;
				$sample = \CSaleOrderProps::GetList(
					['SORT' => 'ASC'],
					[
						'PERSON_TYPE_ID' => $personTypeId,
						'CODE' => 'DELIVERY_LOCATION_TEXT',
					],
					false,
					false,
					['ID', 'PROPS_GROUP_ID']
				)->Fetch();
				if (is_array($sample) && (int)($sample['PROPS_GROUP_ID'] ?? 0) > 0)
				{
					$propsGroupId = (int)$sample['PROPS_GROUP_ID'];
				}

				$defs[] = [
					'PERSON_TYPE_ID' => $personTypeId,
					'NAME' => 'Геоданные адреса (OSM, служебное)',
					'TYPE' => 'STRING',
					'SETTINGS' => [
						'MULTILINE' => 'Y',
						'ROWS' => '12',
						'COLS' => '96',
						'MAXLENGTH' => '65000',
					],
					'REQUIRED' => 'N',
					'SORT' => 292,
					'USER_PROPS' => 'N',
					'PROPS_GROUP_ID' => $propsGroupId,
					'CODE' => 'MF_NOMINATIM_JSON',
					'ACTIVE' => 'Y',
					'UTIL' => 'Y',
					'ENTITY_TYPE' => 'ORDER',
					'ENTITY_REGISTRY_TYPE' => 'ORDER',
				];
				$defs[] = [
					'PERSON_TYPE_ID' => $personTypeId,
					'NAME' => 'Город для eDost (служебное)',
					'TYPE' => 'STRING',
					'REQUIRED' => 'N',
					'SORT' => 293,
					'USER_PROPS' => 'N',
					'PROPS_GROUP_ID' => $propsGroupId,
					'CODE' => 'MF_EDOST_TO_CITY',
					'ACTIVE' => 'Y',
					'UTIL' => 'Y',
					'ENTITY_TYPE' => 'ORDER',
					'ENTITY_REGISTRY_TYPE' => 'ORDER',
				];
			}

			if (empty($defs))
			{
				return;
			}

			foreach ($defs as $fields)
			{
				$current = \CSaleOrderProps::GetList(
					[],
					[
						'PERSON_TYPE_ID' => (int)$fields['PERSON_TYPE_ID'],
						'CODE' => (string)$fields['CODE'],
					],
					false,
					false,
					['ID', 'CODE', 'REQUIRED', 'PROPS_GROUP_ID', 'SORT', 'TYPE', 'NAME', 'SETTINGS']
				)->Fetch();

				if (is_array($current) && (int)($current['ID'] ?? 0) > 0)
				{
					$updateFields = [];
					foreach (['NAME', 'TYPE', 'REQUIRED', 'SORT', 'PROPS_GROUP_ID'] as $fieldName)
					{
						$currentValue = (string)($current[$fieldName] ?? '');
						$targetValue = (string)($fields[$fieldName] ?? '');
						if ($currentValue !== $targetValue)
						{
							$updateFields[$fieldName] = $fields[$fieldName];
						}
					}
					if (array_key_exists('SETTINGS', $fields) && is_array($fields['SETTINGS']))
					{
						$curSt = $current['SETTINGS'] ?? [];
						if (is_string($curSt))
						{
							$curSt = @unserialize($curSt, ['allowed_classes' => false]);
						}
						$curSt = is_array($curSt) ? $curSt : [];
						if ($curSt != $fields['SETTINGS'])
						{
							$updateFields['SETTINGS'] = $fields['SETTINGS'];
						}
					}
					// Исправление уже созданных свойств с устаревшим TYPE=TEXTAREA (не зарегистрирован в Input\Manager).
					if ((string)($fields['CODE'] ?? '') === 'MF_NOMINATIM_JSON' && (string)($current['TYPE'] ?? '') === 'TEXTAREA')
					{
						$updateFields['TYPE'] = 'STRING';
						if (isset($fields['SETTINGS']) && is_array($fields['SETTINGS']))
						{
							$updateFields['SETTINGS'] = $fields['SETTINGS'];
						}
					}
					if (!empty($updateFields))
					{
						\CSaleOrderProps::Update((int)$current['ID'], $updateFields);
					}
					continue;
				}

				\CSaleOrderProps::Add($fields);
			}

			if (class_exists(\Bitrix\Main\Application::class))
			{
				$sqlHelper = \Bitrix\Main\Application::getConnection()->getSqlHelper();
				foreach ($defs as $fields)
				{
					$updates = [];
					foreach (['NAME', 'TYPE', 'REQUIRED', 'SORT', 'USER_PROPS', 'PROPS_GROUP_ID', 'ACTIVE', 'UTIL', 'ENTITY_TYPE', 'ENTITY_REGISTRY_TYPE'] as $fieldName)
					{
						if (!array_key_exists($fieldName, $fields))
						{
							continue;
						}
						$updates[] = $fieldName . " = '" . $sqlHelper->forSql((string)$fields[$fieldName]) . "'";
					}
					if (empty($updates))
					{
						continue;
					}

					\Bitrix\Main\Application::getConnection()->queryExecute(
						"UPDATE b_sale_order_props SET "
						. implode(', ', $updates)
						. " WHERE PERSON_TYPE_ID = " . (int)$fields['PERSON_TYPE_ID']
						. " AND CODE = '" . $sqlHelper->forSql((string)$fields['CODE']) . "'"
					);
				}
			}

			try
			{
				\Bitrix\Main\Config\Option::set('main', 'mf_checkout_nominatim_geo_setup', $version);
			}
			catch (\Throwable $e)
			{
			}
		}
		catch (\Throwable $e)
		{
			// Не ломаем сайт, если создание свойств недоступно (БД, права, конфликт кодов).
		}
	}
}

if (!function_exists('mf_checkout_ensure_company_order_props'))
{
	function mf_checkout_ensure_company_order_props(): void
	{
		static $done = false;
		if ($done)
		{
			return;
		}
		$done = true;

		if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return;
		}
		if (!class_exists(\Bitrix\Main\Config\Option::class) || !class_exists('CSaleOrderProps'))
		{
			return;
		}

		$version = mf_checkout_props_version();
		try
		{
			if (\Bitrix\Main\Config\Option::get('main', 'mf_checkout_props_setup', '') === $version)
			{
				return;
			}
		}
		catch (\Throwable $e)
		{
			// ignore and continue
		}

		$personTypes = mf_checkout_person_type_map();
		$jurPersonTypeId = (int)($personTypes['jur'] ?? 0);
		if ($jurPersonTypeId <= 0)
		{
			return;
		}

		$defs = [
			'COMPANY' => [
				'PERSON_TYPE_ID' => $jurPersonTypeId,
				'NAME' => 'Название компании',
				'TYPE' => 'STRING',
				'REQUIRED' => 'Y',
				'SORT' => 200,
				'USER_PROPS' => 'Y',
				'PROPS_GROUP_ID' => 3,
				'CODE' => 'COMPANY',
				'ACTIVE' => 'Y',
				'UTIL' => 'N',
				'IS_PAYER' => 'Y',
				'ENTITY_TYPE' => 'ORDER',
				'ENTITY_REGISTRY_TYPE' => 'ORDER',
			],
			'COMPANY_ADR' => [
				'PERSON_TYPE_ID' => $jurPersonTypeId,
				'NAME' => 'Юридический адрес',
				'TYPE' => 'STRING',
				'REQUIRED' => 'Y',
				'SORT' => 210,
				'USER_PROPS' => 'Y',
				'PROPS_GROUP_ID' => 3,
				'CODE' => 'COMPANY_ADR',
				'ACTIVE' => 'Y',
				'UTIL' => 'N',
				'ENTITY_TYPE' => 'ORDER',
				'ENTITY_REGISTRY_TYPE' => 'ORDER',
			],
			'INN' => [
				'PERSON_TYPE_ID' => $jurPersonTypeId,
				'NAME' => 'ИНН',
				'TYPE' => 'STRING',
				'REQUIRED' => 'Y',
				'SORT' => 220,
				'USER_PROPS' => 'Y',
				'PROPS_GROUP_ID' => 3,
				'CODE' => 'INN',
				'ACTIVE' => 'Y',
				'UTIL' => 'N',
				'ENTITY_TYPE' => 'ORDER',
				'ENTITY_REGISTRY_TYPE' => 'ORDER',
			],
			'KPP' => [
				'PERSON_TYPE_ID' => $jurPersonTypeId,
				'NAME' => 'КПП',
				'TYPE' => 'STRING',
				'REQUIRED' => 'Y',
				'SORT' => 230,
				'USER_PROPS' => 'Y',
				'PROPS_GROUP_ID' => 3,
				'CODE' => 'KPP',
				'ACTIVE' => 'Y',
				'UTIL' => 'N',
				'ENTITY_TYPE' => 'ORDER',
				'ENTITY_REGISTRY_TYPE' => 'ORDER',
			],
			'OGRN' => [
				'PERSON_TYPE_ID' => $jurPersonTypeId,
				'NAME' => 'ОГРН / ОГРНИП',
				'TYPE' => 'STRING',
				'REQUIRED' => 'Y',
				'SORT' => 235,
				'USER_PROPS' => 'Y',
				'PROPS_GROUP_ID' => 3,
				'CODE' => 'OGRN',
				'ACTIVE' => 'Y',
				'UTIL' => 'N',
				'ENTITY_TYPE' => 'ORDER',
				'ENTITY_REGISTRY_TYPE' => 'ORDER',
			],
			'CONTACT_PERSON' => [
				'PERSON_TYPE_ID' => $jurPersonTypeId,
				'NAME' => 'Контактное лицо',
				'TYPE' => 'STRING',
				'REQUIRED' => 'Y',
				'SORT' => 240,
				'USER_PROPS' => 'Y',
				'PROPS_GROUP_ID' => 4,
				'CODE' => 'CONTACT_PERSON',
				'ACTIVE' => 'Y',
				'UTIL' => 'N',
				'ENTITY_TYPE' => 'ORDER',
				'ENTITY_REGISTRY_TYPE' => 'ORDER',
			],
			'EMAIL' => [
				'PERSON_TYPE_ID' => $jurPersonTypeId,
				'NAME' => 'E-Mail',
				'TYPE' => 'STRING',
				'REQUIRED' => 'Y',
				'SORT' => 250,
				'USER_PROPS' => 'Y',
				'PROPS_GROUP_ID' => 4,
				'CODE' => 'EMAIL',
				'ACTIVE' => 'Y',
				'UTIL' => 'N',
				'IS_EMAIL' => 'Y',
				'ENTITY_TYPE' => 'ORDER',
				'ENTITY_REGISTRY_TYPE' => 'ORDER',
			],
			'PHONE' => [
				'PERSON_TYPE_ID' => $jurPersonTypeId,
				'NAME' => 'Телефон',
				'TYPE' => 'STRING',
				'REQUIRED' => 'Y',
				'SORT' => 260,
				'USER_PROPS' => 'Y',
				'PROPS_GROUP_ID' => 4,
				'CODE' => 'PHONE',
				'ACTIVE' => 'Y',
				'UTIL' => 'N',
				'IS_PHONE' => 'Y',
				'ENTITY_TYPE' => 'ORDER',
				'ENTITY_REGISTRY_TYPE' => 'ORDER',
			],
			'BANK_DETAILS' => [
				'PERSON_TYPE_ID' => $jurPersonTypeId,
				'NAME' => 'Банковские реквизиты',
				'TYPE' => 'STRING',
				'REQUIRED' => 'Y',
				'SORT' => 265,
				'USER_PROPS' => 'Y',
				'PROPS_GROUP_ID' => 3,
				'CODE' => 'BANK_DETAILS',
				'ACTIVE' => 'Y',
				'UTIL' => 'N',
				'ENTITY_TYPE' => 'ORDER',
				'ENTITY_REGISTRY_TYPE' => 'ORDER',
			],
		];

		foreach ($defs as $code => $fields)
		{
			$current = \CSaleOrderProps::GetList(
				[],
				[
					'PERSON_TYPE_ID' => (int)$fields['PERSON_TYPE_ID'],
					'CODE' => $code,
				],
				false,
				false,
				['ID', 'CODE', 'REQUIRED', 'PROPS_GROUP_ID', 'SORT', 'TYPE', 'NAME', 'SETTINGS']
			)->Fetch();

			if (is_array($current) && (int)($current['ID'] ?? 0) > 0)
			{
				$updateFields = [];
				foreach (['NAME', 'TYPE', 'REQUIRED', 'SORT', 'PROPS_GROUP_ID', 'SETTINGS'] as $fieldName)
				{
					$currentValue = (string)($current[$fieldName] ?? '');
					$targetValue = (string)($fields[$fieldName] ?? '');
					if ($currentValue !== $targetValue)
					{
						$updateFields[$fieldName] = $fields[$fieldName];
					}
				}
				foreach (['IS_EMAIL', 'IS_PHONE', 'IS_PAYER'] as $fieldName)
				{
					if (array_key_exists($fieldName, $fields))
					{
						$updateFields[$fieldName] = $fields[$fieldName];
					}
				}
				if (!empty($updateFields))
				{
					\CSaleOrderProps::Update((int)$current['ID'], $updateFields);
				}
				continue;
			}

			\CSaleOrderProps::Add($fields);
		}

		if (class_exists(\Bitrix\Main\Application::class))
		{
			$sqlHelper = \Bitrix\Main\Application::getConnection()->getSqlHelper();
			foreach ($defs as $code => $fields)
			{
				$updates = [];
				foreach (['NAME', 'TYPE', 'REQUIRED', 'SORT', 'USER_PROPS', 'PROPS_GROUP_ID', 'ACTIVE', 'UTIL', 'ENTITY_TYPE', 'ENTITY_REGISTRY_TYPE'] as $fieldName)
				{
					if (!array_key_exists($fieldName, $fields))
					{
						continue;
					}
					$updates[] = $fieldName . " = '" . $sqlHelper->forSql((string)$fields[$fieldName]) . "'";
				}
				foreach (['IS_EMAIL', 'IS_PHONE', 'IS_PAYER', 'SETTINGS'] as $fieldName)
				{
					if (!array_key_exists($fieldName, $fields))
					{
						continue;
					}
					$updates[] = $fieldName . " = '" . $sqlHelper->forSql((string)$fields[$fieldName]) . "'";
				}
				if (empty($updates))
				{
					continue;
				}

				\Bitrix\Main\Application::getConnection()->queryExecute(
					"UPDATE b_sale_order_props SET "
					. implode(', ', $updates)
					. " WHERE PERSON_TYPE_ID = " . (int)$jurPersonTypeId
					. " AND CODE = '" . $sqlHelper->forSql((string)$code) . "'"
				);
			}
		}

		try
		{
			\Bitrix\Main\Config\Option::set('main', 'mf_checkout_props_setup', $version);
		}
		catch (\Throwable $e)
		{
			// ignore
		}
	}
}

if (!function_exists('mf_checkout_on_order_user_result'))
{
	function mf_checkout_on_order_user_result(array &$arUserResult, \Bitrix\Main\HttpRequest $request, array &$arParams): void
	{
		if (!mf_checkout_custom_flow_enabled($arParams))
		{
			return;
		}

		$mode = trim((string)$request->get('MF_CHECKOUT_MODE'));
		if ($mode !== 'register')
		{
			$mode = 'guest';
		}
		$arUserResult['MF_CHECKOUT_MODE'] = $mode;
		$arUserResult['MF_RESET_PERSON_TYPE_SWITCH'] = ($request->get('MF_RESET_PERSON_TYPE_SWITCH') === 'Y' ? 'Y' : 'N');

		if ($arUserResult['MF_RESET_PERSON_TYPE_SWITCH'] === 'Y')
		{
			$arUserResult['PROFILE_ID'] = 0;
			$arUserResult['PROFILE_CHANGE'] = 'Y';
			$arUserResult['PAY_SYSTEM_ID'] = 0;
			$arUserResult['DELIVERY_ID'] = 0;
			$arUserResult['BUYER_STORE'] = 0;
			$arUserResult['DELIVERY_EXTRA_SERVICES'] = [];
			$arUserResult['ORDER_PROP'] = [];
		}

		$personTypeId = (int)($arUserResult['PERSON_TYPE_ID'] ?? 0);
		$personTypes = mf_checkout_person_type_map();
		$jurPersonTypeId = (int)($personTypes['jur'] ?? 0);
		if (
			$arUserResult['MF_RESET_PERSON_TYPE_SWITCH'] !== 'Y'
			&& $jurPersonTypeId > 0
			&& $personTypeId === $jurPersonTypeId
		)
		{
			$invoiceIds = mf_checkout_invoice_pay_system_ids();
			if (!empty($invoiceIds))
			{
				$currentPaySystemId = (int)($arUserResult['PAY_SYSTEM_ID'] ?? 0);
				if ($currentPaySystemId <= 0 || !in_array($currentPaySystemId, $invoiceIds, true))
				{
					$arUserResult['PAY_SYSTEM_ID'] = $invoiceIds[0];
					$arUserResult['CALCULATE_PAYMENT'] = true;
				}
			}
		}

		// eDost / Nominatim: тарифы считаются по MF_* в POST, а обязательное IS_LOCATION в ORDER_PROP может быть пустым.
		try
		{
			$personTypeId = (int)($arUserResult['PERSON_TYPE_ID'] ?? 0);
			if ($personTypeId <= 0)
			{
				$personTypeId = (int)$request->getPost('PERSON_TYPE');
			}
			if ($personTypeId <= 0 || !\Bitrix\Main\Loader::includeModule('sale'))
			{
				return;
			}
			$locationPropId = 0;
			if (class_exists(\CSaleOrderProps::class))
			{
				$dbRes = \CSaleOrderProps::GetList(
					['SORT' => 'ASC'],
					[
						'PERSON_TYPE_ID' => $personTypeId,
						'ACTIVE' => 'Y',
						'IS_LOCATION' => 'Y',
					],
					false,
					false,
					['ID']
				);
				if ($row = $dbRes->Fetch())
				{
					$locationPropId = (int)($row['ID'] ?? 0);
				}
			}
			if ($locationPropId <= 0)
			{
				return;
			}
			if (!isset($arUserResult['ORDER_PROP']) || !is_array($arUserResult['ORDER_PROP']))
			{
				$arUserResult['ORDER_PROP'] = [];
			}
			$cur = '';
			if (array_key_exists($locationPropId, $arUserResult['ORDER_PROP']))
			{
				$v = $arUserResult['ORDER_PROP'][$locationPropId];
				$cur = is_array($v) ? trim((string)reset($v)) : trim((string)$v);
			}
			if ($cur === '')
			{
				$cur = trim((string)$request->getPost('ORDER_PROP_' . $locationPropId));
			}
			if ($cur !== '')
			{
				return;
			}
			$edostTid = trim((string)$request->getPost('MF_EDOST_TARIF_ID'));
			$nom = trim((string)$request->getPost('MF_NOMINATIM_JSON'));
			$toCity = trim((string)$request->getPost('MF_EDOST_TO_CITY'));
			if ($toCity === '')
			{
				$toCity = trim((string)$request->getPost('mf_edost_to_city'));
			}
			if ($edostTid === '' && $nom === '' && $toCity === '')
			{
				return;
			}
			$fb = mf_checkout_resolve_fallback_location_code();
			if ($fb === '')
			{
				return;
			}
			$arUserResult['ORDER_PROP'][$locationPropId] = $fb;
		}
		catch (\Throwable $e)
		{
			// ignore
		}
	}
}

if (!function_exists('mf_checkout_order_prop_scalar'))
{
	function mf_checkout_order_prop_scalar(array $arUserResult, int $propId): string
	{
		if ($propId <= 0 || !isset($arUserResult['ORDER_PROP'][$propId]))
		{
			return '';
		}
		$v = $arUserResult['ORDER_PROP'][$propId];
		if (is_array($v))
		{
			$v = reset($v);
		}

		return trim((string)$v);
	}
}

if (!function_exists('mf_checkout_on_order_properties'))
{
	/**
	 * Подставляет в «легаси» обязательное свойство «Адрес доставки» (и аналоги) строку из DELIVERY_*,
	 * если оно не попало в POST (в форме только город/улица Motor-Force).
	 */
	function mf_checkout_on_order_properties(array &$arUserResult, $request, array &$arParams, array &$arResult): void
	{
		if (!mf_checkout_custom_flow_enabled($arParams))
		{
			return;
		}
		if (!isset($arUserResult['ORDER_PROP']) || !is_array($arUserResult['ORDER_PROP']))
		{
			return;
		}
		if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale') || !class_exists('CSaleOrderProps'))
		{
			return;
		}

		$personTypeId = (int)($arUserResult['PERSON_TYPE_ID'] ?? 0);
		if ($personTypeId <= 0)
		{
			return;
		}

		$byCode = [];
		$db = \CSaleOrderProps::GetList(
			['SORT' => 'ASC'],
			['PERSON_TYPE_ID' => $personTypeId, 'ACTIVE' => 'Y'],
			false,
			false,
			['ID', 'CODE']
		);
		while ($row = $db->Fetch())
		{
			$cid = strtoupper(trim((string)($row['CODE'] ?? '')));
			if ($cid === 'DELIVERY_LOCATION_TEXT' || $cid === 'DELIVERY_ADDRESS' || $cid === 'DELIVERY_ZIP')
			{
				$byCode[$cid] = (int)($row['ID'] ?? 0);
			}
		}

		$locId = (int)($byCode['DELIVERY_LOCATION_TEXT'] ?? 0);
		$strId = (int)($byCode['DELIVERY_ADDRESS'] ?? 0);
		$zipId = (int)($byCode['DELIVERY_ZIP'] ?? 0);

		$city = mf_checkout_order_prop_scalar($arUserResult, $locId);
		$street = mf_checkout_order_prop_scalar($arUserResult, $strId);
		$zip = mf_checkout_order_prop_scalar($arUserResult, $zipId);

		$parts = [];
		if ($city !== '')
		{
			$parts[] = $city;
		}
		if ($street !== '')
		{
			$parts[] = $street;
		}
		$combined = implode(', ', $parts);
		if ($zip !== '')
		{
			$combined = trim($combined . ($combined !== '' ? ', ' : '') . $zip);
		}
		if ($combined === '')
		{
			return;
		}

		$db2 = \CSaleOrderProps::GetList(
			['SORT' => 'ASC'],
			['PERSON_TYPE_ID' => $personTypeId, 'ACTIVE' => 'Y', 'REQUIRED' => 'Y'],
			false,
			false,
			['ID', 'CODE', 'NAME', 'TYPE', 'UTIL']
		);
		while ($row = $db2->Fetch())
		{
			if (($row['UTIL'] ?? 'N') === 'Y')
			{
				continue;
			}
			$pid = (int)($row['ID'] ?? 0);
			if ($pid <= 0 || $pid === $locId || $pid === $strId || $pid === $zipId)
			{
				continue;
			}
			$type = strtoupper((string)($row['TYPE'] ?? ''));
			if (!in_array($type, ['STRING', 'TEXT', 'TEXTAREA'], true))
			{
				continue;
			}
			$name = trim((string)($row['NAME'] ?? ''));
			$code = strtoupper(trim((string)($row['CODE'] ?? '')));
			$legacy = ($name === 'Адрес доставки')
				|| ($code === 'ADDRESS')
				|| ($code === 'FULL_ADDRESS')
				|| ($code === 'DELIVERY_ADDR');
			if (!$legacy)
			{
				continue;
			}
			if (mf_checkout_order_prop_scalar($arUserResult, $pid) !== '')
			{
				continue;
			}
			$arUserResult['ORDER_PROP'][$pid] = $combined;
		}
	}
}

if (!function_exists('mf_checkout_default_edost_offer'))
{
	function mf_checkout_default_edost_offer(array $jsData): ?array
	{
		try
		{
			if (
				!class_exists(\Bitrix\Main\Loader::class)
				|| !\Bitrix\Main\Loader::includeModule('sale')
			)
			{
				return null;
			}

			if (!class_exists(\Bitrix\Sale\Basket::class) || !class_exists(\Bitrix\Sale\Fuser::class))
			{
				return null;
			}

			$locationCode = '';
			$properties = $jsData['ORDER_PROP']['properties'] ?? [];
			if (is_array($properties))
			{
				foreach ($properties as $property)
				{
					if (($property['IS_LOCATION'] ?? 'N') !== 'Y')
					{
						continue;
					}

					$value = $property['VALUE'] ?? '';
					if (is_array($value))
					{
						$value = reset($value);
					}
					$locationCode = trim((string)$value);
					break;
				}
			}

			if ($locationCode === '')
			{
				return null;
			}

			$tariffFile = $_SERVER['DOCUMENT_ROOT'] . '/mf_delivery_tariffed.php';
			if (!is_file($tariffFile))
			{
				return null;
			}
			require_once $tariffFile;

			if (!class_exists(\MF\Delivery\Edost::class))
			{
				return null;
			}

			$toCity = \MF\Delivery\Edost::resolveCityNameRuByLocationCode($locationCode);
			if ($toCity === '')
			{
				return null;
			}

			$siteId = (string)(\Bitrix\Main\Application::getInstance()->getContext()->getSite() ?: 's1');
			$fuserId = \Bitrix\Sale\Fuser::getId();
			$basket = \Bitrix\Sale\Basket::loadItemsForFUser($fuserId, $siteId);

			$weightGrams = 0.0;
			foreach ($basket as $item)
			{
				$q = (float)$item->getQuantity();
				$w = (float)$item->getWeight();
				$weightGrams += max(0.0, $w) * max(0.0, $q);
			}

			$weightKg = $weightGrams > 0 ? ($weightGrams / 1000.0) : 0.001;
			$resp = \MF\Delivery\Edost::calculate($toCity, $weightKg, 0.0, '');
			if (!is_array($resp) || !($resp['ok'] ?? false) || empty($resp['offers']) || !is_array($resp['offers']))
			{
				return null;
			}

			$offers = array_values(array_filter($resp['offers'], static function ($offer) {
				return is_array($offer) && trim((string)($offer['id'] ?? '')) !== '';
			}));
			if (empty($offers))
			{
				return null;
			}

			usort($offers, static function ($a, $b) {
				$ca = mb_strtolower(trim((string)($a['company'] ?? '')));
				$cb = mb_strtolower(trim((string)($b['company'] ?? '')));
				if ($ca !== $cb)
				{
					return $ca <=> $cb;
				}
				$pa = (float)($a['price'] ?? 0);
				$pb = (float)($b['price'] ?? 0);
				if ($pa !== $pb)
				{
					return $pa <=> $pb;
				}
				$na = mb_strtolower(trim((string)($a['name'] ?? '')));
				$nb = mb_strtolower(trim((string)($b['name'] ?? '')));
				return $na <=> $nb;
			});

			$offer = $offers[0];
			$name = trim((string)($offer['name'] ?? ''));
			$company = trim((string)($offer['company'] ?? ''));
			if ($name === '')
			{
				$name = $company;
			}

			return [
				'id' => (string)($offer['id'] ?? ''),
				'company' => $company,
				'name' => $name,
				'price' => (string)($offer['price'] ?? ''),
			];
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}
}

if (!function_exists('mf_checkout_virtual_delivery_stub_for_js'))
{
	/**
	 * Одна реальная служба доставки из БД для JS sale.order.ajax при пустом списке (виртуальный eDost).
	 * Иначе getSelectedDelivery() = false и ломается свёрнутый блок / инициализация.
	 */
	function mf_checkout_virtual_delivery_stub_for_js(\Bitrix\Sale\Order $order): ?array
	{
		try
		{
			if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
			{
				return null;
			}

			$row = null;
			$envId = (int)(getenv('MF_CHECKOUT_PLACEHOLDER_DELIVERY_ID') ?: 0);
			if ($envId > 0 && class_exists(\Bitrix\Sale\Delivery\Services\Table::class))
			{
				$row = \Bitrix\Sale\Delivery\Services\Table::getRowById($envId);
				if ($row && (($row['ACTIVE'] ?? 'N') !== 'Y'))
				{
					$row = null;
				}
			}

			if (!$row && class_exists(\Bitrix\Sale\Delivery\Services\Table::class))
			{
				$res = \Bitrix\Sale\Delivery\Services\Table::getList([
					'filter' => [
						'=ACTIVE' => 'Y',
						'=PARENT_ID' => 0,
					],
					'select' => ['ID', 'NAME', 'DESCRIPTION', 'SORT'],
					'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
					'limit' => 1,
				]);
				$row = $res->fetch() ?: null;
			}

			if (!$row || (int)($row['ID'] ?? 0) <= 0)
			{
				return null;
			}

			$currency = (string)$order->getCurrency();
			if ($currency === '')
			{
				$currency = 'RUB';
			}

			$name = trim((string)($row['NAME'] ?? ''));
			if ($name === '')
			{
				$name = 'Доставка';
			}

			$zeroFormatted = function_exists('SaleFormatCurrency')
				? SaleFormatCurrency(0.0, $currency)
				: '0';

			return [
				'ID' => (int)$row['ID'],
				'NAME' => $name,
				'OWN_NAME' => $name,
				'DESCRIPTION' => (string)($row['DESCRIPTION'] ?? ''),
				'CHECKED' => 'Y',
				'SORT' => (int)($row['SORT'] ?? 100),
				'PRICE' => 0.0,
				'PRICE_FORMATED' => $zeroFormatted,
				'DELIVERY_DISCOUNT_PRICE' => 0.0,
				'DELIVERY_DISCOUNT_PRICE_FORMATED' => $zeroFormatted,
				'CURRENCY' => $currency,
				'EXTRA_SERVICES' => [],
				'STORE' => false,
				'CALCULATE_ERRORS' => false,
			];
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}
}

if (!function_exists('mf_checkout_resolve_fallback_location_code'))
{
	/**
	 * Резервный CODE местоположения Bitrix для скрытого поля + eDost: env / опция / авто «Санкт-Петербург» из БД.
	 * Явный код в .env имеет приоритет; иначе подставляется первая найденная локация «Санкт-Петербург» (типичный кейс для РФ-магазина).
	 */
	function mf_checkout_resolve_fallback_location_code(): string
	{
		$envLoc = getenv('MF_CHECKOUT_FALLBACK_LOCATION_CODE');
		if ($envLoc !== false && trim((string)$envLoc) !== '')
		{
			return trim((string)$envLoc);
		}

		try
		{
			$opt = trim((string)\Bitrix\Main\Config\Option::get('mf.checkout', 'fallback_location_code', ''));
			if ($opt !== '')
			{
				return $opt;
			}
		}
		catch (\Throwable $e)
		{
		}

		if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return '';
		}

		try
		{
			$conn = \Bitrix\Main\Application::getConnection();
			$h = $conn->getSqlHelper();
			$needle = $h->forSql('Санкт-Петербург');
			$code = (string)$conn->queryScalar(
				"SELECT l.CODE FROM b_sale_location l
				INNER JOIN b_sale_loc_name n ON n.LOCATION_ID = l.ID AND n.LANGUAGE_ID = 'ru'
				WHERE (n.NAME = '{$needle}' OR n.NAME LIKE '{$needle},%' OR n.NAME LIKE '%{$needle}%')
					AND l.CODE IS NOT NULL AND l.CODE <> ''
				ORDER BY l.DEPTH_LEVEL DESC, l.ID ASC
				LIMIT 1"
			);
			$code = trim($code);
			if ($code !== '')
			{
				return $code;
			}
		}
		catch (\Throwable $e)
		{
		}

		// Магазин по умолчанию (настройки модуля sale).
		try
		{
			if (class_exists(\CSaleHelper::class))
			{
				$sl = \CSaleHelper::getShopLocation(false);
				if (is_array($sl))
				{
					$c = trim((string)($sl['CODE'] ?? ''));
					if ($c !== '')
					{
						return $c;
					}
					$lid = (int)($sl['ID'] ?? 0);
					if ($lid > 0 && class_exists(\CSaleLocation::class))
					{
						$byId = \CSaleLocation::GetByID($lid, defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru');
						if (is_array($byId))
						{
							$c2 = trim((string)($byId['CODE'] ?? ''));
							if ($c2 !== '')
							{
								return $c2;
							}
						}
					}
				}
			}
		}
		catch (\Throwable $e)
		{
		}

		// D7: любая ненулевая локация с кодом (последний шанс до «голого» поля).
		try
		{
			if (class_exists(\Bitrix\Sale\Location\LocationTable::class))
			{
				$res = \Bitrix\Sale\Location\LocationTable::getList([
					'select' => ['CODE'],
					'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
					'limit' => 100,
				]);
				while ($row = $res->fetch())
				{
					$c = trim((string)($row['CODE'] ?? ''));
					if ($c !== '')
					{
						return $c;
					}
				}
			}
		}
		catch (\Throwable $e)
		{
		}

		try
		{
			$conn = \Bitrix\Main\Application::getConnection();
			$code = (string)$conn->queryScalar(
				"SELECT CODE FROM b_sale_location WHERE CODE IS NOT NULL AND CODE <> '' ORDER BY SORT ASC, ID ASC LIMIT 1"
			);

			return trim($code);
		}
		catch (\Throwable $e)
		{
			return '';
		}
	}
}

if (!function_exists('mf_catalog_element_code_for_basket_row'))
{
	/**
	 * Символьный код товара для mf_mf_product_img_url (как на витрине / в patchBasketImages в mf-cart.js).
	 */
	function mf_catalog_element_code_for_basket_row(int $productId, array $d): string
	{
		$detailUrl = (string)($d['DETAIL_PAGE_URL'] ?? '');
		if ($detailUrl !== '' && preg_match('#/products/([^/]+)/?#', $detailUrl, $m))
		{
			return (string)$m[1];
		}
		if ($productId <= 0)
		{
			return '';
		}
		static $cache = [];
		if (array_key_exists($productId, $cache))
		{
			return $cache[$productId];
		}
		$code = '';
		$rs = \CIBlockElement::GetList([], ['ID' => $productId], false, false, ['ID', 'CODE']);
		if ($row = $rs->GetNext())
		{
			$code = trim((string)($row['CODE'] ?? ''));
		}
		$cache[$productId] = $code;

		return $code;
	}
}

if (!function_exists('mf_sale_order_ajax_enrich_grid_rows'))
{
	/**
	 * Достаём выбранный MF_STORE_ID из PROPS строки корзины sale.order.ajax.
	 */
	function mf_sale_order_ajax_row_store_id(array $rowData): int
	{
		$props = $rowData['PROPS'] ?? null;
		if (!is_array($props))
		{
			return 0;
		}
		foreach ($props as $p)
		{
			if (!is_array($p))
			{
				continue;
			}
			$code = trim((string)($p['CODE'] ?? ''));
			if ($code !== 'MF_STORE_ID')
			{
				continue;
			}
			$val = (int)($p['VALUE'] ?? 0);
			if ($val > 0)
			{
				return $val;
			}
		}

		return 0;
	}

	/**
	 * Строки корзины в sale.order.ajax: восстанавливаем пустое NAME из каталога и SRC превью,
	 * если файл есть, а масштабирование по какой-то причине не выполнилось.
	 */
	function mf_sale_order_ajax_enrich_grid_rows(array &$rows, string $basketImagesScaling): void
	{
		if ($rows === [] || !class_exists('SaleOrderAjax'))
		{
			return;
		}
		if (!\Bitrix\Main\Loader::includeModule('iblock'))
		{
			return;
		}
		$basketImagesScaling = $basketImagesScaling !== '' ? $basketImagesScaling : 'adaptive';
		foreach ($rows as &$row)
		{
			if (!isset($row['data']) || !is_array($row['data']))
			{
				continue;
			}
			$d = &$row['data'];
			$productId = (int)($d['PRODUCT_ID'] ?? 0);
			if ($productId <= 0)
			{
				continue;
			}
			if (trim((string)($d['NAME'] ?? '')) === '')
			{
				$rsEl = \CIBlockElement::GetByID($productId);
				if ($el = $rsEl->GetNext(false, false))
				{
					$rawName = (string)($el['NAME'] ?? '');
					if ($rawName !== '')
					{
						$d['NAME'] = htmlspecialcharsEx($rawName);
						$d['~NAME'] = $rawName;
					}
				}
			}
			foreach (['PREVIEW_PICTURE' => 'PREVIEW_PICTURE_SRC', 'DETAIL_PICTURE' => 'DETAIL_PICTURE_SRC'] as $picKey => $srcKey)
			{
				$fid = (int)($d[$picKey] ?? 0);
				if ($fid <= 0 || trim((string)($d[$srcKey] ?? '')) !== '')
				{
					continue;
				}
				$arImage = \CFile::GetFileArray($fid);
				if (empty($arImage))
				{
					continue;
				}
				\SaleOrderAjax::resizeImage(
					$d,
					$picKey,
					$arImage,
					['width' => 320, 'height' => 320],
					['width' => 110, 'height' => 110],
					$basketImagesScaling
				);
			}
			// Как в корзине (mf-cart.js): канонические картинки с внешнего хоста /mf-img, а не только /upload/resize
			if (function_exists('mf_mf_product_img_url'))
			{
				$catalogCode = mf_catalog_element_code_for_basket_row($productId, $d);
				if ($catalogCode !== '')
				{
					$mfSrc = (string)mf_mf_product_img_url($catalogCode, 1);
					if ($mfSrc !== '')
					{
						$d['PREVIEW_PICTURE_SRC'] = $mfSrc;
						$d['PREVIEW_PICTURE_SRC_2X'] = $mfSrc;
						$d['PREVIEW_PICTURE_SRC_ORIGINAL'] = $mfSrc;
					}
				}
			}
			// Срок доставки в checkout, как в корзине: по выбранному складу (MF_STORE_ID).
			$storeId = mf_sale_order_ajax_row_store_id($d);
			if ($storeId > 0 && function_exists('mf_store_delivery_term'))
			{
				$deliveryTerm = trim((string)mf_store_delivery_term($storeId));
				if ($deliveryTerm !== '')
				{
					$d['MF_DELIVERY_TERM'] = $deliveryTerm;
				}
			}
			// Не выводим служебные свойства склада в составе заказа (иначе в шаблоне видны «MF_STORE_ID» как текст).
			if (!empty($d['PROPS']) && is_array($d['PROPS']))
			{
				$d['PROPS'] = array_values(array_filter($d['PROPS'], static function ($prop) {
					if (!is_array($prop))
					{
						return true;
					}
					$code = trim((string)($prop['CODE'] ?? ''));
					$name = trim((string)($prop['NAME'] ?? ''));
					if ($code !== '' && strncmp($code, 'MF_STORE_', 9) === 0)
					{
						return false;
					}
					if ($name !== '' && strncmp($name, 'MF_STORE_', 9) === 0)
					{
						return false;
					}

					return true;
				}));
			}
			unset($d);
		}
		unset($row);
	}
}

if (!function_exists('mf_checkout_order_prop_value_meta'))
{
	/**
	 * @return array{CODE: string, IS_LOCATION: bool}
	 */
	function mf_checkout_order_prop_value_meta($propValue): array
	{
		$code = '';
		$isLocation = false;
		if (!is_object($propValue))
		{
			return ['CODE' => '', 'IS_LOCATION' => false];
		}

		if (method_exists($propValue, 'getProperty'))
		{
			$propMeta = $propValue->getProperty();
			if (is_array($propMeta))
			{
				$code = trim((string)($propMeta['CODE'] ?? ''));
				$isLocation = ((string)($propMeta['IS_LOCATION'] ?? '') === 'Y');
			}
			elseif (is_object($propMeta) && method_exists($propMeta, 'getField'))
			{
				$code = trim((string)$propMeta->getField('CODE'));
				$isLocation = ((string)$propMeta->getField('IS_LOCATION') === 'Y');
			}
		}
		if ($code === '' && method_exists($propValue, 'getField'))
		{
			$code = trim((string)$propValue->getField('CODE'));
		}

		return ['CODE' => $code, 'IS_LOCATION' => $isLocation];
	}
}

if (!function_exists('mf_checkout_nominatim_display_line'))
{
	function mf_checkout_nominatim_display_line(string $json): string
	{
		$json = trim($json);
		if ($json === '')
		{
			return '';
		}

		$data = json_decode($json, true);
		if (!is_array($data))
		{
			return '';
		}

		$line = trim((string)($data['display_name'] ?? ''));
		if ($line !== '')
		{
			return $line;
		}

		$addr = is_array($data['address'] ?? null) ? $data['address'] : [];
		foreach (['city', 'town', 'village', 'locality', 'name'] as $key)
		{
			$part = trim((string)($addr[$key] ?? ''));
			if ($part !== '')
			{
				return $part;
			}
		}

		return '';
	}
}

if (!function_exists('mf_checkout_collect_last_order_delivery_context'))
{
	/**
	 * Адрес доставки, Nominatim и тариф eDost из последнего заказа (для preload авторизованного пользователя).
	 *
	 * @return array{
	 *   ORDER_PROP: array<int, string>,
	 *   MF_EDOST: ?array{ID: string, COMPANY: string, NAME: string, PRICE: string},
	 *   MF_NOMINATIM_JSON: string,
	 *   MF_EDOST_TO_CITY: string
	 * }
	 */
	function mf_checkout_collect_last_order_delivery_context(\Bitrix\Sale\Order $order): array
	{
		$out = [
			'ORDER_PROP' => [],
			'MF_EDOST' => null,
			'MF_NOMINATIM_JSON' => '',
			'MF_EDOST_TO_CITY' => '',
		];

		try
		{
			$propCollection = $order->getPropertyCollection();
			if (!$propCollection)
			{
				return $out;
			}

			$preferCodes = [
				'DELIVERY_LOCATION_TEXT',
				'DELIVERY_ADDRESS',
				'DELIVERY_ZIP',
				'MF_NOMINATIM_JSON',
				'MF_EDOST_TO_CITY',
				'ADDRESS',
				'STREET',
				'CITY',
				'ZIP',
			];
			$deliveryLocationTextPropId = 0;

			foreach ($propCollection as $propValue)
			{
				if (!is_object($propValue) || !method_exists($propValue, 'getPropertyId'))
				{
					continue;
				}
				$propId = (int)$propValue->getPropertyId();
				if ($propId <= 0)
				{
					continue;
				}
				$meta = mf_checkout_order_prop_value_meta($propValue);
				$code = $meta['CODE'];
				$isLocation = $meta['IS_LOCATION'];

				$val = $propValue->getValue();
				if (is_array($val))
				{
					$val = implode(', ', array_map('strval', $val));
				}
				$val = trim((string)$val);
				if ($val === '')
				{
					continue;
				}

				$codeUp = strtoupper($code);
				if ($codeUp === 'DELIVERY_LOCATION_TEXT')
				{
					$deliveryLocationTextPropId = $propId;
				}
				if (
					$isLocation
					|| in_array($codeUp, $preferCodes, true)
					|| str_starts_with($codeUp, 'DELIVERY_')
					|| str_starts_with($codeUp, 'MF_')
				)
				{
					$out['ORDER_PROP'][$propId] = $val;
				}
				if ($codeUp === 'MF_NOMINATIM_JSON')
				{
					$out['MF_NOMINATIM_JSON'] = $val;
				}
				if ($codeUp === 'MF_EDOST_TO_CITY')
				{
					$out['MF_EDOST_TO_CITY'] = $val;
				}
			}

			if ($out['MF_NOMINATIM_JSON'] !== '' && $deliveryLocationTextPropId > 0)
			{
				$nomLine = mf_checkout_nominatim_display_line($out['MF_NOMINATIM_JSON']);
				if ($nomLine !== '')
				{
					$out['ORDER_PROP'][$deliveryLocationTextPropId] = $nomLine;
				}
			}

			$comments = (string)$order->getField('COMMENTS');
			if ($comments !== '' && preg_match('~\(tarif_id=([^)]+)\)~', $comments, $m))
			{
				$tid = trim((string)$m[1]);
				$company = '';
				$name = '';
				$price = '';
				if (preg_match('~Доставка \(eDost[^:]*:\s*(.+?)\s*—\s*(.+?)\s*—\s*([0-9]+)\s*₽~u', $comments, $m2))
				{
					$company = trim((string)$m2[1]);
					$name = trim((string)$m2[2]);
					$price = trim((string)$m2[3]);
				}
				if ($tid !== '')
				{
					$out['MF_EDOST'] = [
						'ID' => $tid,
						'COMPANY' => $company,
						'NAME' => $name,
						'PRICE' => $price,
					];
				}
			}
		}
		catch (\Throwable $e)
		{
		}

		return $out;
	}
}

if (!function_exists('mf_checkout_merge_delivery_context'))
{
	/**
	 * Объединяет контекст доставки: свежий заказ + более ранние (Nominatim мог не сохраниться в последнем).
	 *
	 * @param array|null $primary
	 * @param array      $fallback
	 */
	function mf_checkout_merge_delivery_context(?array $primary, array $fallback): array
	{
		if (!is_array($primary) || $primary === [])
		{
			return $fallback;
		}

		if ($primary['MF_NOMINATIM_JSON'] === '' && $fallback['MF_NOMINATIM_JSON'] !== '')
		{
			$primary['MF_NOMINATIM_JSON'] = $fallback['MF_NOMINATIM_JSON'];
		}
		if ($primary['MF_EDOST_TO_CITY'] === '' && $fallback['MF_EDOST_TO_CITY'] !== '')
		{
			$primary['MF_EDOST_TO_CITY'] = $fallback['MF_EDOST_TO_CITY'];
		}
		if (empty($primary['MF_EDOST']) && !empty($fallback['MF_EDOST']))
		{
			$primary['MF_EDOST'] = $fallback['MF_EDOST'];
		}

		if (!isset($primary['ORDER_PROP']) || !is_array($primary['ORDER_PROP']))
		{
			$primary['ORDER_PROP'] = [];
		}
		if (!empty($fallback['ORDER_PROP']) && is_array($fallback['ORDER_PROP']))
		{
			foreach ($fallback['ORDER_PROP'] as $propId => $val)
			{
				$propId = (int)$propId;
				if ($propId <= 0)
				{
					continue;
				}
				$cur = trim((string)($primary['ORDER_PROP'][$propId] ?? ''));
				$add = trim((string)$val);
				if ($add === '')
				{
					continue;
				}
				if ($cur === '')
				{
					$primary['ORDER_PROP'][$propId] = $add;
				}
			}
		}

		if ($primary['MF_NOMINATIM_JSON'] !== '')
		{
			$nomLine = mf_checkout_nominatim_display_line($primary['MF_NOMINATIM_JSON']);
			if ($nomLine !== '' && class_exists(\Bitrix\Main\Loader::class) && \Bitrix\Main\Loader::includeModule('sale'))
			{
				$locProp = \Bitrix\Sale\Internals\OrderPropsTable::getList([
					'filter' => ['=CODE' => 'DELIVERY_LOCATION_TEXT'],
					'select' => ['ID'],
					'limit' => 1,
				])->fetch();
				if (!empty($locProp['ID']))
				{
					$primary['ORDER_PROP'][(int)$locProp['ID']] = $nomLine;
				}
			}
		}

		return $primary;
	}
}

if (!function_exists('mf_checkout_collect_user_delivery_context'))
{
	/**
	 * Контекст доставки из последних заказов пользователя (до 10), с подстановкой Nominatim из более ранних.
	 */
	function mf_checkout_collect_user_delivery_context(int $userId, string $siteId): array
	{
		$empty = [
			'ORDER_PROP' => [],
			'MF_EDOST' => null,
			'MF_NOMINATIM_JSON' => '',
			'MF_EDOST_TO_CITY' => '',
		];
		if ($userId <= 0 || $siteId === '' || !class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return $empty;
		}

		$registry = \Bitrix\Sale\Registry::getInstance(\Bitrix\Sale\Registry::REGISTRY_TYPE_ORDER);
		$orderClassName = $registry->getOrderClassName();
		$merged = null;

		$res = $orderClassName::getList([
			'filter' => [
				'USER_ID' => $userId,
				'LID' => $siteId,
				'CANCELED' => 'N',
			],
			'select' => ['ID'],
			'order' => ['ID' => 'DESC'],
			'limit' => 10,
		]);
		while ($row = $res->fetch())
		{
			$orderId = (int)($row['ID'] ?? 0);
			if ($orderId <= 0)
			{
				continue;
			}
			$loaded = $orderClassName::load($orderId);
			if (!$loaded)
			{
				continue;
			}
			$ctx = mf_checkout_collect_last_order_delivery_context($loaded);
			if ($merged === null)
			{
				$merged = $ctx;
				continue;
			}
			$merged = mf_checkout_merge_delivery_context($merged, $ctx);
		}

		return is_array($merged) ? $merged : $empty;
	}
}

if (!function_exists('mf_checkout_on_order_js_data'))
{
	function mf_checkout_on_order_js_data(array &$arResult, array &$arParams): void
	{
		try
		{
			if (empty($arResult['JS_DATA']) || !is_array($arResult['JS_DATA']))
			{
				return;
			}

			$basketImgScale = (string)($arParams['BASKET_IMAGES_SCALING'] ?? 'adaptive');
			if (!empty($arResult['JS_DATA']['GRID']['ROWS']) && is_array($arResult['JS_DATA']['GRID']['ROWS']))
			{
				mf_sale_order_ajax_enrich_grid_rows($arResult['JS_DATA']['GRID']['ROWS'], $basketImgScale);
			}
			if (!empty($arResult['GRID']['ROWS']) && is_array($arResult['GRID']['ROWS']))
			{
				mf_sale_order_ajax_enrich_grid_rows($arResult['GRID']['ROWS'], $basketImgScale);
			}

			$fallbackLoc = mf_checkout_resolve_fallback_location_code();

			$arResult['JS_DATA']['MF_CHECKOUT'] = array_merge(
				is_array($arResult['JS_DATA']['MF_CHECKOUT'] ?? null) ? $arResult['JS_DATA']['MF_CHECKOUT'] : [],
				[
					'FALLBACK_LOCATION_CODE' => $fallbackLoc,
					'NOMINATIM_SEARCH_URL' => '/ajax/mf_nominatim_search.php',
				]
			);

			if (!mf_checkout_custom_flow_enabled($arParams))
			{
				return;
			}

			$personTypes = mf_checkout_person_type_map();
			$invoiceIds = mf_checkout_invoice_pay_system_ids();
			$currentPersonTypeId = 0;
			if (!empty($arResult['JS_DATA']['PERSON_TYPE']) && is_array($arResult['JS_DATA']['PERSON_TYPE']))
			{
				foreach ($arResult['JS_DATA']['PERSON_TYPE'] as $personType)
				{
					if (($personType['CHECKED'] ?? 'N') === 'Y')
					{
						$currentPersonTypeId = (int)($personType['ID'] ?? 0);
						break;
					}
				}
			}

			$orderReturnPath = (string)($arParams['PATH_TO_ORDER'] ?? '/personal/order/make/');
			$orderReturnPath = '/' . ltrim(str_replace('\\', '/', $orderReturnPath), '/');
			if ($orderReturnPath === '/' || $orderReturnPath === '')
			{
				$orderReturnPath = '/personal/order/make/';
			}
			$orderBackEnc = rawurlencode($orderReturnPath);

			$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
			$tidPost = trim((string)$request->getPost('MF_EDOST_TARIF_ID'));
			$managerDeliveryPost = (trim((string)$request->getPost('MF_EDOST_MANAGER_FALLBACK')) === 'Y' && $tidPost === '');

			$arResult['JS_DATA']['MF_CHECKOUT'] = array_merge(
				is_array($arResult['JS_DATA']['MF_CHECKOUT'] ?? null) ? $arResult['JS_DATA']['MF_CHECKOUT'] : [],
				[
					'ENABLED' => true,
					'FIZ_PERSON_TYPE_ID' => (int)($personTypes['fiz'] ?? 0),
					'JUR_PERSON_TYPE_ID' => (int)($personTypes['jur'] ?? 0),
					'INVOICE_PAY_SYSTEM_IDS' => $invoiceIds,
					'CURRENT_PERSON_TYPE_ID' => $currentPersonTypeId,
					'LOGIN_HREF' => '/login/?login=yes&backurl=' . $orderBackEnc,
					'REGISTER_HREF' => '/login/?register=yes&backurl=' . $orderBackEnc,
					'MANAGER_DELIVERY_POST' => $managerDeliveryPost ? 'Y' : 'N',
				]
			);

			$preload = is_array($arResult['MF_CHECKOUT_LAST_ORDER_PRELOAD'] ?? null)
				? $arResult['MF_CHECKOUT_LAST_ORDER_PRELOAD']
				: [];
			if (!empty($preload))
			{
				if (!empty($preload['MF_EDOST']) && is_array($preload['MF_EDOST']))
				{
					$arResult['JS_DATA']['MF_CHECKOUT']['LAST_ORDER_EDOST'] = $preload['MF_EDOST'];
				}
				if (!empty($preload['MF_NOMINATIM_JSON']))
				{
					$arResult['JS_DATA']['MF_CHECKOUT']['LAST_ORDER_NOMINATIM_JSON'] = (string)$preload['MF_NOMINATIM_JSON'];
				}
				if (!empty($preload['MF_EDOST_TO_CITY']))
				{
					$arResult['JS_DATA']['MF_CHECKOUT']['LAST_ORDER_EDOST_TO_CITY'] = (string)$preload['MF_EDOST_TO_CITY'];
				}
			}

			if (
				(int)($personTypes['jur'] ?? 0) > 0
				&& $currentPersonTypeId === (int)$personTypes['jur']
				&& !empty($arResult['JS_DATA']['PAY_SYSTEM'])
				&& is_array($arResult['JS_DATA']['PAY_SYSTEM'])
			)
			{
				mf_checkout_apply_pay_system_filter($arResult['JS_DATA']['PAY_SYSTEM'], $invoiceIds);
			}
		}
		catch (\Throwable $e)
		{
			// Не роняем оформление заказа из-за вспомогательных данных в JS.
		}
	}
}

if (!function_exists('mf_checkout_bootstrap_log'))
{
	function mf_checkout_bootstrap_log(string $step, \Throwable $e): void
	{
		$docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
		if ($docRoot === '')
		{
			return;
		}
		$line = date('c')
			. " [{$step}] "
			. $e->getMessage()
			. ' in ' . $e->getFile()
			. ':' . (string)$e->getLine()
			. "\n"
			. $e->getTraceAsString()
			. "\n\n";
		$paths = [
			rtrim($docRoot, '/') . '/upload/mf_checkout_bootstrap_errors.log',
			rtrim($docRoot, '/') . '/local/php_interface/mf_checkout_bootstrap_errors.log',
		];
		foreach ($paths as $path)
		{
			if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false)
			{
				break;
			}
		}
	}
}

if (!function_exists('mf_checkout_bootstrap'))
{
	function mf_checkout_bootstrap(): void
	{
		try
		{
			mf_checkout_ensure_delivery_address_props();
		}
		catch (\Throwable $e)
		{
			mf_checkout_bootstrap_log('delivery_address_props', $e);
		}

		try
		{
			mf_checkout_ensure_nominatim_geo_props();
		}
		catch (\Throwable $e)
		{
			mf_checkout_bootstrap_log('nominatim_geo_props', $e);
		}

		try
		{
			mf_checkout_ensure_company_order_props();
		}
		catch (\Throwable $e)
		{
			mf_checkout_bootstrap_log('company_order_props', $e);
		}

		try
		{
			mf_checkout_ensure_confirm_channel_prop();
		}
		catch (\Throwable $e)
		{
			mf_checkout_bootstrap_log('confirm_channel_prop', $e);
		}

		try
		{
			mf_checkout_ensure_payment_systems();
		}
		catch (\Throwable $e)
		{
			mf_checkout_bootstrap_log('payment_systems', $e);
		}

		if (!class_exists(\Bitrix\Main\EventManager::class))
		{
			return;
		}

		$em = \Bitrix\Main\EventManager::getInstance();
		$em->addEventHandler('sale', 'OnSaleComponentOrderUserResult', 'mf_checkout_on_order_user_result');
		$em->addEventHandler('sale', 'OnSaleComponentOrderJsData', 'mf_checkout_on_order_js_data');
		$em->addEventHandler('sale', 'OnSaleComponentOrderProperties', 'mf_checkout_on_order_properties');
	}
}

