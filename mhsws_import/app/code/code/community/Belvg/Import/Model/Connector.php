<?php

class Belvg_Import_Model_Connector extends Mage_Core_Model_Abstract
{
    const BUILD_CACHE_URL = "https://mhswsgen2rmsstackapi.appspot.com/cache/build";
    const DOWNLOAD_PRODUCT_URL = "https://mhswsgen2rmsstackapi.appspot.com/cache/downloadproduct";
    const DATE_FORMAT = "Y-m-d H:i:s";
    const CONTEXT_LIVETIME = 10800; //in seconds = 3 hour {moved to module config}

    public $keyword = ''; //ilovetoronto
    public $access_identifier = ''; //d114301e8b56aac9791bdad9adc9dece
    public $secret_key = ''; //bfdef1b9ba9bf0236ebbd496bddceade
    public $context;
    public $cursor;

    public $log = array();

    public function __construct() {
        $this->access_identifier = Mage::getStoreConfig('import/settings/access_identifier');
        $this->secret_key = Mage::getStoreConfig('import/settings/secret_key');
        $this->keyword = Mage::getStoreConfig('import/settings/keyword');

        if (Mage::getStoreConfig('mhsws/context/value')) {
            $now = strtotime(date(self::DATE_FORMAT));
            $context_time = strtotime(Mage::getStoreConfig('mhsws/context/time'));
            if (Mage::getStoreConfig('import/settings/context_livetime') < ($now - $context_time)) {
                $this->log[] = 'Build new context...';
                $this->context = $this->buildCache();
            } else {
                $this->log[] = 'Load saved context... [' . Mage::getStoreConfig('mhsws/context/value') . ']';
                //load saved context
                $this->context = Mage::getStoreConfig('mhsws/context/value');
            }
        } else {
            //generate new context
            $this->context = $this->buildCache();
        }
    }

    public function buildCache() {
        $url_params = array(
            'keyword' => $this->keyword,
            'client_identifier' => $this->access_identifier,
            'signature' => md5($this->secret_key . $this->keyword),
        );

        $url = self::BUILD_CACHE_URL . '?' . http_build_query($url_params);
        $result = $this->runRequest($url);

        if (isset($result->context)) {
            Mage::getModel('core/config')->saveConfig('mhsws/context/value', $result->context);
            Mage::getModel('core/config')->saveConfig('mhsws/context/time', date(self::DATE_FORMAT));

            return $result->context;
        } else {
            return NULL;
        }
    }

    public function downloadCache($context, $limit = 128, $qtyonly = FALSE) {
        if (empty($context)) {
            Mage::Log('context is empty', NULL, 'mhsws_connector.log');
            $this->log[] = 'context is empty';
            return array(
                'error' => 1,
                'log' => $this->log,
                'cursor' => $this->cursor,
                'length' => 0,
            );
        }

        $url_params = array(
            'keyword' => $this->keyword,
            'client_identifier' => $this->access_identifier,
            'limit' => $limit,
            'context' => $context,
            'signature' => md5($this->secret_key . $this->keyword),
        );
        if ($qtyonly) {
            $url_params['qtyonly'] = 'true';
        }

        //for (;;) {
            if (isset($this->cursor) && !empty($this->cursor)) {
                $url_params['cursor'] = $this->cursor;
            }

            $url = self::DOWNLOAD_PRODUCT_URL . '?' . http_build_query($url_params);
            $result = $this->runRequest($url);

            if (!is_object($result)) {
                $this->buildCache();
                Mage::Log('---FORCE NEW CACHE---', NULL, 'mhsws_connector.log');
            }

            if (!$result->success || (!$qtyonly && !$this->__checkBatch($result))) {
                $this->log[] = 'Waiting ...';
                Mage::Log('Waiting ...', NULL, 'mhsws_connector.log');

                sleep(1);
                return array(
                    'error' => 0,
                    'waiting' => 1,
                    'log' => $this->log,
                    'cursor' => $this->cursor,
                    'length' => 0,
                );
            }

            $batch = $result->batch;
            $length = count($result->batch);

            if ($length == 0) {
                $this->log[] = 'Nothing more; exit!';
                Mage::Log('Nothing more; exit!', NULL, 'mhsws_connector.log');
                return array(
                    'error' => 0,
                    'waiting' => 0,
                    'log' => $this->log,
                    'cursor' => $this->cursor,
                    'length' => 0,
                );
            }

            if (!$qtyonly) {
                for ($j = 0; $j < $length; $j++) {
                    $this->log[] = $j;
                    $this->log[] = '<pre>' . print_r($batch[$j], TRUE) . '</pre>';
                    $cat_ids = $this->saveCategory($batch[$j]);
                    $this->saveProduct($batch[$j], $cat_ids);
                }
            }

            $this->cursor = isset($result->cursor) ? $result->cursor : NULL;

            return array(
                'error' => 0,
                'waiting' => 0,
                'log' => $this->log,
                'cursor' => $this->cursor,
                'length' => $length,
                'batch' => $batch,
            );
        //}

    }

