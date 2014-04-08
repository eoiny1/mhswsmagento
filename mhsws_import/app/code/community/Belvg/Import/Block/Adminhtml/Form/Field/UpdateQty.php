<?php

class Belvg_Import_Block_Adminhtml_Form_Field_UpdateQty extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    protected $_helper = null;

    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $this->setElement($element);
        return $this->toHtml();
    }

    public function __construct()
    {
        parent::__construct();
        $this->_helper = Mage::helper('import');
        $this->setTemplate('belvg/import/update_qty.phtml');
    }
}