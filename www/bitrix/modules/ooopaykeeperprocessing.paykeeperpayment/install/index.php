<?php

use \Bitrix\Main\Localization\Loc;
use \Bitrix\Main\Application;

Loc::loadMessages(__FILE__);

Class ooopaykeeperprocessing_paykeeperpayment extends CModule
{
    var $MODULE_ID = "ooopaykeeperprocessing.paykeeperpayment";

    function __construct()
    {
        $arModuleVersion = array();
        include(__DIR__."/version.php");
        $this->MODULE_ID = Loc::getMessage('PAYKEEPER_MODULE_ID');
        $this->MODULE_VERSION = $arModuleVersion["VERSION"];
        $this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];
        $this->MODULE_NAME = Loc::getMessage("PAYKEEPER_MODULE_NAME");
        $this->MODULE_DESCRIPTION = Loc::getMessage("PAYKEEPER_MODULE_DESC");
        $this->PARTNER_NAME = Loc::getMessage("PAYKEEPER_PARTNER_NAME");
        $this->PARTNER_URI = Loc::getMessage("PAYKEEPER_PARTNER_URI");

    }
    function InstallDB()
    {
        return true;
    }
    function UnInstallDB()
    {
        UnRegisterModule($this->MODULE_ID);
        return true;
    }
    function InstallEvents()
    {
        return true;
    }

    function UnInstallEvents()
    {
        return true;
    }
    function InstallFiles()
    {
        CopyDirFiles(__DIR__."/bitrix", Application::getDocumentRoot()."/bitrix", true, true);
        CopyDirFiles(__DIR__."/local", Application::getDocumentRoot()."/local", true, true);
        return true;
    }

    function UnInstallFiles()
    {
        DeleteDirFilesEx("/bitrix/modules/sale/handlers/paysystem/paykeeper");
        DeleteDirFilesEx("/local/ajax/paykeeper");
        return true;
    }

    function DoInstall()
    {
        global $APPLICATION;

        if (CheckVersion(\Bitrix\Main\ModuleManager::getVersion('main'), '14.00.00'))
        {
            \Bitrix\Main\ModuleManager::registerModule($this->MODULE_ID);
            $this->InstallDB();
            $this->InstallEvents();
            if (!$this->InstallFiles())
            {
                $APPLICATION->ThrowException(Loc::getMessage("PAYKEEPER_COPYFILE_ERROR"));
            }
        }
        else
        {
            $APPLICATION->ThrowException(Loc::getMessage("PAYKEEPER_INSTALL_ERROR_VERSION"));
        }
    }

    function DoUninstall()
    {
        $this->UnInstallDB();
        $this->UnInstallEvents();
        if (!$this->UnInstallFiles())
        {
            $APPLICATION->ThrowException(Loc::getMessage("PAYKEEPER_DELETEFILE_ERROR"));
        }

        \Bitrix\Main\ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}
?>
