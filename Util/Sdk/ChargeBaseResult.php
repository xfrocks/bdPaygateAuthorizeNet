<?php

namespace Xfrocks\AuthorizeNetArb\Util\Sdk;

use net\authorize\api\contract\v1 as AnetAPI;

class ChargeBaseResult extends BaseResult
{
    public function getResponseCode()
    {
        return $this->getTransactionResponse()->getResponseCode();
    }

    public function getTransactionErrors()
    {
        $errorTexts = [];

        /** @var AnetAPI\TransactionResponseType\ErrorsAType\ErrorAType[] $errors */
        $errors = $this->getTransactionResponse()->getErrors();
        foreach ($errors as $error) {
            $errorId = $error->getErrorCode();
            if (isset($errorTexts[$errorId])) {
                $errorId .= sprintf('_%d', count($errorTexts) + 1);
            }

            $errorTexts[$errorId] = $error->getErrorText();
        }

        return $errorTexts;
    }

    public function getTransId()
    {
        return $this->getTransactionResponse()->getTransId();
    }

    public function toArray()
    {
        $array = self::castToArray($this->getTransactionResponse());

        $array['_apiMessages'] = $this->getApiMessages();
        $array['_transactionErrors'] = $this->getTransactionErrors();

        return $array;
    }

    protected function getTransactionResponse()
    {
        /** @var AnetAPI\CreateTransactionResponse $apiResponse */
        $apiResponse = $this->apiResponse;

        return $apiResponse->getTransactionResponse();
    }
}
