<?php

namespace Xfrocks\AuthorizeNetArb\Util\Sdk;

use net\authorize\api\contract\v1 as AnetAPI;

abstract class ChargeResult
{
    protected $apiResponse;
    protected $transactionResponse;

    /**
     * ChargeResult constructor.
     *
     * @param AnetAPI\CreateTransactionResponse $apiResponse
     * @param AnetAPI\TransactionResponseType $transactionResponse
     */
    public function __construct($apiResponse, $transactionResponse)
    {
        $this->apiResponse = $apiResponse;
        $this->transactionResponse = $transactionResponse;
    }

    abstract public function isOk();
}