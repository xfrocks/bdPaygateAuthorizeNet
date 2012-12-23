<?php

class bdPaygateAuthorizeNet_XenForo_ControllerAdmin_UserUpgrade extends XFCP_bdPaygateAuthorizeNet_XenForo_ControllerAdmin_UserUpgrade
{
	public function actionIndex()
	{
		$optionModel = $this->getModelFromCache('XenForo_Model_Option');
		$optionModel->bdPaygateAuthorizeNet_hijackOptions();
		
		return parent::actionIndex();
	}
}