    protected function __checkBatch($result) {
        if ($result->success) {
            return TRUE;
        }

        if (count($result->batch)) {
            if (isset($result->batch[0]->plu_sku)) {
                return TRUE;
            }

            $this->context = $this->buildCache();
        }

        return FALSE;
    }

    public function runRequest($url) {
        Mage::Log('FETCHING: ' . $url, NULL, 'mhsws_fetch.log');
        $ch = curl_init();
        // set url
        curl_setopt($ch, CURLOPT_URL, $url);
        //return the transfer as a string
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        // $output contains the output string
        $json_output = curl_exec($ch);
        $output = json_decode($json_output);
        Mage::Log('FETCHED: ' . $json_output, NULL, 'mhsws_fetch.log');
        // close curl resource to free up system resources
        curl_close($ch);

        $this->log[] = $url;

        Mage::Log('runRequest [' . $url . '] :', NULL, 'mhsws_connector.log');
        Mage::Log($output, NULL, 'mhsws_connector.log');

        return $output;
    }

    public function saveCategory($product) {
        if (isset($product->department) && !empty($product->department)) {
            $department = Mage::getModel('catalog/category')->loadByAttribute('mhsws_alias', $product->department);
            if (is_object($department)) {
                $parent_id = $department->getId();
            } else {
                $department = $this->createCategory($product->department, Mage::getModel('catalog/category')->setPath('1/2'));
                $parent_id = $department->getId();
            }
        }

        if ( (isset($product->department) && !empty($product->department)) && (isset($product->category) && !empty($product->category)) ) {
            $category = Mage::getModel('catalog/category')->loadByAttribute('mhsws_alias', $product->category);
            if (is_object($category)) {
                $category_id = $category->getId();
            } else {
                $category = $this->createCategory($product->category, $department);
                $category_id = $category->getId();
            }
        }

        if (isset($parent_id) && isset($category_id)) {
            return array($parent_id, $category_id);
        } elseif (isset($parent_id)) {
            return array($parent_id);
        } else {
            return array();
        }
    }

    public function createCategory($name, $parent_category) {
        $general['name'] = $name;
        $general['path'] = $parent_category->getPath();
        $general['url_key'] = $this->escapeString($name);
        $general['meta_title'] = $name;
        $general['meta_keywords'] = $name;
        $general['display_mode'] = 'PRODUCTS_AND_PAGE'; //static block and the products are shown on the page
        $general['is_active'] = 1;
        $general['is_anchor'] = 1;
        $general['mhsws_alias'] = $name;

        $category = Mage::getModel('catalog/category');
        $category->setStoreId(0); // 0 = default/all store view. If you want to save data for a specific store view, replace 0 by Mage::app()->getStore()->getId().
        $category->addData($general);

        $category->save();
        $this->log[] = 'Save category [' . $name . '] #' . $category->getId();

        return $category;
    }

    public function updStock($uid, $newQty) {
        $sProduct = Mage::getModel('catalog/product')->loadByAttribute('mhsws_uid', $uid);
        if (is_object($sProduct) && $sProduct->getId()) {
            $this->log[] = 'updStock [' . $sProduct->getId() . ']';
            $connection = Mage::getSingleton('core/resource')->getConnection('core_write');

            $sql = "UPDATE " . Mage::getSingleton('core/resource')->getTableName('cataloginventory_stock_item') . " csi,
                       " . Mage::getSingleton('core/resource')->getTableName('cataloginventory_stock_status') . " css
                       SET
                       csi.qty = ?,
                       csi.is_in_stock = ?,
                       css.qty = ?,
                       css.stock_status = ?
                       WHERE
                       csi.product_id = ?
                       AND csi.product_id = css.product_id";
            $isInStock      = $newQty > 0 ? 1 : 0;
            $stockStatus    = $newQty > 0 ? 1 : 0;
            $connection->query($sql, array($newQty, $isInStock, $newQty, $stockStatus, $sProduct->getId()));
        }
    }

