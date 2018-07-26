<?php

namespace Xfrocks\AuthorizeNetArb\Util\Sdk;

use net\authorize\api\contract\v1 as AnetAPI;

class GetTransactionDetailsResult extends BaseResult
{
    public function isOk()
    {
        $transaction = $this->getTransaction();
        if ($transaction === null) {
            return null;
        }

        return $transaction->getResponseCode() === 1;
    }

    /**
     * @return null|string
     */
    public function getInvoiceNumber()
    {
        $transaction = $this->getTransaction();
        if ($transaction === null) {
            return null;
        }

        $order = $transaction->getOrder();

        if (empty($order)) {
            return null;
        }

        return $order->getInvoiceNumber();
    }

    /**
     * @return null|string
     */
    public function getReversedTransId()
    {
        $transaction = $this->getTransaction();
        if ($transaction === null) {
            return null;
        }

        switch ($transaction->getTransactionType()) {
            case 'refundTransaction':
                return $transaction->getRefTransId();
        }

        return null;
    }

    /**
     * @return int|null
     */
    public function getSubscriptionId()
    {
        $transaction = $this->getTransaction();
        if ($transaction === null) {
            return null;
        }

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
        if ($transaction === null) {
            return [];
        }

        $array = self::castToArray($transaction);

        $array['_billTo'] = self::castToArray($transaction->getBillTo());
        $array['_customer'] = self::castToArray($transaction->getCustomer());
        $array['_order'] = self::castToArray($transaction->getOrder());
        $array['_subscription'] = self::castToArray($transaction->getSubscription());

        return $array;
    }

    /**
     * @return AnetAPI\TransactionDetailsType|null
     */
    private function getTransaction()
    {
        /** @var AnetAPI\GetTransactionDetailsResponse|null $getTransactionDetailsResponse */
        $getTransactionDetailsResponse = $this->apiResponse;

        if ($getTransactionDetailsResponse === null) {
            return null;
        }

        return $getTransactionDetailsResponse->getTransaction();
    }
}
