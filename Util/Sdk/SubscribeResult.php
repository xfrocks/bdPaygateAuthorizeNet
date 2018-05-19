<?php

namespace Xfrocks\AuthorizeNetArb\Util\Sdk;

use net\authorize\api\contract\v1 as AnetAPI;

class SubscribeResult extends BaseResult
{
    public function isOk()
    {
        return true;
    }

    public function getSubscriptionId()
    {
        /** @var AnetAPI\ARBCreateSubscriptionResponse $apiResponse */
        $apiResponse = $this->apiResponse;

        return $apiResponse->getSubscriptionId();
    }
}