    public function saveProduct($product, $cat_ids = array(2)) {
        if (empty($cat_ids)) {
            $cat_ids = array(2);
        }

        if (!isset($product->plu_sku)) {
            $this->log[] = 'Something went wrong... We can not save product with empty `plu_sku` param';
            return FALSE;
        }

        $sProduct = Mage::getModel('catalog/product')->loadByAttribute('sku', $product->plu_sku);

        if (!is_object($sProduct)) {
            $sProduct = Mage::getModel('catalog/product');
            $sProduct
                ->setUrlKey($this->escapeString($product->name . '-' . $product->plu_sku))
                ->setTypeId(Mage_Catalog_Model_Product_Type::TYPE_SIMPLE)
                ->setWebsiteIds(array(1)) //Mage::app()->getWebsite()->getId()
                ->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH)
                ->setTaxClassId(0)
                ->setAttributeSetId(4)
                ->setCategoryIds($cat_ids)
                ->setShortDescription(isset($product->style) ? $product->style : '')
                ->setWeight(0);

            //$brandModel = Mage::getModel('eav/entity_attribute')->loadByCode('catalog_product', 'mhsws_brand');

            $stockData = array();
            $stockData['qty'] = $product->qty;
            $stockData['manage_stock'] = 1;
            $stockData['is_in_stock'] = 1;
            $stockData['use_config_manage_stock'] = 1;
            $sProduct->setStockData($stockData);

        } else {
            $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($sProduct->getId());
            $stock->setData('is_in_stock', ((int)$product->qty > 0 ? 1 : 0));
            $stock->setData('qty', (int)$product->qty);
            $stock->setData('manage_stock', 1);
            $stock->setData('use_config_notify_stock_qty', 1);

            $stock->save();
        }

        $sProduct
            ->setStatus(Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
            ->setMhswsUid(isset($product->uid) ? $product->uid : '')
            ->setSku($product->plu_sku)
            ->setMhswsDepartment(isset($product->department) ? $product->department : '')
            ->setMhswsCost(isset($product->cost) ? $product->cost : '')
            //->setMhswsSize2($product->size2)
            //->setMhswsSize($product->size)
            ->setMhswsCategory(isset($product->category) ? $product->category : '')
            ->setMhswsStyle(isset($product->style) ? $product->style : '')
            //->setColor($product->color)
            ->setName(isset($product->name) ? $product->name : '-')
            ->setMsrp(isset($product->retail_price) ? $product->retail_price : 0)
            ->setPrice(isset($product->price) ? $product->price : 0);

        if ($product->price < $product->retail_price) {
            $sProduct->setSpecialPrice($product->price);
        }

        //SIZE
        if (isset($product->size) && $product->size) {
            $sizeOptionId = $this->getAttributeOptionValue("mhsws_size", $product->size);
            if (!$sizeOptionId) {
                $sizeOptionId = $this->addAttributeOption("mhsws_size", $product->size);
            }

            $sProduct->setMhswsSize($sizeOptionId);
        }

        //SIZE2
        if (isset($product->size2) && $product->size2) {
            $size2OptionId = $this->getAttributeOptionValue("mhsws_size2", $product->size2);
            if (!$size2OptionId) {
                $size2OptionId = $this->addAttributeOption("mhsws_size2", $product->size2);
            }

            $sProduct->setMhswsSize2($size2OptionId);
        }

        //COLOR
        if (isset($product->color) && $product->color) {
            $colorOptionId = $this->getAttributeOptionValue("mhsws_color", $product->color);
            if (!$colorOptionId) {
                $colorOptionId = $this->addAttributeOption("mhsws_color", $product->color);
            }

            $sProduct->setMhswsColor($colorOptionId);
        }

        //BRAND
        if (isset($product->brand) && $product->brand) {
            $brandOptionId = $this->getAttributeOptionValue("mhsws_brand", $product->brand);
            if (!$brandOptionId) {
                $brandOptionId = $this->addAttributeOption("mhsws_brand", $product->brand);
            }

            $sProduct->setMhswsBrand($brandOptionId);
        }

        $sProduct->save();
        $this->saveLog('__' . $this->context, Mage::getBaseDir('var') . '/mhsws_export/', $sProduct->getId()); //needed for update product after import

        if (isset($product->style) && $product->style) {
            $this->saveLog($product->style, Mage::getBaseDir('var') . '/mhsws_export/', $sProduct->getId());
        }

        $this->log[] = 'Product saved: ' . $product->uid . ' [id=' . $sProduct->getId() . ']';
    }

