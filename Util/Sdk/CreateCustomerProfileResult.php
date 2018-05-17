<?php

namespace Xfrocks\AuthorizeNetArb\Util\Sdk;

use net\authorize\api\contract\v1 as AnetAPI;

class CreateCustomerProfileResult extends BaseResult
{
    public function getPaymentProfileId()
    {
        return $this->getCustomerProfileResponse()->getCustomerPaymentProfileIdList()[0];
    }

    public function getProfileId()
    {
        return $this->getCustomerProfileResponse()->getCustomerProfileId();
    }

    public function isOk()
    {
        return true;
    }

    public function toArray()
    {
        return (array)$this->getCustomerProfileResponse();
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
