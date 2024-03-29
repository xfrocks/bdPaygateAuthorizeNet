<?php

namespace Xfrocks\AuthorizeNetArb\Util\Sdk;

use net\authorize\api\contract\v1 as AnetAPI;
use XF\Util\Php;

class BaseResult
{
    /**
     * @var AnetAPI\ANetApiResponseType
     */
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

    /**
     * @return string[]
     */
    public function getApiMessages()
    {
        $messageTexts = [];

        $messages = $this->apiResponse->getMessages()->getMessage();
        foreach ($messages as $message) {
            $messageCode = $message->getCode();
            if (isset($messageTexts[$messageCode])) {
                $messageCode .= sprintf('_%d', count($messageTexts) + 1);
            }

            $messageTexts[$messageCode] = $message->getText();
        }

        return $messageTexts;
    }

    /**
     * @return bool
     */
    public function isOk()
    {
        return false;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $array = self::castToArray($this->apiResponse);

        $array['_apiMessages'] = $this->getApiMessages();

        return $array;
    }

    /**
     * @param mixed $obj
     * @return array
     */
    public static function castToArray($obj)
    {
        $isNull = $obj === null;
        $isObject = is_object($obj);
        if ($isNull || !$isObject) {
            return [
                '_isNull' => $isNull,
                '_isObject' => $isObject,
                '_serialized' => Php::safeSerialize($obj),
            ];
        }

        $class = get_class($obj);
        $array = array();
        foreach ((array)$obj as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $key = str_replace($class, '', $key);
            $array[$key] = $value;
        }

        return $array;
    }
}
