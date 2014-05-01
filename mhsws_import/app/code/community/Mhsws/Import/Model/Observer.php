<?php

class Mhsws_Import_Model_Observer {

    const UPDATE_STOCK_URL = "https://mhswsgen2rmsstackapi.appspot.com/checkout";

    /**
    * Mage::dispatchEvent($this->_eventPrefix.'_save_after', $this->_getEventData());
    * protected $_eventPrefix = 'sales_order_invoice';
    * protected $_eventObject = 'invoice'; //Mage_Sales_Model_Order_Invoice
    * event: sales_order_invoice_save_after
    */
    public function sendStockInfo($observer) {
        $invoice = $observer->getEvent()->getInvoice();
        $connector = Mage::getModel('import/connector');
        $items = array();
        foreach ($invoice->getOrder()->getItemsCollection() as $item) {
            $product = Mage::getModel('catalog/product')->load($item->getProductId());
            $mhsws_uid = $product->getMhswsUid();
            if ($mhsws_uid) {
                $items[] = array($mhsws_uid => $item->getQtyInvoiced());
            }
        }

        if (count($items)) {
            $url_params = array(
                'keyword' => $connector->keyword,
                'client_identifier' => $connector->access_identifier,
                'reference' => urlencode($invoice->getIncrementId()),
                'items' => json_encode($items),
                'signature' => md5($connector->secret_key . $connector->keyword),
            );

            $url = self::UPDATE_STOCK_URL . '?' . http_build_query($url_params);
            $result = $connector->runRequest($url);
            Mage::Log(print_r($url_params, TRUE), NULL, 'mhsws_invoice.log');
            Mage::Log($url, NULL, 'mhsws_invoice.log');
        }

        return $this;
    }
}