    public function getAttributeOptionValue($arg_attribute, $arg_value) {
        $attribute_model = Mage::getModel('eav/entity_attribute');
        $attribute_options_model= Mage::getModel('eav/entity_attribute_source_table') ;
        $attribute_code = $attribute_model->getIdByCode('catalog_product', $arg_attribute);
        $attribute = $attribute_model->load($attribute_code);
        $attribute_table = $attribute_options_model->setAttribute($attribute);
        $options = $attribute_options_model->getAllOptions(false);
        foreach ($options as $option) {
            if ($option['label'] == $arg_value) {
                return $option['value'];
            }
        }

        return FALSE;
    }

    public function addAttributeOption($arg_attribute, $arg_value) {
        $attribute_model = Mage::getModel('eav/entity_attribute');
        $attribute_options_model= Mage::getModel('eav/entity_attribute_source_table') ;
        $attribute_code = $attribute_model->getIdByCode('catalog_product', $arg_attribute);
        $attribute = $attribute_model->load($attribute_code);
        $attribute_table = $attribute_options_model->setAttribute($attribute);
        $options = $attribute_options_model->getAllOptions(false);
        $value['option'] = array($arg_value, $arg_value);
        $result = array('value' => $value);
        $attribute->setData('option', $result);
        $attribute->save();

        return $this->getAttributeOptionValue($arg_attribute, $arg_value);
    }

