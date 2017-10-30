<?php

namespace Xfrocks\AuthorizeNetArb\Util\Sdk;

use net\authorize\api\contract\v1 as AnetAPI;

class ChargeError extends ChargeResult
{
    public function isOk()
    {
        return false;
    }

    public function getErrors()
    {
        $errorTexts = [];

        $errors = $this->transactionResponse->getErrors();
        /** @var AnetAPI\TransactionResponseType\ErrorsAType\ErrorAType $error */
        foreach ($errors as $error) {
            $errorTexts[] = $error->getErrorText();
        }

        if (count($errorTexts) === 0) {
            $messages = $this->apiResponse->getMessages()->getMessage();

            /** @var AnetAPI\MessagesType\MessageAType $message */
            foreach ($messages as $message) {
                $errorTexts[] = $message->getText();
            }
        }

        return $errorTexts;
    }
}