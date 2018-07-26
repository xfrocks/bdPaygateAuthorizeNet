<?php

namespace Xfrocks\AuthorizeNetArb\Util\Sdk;

use net\authorize\api\contract\v1 as AnetAPI;

class CreateCustomerProfileResult extends BaseResult
{
    /**
     * @return mixed
     */
    public function getPaymentProfileId()
    {
        return $this->getCustomerProfileResponse()->getCustomerPaymentProfileIdList()[0];
    }

    /**
     * @return string
     */
    public function getProfileId()
    {
        return $this->getCustomerProfileResponse()->getCustomerProfileId();
    }

    public function isOk()
    {
        return true;
    }

    /**
     * @return AnetAPI\CreateCustomerProfileResponse
     */
    private function getCustomerProfileResponse()
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->apiResponse;
    }
}
