<?php

if (!function_exists('mf_checkout_custom_flow_enabled'))
{
	function mf_checkout_custom_flow_enabled(array $arParams): bool
	{
		return (($arParams['MF_CUSTOM_GUEST_FLOW'] ?? 'N') === 'Y');
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
			unset($d);
		}
		unset($row);
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
			rtrim($docRoot, '/') . '/bitrix/php_interface/mf_checkout_bootstrap_errors.log',
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
	}
}

