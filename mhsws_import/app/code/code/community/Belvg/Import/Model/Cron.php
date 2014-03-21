<?php

class Belvg_Import_Model_Cron
{
    public function productsUpdate()
    {
        $_cacheLimit = 5;
print_r('productsUpdate' . chr(10));
        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
        $connector = Mage::getModel('import/connector');
        $processes = Mage::getSingleton('index/indexer')->getProcessesCollection();

        //set indexes to manual mode while importing the products
        $processes->walk('setMode', array(Mage_Index_Model_Process::MODE_MANUAL));
        $processes->walk('save');
        $connector->removeTmpFiles(Mage::getBaseDir('var') . '/mhsws_export/');

$tmp_i = 0;
        for (;;) {
print_r('run iteration #' . $tmp_i . chr(10));
            $connector->log = array();
            $cache_data = $connector->downloadCache($connector->context, $_cacheLimit);
            //$cache_data = $connector->downloadFakeCache($tmp_i);
            if ($cache_data['length'] == 0 && $cache_data['waiting'] == 0) {
                break;
            } elseif($cache_data['waiting']) {
print_r('[ProductsUpdate] waiting...' . chr(10));
print_r($cache_data);
                continue;
            }
print_r($cache_data);
print_r(chr(10) . '-----------------------------------' . chr(10));
            if (empty($connector->cursor)) {
                $connector->cursor = $cache_data['cursor'];
            }

if ($tmp_i++ > 2) {
   //break;
}
        }

print_r('for is break' . chr(10));

        $processes = Mage::getSingleton('index/indexer')->getProcessesCollection();
        $processes->walk('setMode', array(Mage_Index_Model_Process::MODE_MANUAL));
        $processes->walk('save');

        //Import for configurable products
        $i = 0;
        $_configurableLimit = 5;
        for (;;) {
print_r('configurable iteration #' . $i++ . chr(10));
            $connector->log = array();
            $import_data = $connector->importConfigurableProducts($_configurableLimit);
print_r($import_data);
print_r(chr(10));
            if ($import_data['lenght'] == 0) {
print_r('configurable for is break' . chr(10));
                break;
            }
        }


        $connector->log[] = 'Hide not available products...';
        $connector->hideNotAvailableProducts();

        //set indexes to REAL_TIME while after importing the products
        $processes->walk('setMode', array(Mage_Index_Model_Process::MODE_REAL_TIME));
        $processes->walk('save');

        Mage::Log('reindexAll...', NULL, 'mhsws_connector.log'); print_r('reindexAll...' . chr(10));
        $processes->walk('reindexAll');
        Mage::Log('reindexEverything...', NULL, 'mhsws_connector.log'); print_r('reindexEverything...' . chr(10));
        $processes->walk('reindexEverything');

        /*if (Mage::getStoreConfig('catalog/frontend/flat_catalog_category')) {
            $process = Mage::getSingleton('index/indexer')->getProcessByCode('catalog_product_flat');
        }*/

print_r($connector->log);
print_r(chr(10) . '----------[END]----------' . chr(10));
        Mage::Log('----------[END]----------', NULL, 'mhsws_connector.log');
    }

    public function stockUpdate()
    {
        Mage::Log('stockUpdate', NULL, 'MHSWSConnector_stockUpdate.log');
        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
        $connector = Mage::getModel('import/connector');
        $processes = Mage::getSingleton('index/indexer')->getProcessesCollection();
        $_cacheLimit = 5;

        $tmp_i = 0;
        for (;;) {
            print_r('run qty iteration #' . $tmp_i . chr(10));
            $cache_data = $connector->downloadCache($connector->context, $_cacheLimit, TRUE);

            if ((isset($cache_data['length']) && !$cache_data['length']) && $cache_data['waiting'] != 1) {
                break;
            } elseif($cache_data['waiting']) {
                print_r('[StockUpdate] waiting...' . chr(10));
                continue;
            } else {
                $tmp_i++;
                foreach ($cache_data['batch'] as $batch) {
                    print_r($batch->uid . chr(10));
                    $connector->updStock($batch->uid, $batch->qty);
                }

                print_r($connector->log);
                $connector->log = array();
            }
        }

        print_r('cataloginventory_stock' . chr(10));
        $process = Mage::getSingleton('index/indexer')->getProcessByCode('cataloginventory_stock');

        print_r($connector->log);
        print_r(chr(10) . '----------[END]----------' . chr(10));
        Mage::Log('----------[END]----------', NULL, 'mhsws_connector.log');
    }

    public function cronTest()
    {
        Mage::Log('Cron enabled', NULL, 'MHSWSConnector_TestCron.log');
    }
}