    protected function __getConfigurableProduct($sku, $prod_id) {
        $sProduct = Mage::getModel('catalog/product')->loadByAttribute('sku', $sku);

        if (is_object($sProduct) && $sProduct->getTypeID() != Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {
            $sku .= '-conf';
            $sProduct = Mage::getModel('catalog/product')->loadByAttribute('sku', $sku);
        }

        $sProductSimple = Mage::getModel('catalog/product')->load($prod_id);
        $_attributeIds = array();
        if ($sProductSimple->getMhswsSize()) {
            $tmpAttribute = Mage::getSingleton('eav/config')->getAttribute('catalog_product', 'mhsws_size', 'attribute_id');
            $_attributeIds['mhsws_size'] = $tmpAttribute->getId();
        }
        if ($sProductSimple->getMhswsColor()) {
            $tmpAttribute = Mage::getSingleton('eav/config')->getAttribute('catalog_product', 'mhsws_color', 'attribute_id');
            $_attributeIds['mhsws_color'] = $tmpAttribute->getId();
        }
        if ($sProductSimple->getMhswsSize2()) {
            $tmpAttribute = Mage::getSingleton('eav/config')->getAttribute('catalog_product', 'mhsws_size2', 'attribute_id');
            $_attributeIds['mhsws_size2'] =  $tmpAttribute->getId();
        }

        $is_new = empty($sProduct) ? 1 : 0;
        if ($is_new) {
            $sProduct = Mage::getModel('catalog/product');
            $sProduct
                ->setTypeId(Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE)
                ->setWebsiteIds(array(1)) //Mage::app()->getWebsite()->getId()
                ->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH)
                ->setTaxClassId(0)
                ->setAttributeSetId(4)
                ->setCategoryIds($sProductSimple->getCategoryIds())
                ->setShortDescription($sProductSimple->style)
                ->setWeight(0)
                ->setMhswsUid($sProductSimple->getUid())
                ->setMhswsDepartment($sProductSimple->getDepartment())
                ->setMhswsCost($sProductSimple->getCost())
                ->setMhswsCategory($sProductSimple->getCategory())
                ->setMhswsStyle($sProductSimple->getStyle())
                ->setName($sProductSimple->getName())
                ->setUrlKey($this->escapeString($sProductSimple->getName() . '-' . $sku))
                ->setMsrp($sProductSimple->getMsrp())
                ->setPrice($sProductSimple->getPrice());
        }

        $sProduct
            ->setStatus(Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
            ->setSku($sku)
            ->setCategoryIds($sProductSimple->getCategoryIds());

        $sProduct->setCanSaveConfigurableAttributes(TRUE);
        $sProduct->setCanSaveCustomOptions(TRUE);
        $cProductTypeInstance = $sProduct->getTypeInstance();
        $cProductTypeInstance->setUsedProductAttributeIds($_attributeIds);
        $attributes_array = $cProductTypeInstance->getConfigurableAttributesAsArray();
        foreach($attributes_array as $key => $attribute_array) {
            $attributes_array[$key]['use_default'] = 1;
            $attributes_array[$key]['position'] = 0;
            if (isset($attribute_array['frontend_label'])) {
                $attributes_array[$key]['label'] = $attribute_array['frontend_label'];
            } else {
                $attributes_array[$key]['label'] = $attribute_array['attribute_code'];
            }
        }
        if ($is_new) {
            $sProduct->setConfigurableAttributesData($attributes_array);
        }

        //$sProduct->save();

        return array(
            'configurableProduct' => $sProduct,
            'attributeIds' => $_attributeIds,
            'attributes_array' => $attributes_array,
            'is_new' => $is_new,
        );
    }

    public function importConfigurableProducts($limit) {
        $files = glob(Mage::getBaseDir('var') . '/mhsws_export/' . '*');
        $cntr_files = count($files);
        $cntr = 0;

        if ($cntr_files) {
            foreach ($files as $cacheFile) {
                $pathinfo = pathinfo($cacheFile);
                $simpleProducts = array();
                if (strpos($pathinfo['filename'], '__') === false) {

                    $file_handle = fopen($cacheFile, "r");
                    $contents = fread($file_handle, filesize($cacheFile));
                    fclose($file_handle);

                    $prod_ids = explode(chr(10), $contents);

                    foreach ($prod_ids as $prod_id) {
                        if (empty($prod_id)) {
                            continue;
                        }

                        array_push(
                            $simpleProducts,
                            array(
                                "id" => $prod_id,
                            )
                        );
                    }

                    $configurable_data = $this->__getConfigurableProduct($pathinfo['filename'], $prod_ids[0]);
                    $sProduct = $configurable_data['configurableProduct'];
                    $attributes_array = $configurable_data['attributes_array'];
                    $is_new = $configurable_data['is_new'];

                    $dataArray = array();
                    $productTypeIns = $sProduct->getTypeInstance(true);
                    $childIds = $productTypeIns->getChildrenIds($sProduct->getId());  //There is an array of child products id
                    foreach($childIds as $childId) {
                        foreach($childId as $id) {
                            $simpleProducts[] = array('id' => $id);
                        }
                    }

                    foreach ($simpleProducts as $simpleArray) {
                        $dataArray[$simpleArray['id']] = array();
                        foreach ($attributes_array as $attrArray) {
                            array_push( $dataArray[$simpleArray['id']],
                                array(
                                    "attribute_id" => $attrArray['attribute_id'],
                                    "label" => $attrArray['label'],
                                    "is_percent" => false,
                                    "pricing_value" => 777
                                )
                            );
                        }
                    }

                    $sProduct->setConfigurableProductsData($dataArray);
                    if ($is_new) {
                        $sProduct->setStockData(
                            array(
                                'use_config_manage_stock' => 1,
                                'is_in_stock' => 1,
                                'is_salable' => 1
                            )
                        );
                    }

                    $sProduct->save();
                    $this->saveLog('__' . $this->context, Mage::getBaseDir('var') . '/mhsws_export/', $sProduct->getId()); //needed for update product after import

                    $this->log[] = $pathinfo['filename'];

                    unlink($cacheFile);
                    if ($cntr++ >= $limit) {
                        return array(
                            'log' => $this->log,
                            'lenght' => $cntr_files
                        );
                    }

                }
            }
        }

        return array(
            'log' => $this->log,
            'lenght' => 0
        );

    }

    public function hideNotAvailableProducts() {
        $file = Mage::getBaseDir('var') . '/mhsws_export/' . '__' . $this->context . '.tmp';
        $file_handle = fopen($file, "r");
        $contents = fread($file_handle, filesize($file));
        fclose($file_handle);

        $prod_ids = explode(chr(10), $contents);

        $attributeModel = Mage::getModel('eav/entity_attribute')->loadByCode('catalog_product', 'status');
        $resource = Mage::getSingleton('core/resource');
        $query = "
            UPDATE `" . $resource->getTableName('catalog_product_entity_int') . "` at_status
            JOIN `" . $resource->getTableName('catalog_product_entity') . "` e ON (
              `at_status`.`entity_id` =  `e`.`entity_id`
              AND `at_status`.`attribute_id` =  " . (int)$attributeModel->getId() . "
              AND `at_status`.`store_id` = " . (int)Mage::app()->getStore()->getId() . "
            )
            SET `at_status`.`value` = " . (int)Mage_Catalog_Model_Product_Status::STATUS_DISABLED . "
            WHERE e.`entity_id` NOT IN (" . rtrim(implode(', ', $prod_ids), ', ') . ")";

        Mage::Log($query, NULL, 'mhsws_connector.log');

        $this->log[] = ($query . chr(10));
        $write = $resource->getConnection('core_write');
        $write->query($query);
    }

    public function saveLog($filename, $path, $info) {
        if (empty($info)) {
            return;
        }

        $file = $path . $this->escapeString($filename) . '.tmp';

        if(!is_file($file)) {
            $file_handle = fopen($file, "x");
        } else {
            $file_handle = fopen($file, "a");
        }

        fwrite($file_handle, $info . chr(10));
    }

    public function escapeString($filename) {
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);
    }

