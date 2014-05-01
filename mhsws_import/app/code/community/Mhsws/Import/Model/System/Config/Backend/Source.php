<?php

class Mhsws_Import_Model_System_Config_Backend_Source extends Mage_Core_Model_Config_Data
{
	public function getImportFolder()
	{
		return Mage::getBaseDir('media') . '/mhsws_import/';
	}

    public function toOptionArray()
    { 
		$options = array();
		foreach ((array)glob($this->getImportFolder() . '*') as $folder) {
			$options[] = array('value' => $folder, 'label' => basename($folder));
		}

        return $options;
    }
}
