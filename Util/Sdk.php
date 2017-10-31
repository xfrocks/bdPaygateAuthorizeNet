<?php

namespace Xfrocks\AuthorizeNetArb\Util;

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use net\authorize\api\constants as AnetConstants;
use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Purchasable\Purchase;
use XF\Util\File;
use Xfrocks\AuthorizeNetArb\Util\Sdk\BaseResult;
use Xfrocks\AuthorizeNetArb\Util\Sdk\ChargeResult;
use Xfrocks\AuthorizeNetArb\Util\Sdk\ChargeBaseResult;
use Xfrocks\AuthorizeNetArb\Util\Sdk\CreateCustomerProfileResult;
use Xfrocks\AuthorizeNetArb\Util\Sdk\SubscribeResult;

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
     * @param PurchaseRequest $purchaseRequest
     * @param PaymentProfile $paymentProfile
     * @param Purchase $purchase
     * @param string $opaqueDataJson
     * @param array $inputs
     * @return ChargeBaseResult|ChargeResult
     * @throws \Exception
     */
    public static function charge($purchaseRequest, $paymentProfile, $purchase, $opaqueDataJson, array $inputs)
    {
        self::autoload();

        $customerAddress = new AnetAPI\CustomerAddressType();
        $customerAddressHasData = false;
        if (isset($inputs['first_name']) && isset($inputs['last_name'])) {
            $customerAddress->setFirstName($inputs['first_name']);
            $customerAddress->setLastName($inputs['last_name']);
            $customerAddressHasData = true;
        } elseif ($purchase->recurring) {
            // names are required for ARB subscriptions
            $customerAddress->setFirstName('John');
            $customerAddress->setLastName('Appleseed');
            $customerAddressHasData = true;
        }
        if (isset($inputs['address']) && isset($inputs['city']) && isset($inputs['state']) && isset($inputs['zip'])) {
            $customerAddress->setAddress($inputs['address']);
            $customerAddress->setCity($inputs['city']);
            $customerAddress->setState($inputs['state']);
            $customerAddress->setZip($inputs['zip']);
            $customerAddressHasData = true;
        }

        $customerData = new AnetAPI\CustomerDataType();
        $customerDataHasData = false;
        $visitor = \XF::visitor();
        if ($visitor->user_id > 0) {
            $customerData->setId($visitor->user_id);
            $customerDataHasData = true;
        }
        if (isset($inputs['email'])) {
            $customerData->setEmail($inputs['email']);
            $customerDataHasData = true;
        }

        $order = new AnetAPI\OrderType();
        $order->setInvoiceNumber($purchaseRequest->purchase_request_id);
        $order->setDescription($purchase->title);

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
        if ($customerAddressHasData) {
            $transactionRequest->setBillTo($customerAddress);
        }
        if ($customerDataHasData) {
            $transactionRequest->setCustomer($customerData);
        }
        $transactionRequest->setOrder($order);
        $transactionRequest->setPayment($payment);
        $transactionRequest->setTransactionType('authCaptureTransaction');

        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication(self::newMerchantAuthentication($paymentProfile));
        $request->setTransactionRequest($transactionRequest);

        $controller = new AnetController\CreateTransactionController($request);

        /** @var AnetAPI\CreateTransactionResponse $apiResponse */
        $apiResponse = self::chooseEndpointAndExecute($controller);

        if ($apiResponse->getMessages()->getResultCode() == self::RESPONSE_OK) {
            return new ChargeResult($apiResponse);
        } else {
            return new ChargeBaseResult($apiResponse);
        }
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @param ChargeResult $chargeOk
     * @return BaseResult|CreateCustomerProfileResult
     */
    public static function createCustomerProfileFromTransaction($paymentProfile, $chargeOk)
    {
        self::autoload();

        $customer = new AnetAPI\CustomerProfileBaseType();
        $customer->setDescription(sprintf('Customer Profile for transaction %s', $chargeOk->getTransId()));

        $request = new AnetApi\CreateCustomerProfileFromTransactionRequest();
        $request->setCustomer($customer);
        $request->setMerchantAuthentication(self::newMerchantAuthentication($paymentProfile));
        $request->setTransId($chargeOk->getTransId());

        $controller = new AnetController\CreateCustomerProfileFromTransactionController($request);

        /** @var AnetApi\CreateCustomerProfileResponse $apiResponse */
        $apiResponse = self::chooseEndpointAndExecute($controller);

        if ($apiResponse->getMessages()->getResultCode() == self::RESPONSE_OK) {
            return new CreateCustomerProfileResult($apiResponse);
        } else {
            return new BaseResult($apiResponse);
        }
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @param Purchase $purchase
     * @param CreateCustomerProfileResult $customerProfile
     * @param int $retries
     * @return BaseResult|SubscribeResult
     */
    public static function subscribe($paymentProfile, $purchase, $customerProfile, $retries = 3)
    {
        self::autoload();

        self::assertRecurringLength($purchase->lengthAmount, $purchase->lengthUnit);

        $interval = new AnetAPI\PaymentScheduleType\IntervalAType();
        $interval->setLength($purchase->lengthAmount);
        $interval->setUnit($purchase->lengthUnit . 's');

        $startDate = new \DateTime();
        $startDate->add(new \DateInterval(sprintf(
            'P%d%s',
            $purchase->lengthAmount,
            strtoupper(substr($purchase->lengthUnit, 0, 1))
        )));

        $paymentSchedule = new AnetAPI\PaymentScheduleType();
        $paymentSchedule->setInterval($interval);
        $paymentSchedule->setStartDate($startDate);
        $paymentSchedule->setTotalOccurrences(9999);

        $apiCustomerProfile = new AnetAPI\CustomerProfileIdType();
        $apiCustomerProfile->setCustomerPaymentProfileId($customerProfile->getPaymentProfileId());
        $apiCustomerProfile->setCustomerProfileId($customerProfile->getProfileId());

        $subscription = new AnetAPI\ARBSubscriptionType();
        $subscription->setAmount($purchase->cost);
        $subscription->setPaymentSchedule($paymentSchedule);
        $subscription->setProfile($apiCustomerProfile);

        $request = new AnetAPI\ARBCreateSubscriptionRequest();
        $request->setMerchantAuthentication(self::newMerchantAuthentication($paymentProfile));
        $request->setSubscription($subscription);

        $controller = new AnetController\ARBCreateSubscriptionController($request);

        /** @var AnetAPI\ARBCreateSubscriptionResponse $apiResponse */
        $apiResponse = self::chooseEndpointAndExecute($controller);

        if ($apiResponse->getMessages()->getResultCode() == self::RESPONSE_OK) {
            return new SubscribeResult($apiResponse);
        } else {
            $baseResult = new BaseResult($apiResponse);

            $shouldRetry = false;
            $errors = $baseResult->getErrors();
            if (isset($errors['E00040'])) {
                $shouldRetry = true;
            }

            if ($shouldRetry && $retries > 0) {
                if (!\XF::config('enableLivePayments')) {
                    // Sandbox environment is a bit slow. Creating a new subscription immediately after
                    // creating a customer profile may trigger error 40 (The record cannot be found.)
                    sleep(15);
                } else {
                    sleep(1);
                }

                return self::subscribe($paymentProfile, $purchase, $customerProfile, $retries - 1);
            }

            return $baseResult;
        }
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @param string $subscriptionId
     * @return bool
     */
    public static function unSubscribe($paymentProfile, $subscriptionId)
    {
        self::autoload();

        $request = new AnetAPI\ARBCancelSubscriptionRequest();
        $request->setMerchantAuthentication(self::newMerchantAuthentication($paymentProfile));
        $request->setSubscriptionId($subscriptionId);

        $controller = new AnetController\ARBCancelSubscriptionController($request);

        /** @var AnetAPI\ARBCancelSubscriptionResponse $apiResponse */
        $apiResponse = self::chooseEndpointAndExecute($controller);

        if ($apiResponse->getMessages()->getResultCode() == self::RESPONSE_OK) {
            return true;
        } else {
            return false;
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
     * @param int $amount
     * @param string $unit
     * @throws \Exception
     */
    private static function assertRecurringLength($amount, $unit)
    {
        switch ($unit) {
            case 'day':
                if ($amount >= 7 && $amount <= 365) {
                    return;
                }
                break;
            case 'month':
                if ($amount >= 1 && $amount <= 12) {
                    return;
                }
                break;
        }

        throw new \Exception(sprintf('Recurring length combination %d %s is not supported', $amount, $unit));
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