    public function removeTmpFiles($path) {
        if (is_dir($path)) {
            $files = glob($path . '*');
            if (is_array($files)) {
                foreach ($files as $cacheFile) {
                    if (is_dir($cacheFile)) {
                        self::removeDir($cacheFile . '/');
                        //rmdir($cacheFile);
                    } else {
                        unlink($cacheFile);
                    }
                }
            }
        }

        return TRUE;
    }


    /**
     * Just for testing, please do not use it in production
     *
     * @param $tmp_i
     * @return array
     */
    public function downloadFakeCache($tmp_i) {
        $result = $this->runFakeRequest($tmp_i);
        if (!$result->success) {
            $this->log[] = 'Waiting ...';
            Mage::Log('Waiting ...', NULL, 'mhsws_connector.log');

            sleep(1);
            return array(
                'error' => 0,
                'waiting' => 1,
                'log' => $this->log,
                'cursor' => $this->cursor,
            );
        }

        $batch = $result->batch;
        $length = count($result->batch);

        if ($length == 0) {
            $this->log[] = 'Nothing more; exit!';
            Mage::Log('Nothing more; exit!', NULL, 'mhsws_connector.log');
            return array(
                'error' => 0,
                'waiting' => 0,
                'log' => $this->log,
                'cursor' => $this->cursor,
                'length' => 0,
            );
        }

        for ($j = 0; $j < $length; $j++) {
            $this->log[] = $j;
            $this->log[] = '<pre>' . print_r($batch[$j], TRUE) . '</pre>';
            $cat_ids = $this->saveCategory($batch[$j]);
            $this->saveProduct($batch[$j], $cat_ids);
        }

        $this->cursor = isset($result->cursor) ? $result->cursor : NULL;

        return array(
            'error' => 0,
            'waiting' => 0,
            'log' => $this->log,
            'cursor' => $this->cursor,
            'length' => $length,
        );
        //}

    }

    public function runFakeRequest($tmp_i) {
        if ($tmp_i > 2) {
            return (object)(
                array(
                    'cursor' => 'FAKE-CURSORCURSORCURSORCURSORCURSORCURSORCURSOR',
                    'success' => 1,
                    'batch' => array(),
                    'length' => 0,
                    'waiting' => 0,
                )
            );
        } else {
            $batch = array();
            for ($i=0; $i<5; $i++) {
                $tmp = new StdClass();
                $tmp->uid = rand(1611686018428828000, 4611686018428828805);
                $tmp->plu_sku = rand(10000, 15000);
                $tmp->qty = rand(0, 123);
                $tmp->retail_price = rand(0, 100);
                $tmp->cost = rand(0, 100);
                $tmp->size2 = rand(0, 3);
                $tmp->size = rand(2, 7);
                $tmp->category = 'full cup';
                $tmp->price = rand(100, 110);
                $tmp->style = 'belvg';
                $tmp->color = 'black';
                $tmp->name = 'belvg test price product';
                $tmp->brand = 'belvg';
                $batch[] = $tmp;
            }

            return (object)(
                array(
                    'cursor' => 'FAKE-CURSORCURSORCURSORCURSORCURSORCURSORCURSOR',
                    'success' => 1,
                    'batch' => $batch,
                    'length' => count($batch),
                    'waiting' => 0,
                )
            );
        }
    }


}