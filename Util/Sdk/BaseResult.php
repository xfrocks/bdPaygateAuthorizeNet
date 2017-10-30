<?php

namespace Xfrocks\AuthorizeNetArb\Util\Sdk;

use net\authorize\api\contract\v1 as AnetAPI;

class BaseResult
{
    protected $apiResponse;

    /**
     * AbstractResult constructor.
     *
     * @param AnetAPI\ANetApiResponseType $apiResponse
     */
    public function __construct($apiResponse)
    {
        $this->apiResponse = $apiResponse;
    }

    public function getErrors()
    {
        $errorTexts = [];

        $messages = $this->apiResponse->getMessages()->getMessage();

        /** @var AnetAPI\MessagesType\MessageAType $message */
        foreach ($messages as $message) {
            $errorId = $message->getCode();
            if (isset($errorTexts[$errorId])) {
                $errorId .= sprintf('_%d', count($errorTexts) + 1);
            }

            $errorTexts[$errorId] = $message->getText();
        }

        return $errorTexts;
    }

    public function isOk()
    {
        return false;
    }
}