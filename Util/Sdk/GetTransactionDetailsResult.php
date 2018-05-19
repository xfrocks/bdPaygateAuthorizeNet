<?php

namespace Xfrocks\AuthorizeNetArb\Util\Sdk;

use net\authorize\api\contract\v1 as AnetAPI;

class GetTransactionDetailsResult extends BaseResult
{
    public function isOk()
    {
        return $this->getTransaction()->getResponseCode() === 1;
    }

    public function getInvoiceNumber()
    {
        $order = $this->getTransaction()->getOrder();

        if (empty($order)) {
            return null;
        }

        return $order->getInvoiceNumber();
    }

    public function getReversedTransId()
    {
        $transaction = $this->getTransaction();

        switch ($transaction->getTransactionType()) {
            case 'refundTransaction':
                return $transaction->getRefTransId();
        }

        return null;
    }

    public function getSubscriptionId()
    {
        $transaction = $this->getTransaction();

        if (!$transaction->getRecurringBilling()) {
            return null;
        }

        $subscription = $transaction->getSubscription();
        if (empty($subscription)) {
            return null;
        }

        return $subscription->getId();
    }

    public function toArray()
    {
        $transaction = $this->getTransaction();
        $array = self::castToArray($transaction);

        $array['_billTo'] = self::castToArray($transaction->getBillTo());
        $array['_customer'] = self::castToArray($transaction->getCustomer());
        $array['_order'] = self::castToArray($transaction->getOrder());
        $array['_subscription'] = self::castToArray($transaction->getSubscription());

        return $array;
    }

    private function getTransaction()
    {
        /** @var AnetAPI\GetTransactionDetailsResponse $getTransactionDetailsResponse */
        $getTransactionDetailsResponse = $this->apiResponse;

        return $getTransactionDetailsResponse->getTransaction();
    }
}
