<?php

namespace Xfrocks\AuthorizeNetArb\Util\Sdk;

class ChargeOk extends ChargeResult
{
    public function isOk()
    {
        return true;
    }

    public function getTransactionId()
    {
        return $this->transactionResponse->getTransId();
    }

    public function toArray()
    {
        return (array)$this->transactionResponse;
    }
}