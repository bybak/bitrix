<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2024 Bitrix
 */

use Bitrix\Main;
use Bitrix\Main\Session\Legacy\HealerEarlySessionStart;
use Bitrix\Main\DI\ServiceLocator;

require_once __DIR__ . "/start.php";

$application = Main\HttpApplication::getInstance();
$application->initializeExtendedKernel([
	"get" => $_GET,
	"post" => $_POST,
	"files" => $_FILES,
	"cookie" => $_COOKIE,
	"server" => $_SERVER,
	"env" => $_ENV
]);

if (class_exists('\Dev\Main\Migrator\ModuleUpdater'))
{
	\Dev\Main\Migrator\ModuleUpdater::checkUpdates('main', __DIR__);
}

if (!Main\ModuleManager::isModuleInstalled('bitrix24'))
{
	// wwall rules
	(new Main\Security\W\WWall)->handle();

	$application->addBackgroundJob([
		Main\Security\W\WWall::class, 'refreshRules'
	]);

	// vendor security notifications
	$application->addBackgroundJob([
		Main\Security\Notifications\VendorNotifier::class, 'refreshNotifications'
	]);
}

if (defined('SITE_ID'))
{
	define('LANG', SITE_ID);
}

$context = $application->getContext();
$context->initializeCulture(defined('LANG') ? LANG : null, defined('LANGUAGE_ID') ? LANGUAGE_ID : null);

// needs to be after culture initialization
$application->start();

// Register main's services
ServiceLocator::getInstance()->registerByModuleSettings('main');

// constants for compatibility
$culture = $context->getCulture();
define('SITE_CHARSET', $culture->getCharset());
define('FORMAT_DATE', $culture->getFormatDate());
define('FORMAT_DATETIME', $culture->getFormatDatetime());
define('LANG_CHARSET', SITE_CHARSET);

$site = $context->getSiteObject();
if (!defined('LANG'))
{
	define('LANG', ($site ? $site->getLid() : $context->getLanguage()));
}
define('SITE_DIR', ($site ? $site->getDir() : ''));
if (!defined('SITE_SERVER_NAME'))
{
	define('SITE_SERVER_NAME', ($site ? $site->getServerName() : ''));
}
define('LANG_DIR', SITE_DIR);

if (!defined('LANGUAGE_ID'))
{
	define('LANGUAGE_ID', $context->getLanguage());
}
define('LANG_ADMIN_LID', LANGUAGE_ID);

if (!defined('SITE_ID'))
{
	define('SITE_ID', LANG);
}

/** @global $lang */
$lang = $context->getLanguage();

//define global application object
$GLOBALS["APPLICATION"] = new CMain;

if (!defined("POST_FORM_ACTION_URI"))
{
	define("POST_FORM_ACTION_URI", htmlspecialcharsbx(GetRequestUri()));
}

$GLOBALS["MESS"] = [];
$GLOBALS["ALL_LANG_FILES"] = [];
IncludeModuleLangFile(__DIR__."/tools.php");
IncludeModuleLangFile(__FILE__);

error_reporting(COption::GetOptionInt("main", "error_reporting", E_COMPILE_ERROR | E_ERROR | E_CORE_ERROR | E_PARSE) & ~E_STRICT & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE);

if (!defined("BX_COMP_MANAGED_CACHE") && COption::GetOptionString("main", "component_managed_cache_on", "Y") != "N")
{
	define("BX_COMP_MANAGED_CACHE", true);
}

// global functions
require_once __DIR__ . "/filter_tools.php";

