<?php

namespace Xfrocks\AuthorizeNetArb\Util\Sdk;

use net\authorize\api\contract\v1 as AnetAPI;

class GetTransactionDetailsResult extends BaseResult
{
    public function isOk()
    {
        return true;
    }

    public function getSubscriptionId()
    {
        $subscription = $this->getTransaction()->getSubscription();
        if (empty($subscription)) {
            return null;
        }

        return $subscription->getId();
    }

    public function toArray()
    {
        return (array)$this->getTransaction();
    }

    private function getTransaction()
    {
        /** @var AnetAPI\GetTransactionDetailsResponse $getTransactionDetailsResponse */
        $getTransactionDetailsResponse = $this->apiResponse;

        return $getTransactionDetailsResponse->getTransaction();
    }
}