<?php

use \Bitrix\Main\ORM\Data\DataManager;
use \Bitrix\Main\ORM\Fields\BooleanField;
use \Bitrix\Main\ORM\Fields\EnumField;
use \Bitrix\Main\ORM\Fields\IntegerField;
use \Bitrix\Main\ORM\Fields\StringField;
use \Bitrix\Main\ORM\Fields\TextField;

use \Bitrix\Main\Application;
use \Bitrix\Main\Entity\Base;

class OrdersTable extends DataManager
{
    /**
     * Returns DB table name for entity.
     *
     * @return string
     */
    public static function getTableName()
    {
        return 'b_a_paykeeper_orders';
    }

    /**
     * Creates database table
     *
     * @return bool
     */
    public static function createTable()
    {
        $connection = Application::getConnection(self::getConnectionName());
        $entity = Base::getInstance(self::class);

        if (!$connection->isTableExists($entity->getDBTableName()))
        {
            $entity->createDbTable();
            self::createIndexes($connection);
            return true;
        }

        if (VersionsTable::migrateNeed()) {
            self::migrateToVersion155($connection);
        }

        return false;
    }

    /**
     * Drops database table
     *
     * @return bool
     */
    public static function dropTable()
    {
        $connection = Application::getConnection(self::getConnectionName());
        $entity = Base::getInstance(self::class);

        if($connection->isTableExists($entity->getDBTableName()))
        {
            $connection->queryExecute('DROP TABLE IF EXISTS ' . $entity->getDBTableName());
            return true;
        }

        return false;
    }

    /**
     * Returns entity map definition.
     *
     * @return array
     */
    public static function getMap()
    {
        return [
            new IntegerField('ID', [
                'primary' => true,
                'autocomplete' => true
            ]),
            new IntegerField('BX_USER_ID', [ 'default_value' => 0 ]),
            new StringField('BX_USER_NAME', []),
            new StringField('BX_ORDER_ID', [ 'required' => true ]),
            new StringField('BX_PAYMENT_ID', []),

            new IntegerField('PK_PAYMENT_ID', [ 'default_value' => 0 ]),
            new IntegerField('PK_RECEIPT_ID', [ 'default_value' => 0 ]),
            new StringField('PK_BANK_ID', [ 'default_value' => '' ]),
            new StringField('PK_ORDER_ID', [ 'default_value' => '' ]),
            new StringField('PK_CLIENT_ID', [ 'default_value' => '' ]),
            new StringField('PK_SUM', [ 'default_value' => '' ]),
            new StringField('PK_SERVICE_NAME', [ 'default_value' => '' ]),
            new StringField('PK_CLIENT_EMAIL', [ 'default_value' => '' ]),
            new StringField('PK_CLIENT_PHONE', [ 'default_value' => '' ]),
            new StringField('PK_PS_ID', [ 'default_value' => '' ]),
            new StringField('PK_BATCH_DATE', [ 'default_value' => '' ]),
            new StringField('PK_FOP_RECEIPT_KEY', [ 'default_value' => '' ]),
            new StringField('PK_BANK_PAYER_ID', [ 'default_value' => '' ]),
            new StringField('PK_CARD_NUMBER', [ 'default_value' => '' ]),
            new StringField('PK_CARD_HOLDER', [ 'default_value' => '' ]),
            new StringField('PK_CARD_EXPIRY', [ 'default_value' => '' ]),
            new StringField('PK_BANK_OPERATION_DATETIME', [ 'default_value' => '' ]),

            new BooleanField('PAYFINISHED', [ 'values' => ['N', 'Y'] ]),
            new BooleanField('CONFIRMED', [ 'values' => ['N', 'Y'] ]),
            new StringField('STATUS', [ 'default_value' => '' ]),
            new StringField('SUM', [ 'default_value' => '' ]),
            new EnumField('PAYMENT_SCHEME', [ 'values' => ['1', '2'] ]),
            new TextField('REQUEST', []),
            new TextField('RESULT', []),
            new TextField('BASKET', [])
        ];
    }

    public static function getIndexes()
    {
        return [
            'idx_bx_order_id' => [
                'columns' => ['BX_ORDER_ID'], // Поле для индекса
                'type' => 'INDEX', // Обычный индекс (не уникальный)
            ],
        ];
    }

    /**
     * @param $connection
     * @return void
     */
    protected static function createIndexes($connection)
    {
        $tableName = self::getTableName();
        $sql = "ALTER TABLE `{$tableName}` ADD INDEX `idx_bx_order_id` (`BX_ORDER_ID`);";
        $connection->queryExecute($sql);
    }

    /**
     * @param $connection
     * @return bool
     */
    protected static function migrateToVersion155($connection)
    {
        $tableName = self::getTableName();

        try {
            $arrSqlForAddIndex = [];
            $isHashIndexExists = $connection->isIndexExists($tableName, [ 'BX_ORDER_ID' ]);
            if (!$isHashIndexExists) {
                $arrSqlForAddIndex[] = "ADD INDEX `idx_bx_order_id` (`BX_ORDER_ID`)";
            }

            if ($arrSqlForAddIndex) {
                $sql = "ALTER TABLE `{$tableName}` " . implode(', ', $arrSqlForAddIndex) . ";";
                $connection->queryExecute($sql);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param int $bxOrderId
     * @return mixed
     */
    public static function getByBitrixid($bxOrderId)
    {
        return self::getRow([
            'filter' => ['=BX_ORDER_ID' => $bxOrderId],
        ]);
    }

    /**
     * @param array $data
     * @return void
     */
    public static function addOrUpdate($data)
    {
        $currentRecord = self::getByBitrixid(intval($data['BX_ORDER_ID']));
        if ($currentRecord) {
            unset($data['ID']);
            $result = self::update($currentRecord['ID'], $data);
        } else {
            $result = self::add($data);
        }
        return $result;
    }
}