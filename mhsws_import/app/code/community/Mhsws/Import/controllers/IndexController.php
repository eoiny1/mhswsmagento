<?php

class Mhsws_Import_IndexController extends Mage_Core_Controller_Front_Action
{
    const CACHE_LIMIT = 50;
    const CONFIGURABLE_LIMIT = 5;

    public function update_qtyAction() {
        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
        $iteration = (int)$this->getRequest()->getParam('iteration');
        $cursor = $this->getRequest()->getParam('cursor');
        $connector = Mage::getModel('import/connector');
        if (!empty($cursor)) {
            $connector->cursor = $cursor;
        }

        $cache_data = $connector->downloadCache($connector->context, self::CACHE_LIMIT, TRUE);

        if ((isset($cache_data['length']) && !$cache_data['length']) && $cache_data['waiting'] != 1) {
            $cache_data['log'][] = 'cataloginventory_stock';
            $process = Mage::getSingleton('index/indexer')->getProcessByCode('cataloginventory_stock');

            $cache_data['log'][] = '----------[Stock Update -END-]----------';
        } elseif($cache_data['waiting']) {
            $cache_data['log'][] = '[StockUpdate] waiting...';
        } else {
            foreach ($cache_data['batch'] as $batch) {
                $cache_data['log'][] = $batch->uid . ' [qty=' . $batch->qty . ']';
                $connector->updStock($batch->uid, $batch->qty);
            }
        }

        die(json_encode($cache_data));
    }

	public function product_listAction() {
        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
        $iteration = (int)$this->getRequest()->getParam('iteration');
        $cursor = $this->getRequest()->getParam('cursor');
        $connector = Mage::getModel('import/connector');
        $processes = Mage::getSingleton('index/indexer')->getProcessesCollection();
        if (empty($cursor)) {
            //set indexes to manual mode while importing the products
            $processes->walk('setMode', array(Mage_Index_Model_Process::MODE_MANUAL));
            $processes->walk('save');

            $connector->removeTmpFiles(Mage::getBaseDir('var') . '/mhsws_export/');
        } else {
            $connector->cursor = $cursor;
        }

        $cache_data = $connector->downloadCache($connector->context, self::CACHE_LIMIT);

        die(json_encode($cache_data));
	}

    public function configurable_productAction() {
        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
        $connector = Mage::getModel('import/connector');
        $import_data = $connector->importConfigurableProducts(self::CONFIGURABLE_LIMIT);

        if (!$import_data['lenght']) {
            $import_data['log'][] = 'Hide not available products...';
            $connector->hideNotAvailableProducts();

            $processes = Mage::getSingleton('index/indexer')->getProcessesCollection();
            //set indexes to REAL_TIME while after importing the products
            $processes->walk('setMode', array(Mage_Index_Model_Process::MODE_REAL_TIME));
            $processes->walk('save');

            $import_data['log'][] = 'reindexAll...';
            $processes->walk('reindexAll');
            $import_data['log'][] = 'reindexEverything...';
            $processes->walk('reindexEverything');

            if (Mage::getStoreConfig('catalog/frontend/flat_catalog_category')) {
                $process = Mage::getSingleton('index/indexer')->getProcessByCode('catalog_product_flat');
            }

            Mage::Log('----------[END]----------', NULL, 'mhsws_connector.log');
            $cache_data['log'][] = '----------[END]----------';
        }

        die(json_encode($import_data));
    }

}
