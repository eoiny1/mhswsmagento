<?php

class Mhsws_Import_Model_Cron
{
    public function productsUpdate()
    {
        $this->sendEmail('productsUpdate');
        $_cacheLimit = 5;

        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
        $connector = Mage::getModel('import/connector');
        $processes = Mage::getSingleton('index/indexer')->getProcessesCollection();

        //set indexes to manual mode while importing the products
        $processes->walk('setMode', array(Mage_Index_Model_Process::MODE_MANUAL));
        $processes->walk('save');
        $connector->removeTmpFiles(Mage::getBaseDir('var') . '/mhsws_export/');

        for (;;) {
            $connector->log = array();
            $cache_data = $connector->downloadCache($connector->context, $_cacheLimit);
            //$cache_data = $connector->downloadFakeCache($tmp_i);
            if ($cache_data['length'] == 0 && $cache_data['waiting'] == 0) {
                break;
            } elseif($cache_data['waiting']) {
                continue;
            }

            if (empty($connector->cursor)) {
                $connector->cursor = $cache_data['cursor'];
            }
        }

        $processes = Mage::getSingleton('index/indexer')->getProcessesCollection();
        $processes->walk('setMode', array(Mage_Index_Model_Process::MODE_MANUAL));
        $processes->walk('save');

        //Import for configurable products
        $i = 0;
        $_configurableLimit = 5;
        for (;;) {
            $connector->log = array();
            $import_data = $connector->importConfigurableProducts($_configurableLimit);

            if ($import_data['lenght'] == 0) {
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

        Mage::Log('----------[END]----------', NULL, 'mhsws_connector.log');
    }

    public function stockUpdate()
    {
        $this->sendEmail('stockUpdate');
        Mage::Log('stockUpdate', NULL, 'MHSWSConnector_stockUpdate.log');
        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
        $connector = Mage::getModel('import/connector');
        $processes = Mage::getSingleton('index/indexer')->getProcessesCollection();
        $_cacheLimit = 5;

        for (;;) {
            $cache_data = $connector->downloadCache($connector->context, $_cacheLimit, TRUE);

            if ((isset($cache_data['length']) && !$cache_data['length']) && $cache_data['waiting'] != 1) {
                break;
            } elseif($cache_data['waiting']) {
                continue;
            } else {
                foreach ($cache_data['batch'] as $batch) {
                    $connector->updStock($batch->uid, $batch->qty);
                }

                $connector->log = array();
            }
        }

        $process = Mage::getSingleton('index/indexer')->getProcessByCode('cataloginventory_stock');

        Mage::Log('----------[END]----------', NULL, 'mhsws_connector.log');
    }

    protected function sendEmail($type)
    {
        try {
            $storeId = Mage::app()->getStore()->getId();
            $translate = Mage::getSingleton('core/translate');
            $recipient_email = Mage::getStoreConfig('import/settings/cron_email');
            $recipient_name = Mage::getStoreConfig('trans_email/ident_general/name');
            $templateId = 'test_mhsws_email_template';
            $sender = array(
                'name' => Mage::getStoreConfig('trans_email/ident_general/name'),
                'email' => Mage::getStoreConfig('trans_email/ident_general/email')
            );
            $vars = array(
                'type' => $type,
            );

            if ($recipient_email) {
                Mage::getModel('core/email_template')
                    ->sendTransactional($templateId, $sender, $recipient_email, $recipient_name, $vars, $storeId);
                $translate->setTranslateInline(TRUE);
            }
        } catch (Exception $e) {
            Mage::Log($e->getMessage(), NULL, 'MHSWSConnector_TestCron.log');
        }
    }

    public function cronTest()
    {
        Mage::Log('Cron enabled', NULL, 'MHSWSConnector_TestCron.log');
    }
}
