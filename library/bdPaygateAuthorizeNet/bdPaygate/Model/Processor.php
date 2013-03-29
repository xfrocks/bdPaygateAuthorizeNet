<?php

class bdPaygateAuthorizeNet_bdPaygate_Model_Processor extends XFCP_bdPaygateAuthorizeNet_bdPaygate_Model_Processor
{
	public function getProcessorNames()
	{
		$names = parent::getProcessorNames();
		
		$names['authnet'] = 'bdPaygateAuthorizeNet_Processor';
		
		return $names;
	}

	protected function _verifyPaymentAmount(bdPaygate_Processor_Abstract $processor, $actualAmount, $actualCurrency, $expectAmount, $expectCurrency)
	{
		if ($processor instanceof bdPaygateAuthorizeNet_Processor)
		{
			// disable currency check because Authorize.Net server doesn't
			// post back that information. It's still safe though because we have hash
			// included in the payment form
			$actualCurrency = $expectCurrency;
		}

		return parent::_verifyPaymentAmount($processor, $actualAmount, $actualCurrency, $expectAmount, $expectCurrency);
	}
}