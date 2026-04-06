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
		return '2026-04-06-02';
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

if (!function_exists('mf_checkout_ensure_company_order_props'))
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

if (!function_exists('mf_checkout_on_order_js_data'))
{
	function mf_checkout_default_edost_offer(array $jsData): ?array
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

	function mf_checkout_on_order_js_data(array &$arResult, array &$arParams): void
	{
		if (!mf_checkout_custom_flow_enabled($arParams))
		{
			return;
		}

		if (empty($arResult['JS_DATA']) || !is_array($arResult['JS_DATA']))
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

		$arResult['JS_DATA']['MF_CHECKOUT'] = array_merge(
			is_array($arResult['JS_DATA']['MF_CHECKOUT'] ?? null) ? $arResult['JS_DATA']['MF_CHECKOUT'] : [],
			[
				'ENABLED' => true,
				'FIZ_PERSON_TYPE_ID' => (int)($personTypes['fiz'] ?? 0),
				'JUR_PERSON_TYPE_ID' => (int)($personTypes['jur'] ?? 0),
				'INVOICE_PAY_SYSTEM_IDS' => $invoiceIds,
				'CURRENT_PERSON_TYPE_ID' => $currentPersonTypeId,
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

		$defaultEdostOffer = mf_checkout_default_edost_offer($arResult['JS_DATA']);
		if (
			is_array($defaultEdostOffer)
			&& !empty($defaultEdostOffer['id'])
			&& (!empty($defaultEdostOffer['name']) || !empty($defaultEdostOffer['company']))
		)
		{
			$arResult['JS_DATA']['MF_EDOST_DEFAULT'] = $defaultEdostOffer;
		}
	}
}

if (!function_exists('mf_checkout_bootstrap'))
{
	function mf_checkout_bootstrap(): void
	{
		mf_checkout_ensure_delivery_address_props();
		mf_checkout_ensure_company_order_props();
		mf_checkout_ensure_payment_systems();

		if (!class_exists(\Bitrix\Main\EventManager::class))
		{
			return;
		}

		$em = \Bitrix\Main\EventManager::getInstance();
		$em->addEventHandler('sale', 'OnSaleComponentOrderUserResult', 'mf_checkout_on_order_user_result');
		$em->addEventHandler('sale', 'OnSaleComponentOrderJsData', 'mf_checkout_on_order_js_data');
	}
}