/*ZDUyZmZNTk1ZTFmZmM0YTQxNDA1MDljZDY3ODcxNTRmMTVmMmM=*/$GLOBALS['_____1477776978']= array(base64_decode('R2V0TW'.'9kdWxlRXZlb'.'nRz'),base64_decode('RXhlY3'.'V0Z'.'U1vZHV'.'sZ'.'UV2ZW50RXg='));$GLOBALS['____1236346724']= array(base64_decode('Z'.'GVm'.'a'.'W5l'),base64_decode('YmF'.'zZ'.'T'.'Y'.'0X2RlY29k'.'ZQ=='),base64_decode('dW'.'5zZX'.'JpYWxp'.'em'.'U'.'='),base64_decode('a'.'XNf'.'YXJyYX'.'k='),base64_decode(''.'aW5fYXJyY'.'X'.'k='),base64_decode('c2V'.'yaWFsaXp'.'l'),base64_decode('YmFzZTY'.'0X'.'2'.'VuY29kZQ='.'='),base64_decode('bWt0a'.'W1l'),base64_decode('ZGF0Z'.'Q=='),base64_decode('ZGF0Z'.'Q=='),base64_decode('c3RybGVu'),base64_decode('bWt0aW1l'),base64_decode('Z'.'GF0'.'Z'.'Q=='),base64_decode('Z'.'GF0Z'.'Q=='),base64_decode('bW'.'V0aG9kX2V4'.'aXN'.'0'.'cw='.'='),base64_decode('Y2F'.'sbF9'.'1'.'c2VyX2'.'Z1'.'bmN'.'fYXJyYX'.'k='),base64_decode('c'.'3'.'RybGVu'),base64_decode('c2VyaWF'.'s'.'a'.'Xpl'),base64_decode('Y'.'m'.'FzZTY0X2VuY2'.'9k'.'ZQ=='),base64_decode('c3RybGV'.'u'),base64_decode('aXNf'.'YXJyYX'.'k='),base64_decode('c2Vy'.'aWFsaX'.'pl'),base64_decode('YmFzZTY'.'0'.'X2VuY29kZQ=='),base64_decode('c2VyaWFsa'.'X'.'p'.'l'),base64_decode('YmFzZTY0X2'.'VuY29kZQ=='),base64_decode('aXNfYX'.'JyYX'.'k='),base64_decode('aXNfY'.'X'.'JyYXk='),base64_decode(''.'aW5f'.'Y'.'XJy'.'YXk'.'='),base64_decode('a'.'W5fYXJ'.'yYXk='),base64_decode('bWt0aW1l'),base64_decode(''.'Z'.'GF0'.'ZQ=='),base64_decode(''.'Z'.'GF0ZQ=='),base64_decode(''.'ZGF0ZQ=='),base64_decode('bWt0aW1'.'l'),base64_decode('ZGF0ZQ=='),base64_decode('Z'.'GF0ZQ=='),base64_decode('aW'.'5fYXJyYX'.'k='),base64_decode('c2'.'VyaWFs'.'aX'.'pl'),base64_decode('Y'.'mFzZTY0X2VuY29kZ'.'Q=='),base64_decode(''.'aW50dmFs'),base64_decode('d'.'GltZQ=='),base64_decode('ZmlsZV'.'9l'.'eGlzd'.'HM='),base64_decode('c'.'3R'.'y'.'X3JlcGxhY2U='),base64_decode('Y2xhc3NfZ'.'Xhpc3R'.'z'),base64_decode('ZGVmaW'.'5l'));if(!function_exists(__NAMESPACE__.'\\___1559521492')){function ___1559521492($_1772770282){static $_197050112= false; if($_197050112 == false) $_197050112=array('SU5UUkFORV'.'RfR'.'URJ'.'VE'.'lPTg==','W'.'Q==','bWFpbg==','fmN'.'wZ'.'l9'.'tY'.'XBfd'.'mF'.'sdWU=','','','Y'.'W'.'xs'.'b3dlZF'.'9j'.'bG'.'Fzc2Vz','ZQ='.'=','Zg='.'=','ZQ==','Rg==',''.'WA'.'='.'=','Zg'.'==',''.'bWFpbg='.'=','fmNwZ'.'l9tYXBfdmFsdW'.'U=','UG9'.'ydGFs','Rg==','ZQ'.'==','ZQ==','W'.'A'.'==','Rg==','RA==','RA==',''.'bQ==','ZA'.'==',''.'WQ='.'=','Zg'.'==','Zg==','Zg==','Zg==','UG9ydGF'.'s','R'.'g==','ZQ==',''.'ZQ'.'==','W'.'A==','R'.'g==','RA'.'==','RA==',''.'bQ'.'==','ZA'.'==','WQ==','bWFpbg='.'=','T2'.'4=','U2V0dGluZ3ND'.'aG'.'FuZ'.'2U=','Z'.'g==','Zg==','Zg==',''.'Zg==','bW'.'Fpb'.'g==',''.'fmNw'.'Zl9tYX'.'BfdmFsdWU=','ZQ'.'==','Z'.'Q='.'=','RA==','ZQ==','ZQ='.'=','Zg==','Zg='.'=','Zg'.'==','ZQ='.'=','bWF'.'pb'.'g='.'=','fmNwZl'.'9t'.'YXBfd'.'m'.'FsdWU'.'=','Z'.'Q==','Z'.'g='.'=','Z'.'g='.'=','Zg==','Zg==',''.'bWF'.'pbg'.'==','fmNw'.'Z'.'l9'.'t'.'YXBfdmFsdWU=','Z'.'Q='.'=','Zg'.'==','UG9'.'y'.'dG'.'Fs','UG9ydGF'.'s',''.'Z'.'Q==',''.'ZQ==','UG9yd'.'G'.'Fs','Rg==',''.'WA==','Rg==','RA==','ZQ==',''.'ZQ==','RA'.'='.'=',''.'bQ==','Z'.'A==',''.'WQ==','ZQ==','WA==','ZQ==','Rg==','ZQ==','RA='.'=','Zg==',''.'ZQ==','RA'.'='.'=',''.'ZQ==','b'.'Q==','ZA==','WQ==','Zg==','Zg==','Zg'.'==','Zg==','Zg==','Zg='.'=','Zg==','Zg==','bWFpbg='.'=',''.'f'.'mN'.'wZl9tY'.'X'.'Bf'.'dmF'.'sdWU=','Z'.'Q'.'==','ZQ==',''.'U'.'G9'.'ydG'.'Fs','R'.'g==',''.'WA='.'=','VF'.'l'.'QRQ==',''.'REFU'.'RQ==','RkVB'.'V'.'FVS'.'RVM=','RV'.'hQSVJFRA'.'==','VFlQR'.'Q==','R'.'A==','VFJZX'.'0RBWVNfQ09VTlQ'.'=',''.'REFURQ='.'=','VFJZX0RB'.'WVNf'.'Q0'.'9'.'VTlQ'.'=','RVhQS'.'VJ'.'F'.'R'.'A'.'==','RkVBVF'.'VSRV'.'M=','Zg==','Zg'.'==','RE9D'.'VU1FTl'.'RfUk9PVA==','L'.'2JpdHJp'.'eC9tb2R1b'.'GVzLw==','L2'.'luc3RhbGwvaW'.'5kZXgucGhw','Lg==','X'.'w==','c2VhcmNo','T'.'g==','','','Q'.'UN'.'USVZF','WQ==','c'.'29jaWF'.'sbmV'.'0'.'d'.'2'.'9y'.'aw'.'==',''.'Y'.'Wxsb3dfZnJpZWxkc'.'w==','WQ==','S'.'UQ=','c'.'2'.'9ja'.'WFs'.'bmV0d29'.'ya'.'w'.'='.'=','YWx'.'s'.'b3df'.'ZnJpZWxkcw='.'=','SUQ=',''.'c'.'29jaWF'.'sb'.'mV0d29yaw==',''.'YWxs'.'b3df'.'ZnJpZWxkc'.'w==','Tg='.'=','','','QUNUSVZ'.'F','WQ==','c2'.'9ja'.'WFs'.'bmV0d29ya'.'w==','YWxs'.'b3dfb'.'Wljc'.'m9'.'ibG9nX3V'.'zZXI=','WQ'.'==',''.'SUQ=','c'.'29j'.'aW'.'FsbmV0d'.'29yaw='.'=','YW'.'xsb3dfbWljc'.'m9'.'ibG9n'.'X'.'3V'.'zZX'.'I'.'=','SUQ=',''.'c'.'2'.'9jaWFsbmV'.'0d'.'29y'.'aw==','YWxsb'.'3dfbWljc'.'m9ibG'.'9nX3VzZXI=',''.'c2'.'9ja'.'WFsbmV0d29ya'.'w==','YWxsb3d'.'fbWljcm9ibG9'.'n'.'X2dyb3Vw','WQ'.'==',''.'S'.'U'.'Q'.'=','c29jaWFs'.'b'.'m'.'V0'.'d'.'29yaw'.'==','YWxsb3'.'df'.'b'.'Wljcm9ibG'.'9'.'n'.'X2d'.'yb3V'.'w','SUQ=','c29jaW'.'Fsbm'.'V0'.'d29yaw='.'=','YWxsb3d'.'f'.'bWl'.'jcm'.'9'.'ibG9nX2dyb3Vw','T'.'g='.'=','','','QUN'.'U'.'SVZF','WQ'.'==','c29jaWFs'.'bmV0'.'d29ya'.'w'.'='.'=','YWxs'.'b'.'3d'.'fZ'.'ml'.'s'.'Z'.'X'.'NfdXNl'.'cg='.'=','WQ==','SUQ=',''.'c2'.'9ja'.'WFsb'.'mV0d2'.'9y'.'aw==','Y'.'Wxs'.'b3'.'d'.'f'.'ZmlsZX'.'NfdXNlcg==','SUQ=','c2'.'9jaW'.'FsbmV0d2'.'9yaw==','Y'.'Wx'.'sb3'.'dfZml'.'sZ'.'XNfd'.'XNl'.'cg==','Tg==','','','QU'.'N'.'US'.'V'.'ZF','WQ==','c29jaWFsbmV0d'.'29yaw==','YWxs'.'b'.'3d'.'fY'.'mxvZ191c2'.'Vy','WQ==','SUQ=','c29ja'.'WFsbmV'.'0'.'d'.'29yaw==','YWx'.'sb3dfYmxvZ1'.'91'.'c2'.'Vy',''.'SU'.'Q'.'=','c'.'2'.'9j'.'aW'.'FsbmV'.'0d29ya'.'w'.'==','YWx'.'s'.'b3'.'dfYmxvZ'.'191'.'c'.'2Vy','Tg==','','',''.'Q'.'UNUSVZF','WQ==','c29jaWFsbmV'.'0d2'.'9'.'ya'.'w'.'='.'=','YWxsb'.'3'.'df'.'c'.'Ghv'.'d'.'G9fdXN'.'lcg='.'=','WQ==','SU'.'Q'.'=','c'.'2'.'9jaWFsbmV'.'0d29yaw==','YWx'.'sb'.'3dfcGh'.'vd'.'G9fdXNl'.'c'.'g==','SUQ=','c29jaWFsbmV0d29yaw==',''.'YWxsb'.'3df'.'c'.'Gh'.'vdG9fd'.'XNlc'.'g==','T'.'g==','','',''.'Q'.'U'.'NUSV'.'ZF',''.'WQ==','c29'.'ja'.'WFs'.'bmV0d'.'29yaw==','YWxsb3d'.'fZm9'.'ydW1fd'.'XNlcg='.'=',''.'WQ='.'=','SUQ=','c29'.'jaWF'.'sbmV'.'0d'.'29yaw'.'==','YWxs'.'b3'.'d'.'f'.'Zm9ydW1fdX'.'N'.'lcg==','S'.'U'.'Q=','c2'.'9jaWF'.'sbmV0d29'.'yaw'.'==','YWxsb3dfZm9ydW1fdXNlcg==','Tg==','','',''.'QU'.'NUSV'.'ZF','W'.'Q==','c29jaWF'.'sbmV0d29yaw==','YWxsb3dfdGFza3NfdXNlcg'.'='.'=','WQ='.'=','S'.'UQ=','c29'.'jaWFs'.'bm'.'V0d29ya'.'w==',''.'YW'.'x'.'sb'.'3dfdG'.'Fza3'.'NfdXNl'.'cg==','SUQ=','c'.'29'.'jaWF'.'sbmV0d29yaw==','Y'.'Wx'.'sb'.'3d'.'fd'.'GFza3NfdXN'.'l'.'cg==','c2'.'9jaWFsbm'.'V0d'.'29'.'yaw'.'==','YWxsb3df'.'dG'.'Fza3N'.'fZ3JvdXA'.'=','WQ==','S'.'UQ=','c29'.'jaW'.'FsbmV0d29'.'ya'.'w==','YWxsb3dfdGFza3N'.'fZ3'.'J'.'v'.'dXA=','S'.'UQ=','c2'.'9jaWFsbm'.'V0d29yaw='.'=',''.'YWx'.'sb3'.'df'.'dG'.'F'.'z'.'a'.'3NfZ'.'3Jvd'.'XA'.'=','dGFza3'.'M'.'=','Tg==','','','Q'.'U'.'NUS'.'VZ'.'F','WQ==','c29jaWFsbm'.'V0'.'d'.'29'.'yaw==','YWxs'.'b3dfY2Fs'.'ZW5kYX'.'JfdXNlcg'.'==','WQ==','S'.'UQ'.'=','c29ja'.'WFs'.'bmV0d29yaw==','YWxs'.'b3d'.'fY2FsZW5kYXJf'.'dX'.'Nlcg==','S'.'UQ=','c'.'29j'.'aWFsb'.'mV0d29yaw==','YWxsb3d'.'fY2FsZW'.'5kYXJfd'.'XNlcg'.'==','c2'.'9'.'jaWFsb'.'mV0d'.'29ya'.'w==','YWxsb3'.'dfY2Fs'.'ZW'.'5k'.'YX'.'JfZ3Jvd'.'XA'.'=','WQ==','SU'.'Q=','c'.'29j'.'aWFsbmV'.'0d29yaw==','YWxsb3dfY2F'.'s'.'ZW5kYXJfZ3JvdXA=','SUQ=','c29jaWFs'.'b'.'mV0'.'d29yaw'.'='.'=',''.'Y'.'Wx'.'sb'.'3dfY2FsZ'.'W5'.'kYXJf'.'Z'.'3Jvd'.'XA=','QUNU'.'SVZF','WQ'.'==','Tg'.'==','ZX'.'h0cmFuZXQ=','aWJsb2Nr',''.'T25BZnR'.'l'.'ck'.'lC'.'b'.'G9ja'.'0VsZW1'.'l'.'bnRVcG'.'RhdGU'.'=',''.'aW50'.'cmF'.'uZXQ'.'=','Q0'.'ludHJhbmV0RXZ'.'lb'.'nRIYW'.'5'.'k'.'bGVycw'.'==','U1BSZWdp'.'c'.'3RlclVwZGF0ZWRJdG'.'V'.'t','Q0'.'ludH'.'JhbmV0U'.'2h'.'h'.'cmVw'.'b'.'2lu'.'dDo6QWdlbn'.'RMa'.'XN0cyg'.'p'.'Ow'.'==','aW50c'.'mF'.'uZXQ'.'=','T'.'g==','Q0ludH'.'J'.'h'.'bmV0U2'.'hhc'.'mVw'.'b2ludDo6QWdlbnRR'.'dW'.'V1ZSgpO'.'w'.'==','a'.'W'.'50'.'cmFuZX'.'Q=','Tg==','Q0ludHJh'.'b'.'mV0U2hh'.'cmVwb'.'2lu'.'dDo6'.'QWdlbnRVcGRhd'.'GUoK'.'Ts=','aW'.'50'.'cmF'.'uZXQ=','Tg'.'==','aWJ'.'s'.'b2Nr','T25BZnRlcklCb'.'G9ja'.'0VsZW1lbn'.'RB'.'ZGQ'.'=',''.'aW5'.'0cm'.'FuZXQ=','Q0ludHJh'.'b'.'mV0'.'RXZlbnRIYW'.'5kbGVycw==',''.'U1BSZWdpc3RlclVwZGF0'.'ZWRJdGVt','aW'.'Jsb2'.'Nr','T25BZ'.'nR'.'lcklCbG9ja'.'0VsZW1lbnRV'.'c'.'GRhdGU=',''.'aW50c'.'mF'.'uZ'.'XQ'.'=','Q'.'0ludHJ'.'hbm'.'V0RXZlbnR'.'IY'.'W5kbGVycw==','U1BSZWdpc3RlclVwZG'.'F0Z'.'WR'.'JdGVt','Q0lud'.'HJ'.'h'.'bm'.'V0U'.'2hhcmVwb2ludDo6QWd'.'l'.'bnRM'.'aXN0cyg'.'p'.'O'.'w'.'==',''.'aW5'.'0cmFuZXQ=','Q'.'0ludH'.'JhbmV'.'0U2hhcmVwb'.'2'.'ludDo'.'6QWdlb'.'nR'.'R'.'dWV'.'1ZSg'.'p'.'Ow==','aW50cmF'.'uZX'.'Q=','Q0ludHJhbmV0U2hhc'.'mV'.'wb2ludDo6QW'.'dlbnR'.'V'.'cG'.'RhdGU'.'oKTs=','aW50cmFuZXQ=',''.'Y3Jt','bWFpbg'.'==','T2'.'5C'.'Z'.'WZvcmV'.'Qcm'.'9'.'sb2c=','b'.'WF'.'p'.'bg==','Q1dpemF'.'yZFNvb'.'FBhbmVsS'.'W50cm'.'FuZXQ=','U2hvd'.'1Bhb'.'m'.'Vs','L'.'21vZHVsZXMvaW5'.'0c'.'m'.'FuZ'.'XQvcGFuZW'.'xfYn'.'V0d'.'G9uLnBo'.'cA==','RU5DT0RF',''.'W'.'Q==');return base64_decode($_197050112[$_1772770282]);}};$GLOBALS['____1236346724'][0](___1559521492(0), ___1559521492(1));class CBXFeatures{ private static $_85946221= 30; private static $_2134155648= array( "Portal" => array( "CompanyCalendar", "CompanyPhoto", "CompanyVideo", "CompanyCareer", "StaffChanges", "StaffAbsence", "CommonDocuments", "MeetingRoomBookingSystem", "Wiki", "Learning", "Vote", "WebLink", "Subscribe", "Friends", "PersonalFiles", "PersonalBlog", "PersonalPhoto", "PersonalForum", "Blog", "Forum", "Gallery", "Board", "MicroBlog", "WebMessenger",), "Communications" => array( "Tasks", "Calendar", "Workgroups", "Jabber", "VideoConference", "Extranet", "SMTP", "Requests", "DAV", "intranet_sharepoint", "timeman", "Idea", "Meeting", "EventList", "Salary", "XDImport",), "Enterprise" => array( "BizProc", "Lists", "Support", "Analytics", "crm", "Controller", "LdapUnlimitedUsers",), "Holding" => array( "Cluster", "MultiSites",),); private static $_1121094560= null; private static $_188851634= null; private static function __1083868439(){ if(self::$_1121094560 === null){ self::$_1121094560= array(); foreach(self::$_2134155648 as $_1603968965 => $_903918376){ foreach($_903918376 as $_235824304) self::$_1121094560[$_235824304]= $_1603968965;}} if(self::$_188851634 === null){ self::$_188851634= array(); $_1035664654= COption::GetOptionString(___1559521492(2), ___1559521492(3), ___1559521492(4)); if($_1035664654 != ___1559521492(5)){ $_1035664654= $GLOBALS['____1236346724'][1]($_1035664654); $_1035664654= $GLOBALS['____1236346724'][2]($_1035664654,[___1559521492(6) => false]); if($GLOBALS['____1236346724'][3]($_1035664654)){ self::$_188851634= $_1035664654;}} if(empty(self::$_188851634)){ self::$_188851634= array(___1559521492(7) => array(), ___1559521492(8) => array());}}} public static function InitiateEditionsSettings($_768206911){ self::__1083868439(); $_166202954= array(); foreach(self::$_2134155648 as $_1603968965 => $_903918376){ $_1931091972= $GLOBALS['____1236346724'][4]($_1603968965, $_768206911); self::$_188851634[___1559521492(9)][$_1603968965]=($_1931091972? array(___1559521492(10)): array(___1559521492(11))); foreach($_903918376 as $_235824304){ self::$_188851634[___1559521492(12)][$_235824304]= $_1931091972; if(!$_1931091972) $_166202954[]= array($_235824304, false);}} $_538113753= $GLOBALS['____1236346724'][5](self::$_188851634); $_538113753= $GLOBALS['____1236346724'][6]($_538113753); COption::SetOptionString(___1559521492(13), ___1559521492(14), $_538113753); foreach($_166202954 as $_1707754454) self::__1774711083($_1707754454[(200*2-400)], $_1707754454[round(0+0.33333333333333+0.33333333333333+0.33333333333333)]);} public static function IsFeatureEnabled($_235824304){ if($_235824304 == '') return true; self::__1083868439(); if(!isset(self::$_1121094560[$_235824304])) return true; if(self::$_1121094560[$_235824304] == ___1559521492(15)) $_238668396= array(___1559521492(16)); elseif(isset(self::$_188851634[___1559521492(17)][self::$_1121094560[$_235824304]])) $_238668396= self::$_188851634[___1559521492(18)][self::$_1121094560[$_235824304]]; else $_238668396= array(___1559521492(19)); if($_238668396[min(174,0,58)] != ___1559521492(20) && $_238668396[(136*2-272)] != ___1559521492(21)){ return false;} elseif($_238668396[(168*2-336)] == ___1559521492(22)){ if($_238668396[round(0+0.25+0.25+0.25+0.25)]< $GLOBALS['____1236346724'][7]((244*2-488), min(180,0,60),(167*2-334), Date(___1559521492(23)), $GLOBALS['____1236346724'][8](___1559521492(24))- self::$_85946221, $GLOBALS['____1236346724'][9](___1559521492(25)))){ if(!isset($_238668396[round(0+2)]) ||!$_238668396[round(0+0.66666666666667+0.66666666666667+0.66666666666667)]) self::__1214842962(self::$_1121094560[$_235824304]); return false;}} return!isset(self::$_188851634[___1559521492(26)][$_235824304]) || self::$_188851634[___1559521492(27)][$_235824304];} public static function IsFeatureInstalled($_235824304){ if($GLOBALS['____1236346724'][10]($_235824304) <= 0) return true; self::__1083868439(); return(isset(self::$_188851634[___1559521492(28)][$_235824304]) && self::$_188851634[___1559521492(29)][$_235824304]);} public static function IsFeatureEditable($_235824304){ if($_235824304 == '') return true; self::__1083868439(); if(!isset(self::$_1121094560[$_235824304])) return true; if(self::$_1121094560[$_235824304] == ___1559521492(30)) $_238668396= array(___1559521492(31)); elseif(isset(self::$_188851634[___1559521492(32)][self::$_1121094560[$_235824304]])) $_238668396= self::$_188851634[___1559521492(33)][self::$_1121094560[$_235824304]]; else $_238668396= array(___1559521492(34)); if($_238668396[(178*2-356)] != ___1559521492(35) && $_238668396[(1236/2-618)] != ___1559521492(36)){ return false;} elseif($_238668396[(141*2-282)] == ___1559521492(37)){ if($_238668396[round(0+0.2+0.2+0.2+0.2+0.2)]< $GLOBALS['____1236346724'][11]((790-2*395), min(214,0,71.333333333333),(1496/2-748), Date(___1559521492(38)), $GLOBALS['____1236346724'][12](___1559521492(39))- self::$_85946221, $GLOBALS['____1236346724'][13](___1559521492(40)))){ if(!isset($_238668396[round(0+0.5+0.5+0.5+0.5)]) ||!$_238668396[round(0+2)]) self::__1214842962(self::$_1121094560[$_235824304]); return false;}} return true;} private static function __1774711083($_235824304, $_388388781){ if($GLOBALS['____1236346724'][14]("CBXFeatures", "On".$_235824304."SettingsChange")) $GLOBALS['____1236346724'][15](array("CBXFeatures", "On".$_235824304."SettingsChange"), array($_235824304, $_388388781)); $_1128161880= $GLOBALS['_____1477776978'][0](___1559521492(41), ___1559521492(42).$_235824304.___1559521492(43)); while($_1346286303= $_1128161880->Fetch()) $GLOBALS['_____1477776978'][1]($_1346286303, array($_235824304, $_388388781));} public static function SetFeatureEnabled($_235824304, $_388388781= true, $_1098772914= true){ if($GLOBALS['____1236346724'][16]($_235824304) <= 0) return; if(!self::IsFeatureEditable($_235824304)) $_388388781= false; $_388388781= (bool)$_388388781; self::__1083868439(); $_1569441231=(!isset(self::$_188851634[___1559521492(44)][$_235824304]) && $_388388781 || isset(self::$_188851634[___1559521492(45)][$_235824304]) && $_388388781 != self::$_188851634[___1559521492(46)][$_235824304]); self::$_188851634[___1559521492(47)][$_235824304]= $_388388781; $_538113753= $GLOBALS['____1236346724'][17](self::$_188851634); $_538113753= $GLOBALS['____1236346724'][18]($_538113753); COption::SetOptionString(___1559521492(48), ___1559521492(49), $_538113753); if($_1569441231 && $_1098772914) self::__1774711083($_235824304, $_388388781);} private static function __1214842962($_1603968965){ if($GLOBALS['____1236346724'][19]($_1603968965) <= 0 || $_1603968965 == "Portal") return; self::__1083868439(); if(!isset(self::$_188851634[___1559521492(50)][$_1603968965]) || self::$_188851634[___1559521492(51)][$_1603968965][(830-2*415)] != ___1559521492(52)) return; if(isset(self::$_188851634[___1559521492(53)][$_1603968965][round(0+0.4+0.4+0.4+0.4+0.4)]) && self::$_188851634[___1559521492(54)][$_1603968965][round(0+2)]) return; $_166202954= array(); if(isset(self::$_2134155648[$_1603968965]) && $GLOBALS['____1236346724'][20](self::$_2134155648[$_1603968965])){ foreach(self::$_2134155648[$_1603968965] as $_235824304){ if(isset(self::$_188851634[___1559521492(55)][$_235824304]) && self::$_188851634[___1559521492(56)][$_235824304]){ self::$_188851634[___1559521492(57)][$_235824304]= false; $_166202954[]= array($_235824304, false);}} self::$_188851634[___1559521492(58)][$_1603968965][round(0+0.5+0.5+0.5+0.5)]= true;} $_538113753= $GLOBALS['____1236346724'][21](self::$_188851634); $_538113753= $GLOBALS['____1236346724'][22]($_538113753); COption::SetOptionString(___1559521492(59), ___1559521492(60), $_538113753); foreach($_166202954 as $_1707754454) self::__1774711083($_1707754454[(1256/2-628)], $_1707754454[round(0+1)]);} public static function ModifyFeaturesSettings($_768206911, $_903918376){ self::__1083868439(); foreach($_768206911 as $_1603968965 => $_1299468313) self::$_188851634[___1559521492(61)][$_1603968965]= $_1299468313; $_166202954= array(); foreach($_903918376 as $_235824304 => $_388388781){ if(!isset(self::$_188851634[___1559521492(62)][$_235824304]) && $_388388781 || isset(self::$_188851634[___1559521492(63)][$_235824304]) && $_388388781 != self::$_188851634[___1559521492(64)][$_235824304]) $_166202954[]= array($_235824304, $_388388781); self::$_188851634[___1559521492(65)][$_235824304]= $_388388781;} $_538113753= $GLOBALS['____1236346724'][23](self::$_188851634); $_538113753= $GLOBALS['____1236346724'][24]($_538113753); COption::SetOptionString(___1559521492(66), ___1559521492(67), $_538113753); self::$_188851634= false; foreach($_166202954 as $_1707754454) self::__1774711083($_1707754454[(196*2-392)], $_1707754454[round(0+1)]);} public static function SaveFeaturesSettings($_741880655, $_1433404010){ self::__1083868439(); $_262721953= array(___1559521492(68) => array(), ___1559521492(69) => array()); if(!$GLOBALS['____1236346724'][25]($_741880655)) $_741880655= array(); if(!$GLOBALS['____1236346724'][26]($_1433404010)) $_1433404010= array(); if(!$GLOBALS['____1236346724'][27](___1559521492(70), $_741880655)) $_741880655[]= ___1559521492(71); foreach(self::$_2134155648 as $_1603968965 => $_903918376){ if(isset(self::$_188851634[___1559521492(72)][$_1603968965])){ $_1563768099= self::$_188851634[___1559521492(73)][$_1603968965];} else{ $_1563768099=($_1603968965 == ___1559521492(74)? array(___1559521492(75)): array(___1559521492(76)));} if($_1563768099[(760-2*380)] == ___1559521492(77) || $_1563768099[(238*2-476)] == ___1559521492(78)){ $_262721953[___1559521492(79)][$_1603968965]= $_1563768099;} else{ if($GLOBALS['____1236346724'][28]($_1603968965, $_741880655)) $_262721953[___1559521492(80)][$_1603968965]= array(___1559521492(81), $GLOBALS['____1236346724'][29]((816-2*408),(806-2*403),(1172/2-586), $GLOBALS['____1236346724'][30](___1559521492(82)), $GLOBALS['____1236346724'][31](___1559521492(83)), $GLOBALS['____1236346724'][32](___1559521492(84)))); else $_262721953[___1559521492(85)][$_1603968965]= array(___1559521492(86));}} $_166202954= array(); foreach(self::$_1121094560 as $_235824304 => $_1603968965){ if($_262721953[___1559521492(87)][$_1603968965][min(196,0,65.333333333333)] != ___1559521492(88) && $_262721953[___1559521492(89)][$_1603968965][(162*2-324)] != ___1559521492(90)){ $_262721953[___1559521492(91)][$_235824304]= false;} else{ if($_262721953[___1559521492(92)][$_1603968965][(942-2*471)] == ___1559521492(93) && $_262721953[___1559521492(94)][$_1603968965][round(0+1)]< $GLOBALS['____1236346724'][33]((206*2-412),(942-2*471), min(126,0,42), Date(___1559521492(95)), $GLOBALS['____1236346724'][34](___1559521492(96))- self::$_85946221, $GLOBALS['____1236346724'][35](___1559521492(97)))) $_262721953[___1559521492(98)][$_235824304]= false; else $_262721953[___1559521492(99)][$_235824304]= $GLOBALS['____1236346724'][36]($_235824304, $_1433404010); if(!isset(self::$_188851634[___1559521492(100)][$_235824304]) && $_262721953[___1559521492(101)][$_235824304] || isset(self::$_188851634[___1559521492(102)][$_235824304]) && $_262721953[___1559521492(103)][$_235824304] != self::$_188851634[___1559521492(104)][$_235824304]) $_166202954[]= array($_235824304, $_262721953[___1559521492(105)][$_235824304]);}} $_538113753= $GLOBALS['____1236346724'][37]($_262721953); $_538113753= $GLOBALS['____1236346724'][38]($_538113753); COption::SetOptionString(___1559521492(106), ___1559521492(107), $_538113753); self::$_188851634= false; foreach($_166202954 as $_1707754454) self::__1774711083($_1707754454[(780-2*390)], $_1707754454[round(0+0.25+0.25+0.25+0.25)]);} public static function GetFeaturesList(){ self::__1083868439(); $_1600893873= array(); foreach(self::$_2134155648 as $_1603968965 => $_903918376){ if(isset(self::$_188851634[___1559521492(108)][$_1603968965])){ $_1563768099= self::$_188851634[___1559521492(109)][$_1603968965];} else{ $_1563768099=($_1603968965 == ___1559521492(110)? array(___1559521492(111)): array(___1559521492(112)));} $_1600893873[$_1603968965]= array( ___1559521492(113) => $_1563768099[(788-2*394)], ___1559521492(114) => $_1563768099[round(0+0.5+0.5)], ___1559521492(115) => array(),); $_1600893873[$_1603968965][___1559521492(116)]= false; if($_1600893873[$_1603968965][___1559521492(117)] == ___1559521492(118)){ $_1600893873[$_1603968965][___1559521492(119)]= $GLOBALS['____1236346724'][39](($GLOBALS['____1236346724'][40]()- $_1600893873[$_1603968965][___1559521492(120)])/ round(0+86400)); if($_1600893873[$_1603968965][___1559521492(121)]> self::$_85946221) $_1600893873[$_1603968965][___1559521492(122)]= true;} foreach($_903918376 as $_235824304) $_1600893873[$_1603968965][___1559521492(123)][$_235824304]=(!isset(self::$_188851634[___1559521492(124)][$_235824304]) || self::$_188851634[___1559521492(125)][$_235824304]);} return $_1600893873;} private static function __342331199($_1374798314, $_1982202150){ if(IsModuleInstalled($_1374798314) == $_1982202150) return true; $_2004539696= $_SERVER[___1559521492(126)].___1559521492(127).$_1374798314.___1559521492(128); if(!$GLOBALS['____1236346724'][41]($_2004539696)) return false; include_once($_2004539696); $_1893893115= $GLOBALS['____1236346724'][42](___1559521492(129), ___1559521492(130), $_1374798314); if(!$GLOBALS['____1236346724'][43]($_1893893115)) return false; $_1308121915= new $_1893893115; if($_1982202150){ if(!$_1308121915->InstallDB()) return false; $_1308121915->InstallEvents(); if(!$_1308121915->InstallFiles()) return false;} else{ if(CModule::IncludeModule(___1559521492(131))) CSearch::DeleteIndex($_1374798314); UnRegisterModule($_1374798314);} return true;} protected static function OnRequestsSettingsChange($_235824304, $_388388781){ self::__342331199("form", $_388388781);} protected static function OnLearningSettingsChange($_235824304, $_388388781){ self::__342331199("learning", $_388388781);} protected static function OnJabberSettingsChange($_235824304, $_388388781){ self::__342331199("xmpp", $_388388781);} protected static function OnVideoConferenceSettingsChange($_235824304, $_388388781){} protected static function OnBizProcSettingsChange($_235824304, $_388388781){ self::__342331199("bizprocdesigner", $_388388781);} protected static function OnListsSettingsChange($_235824304, $_388388781){ self::__342331199("lists", $_388388781);} protected static function OnWikiSettingsChange($_235824304, $_388388781){ self::__342331199("wiki", $_388388781);} protected static function OnSupportSettingsChange($_235824304, $_388388781){ self::__342331199("support", $_388388781);} protected static function OnControllerSettingsChange($_235824304, $_388388781){ self::__342331199("controller", $_388388781);} protected static function OnAnalyticsSettingsChange($_235824304, $_388388781){ self::__342331199("statistic", $_388388781);} protected static function OnVoteSettingsChange($_235824304, $_388388781){ self::__342331199("vote", $_388388781);} protected static function OnFriendsSettingsChange($_235824304, $_388388781){ if($_388388781) $_474064241= "Y"; else $_474064241= ___1559521492(132); $_2105606754= CSite::GetList(___1559521492(133), ___1559521492(134), array(___1559521492(135) => ___1559521492(136))); while($_1654866524= $_2105606754->Fetch()){ if(COption::GetOptionString(___1559521492(137), ___1559521492(138), ___1559521492(139), $_1654866524[___1559521492(140)]) != $_474064241){ COption::SetOptionString(___1559521492(141), ___1559521492(142), $_474064241, false, $_1654866524[___1559521492(143)]); COption::SetOptionString(___1559521492(144), ___1559521492(145), $_474064241);}}} protected static function OnMicroBlogSettingsChange($_235824304, $_388388781){ if($_388388781) $_474064241= "Y"; else $_474064241= ___1559521492(146); $_2105606754= CSite::GetList(___1559521492(147), ___1559521492(148), array(___1559521492(149) => ___1559521492(150))); while($_1654866524= $_2105606754->Fetch()){ if(COption::GetOptionString(___1559521492(151), ___1559521492(152), ___1559521492(153), $_1654866524[___1559521492(154)]) != $_474064241){ COption::SetOptionString(___1559521492(155), ___1559521492(156), $_474064241, false, $_1654866524[___1559521492(157)]); COption::SetOptionString(___1559521492(158), ___1559521492(159), $_474064241);} if(COption::GetOptionString(___1559521492(160), ___1559521492(161), ___1559521492(162), $_1654866524[___1559521492(163)]) != $_474064241){ COption::SetOptionString(___1559521492(164), ___1559521492(165), $_474064241, false, $_1654866524[___1559521492(166)]); COption::SetOptionString(___1559521492(167), ___1559521492(168), $_474064241);}}} protected static function OnPersonalFilesSettingsChange($_235824304, $_388388781){ if($_388388781) $_474064241= "Y"; else $_474064241= ___1559521492(169); $_2105606754= CSite::GetList(___1559521492(170), ___1559521492(171), array(___1559521492(172) => ___1559521492(173))); while($_1654866524= $_2105606754->Fetch()){ if(COption::GetOptionString(___1559521492(174), ___1559521492(175), ___1559521492(176), $_1654866524[___1559521492(177)]) != $_474064241){ COption::SetOptionString(___1559521492(178), ___1559521492(179), $_474064241, false, $_1654866524[___1559521492(180)]); COption::SetOptionString(___1559521492(181), ___1559521492(182), $_474064241);}}} protected static function OnPersonalBlogSettingsChange($_235824304, $_388388781){ if($_388388781) $_474064241= "Y"; else $_474064241= ___1559521492(183); $_2105606754= CSite::GetList(___1559521492(184), ___1559521492(185), array(___1559521492(186) => ___1559521492(187))); while($_1654866524= $_2105606754->Fetch()){ if(COption::GetOptionString(___1559521492(188), ___1559521492(189), ___1559521492(190), $_1654866524[___1559521492(191)]) != $_474064241){ COption::SetOptionString(___1559521492(192), ___1559521492(193), $_474064241, false, $_1654866524[___1559521492(194)]); COption::SetOptionString(___1559521492(195), ___1559521492(196), $_474064241);}}} protected static function OnPersonalPhotoSettingsChange($_235824304, $_388388781){ if($_388388781) $_474064241= "Y"; else $_474064241= ___1559521492(197); $_2105606754= CSite::GetList(___1559521492(198), ___1559521492(199), array(___1559521492(200) => ___1559521492(201))); while($_1654866524= $_2105606754->Fetch()){ if(COption::GetOptionString(___1559521492(202), ___1559521492(203), ___1559521492(204), $_1654866524[___1559521492(205)]) != $_474064241){ COption::SetOptionString(___1559521492(206), ___1559521492(207), $_474064241, false, $_1654866524[___1559521492(208)]); COption::SetOptionString(___1559521492(209), ___1559521492(210), $_474064241);}}} protected static function OnPersonalForumSettingsChange($_235824304, $_388388781){ if($_388388781) $_474064241= "Y"; else $_474064241= ___1559521492(211); $_2105606754= CSite::GetList(___1559521492(212), ___1559521492(213), array(___1559521492(214) => ___1559521492(215))); while($_1654866524= $_2105606754->Fetch()){ if(COption::GetOptionString(___1559521492(216), ___1559521492(217), ___1559521492(218), $_1654866524[___1559521492(219)]) != $_474064241){ COption::SetOptionString(___1559521492(220), ___1559521492(221), $_474064241, false, $_1654866524[___1559521492(222)]); COption::SetOptionString(___1559521492(223), ___1559521492(224), $_474064241);}}} protected static function OnTasksSettingsChange($_235824304, $_388388781){ if($_388388781) $_474064241= "Y"; else $_474064241= ___1559521492(225); $_2105606754= CSite::GetList(___1559521492(226), ___1559521492(227), array(___1559521492(228) => ___1559521492(229))); while($_1654866524= $_2105606754->Fetch()){ if(COption::GetOptionString(___1559521492(230), ___1559521492(231), ___1559521492(232), $_1654866524[___1559521492(233)]) != $_474064241){ COption::SetOptionString(___1559521492(234), ___1559521492(235), $_474064241, false, $_1654866524[___1559521492(236)]); COption::SetOptionString(___1559521492(237), ___1559521492(238), $_474064241);} if(COption::GetOptionString(___1559521492(239), ___1559521492(240), ___1559521492(241), $_1654866524[___1559521492(242)]) != $_474064241){ COption::SetOptionString(___1559521492(243), ___1559521492(244), $_474064241, false, $_1654866524[___1559521492(245)]); COption::SetOptionString(___1559521492(246), ___1559521492(247), $_474064241);}} self::__342331199(___1559521492(248), $_388388781);} protected static function OnCalendarSettingsChange($_235824304, $_388388781){ if($_388388781) $_474064241= "Y"; else $_474064241= ___1559521492(249); $_2105606754= CSite::GetList(___1559521492(250), ___1559521492(251), array(___1559521492(252) => ___1559521492(253))); while($_1654866524= $_2105606754->Fetch()){ if(COption::GetOptionString(___1559521492(254), ___1559521492(255), ___1559521492(256), $_1654866524[___1559521492(257)]) != $_474064241){ COption::SetOptionString(___1559521492(258), ___1559521492(259), $_474064241, false, $_1654866524[___1559521492(260)]); COption::SetOptionString(___1559521492(261), ___1559521492(262), $_474064241);} if(COption::GetOptionString(___1559521492(263), ___1559521492(264), ___1559521492(265), $_1654866524[___1559521492(266)]) != $_474064241){ COption::SetOptionString(___1559521492(267), ___1559521492(268), $_474064241, false, $_1654866524[___1559521492(269)]); COption::SetOptionString(___1559521492(270), ___1559521492(271), $_474064241);}}} protected static function OnSMTPSettingsChange($_235824304, $_388388781){ self::__342331199("mail", $_388388781);} protected static function OnExtranetSettingsChange($_235824304, $_388388781){ $_1579504158= COption::GetOptionString("extranet", "extranet_site", ""); if($_1579504158){ $_923190733= new CSite; $_923190733->Update($_1579504158, array(___1559521492(272) =>($_388388781? ___1559521492(273): ___1559521492(274))));} self::__342331199(___1559521492(275), $_388388781);} protected static function OnDAVSettingsChange($_235824304, $_388388781){ self::__342331199("dav", $_388388781);} protected static function OntimemanSettingsChange($_235824304, $_388388781){ self::__342331199("timeman", $_388388781);} protected static function Onintranet_sharepointSettingsChange($_235824304, $_388388781){ if($_388388781){ RegisterModuleDependences("iblock", "OnAfterIBlockElementAdd", "intranet", "CIntranetEventHandlers", "SPRegisterUpdatedItem"); RegisterModuleDependences(___1559521492(276), ___1559521492(277), ___1559521492(278), ___1559521492(279), ___1559521492(280)); CAgent::AddAgent(___1559521492(281), ___1559521492(282), ___1559521492(283), round(0+500)); CAgent::AddAgent(___1559521492(284), ___1559521492(285), ___1559521492(286), round(0+150+150)); CAgent::AddAgent(___1559521492(287), ___1559521492(288), ___1559521492(289), round(0+720+720+720+720+720));} else{ UnRegisterModuleDependences(___1559521492(290), ___1559521492(291), ___1559521492(292), ___1559521492(293), ___1559521492(294)); UnRegisterModuleDependences(___1559521492(295), ___1559521492(296), ___1559521492(297), ___1559521492(298), ___1559521492(299)); CAgent::RemoveAgent(___1559521492(300), ___1559521492(301)); CAgent::RemoveAgent(___1559521492(302), ___1559521492(303)); CAgent::RemoveAgent(___1559521492(304), ___1559521492(305));}} protected static function OncrmSettingsChange($_235824304, $_388388781){ if($_388388781) COption::SetOptionString("crm", "form_features", "Y"); self::__342331199(___1559521492(306), $_388388781);} protected static function OnClusterSettingsChange($_235824304, $_388388781){ self::__342331199("cluster", $_388388781);} protected static function OnMultiSitesSettingsChange($_235824304, $_388388781){ if($_388388781) RegisterModuleDependences("main", "OnBeforeProlog", "main", "CWizardSolPanelIntranet", "ShowPanel", 100, "/modules/intranet/panel_button.php"); else UnRegisterModuleDependences(___1559521492(307), ___1559521492(308), ___1559521492(309), ___1559521492(310), ___1559521492(311), ___1559521492(312));} protected static function OnIdeaSettingsChange($_235824304, $_388388781){ self::__342331199("idea", $_388388781);} protected static function OnMeetingSettingsChange($_235824304, $_388388781){ self::__342331199("meeting", $_388388781);} protected static function OnXDImportSettingsChange($_235824304, $_388388781){ self::__342331199("xdimport", $_388388781);}} $GLOBALS['____1236346724'][44](___1559521492(313), ___1559521492(314));/**/			//Do not remove this

