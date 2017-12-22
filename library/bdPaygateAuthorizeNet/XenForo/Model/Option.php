<?php

class bdPaygateAuthorizeNet_XenForo_Model_Option extends XFCP_bdPaygateAuthorizeNet_XenForo_Model_Option
{
	// this property must be static because XenForo_ControllerAdmin_UserUpgrade::actionIndex
	// for no apparent reason use XenForo_Model::create to create the optionModel
	// (instead of using XenForo_Controller::getModelFromCache)
	private static $_bdPaygateAuthorizeNet_hijackOptions = false;
	
	public function getOptionsByIds(array $optionIds, array $fetchOptions = array())
	{
		if (self::$_bdPaygateAuthorizeNet_hijackOptions === true)
		{
			$optionIds[] = 'bdPaygateAuthorizeNet_id';
			$optionIds[] = 'bdPaygateAuthorizeNet_key';
			$optionIds[] = 'bdPaygateAuthorizeNet_md5hash';
		}
		
		$options = parent::getOptionsByIds($optionIds, $fetchOptions);
		
		self::$_bdPaygateAuthorizeNet_hijackOptions = false;

		return $options;
	}
	
	public function bdPaygateAuthorizeNet_hijackOptions()
	{
		self::$_bdPaygateAuthorizeNet_hijackOptions = true;
	}
}