<?php

class bdPaygateAuthorizeNet_bdPaygate_Model_Processor extends XFCP_bdPaygateAuthorizeNet_bdPaygate_Model_Processor
{
	public function getProcessorNames()
	{
		$names = parent::getProcessorNames();
		
		$names['authnet'] = 'bdPaygateAuthorizeNet_Processor';
		
		return $names;
	}
}