// Component 2.0 template engines
$GLOBALS['arCustomTemplateEngines'] = [];

// User fields manager
$GLOBALS['USER_FIELD_MANAGER'] = new CUserTypeManager;

// todo: remove global
$GLOBALS['BX_MENU_CUSTOM'] = CMenuCustom::getInstance();

if (file_exists(($_fname = __DIR__ . "/classes/general/update_db_updater.php")))
{
	$US_HOST_PROCESS_MAIN = false;
	include $_fname;
}

if (($_fname = getLocalPath("init.php")) !== false)
{
	include_once $_SERVER["DOCUMENT_ROOT"] . $_fname;
}

if (($_fname = getLocalPath("php_interface/init.php", BX_PERSONAL_ROOT)) !== false)
{
	include_once $_SERVER["DOCUMENT_ROOT"] . $_fname;
}

if (($_fname = getLocalPath("php_interface/" . SITE_ID . "/init.php", BX_PERSONAL_ROOT)) !== false)
{
	include_once $_SERVER["DOCUMENT_ROOT"] . $_fname;
}

if ((!(defined("STATISTIC_ONLY") && STATISTIC_ONLY && !str_starts_with($GLOBALS["APPLICATION"]->GetCurPage(), BX_ROOT . "/admin/"))) && COption::GetOptionString("main", "include_charset", "Y") == "Y" && LANG_CHARSET != '')
{
	header("Content-Type: text/html; charset=".LANG_CHARSET);
}

