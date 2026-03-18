<?php

use \Bitrix\Main\ORM\Data\DataManager;
use \Bitrix\Main\ORM\Fields\BooleanField;
use \Bitrix\Main\ORM\Fields\EnumField;
use \Bitrix\Main\ORM\Fields\IntegerField;
use \Bitrix\Main\ORM\Fields\StringField;
use \Bitrix\Main\ORM\Fields\TextField;

use \Bitrix\Main\Application;
use \Bitrix\Main\Entity\Base;

class CardTokensTable extends DataManager
{
    /**
     * Returns DB table name for entity.
     *
     * @return string
     */
    public static function getTableName()
    {
        return 'b_a_paykeeper_card_tokens';
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

        if ($connection->isTableExists($entity->getDBTableName()))
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
            new IntegerField('BX_USER_ID', [ 'required' => true ]),
            new StringField('PK_BANK_ID', [
                'default_value' => '',
                'size' => 255
            ]),
            new StringField('HASH', [
                'unique' => true,
                'size' => 32,
                'default_value' => function() {
                    return md5(uniqid(mt_rand(), true) . microtime());
                }
            ]),
            new IntegerField('IS_DEFAULT', [ 'default_value' => '0' ]),
            new StringField('PK_CARD_NUMBER', [ 'default_value' => '' ]),
            new StringField('PK_CARD_EXPIRY', [ 'default_value' => '' ]),
        ];
    }

    public static function getIndexes()
    {
        return [
            'idx_bx_user_id' => [
                'columns' => ['BX_USER_ID'], // Поле для индекса
                'type' => 'INDEX', // Обычный индекс (не уникальный)
            ],
            'ux_user_bank' => [
                'columns' => ['BX_USER_ID', 'PK_BANK_ID'],
                'type' => 'INDEX',
            ],
            'ux_user_hash' => [
                'columns' => ['BX_USER_ID', 'HASH'],
                'type' => 'UNIQUE',
            ],
        ];
    }

    /**
     * @param int $bxUserId
     * @return mixed
     */
    public static function getByUserId($bxUserId)
    {
        return self::getRow([
            'filter' => ['=BX_USER_ID' => $bxUserId],
        ]);
    }

    public static function getByUserIdBankId($bxUserId, $pkBankId)
    {
        return self::getRow([
            'filter' => [
                '=BX_USER_ID' => $bxUserId,
                '=PK_BANK_ID' => $pkBankId
            ],
        ]);
    }

    /**
     * @param array $data
     * @return void
     */
    public static function addOrUpdate($data)
    {
        $currentRecord = self::getByUserIdBankId((int)$data['BX_USER_ID'],$data['PK_BANK_ID']);
        if ($currentRecord) {
            unset($data['ID']);
            $result = self::update($currentRecord['ID'], $data);
        } else {
            $result = self::add($data);
        }
        return $result;
    }

    /**
     * @return void
     */
    protected static function createIndexes($connection)
    {
        $tableName = self::getTableName();

        $sql = "ALTER TABLE `{$tableName}` 
                ADD INDEX `idx_bx_user_id` (`BX_USER_ID`),
                ADD UNIQUE INDEX `ux_user_bank` (`BX_USER_ID`, `PK_BANK_ID`),
                ADD UNIQUE INDEX `ux_user_hash` (`BX_USER_ID`, `HASH`)";

        $connection->queryExecute($sql);
    }

    /**
     * @param $userId
     * @return mixed|string
     */
    public static function getCardToken($userId)
    {
        $selectUserTable = self::getRow([
            'select' => [ 'ID', 'PK_BANK_ID' ],
            'filter' => [ '=BX_USER_ID' => $userId ],
            'order' => [ 'IS_DEFAULT' => 'DESC' ],
        ]);
        return $selectUserTable['PK_BANK_ID'] ?? '';
    }

    /**
     * @param $userId
     * @param $hash
     * @return mixed|string
     */
    public static function getCardTokenFromHash($userId, $hash)
    {
        $selectUserTable = self::getRow([
            'select' => [ 'ID', 'PK_BANK_ID' ],
            'filter' => [ '=BX_USER_ID' => $userId, '=HASH' => $hash ]
        ]);
        return $selectUserTable['PK_BANK_ID'] ?? '';
    }

    /**
     * Get a list of all user's linked cards
     *
     * @param $userId
     * @return array
     */
    public static function getCardList($userId)
    {
        $rows = self::getList([
            'select' => [ '*' ],
            'filter' => [ '=BX_USER_ID' => $userId ]
        ])->fetchAll();
        return $rows ?: [];
    }

    /**
     * Removing a user's bank card link
     *
     * @param $userId
     * @param $hash
     * @return bool
     */
    public static function removeCard($userId, $hash)
    {
        $row = self::getRow([
            'select' => [ 'ID' ],
            'filter' => [ '=BX_USER_ID' => $userId, '=HASH' => $hash ]
        ]);

        if ($row) {
            $result = self::delete($row['ID']);
            if ($result->isSuccess()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Setting the user's bank card link as primary
     *
     * @param $userId
     * @param $hash
     * @return bool
     */
    public static function setDefaultCard($userId, $hash)
    {
        $result = false;
        $rows = self::getList([
            'select' => [ 'ID', 'IS_DEFAULT', 'HASH' ],
            'filter' => [ '=BX_USER_ID' => $userId ]
        ]);

        while ($row = $rows->fetch()) {
            if ($row['HASH'] === $hash) {
                $updateData = [
                    'IS_DEFAULT' => 1
                ];
                $result = self::update($row['ID'], $updateData);
            } else if ($row['IS_DEFAULT'] == 1) {
                $updateData = [
                    'IS_DEFAULT' => 0
                ];
                self::update($row['ID'], $updateData);
            }
        }

        return $result;
    }
}