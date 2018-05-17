<?php

namespace Xfrocks\AuthorizeNetArb\Util\Sdk;

use net\authorize\api\contract\v1 as AnetAPI;

class ChargeBaseResult extends BaseResult
{
    public function getTransactionErrors()
    {
        $errorTexts = [];

        $errors = $this->getTransactionResponse()->getErrors();
        /** @var AnetAPI\TransactionResponseType\ErrorsAType\ErrorAType $error */
        foreach ($errors as $error) {
            $errorId = $error->getErrorCode();
            if (isset($errorTexts[$errorId])) {
                $errorId .= sprintf('_%d', count($errorTexts) + 1);
            }

            $errorTexts[$errorId] = $error->getErrorText();
        }

        return $errorTexts;
    }

    protected function getTransactionResponse()
    {
        /** @var AnetAPI\CreateTransactionResponse $apiResponse */
        $apiResponse = $this->apiResponse;

        return $apiResponse->getTransactionResponse();
    }
}