if (COption::GetOptionString("main", "set_p3p_header", "Y") == "Y")
{
	header("P3P: policyref=\"/bitrix/p3p.xml\", CP=\"NON DSP COR CUR ADM DEV PSA PSD OUR UNR BUS UNI COM NAV INT DEM STA\"");
}

$license = $application->getLicense();
header("X-Powered-CMS: Bitrix Site Manager (" . ($license->isDemoKey() ? "DEMO" : $license->getPublicHashKey()) . ")");

if (COption::GetOptionString("main", "update_devsrv", "") == "Y")
{
	header("X-DevSrv-CMS: Bitrix");
}

//agents
if (COption::GetOptionString("main", "check_agents", "Y") == "Y")
{
	$application->addBackgroundJob(["CAgent", "CheckAgents"], [], Main\Application::JOB_PRIORITY_LOW);
}

//send email events
if (COption::GetOptionString("main", "check_events", "Y") !== "N")
{
	$application->addBackgroundJob(['\Bitrix\Main\Mail\EventManager', 'checkEvents'], [], Main\Application::JOB_PRIORITY_LOW - 1);
}

$healerOfEarlySessionStart = new HealerEarlySessionStart();
$healerOfEarlySessionStart->process($application->getKernelSession());

$kernelSession = $application->getKernelSession();
$kernelSession->start();
$application->getSessionLocalStorageManager()->setUniqueId($kernelSession->getId());

