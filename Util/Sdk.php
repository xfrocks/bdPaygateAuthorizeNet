<?php

namespace Xfrocks\AuthorizeNetArb\Util;

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use net\authorize\api\constants as AnetConstants;
use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Purchasable\Purchase;
use XF\Util\File;
use Xfrocks\AuthorizeNetArb\Util\Sdk\ChargeError;
use Xfrocks\AuthorizeNetArb\Util\Sdk\ChargeOk;
use Xfrocks\AuthorizeNetArb\Util\Sdk\ChargeResult;
use Xfrocks\AuthorizeNetArb\Util\Sdk\Transaction;

class Sdk
{
    const RESPONSE_OK = 'Ok';

    const WEBHOOK_EVENT_TYPE_AUTH_AND_CAPTURE = 'net.authorize.payment.authcapture.created';
    const WEBHOOK_EVENT_TYPE_CAPTURE = 'net.authorize.payment.capture.created';
    const WEBHOOK_EVENT_TYPE_PRIOR_AUTH_CAPTURE = 'net.authorize.payment.priorAuthCapture.created';
    const WEBHOOK_EVENT_TYPE_REFUND = 'net.authorize.payment.refund.created';

    /**
     * @param string $apiLoginId
     * @param string $transactionKey
     * @param string $callbackUrl
     * @throws \Exception
     */
    public static function assertWebhookExists($apiLoginId, $transactionKey, $callbackUrl)
    {
        self::autoload();

        $client = \XF::app()->http()->client();
        $url = self::getEndpoint() . '/rest/v1/webhooks';
        $options = [
            'auth' => [$apiLoginId, $transactionKey],
        ];
        $eventTypes = [
            self::WEBHOOK_EVENT_TYPE_AUTH_AND_CAPTURE,
            self::WEBHOOK_EVENT_TYPE_CAPTURE,
            self::WEBHOOK_EVENT_TYPE_PRIOR_AUTH_CAPTURE,
            self::WEBHOOK_EVENT_TYPE_REFUND,
        ];
        $existingWebhook = null;
        $existingEventTypes = [];

        $listResponse = $client->get($url, $options);
        $webhooks = $listResponse->json();

        if (!empty($webhooks)) {
            foreach ($webhooks as $webhook) {
                if ($webhook['url'] === $callbackUrl) {
                    $existingWebhook = $webhook;
                    break;
                }
            }
        }

        if ($existingWebhook !== null) {
            foreach ($eventTypes as $eventType) {
                foreach ($existingWebhook['eventTypes'] as $existingWebhookEventType) {
                    if ($existingWebhookEventType === $eventType) {
                        $existingEventTypes[] = $existingWebhookEventType;
                    }
                }
            }
        }

        if ($existingWebhook !== null && count($existingEventTypes) === count($eventTypes)) {
            // existing webhook found and is configured properly
            return;
        }

        $newWebhookData = [
            'url' => $callbackUrl,
            'eventTypes' => $eventTypes,
            'status' => 'active',
        ];
        if ($existingWebhook === null) {
            $createResponse = $client->post($url, array_merge($options, [
                'json' => $newWebhookData
            ]));

            $newWebhook = $createResponse->json();
            if (empty($newWebhook['webhookId'])) {
                throw new \Exception('Webhook cannot be created');
            }
        } else {
            $updateUrl = self::getEndpoint() . $existingWebhook['_links']['self']['href'];
            $updateResponse = $client->put($updateUrl, array_merge($options, [
                'json' => $newWebhookData
            ]));

            $updatedWebhook = $updateResponse->json();
            if (empty($updatedWebhook['webhookId'])) {
                throw new \Exception('Webhook cannot be updated');
            }
        }
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @param Purchase $purchase
     * @param string $opaqueDataJson
     * @param array $inputs
     * @return ChargeResult
     * @throws \Exception
     */
    public static function charge($paymentProfile, $purchase, $opaqueDataJson, array $inputs)
    {
        self::autoload();

        $opaqueDataArray = @json_decode($opaqueDataJson, true);
        if (!is_array($opaqueDataArray)) {
            throw new \Exception('Opaque Data cannot be decoded');
        }
        $opaqueData = new AnetAPI\OpaqueDataType();
        $opaqueData->setDataDescriptor($opaqueDataArray['dataDescriptor']);
        $opaqueData->setDataValue($opaqueDataArray['dataValue']);

        $payment = new AnetAPI\PaymentType();
        $payment->setOpaqueData($opaqueData);

        $transactionRequest = new AnetAPI\TransactionRequestType();
        $transactionRequest->setAmount($purchase->cost);
        $transactionRequest->setPayment($payment);
        $transactionRequest->setTransactionType('authCaptureTransaction');

        if (!empty($inputs['email'])) {
            $customerData = new AnetAPI\CustomerDataType();
            $customerData->setId(\XF::visitor()->user_id);
            $customerData->setEmail($inputs['email']);
            $transactionRequest->setCustomer($customerData);
        }

        if (!empty($inputs['address'])) {
            $customerAddress = new AnetAPI\CustomerAddressType();
            $customerAddress->setAddress($inputs['address']);
            if (!empty($inputs['city'])) {
                $customerAddress->setCity($inputs['city']);
            }
            if (!empty($inputs['state'])) {
                $customerAddress->setState($inputs['state']);
            }
            $transactionRequest->setBillTo($customerAddress);
        }

        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication(self::newMerchantAuthentication($paymentProfile));
        $request->setTransactionRequest($transactionRequest);

        $controller = new AnetController\CreateTransactionController($request);

        /** @var AnetAPI\CreateTransactionResponse $apiResponse */
        $apiResponse = self::chooseEndpointAndExecute($controller);

        /** @var AnetAPI\TransactionResponseType $transactionResponse */
        $transactionResponse = $apiResponse->getTransactionResponse();

        if ($apiResponse->getMessages()->getResultCode() == self::RESPONSE_OK) {
            return new ChargeOk($apiResponse, $transactionResponse);
        } else {
            return new ChargeError($apiResponse, $transactionResponse);
        }
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @param string $signature
     * @param string $json
     * @return bool
     */
    public static function verifyWebhookSignature($paymentProfile, $signature, $json)
    {
        $expected = 'sha512=' . strtoupper(hash_hmac('sha512', $json, $paymentProfile->options['signature_key']));
        return $signature === $expected;
    }

    private static function autoload()
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        require(dirname(__DIR__) . '/vendor/autoload.php');
    }

    /**
     * @param AnetController\base\ApiOperationBase $controller
     * @return AnetAPI\AnetApiResponseType
     * @throws \Exception
     */
    private static function chooseEndpointAndExecute($controller)
    {
        if (\XF::$debugMode) {
            $controller->httpClient->setLogFile(File::getTempDir() . '/authorizenet.log');
        }

        $response = $controller->executeWithApiResponse(self::getEndpoint());

        if ($response === null) {
            throw new \Exception('Cannot execute Authorize.Net operation');
        }

        return $response;
    }

    private static function getEndpoint()
    {
        if (\XF::config('enableLivePayments')) {
            return AnetConstants\ANetEnvironment::PRODUCTION;
        } else {
            return AnetConstants\ANetEnvironment::SANDBOX;
        }
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @return AnetAPI\MerchantAuthenticationType
     */
    private static function newMerchantAuthentication($paymentProfile)
    {
        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName($paymentProfile->options['api_login_id']);
        $merchantAuthentication->setTransactionKey($paymentProfile->options['transaction_key']);

        return $merchantAuthentication;
    }
}