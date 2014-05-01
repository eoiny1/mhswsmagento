<?php

$installer = $this;
//reason - can't correct save attribute properties
$installer->installEntities(); /*Mhsws_Import_Model_Resource_Setup*/

$attribute_set_name = 'Default';
$group_name = 'General';
$attribute_codes = array('mhsws_import', 'mhsws_uid', 'mhsws_department', 'mhsws_cost', 'mhsws_style', 'mhsws_brand',
    'mhsws_color', 'mhsws_size', 'mhsws_size2', 'mhsws_category', 'mhsws_fabric');
/*foreach ($attribute_codes as $attribute_code) {
    $installer->removeAttribute('catalog_product', $attribute_code);
}
return;*/

$attribute_set_id = $installer->getAttributeSetId('catalog_product', $attribute_set_name);
$attribute_group_id = $installer->getAttributeGroupId('catalog_product', $attribute_set_id, $group_name);

//-------------- add attribute to set and group
foreach ($attribute_codes as $attribute_code) {
    $attribute_id = $installer->getAttributeId('catalog_product', $attribute_code);
    $installer->addAttributeToSet($entityTypeId = 'catalog_product', $attribute_set_id, $attribute_group_id, $attribute_id);
}