foreach (GetModuleEvents("main", "OnPageStart", true) as $arEvent)
{
	ExecuteModuleEventEx($arEvent);
}

//define global user object
$GLOBALS["USER"] = new CUser;

//session control from group policy
$arPolicy = $GLOBALS["USER"]->GetSecurityPolicy();
$currTime = time();
if (
	(
		//IP address changed
		$kernelSession['SESS_IP']
		&& $arPolicy["SESSION_IP_MASK"] != ''
		&& (
			(ip2long($arPolicy["SESSION_IP_MASK"]) & ip2long($kernelSession['SESS_IP']))
			!=
			(ip2long($arPolicy["SESSION_IP_MASK"]) & ip2long($_SERVER['REMOTE_ADDR']))
		)
	)
	||
	(
		//session timeout
		$arPolicy["SESSION_TIMEOUT"] > 0
		&& $kernelSession['SESS_TIME'] > 0
		&& ($currTime - $arPolicy["SESSION_TIMEOUT"] * 60) > $kernelSession['SESS_TIME']
	)
	||
	(
		//signed session
		isset($kernelSession["BX_SESSION_SIGN"])
		&& $kernelSession["BX_SESSION_SIGN"] != bitrix_sess_sign()
	)
	||
	(
		//session manually expired, e.g. in $User->LoginHitByHash
		isSessionExpired()
	)
)
{
	$compositeSessionManager = $application->getCompositeSessionManager();
	$compositeSessionManager->destroy();

	$application->getSession()->setId(Main\Security\Random::getString(32));
	$compositeSessionManager->start();

	$GLOBALS["USER"] = new CUser;
}
$kernelSession['SESS_IP'] = $_SERVER['REMOTE_ADDR'] ?? null;
if (empty($kernelSession['SESS_TIME']))
{
	$kernelSession['SESS_TIME'] = $currTime;
}
elseif (($currTime - $kernelSession['SESS_TIME']) > 60)
{
	$kernelSession['SESS_TIME'] = $currTime;
}
if (!isset($kernelSession["BX_SESSION_SIGN"]))
{
	$kernelSession["BX_SESSION_SIGN"] = bitrix_sess_sign();
}

