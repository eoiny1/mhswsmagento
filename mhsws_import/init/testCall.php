<?php

error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
require_once(dirname(__FILE__) . '/../app/Mage.php');
Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));


print_r(chr(10) . chr(10));
Mage::Log('----------[BEGIN]----------', NULL, 'mhsws_connector.log');

$cron = new Belvg_Import_Model_Cron();
//$cron->productsUpdate();
$cron->stockUpdate();


Mage::Log('----------[END]----------', NULL, 'mhsws_connector.log');
die('-------- testing Cron [end]' . chr(10));
