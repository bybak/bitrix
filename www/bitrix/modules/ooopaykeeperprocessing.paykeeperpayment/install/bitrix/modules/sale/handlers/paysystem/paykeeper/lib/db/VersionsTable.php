<?php

use \Bitrix\Main\ORM\Data\DataManager;
use \Bitrix\Main\ORM\Fields\BooleanField;
use \Bitrix\Main\ORM\Fields\EnumField;
use \Bitrix\Main\ORM\Fields\IntegerField;
use \Bitrix\Main\ORM\Fields\StringField;
use \Bitrix\Main\ORM\Fields\TextField;
use \Bitrix\Main\ORM\Fields\DatetimeField;
use \Bitrix\Main\Type\DateTime;

use \Bitrix\Main\Application;
use \Bitrix\Main\Entity\Base;

include_once __DIR__ . '/lib/Utils.php';

class VersionsTable extends DataManager
{
    /**
     * Returns DB table name for entity.
     *
     * @return string
     */
    public static function getTableName()
    {
        return 'b_a_paykeeper_versions';
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
            new IntegerField('NEW_VERSION', [
                'required' => true
            ]),
            new IntegerField('OLD_VERSION', [ ]),
            new DatetimeField('INSTALLED_DATE', [
                'default_value' => function() {
                    return new DateTime();
                }
            ] )
        ];
    }

    /**
     * @return array[]
     */
    public static function getIndexes()
    {
        return [
            'idx_pk_installed_date' => [
                'columns' => ['INSTALLED_DATE'],
                'type' => 'INDEX',
            ],
            'idx_pk_new_version' => [
                'columns' => ['NEW_VERSION'],
                'type' => 'INDEX',
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

        $sql = "ALTER TABLE `{$tableName}` 
                ADD INDEX `idx_pk_installed_date` (`INSTALLED_DATE`),
                ADD INDEX `idx_pk_new_version` (`NEW_VERSION`);";

        $connection->queryExecute($sql);
    }

    /**
     * Get the latest installed version
     *
     * @return int
     */
    public static function getLastInstalledVersion()
    {
        $selectUserTable = self::getRow([
            'select' => [ '*' ],
            'order' => [ 'INSTALLED_DATE' => 'DESC' ],
            'limit' => 1,
        ]);
        return (int) ($selectUserTable['NEW_VERSION'] ?? 0);
    }

    /**
     * @return bool
     */
    public static function checkVersion()
    {
        return self::getLastInstalledVersion() === Utils::$version;
    }

    /**
     * Check if migration is needed
     *
     * @return bool
     */
    public static function migrateNeed()
    {
        return !self::checkVersion();
    }

    /**
     * @return mixed
     */
    public static function initVersion()
    {
        if (!self::checkVersion()) {
            return self::add([
                'OLD_VERSION' => self::getLastInstalledVersion(),
                'NEW_VERSION' => Utils::$version,
                'INSTALLED_DATE' => new DateTime()
            ]);
        }
        return false;
    }
}