//session control from security module
if (
	(COption::GetOptionString("main", "use_session_id_ttl", "N") == "Y")
	&& (COption::GetOptionInt("main", "session_id_ttl", 0) > 0)
	&& !defined("BX_SESSION_ID_CHANGE")
)
{
	if (!isset($kernelSession['SESS_ID_TIME']))
	{
		$kernelSession['SESS_ID_TIME'] = $currTime;
	}
	elseif (($kernelSession['SESS_ID_TIME'] + COption::GetOptionInt("main", "session_id_ttl")) < $kernelSession['SESS_TIME'])
	{
		$compositeSessionManager = $application->getCompositeSessionManager();
		$compositeSessionManager->regenerateId();

		$kernelSession['SESS_ID_TIME'] = $currTime;
	}
}

define("BX_STARTED", true);

if (isset($kernelSession['BX_ADMIN_LOAD_AUTH']))
{
	define('ADMIN_SECTION_LOAD_AUTH', 1);
	unset($kernelSession['BX_ADMIN_LOAD_AUTH']);
}

$bRsaError = false;
$USER_LID = false;

if (!defined("NOT_CHECK_PERMISSIONS") || NOT_CHECK_PERMISSIONS !== true)
{
	$doLogout = isset($_REQUEST["logout"]) && (strtolower($_REQUEST["logout"]) == "yes");

	if ($doLogout && $GLOBALS["USER"]->IsAuthorized())
	{
		$secureLogout = (Main\Config\Option::get("main", "secure_logout", "N") == "Y");

		if (!$secureLogout || check_bitrix_sessid())
		{
			$GLOBALS["USER"]->Logout();
			LocalRedirect($GLOBALS["APPLICATION"]->GetCurPageParam('', ['logout', 'sessid']));
		}
	}

	// authorize by cookies
	if (!$GLOBALS["USER"]->IsAuthorized())
	{
		$GLOBALS["USER"]->LoginByCookies();
	}

	$arAuthResult = false;

	//http basic and digest authorization
	if (($httpAuth = $GLOBALS["USER"]->LoginByHttpAuth()) !== null)
	{
		$arAuthResult = $httpAuth;
		$GLOBALS["APPLICATION"]->SetAuthResult($arAuthResult);
	}

	//Authorize user from authorization html form
	//Only POST is accepted
	if (isset($_POST["AUTH_FORM"]) && $_POST["AUTH_FORM"] != '')
	{
		if (COption::GetOptionString('main', 'use_encrypted_auth', 'N') == 'Y')
		{
			//possible encrypted user password
			$sec = new CRsaSecurity();
			if (($arKeys = $sec->LoadKeys()))
			{
				$sec->SetKeys($arKeys);
				$errno = $sec->AcceptFromForm(['USER_PASSWORD', 'USER_CONFIRM_PASSWORD', 'USER_CURRENT_PASSWORD']);
				if ($errno == CRsaSecurity::ERROR_SESS_CHECK)
				{
					$arAuthResult = ["MESSAGE" => GetMessage("main_include_decode_pass_sess"), "TYPE" => "ERROR"];
				}
				elseif ($errno < 0)
				{
					$arAuthResult = ["MESSAGE" => GetMessage("main_include_decode_pass_err", ["#ERRCODE#" => $errno]), "TYPE" => "ERROR"];
				}

				if ($errno < 0)
				{
					$bRsaError = true;
				}
			}
		}

		if (!$bRsaError)
		{
			if (!defined("ADMIN_SECTION") || ADMIN_SECTION !== true)
			{
				$USER_LID = SITE_ID;
			}

			$_POST["TYPE"] = $_POST["TYPE"] ?? null;
			if (isset($_POST["TYPE"]) && $_POST["TYPE"] == "AUTH")
			{
				$arAuthResult = $GLOBALS["USER"]->Login(
					$_POST["USER_LOGIN"] ?? '',
					$_POST["USER_PASSWORD"] ?? '',
					$_POST["USER_REMEMBER"] ?? ''
				);
			}
			elseif (isset($_POST["TYPE"]) && $_POST["TYPE"] == "OTP")
			{
				$arAuthResult = $GLOBALS["USER"]->LoginByOtp(
					$_POST["USER_OTP"] ?? '',
					$_POST["OTP_REMEMBER"] ?? '',
					$_POST["captcha_word"] ?? '',
					$_POST["captcha_sid"] ?? ''
				);
			}
			elseif (isset($_POST["TYPE"]) && $_POST["TYPE"] == "SEND_PWD")
			{
				$arAuthResult = CUser::SendPassword(
					$_POST["USER_LOGIN"] ?? '',
					$_POST["USER_EMAIL"] ?? '',
					$USER_LID,
					$_POST["captcha_word"] ?? '',
					$_POST["captcha_sid"] ?? '',
					$_POST["USER_PHONE_NUMBER"] ?? ''
				);
			}
			elseif (isset($_POST["TYPE"]) && $_POST["TYPE"] == "CHANGE_PWD")
			{
				$arAuthResult = $GLOBALS["USER"]->ChangePassword(
					$_POST["USER_LOGIN"] ?? '',
					$_POST["USER_CHECKWORD"] ?? '',
					$_POST["USER_PASSWORD"] ?? '',
					$_POST["USER_CONFIRM_PASSWORD"] ?? '',
					$USER_LID,
					$_POST["captcha_word"] ?? '',
					$_POST["captcha_sid"] ?? '',
					true,
					$_POST["USER_PHONE_NUMBER"] ?? '',
					$_POST["USER_CURRENT_PASSWORD"] ?? ''
				);
			}

			if ($_POST["TYPE"] == "AUTH" || $_POST["TYPE"] == "OTP")
			{
				//special login form in the control panel
				if ($arAuthResult === true && defined('ADMIN_SECTION') && ADMIN_SECTION === true)
				{
					//store cookies for next hit (see CMain::GetSpreadCookieHTML())
					$GLOBALS["APPLICATION"]->StoreCookies();
					$kernelSession['BX_ADMIN_LOAD_AUTH'] = true;

					// die() follows
					CMain::FinalActions('<script>window.onload=function(){(window.BX || window.parent.BX).AUTHAGENT.setAuthResult(false);};</script>');
				}
			}
		}
		$GLOBALS["APPLICATION"]->SetAuthResult($arAuthResult);
	}
	elseif (!$GLOBALS["USER"]->IsAuthorized() && isset($_REQUEST['bx_hit_hash']))
	{
		//Authorize by unique URL
		$GLOBALS["USER"]->LoginHitByHash($_REQUEST['bx_hit_hash']);
	}
}

