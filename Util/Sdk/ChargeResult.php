<?php

namespace Xfrocks\AuthorizeNetArb\Util\Sdk;

class ChargeResult extends ChargeBaseResult
{
    public function isOk()
    {
        return true;
    }

    public function getResponseCode()
    {
        return $this->getTransactionResponse()->getResponseCode();
    }

    public function getTransId()
    {
        return $this->getTransactionResponse()->getTransId();
    }

    public function toArray()
    {
        return (array)$this->getTransactionResponse();
    }
}