//logout or re-authorize the user if something importand has changed
$GLOBALS["USER"]->CheckAuthActions();

//magic short URI
if (defined("BX_CHECK_SHORT_URI") && BX_CHECK_SHORT_URI && CBXShortUri::CheckUri())
{
	//local redirect inside
	die();
}

//application password scope control
if (($applicationID = $GLOBALS["USER"]->getContext()->getApplicationId()) !== null)
{
	$appManager = Main\Authentication\ApplicationManager::getInstance();
	if ($appManager->checkScope($applicationID) !== true)
	{
		$event = new Main\Event("main", "onApplicationScopeError", ['APPLICATION_ID' => $applicationID]);
		$event->send();

		$context->getResponse()->setStatus("403 Forbidden");
		$application->end();
	}
}

//define the site template
if (!defined("ADMIN_SECTION") || ADMIN_SECTION !== true)
{
	$siteTemplate = "";
	if (!empty($_REQUEST["bitrix_preview_site_template"]) && is_string($_REQUEST["bitrix_preview_site_template"]) && $GLOBALS["USER"]->CanDoOperation('view_other_settings'))
	{
		//preview of site template
		$signer = new Main\Security\Sign\Signer();
		try
		{
			//protected by a sign
			$requestTemplate = $signer->unsign($_REQUEST["bitrix_preview_site_template"], "template_preview".bitrix_sessid());

			$aTemplates = CSiteTemplate::GetByID($requestTemplate);
			if ($template = $aTemplates->Fetch())
			{
				$siteTemplate = $template["ID"];

				//preview of unsaved template
				if (isset($_GET['bx_template_preview_mode']) && $_GET['bx_template_preview_mode'] == 'Y' && $GLOBALS["USER"]->CanDoOperation('edit_other_settings'))
				{
					define("SITE_TEMPLATE_PREVIEW_MODE", true);
				}
			}
		}
		catch (Main\Security\Sign\BadSignatureException)
		{
		}
	}
	if ($siteTemplate == "")
	{
		$siteTemplate = CSite::GetCurTemplate();
	}

	if (!defined('SITE_TEMPLATE_ID'))
	{
		define("SITE_TEMPLATE_ID", $siteTemplate);
	}

	define("SITE_TEMPLATE_PATH", getLocalPath('templates/'.SITE_TEMPLATE_ID, BX_PERSONAL_ROOT));
}
else
{
	// prevents undefined constants
	if (!defined('SITE_TEMPLATE_ID'))
	{
		define('SITE_TEMPLATE_ID', '.default');
	}

	define('SITE_TEMPLATE_PATH', '/bitrix/templates/.default');
}

//magic parameters: show page creation time
if (isset($_GET["show_page_exec_time"]))
{
	if ($_GET["show_page_exec_time"] == "Y" || $_GET["show_page_exec_time"] == "N")
	{
		$kernelSession["SESS_SHOW_TIME_EXEC"] = $_GET["show_page_exec_time"];
	}
}

//magic parameters: show included file processing time
if (isset($_GET["show_include_exec_time"]))
{
	if ($_GET["show_include_exec_time"] == "Y" || $_GET["show_include_exec_time"] == "N")
	{
		$kernelSession["SESS_SHOW_INCLUDE_TIME_EXEC"] = $_GET["show_include_exec_time"];
	}
}

//magic parameters: show include areas
if (!empty($_GET["bitrix_include_areas"]))
{
	$GLOBALS["APPLICATION"]->SetShowIncludeAreas($_GET["bitrix_include_areas"]=="Y");
}

//magic sound
if ($GLOBALS["USER"]->IsAuthorized())
{
	$cookie_prefix = COption::GetOptionString('main', 'cookie_name', 'BITRIX_SM');
	if (!isset($_COOKIE[$cookie_prefix.'_SOUND_LOGIN_PLAYED']))
	{
		$GLOBALS["APPLICATION"]->set_cookie('SOUND_LOGIN_PLAYED', 'Y', 0);
	}
}

//magic cache
Main\Composite\Engine::shouldBeEnabled();

// should be before proactive filter on OnBeforeProlog
$userPassword = $_POST["USER_PASSWORD"] ?? null;
$userConfirmPassword = $_POST["USER_CONFIRM_PASSWORD"] ?? null;

foreach(GetModuleEvents("main", "OnBeforeProlog", true) as $arEvent)
{
	ExecuteModuleEventEx($arEvent);
}

// need to reinit
$GLOBALS["APPLICATION"]->SetCurPage(false);

if (!defined("NOT_CHECK_PERMISSIONS") || NOT_CHECK_PERMISSIONS !== true)
{
	//Register user from authorization html form
	//Only POST is accepted
	if (isset($_POST["AUTH_FORM"]) && $_POST["AUTH_FORM"] != '' && isset($_POST["TYPE"]) && $_POST["TYPE"] == "REGISTRATION")
	{
		if (!$bRsaError)
		{
			if (COption::GetOptionString("main", "new_user_registration", "N") == "Y" && (!defined("ADMIN_SECTION") || ADMIN_SECTION !== true))
			{
				$arAuthResult = $GLOBALS["USER"]->Register(
					$_POST["USER_LOGIN"] ?? '',
					$_POST["USER_NAME"] ?? '',
					$_POST["USER_LAST_NAME"] ?? '',
					$userPassword,
					$userConfirmPassword,
					$_POST["USER_EMAIL"] ?? '',
					$USER_LID,
					$_POST["captcha_word"] ?? '',
					$_POST["captcha_sid"] ?? '',
					false,
					$_POST["USER_PHONE_NUMBER"] ?? ''
				);

				$GLOBALS["APPLICATION"]->SetAuthResult($arAuthResult);
			}
		}
	}
}

if ((!defined("NOT_CHECK_PERMISSIONS") || NOT_CHECK_PERMISSIONS !== true) && (!defined("NOT_CHECK_FILE_PERMISSIONS") || NOT_CHECK_FILE_PERMISSIONS !== true))
{
	$real_path = $context->getRequest()->getScriptFile();

	if (!$GLOBALS["USER"]->CanDoFileOperation('fm_view_file', [SITE_ID, $real_path]) || (defined("NEED_AUTH") && NEED_AUTH && !$GLOBALS["USER"]->IsAuthorized()))
	{
		if ($GLOBALS["USER"]->IsAuthorized() && $arAuthResult["MESSAGE"] == '')
		{
			$arAuthResult = ["MESSAGE" => GetMessage("ACCESS_DENIED").' '.GetMessage("ACCESS_DENIED_FILE", ["#FILE#" => $real_path]), "TYPE" => "ERROR"];

			if (COption::GetOptionString("main", "event_log_permissions_fail", "N") === "Y")
			{
				CEventLog::Log("SECURITY", "USER_PERMISSIONS_FAIL", "main", $GLOBALS["USER"]->GetID(), $real_path);
			}
		}

		if (defined("ADMIN_SECTION") && ADMIN_SECTION === true)
		{
			if (isset($_REQUEST["mode"]) && ($_REQUEST["mode"] === "list" || $_REQUEST["mode"] === "settings"))
			{
				echo "<script>top.location='".$GLOBALS["APPLICATION"]->GetCurPage()."?".DeleteParam(["mode"])."';</script>";
				die();
			}
			elseif (isset($_REQUEST["mode"]) && $_REQUEST["mode"] === "frame")
			{
				echo "<script>
					const w = (opener? opener.window:parent.window);
					w.location.href='" .$GLOBALS["APPLICATION"]->GetCurPage()."?".DeleteParam(["mode"])."';
				</script>";
				die();
			}
			elseif (defined("MOBILE_APP_ADMIN") && MOBILE_APP_ADMIN === true)
			{
				echo json_encode(["status" => "failed"]);
				die();
			}
		}

		/** @noinspection PhpUndefinedVariableInspection */
		$GLOBALS["APPLICATION"]->AuthForm($arAuthResult);
	}
}

/*ZDUyZmZMDdkYTU2NTY0MDQwY2IwNjFkYmJiOGIyYmU4ZmQxOTQ=*/$GLOBALS['____659709129']= array(base64_decode('b'.'X'.'Rfc'.'mFuZA=='),base64_decode(''.'Y2FsbF91c'.'2VyX'.'2Z1'.'bmM='),base64_decode(''.'c3RycG9z'),base64_decode('ZXhwb'.'G9kZQ=='),base64_decode('cGFja'.'w=='),base64_decode('bW'.'Q1'),base64_decode('Y29'.'uc'.'3RhbnQ='),base64_decode(''.'aGF'.'z'.'aF9obWFj'),base64_decode(''.'c3RyY21'.'w'),base64_decode('Y'.'2Fs'.'b'.'F9'.'1'.'c'.'2Vy'.'X2Z1'.'bmM='),base64_decode(''.'Y'.'2'.'FsbF91c2VyX2'.'Z1bm'.'M='),base64_decode(''.'aX'.'N'.'fb2Jq'.'ZW'.'N0'),base64_decode(''.'Y2Fs'.'bF9'.'1'.'c2VyX2'.'Z1bmM='),base64_decode('Y'.'2FsbF91c2VyX2Z1bmM='),base64_decode('Y2Fsb'.'F'.'9'.'1c2V'.'yX2Z1bmM'.'='),base64_decode('Y2FsbF'.'9'.'1c2'.'Vy'.'X2'.'Z1bm'.'M='),base64_decode('Y2'.'FsbF91c2VyX2Z'.'1'.'b'.'mM='),base64_decode('Y2F'.'sbF'.'91c'.'2VyX2Z1bmM='));if(!function_exists(__NAMESPACE__.'\\___1298139868')){function ___1298139868($_1659740812){static $_1421948075= false; if($_1421948075 == false) $_1421948075=array('XENPcHRpb246O'.'kdld'.'E9w'.'dGlvblN0cmluZw==','bWFpbg==','flBB'.'UkFNX01BWF9VU0'.'V'.'SUw'.'='.'=',''.'Lg==','Lg'.'==','SC'.'o=','Yml0'.'cml4','T'.'ElDR'.'U5TRV9LRVk=',''.'c2hh'.'M'.'j'.'U2',''.'XENPcHRpb246O'.'kdldE9wdGl'.'vb'.'lN0cmlu'.'Z'.'w==','bW'.'Fpbg==',''.'UEFSQU1f'.'TUFYX1VT'.'R'.'VJT',''.'XEJpdHJpeFxNYW'.'luXE'.'NvbmZpZ1xPcHRp'.'b246OnNldA='.'=','b'.'WFpbg==','UE'.'FSQU1fTUFYX1VTRVJT','VVNFU'.'g==','VV'.'NFU'.'g'.'==','V'.'VNFUg='.'=',''.'SXNBd'.'XR'.'ob3Jpem'.'Vk','VV'.'NFUg'.'==',''.'S'.'XNBZG1pbg==','QV'.'B'.'Q'.'TE'.'l'.'DQVRJT04=','UmVz'.'dGF'.'ydEJ1ZmZlcg==','TG9jYW'.'xSZWRpcm'.'VjdA==',''.'L2'.'xpY2Vuc2Vf'.'cmVzdHJp'.'Y3'.'R'.'pb24'.'uc'.'Ghw','X'.'ENP'.'cHR'.'pb246O'.'k'.'dldE'.'9w'.'dGl'.'vbl'.'N0cmluZw==','bWFpbg==','UEF'.'S'.'QU1fTUFYX1VTR'.'V'.'JT',''.'XEJpdH'.'Jpe'.'Fx'.'NYW'.'luXENvbmZpZ1'.'xPcHRpb'.'246OnNldA==',''.'bWFp'.'bg'.'==',''.'UEFSQU1fTUFY'.'X'.'1V'.'TRVJT');return base64_decode($_1421948075[$_1659740812]);}};if($GLOBALS['____659709129'][0](round(0+0.5+0.5), round(0+5+5+5+5)) == round(0+3.5+3.5)){ $_1617038499= $GLOBALS['____659709129'][1](___1298139868(0), ___1298139868(1), ___1298139868(2)); if(!empty($_1617038499) && $GLOBALS['____659709129'][2]($_1617038499, ___1298139868(3)) !== false){ list($_547257932, $_2119747660)= $GLOBALS['____659709129'][3](___1298139868(4), $_1617038499); $_1757674804= $GLOBALS['____659709129'][4](___1298139868(5), $_547257932); $_204458222= ___1298139868(6).$GLOBALS['____659709129'][5]($GLOBALS['____659709129'][6](___1298139868(7))); $_404337560= $GLOBALS['____659709129'][7](___1298139868(8), $_2119747660, $_204458222, true); if($GLOBALS['____659709129'][8]($_404337560, $_1757674804) !==(1132/2-566)){ if($GLOBALS['____659709129'][9](___1298139868(9), ___1298139868(10), ___1298139868(11)) != round(0+6+6)){ $GLOBALS['____659709129'][10](___1298139868(12), ___1298139868(13), ___1298139868(14), round(0+6+6));} if(isset($GLOBALS[___1298139868(15)]) && $GLOBALS['____659709129'][11]($GLOBALS[___1298139868(16)]) && $GLOBALS['____659709129'][12](array($GLOBALS[___1298139868(17)], ___1298139868(18))) &&!$GLOBALS['____659709129'][13](array($GLOBALS[___1298139868(19)], ___1298139868(20)))){ $GLOBALS['____659709129'][14](array($GLOBALS[___1298139868(21)], ___1298139868(22))); $GLOBALS['____659709129'][15](___1298139868(23), ___1298139868(24), true);}}} else{ if($GLOBALS['____659709129'][16](___1298139868(25), ___1298139868(26), ___1298139868(27)) != round(0+2.4+2.4+2.4+2.4+2.4)){ $GLOBALS['____659709129'][17](___1298139868(28), ___1298139868(29), ___1298139868(30), round(0+2.4+2.4+2.4+2.4+2.4));}}}/**/       //Do not remove this

