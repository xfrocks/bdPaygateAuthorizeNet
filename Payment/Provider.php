<?php

namespace Xfrocks\AuthorizeNetArb\Payment;

use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Mvc\Controller;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;
use XF\Repository\Payment;
use Xfrocks\AuthorizeNetArb\Util\Sdk;

class Provider extends AbstractProvider
{
    public function getCallbackUrl()
    {
        if (\XF::$debugMode) {
            $callbackUrl = \XF::config(__METHOD__);
            if (!empty($callbackUrl)) {
                return $callbackUrl;
            }
        }

        return parent::getCallbackUrl();
    }

    public function getPaymentResult(CallbackState $state)
    {
        /** @noinspection PhpUndefinedFieldInspection */
        switch ($state->eventType) {
            case Sdk::WEBHOOK_EVENT_TYPE_AUTH_AND_CAPTURE:
            case Sdk::WEBHOOK_EVENT_TYPE_CAPTURE:
            case Sdk::WEBHOOK_EVENT_TYPE_PRIOR_AUTH_CAPTURE:
                $state->paymentResult = CallbackState::PAYMENT_RECEIVED;
                break;

            case Sdk::WEBHOOK_EVENT_TYPE_REFUND:
                $state->paymentResult = CallbackState::PAYMENT_REVERSED;
                break;
        }
    }

    public function getTitle()
    {
        return 'Authorize.Net with ARB';
    }

    public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        $viewParams = [
            'enableLivePayments' => !!\XF::config('enableLivePayments'),

            'purchaseRequest' => $purchaseRequest,
            'paymentProfile' => $purchase->paymentProfile,
            'purchaser' => $purchase->purchaser,
            'purchase' => $purchase,
        ];

        return $controller->view(
            'Xfrocks\AuthorizeNetArb:PaymentInitiate',
            'Xfrocks_AuthorizeNetArb_payment_initiate',
            $viewParams
        );
    }

    public function prepareLogData(CallbackState $state)
    {
        /** @noinspection PhpUndefinedFieldInspection */
        $state->logDetails = $state->payload;
    }

    public function processCancellation(
        Controller $controller,
        PurchaseRequest $purchaseRequest,
        PaymentProfile $paymentProfile
    ) {
        $logFinder = \XF::finder('XF:PaymentProviderLog')
            ->where('purchase_request_key', $purchaseRequest->request_key)
            ->where('provider_id', $this->getProviderId())
            ->order('log_date', 'desc');

        $logs = $logFinder->fetch();

        $subscriptionId = null;
        foreach ($logs AS $log) {
            if ($log->subscriber_id) {
                $subscriptionId = $log->subscriber_id;
                break;
            }
        }

        if (!$subscriptionId) {
            return $controller->error('Could not find a subscriber ID or customer ID for this purchase request.');
        }

        $unSubscribed = false;
        try {
            $unSubscribed = Sdk::unSubscribe($paymentProfile, $subscriptionId);
        } catch (\Exception $e) {
            \XF::logException($e);
        }
        if (!$unSubscribed) {
            throw $controller->exception($controller->error(
                \XF::phrase('this_subscription_cannot_be_cancelled_maybe_already_cancelled')
            ));
        }

        return $controller->redirect(
            $controller->getDynamicRedirect(),
            \XF::phrase('Xfrocks_AuthorizeNetArb_subscription_cancelled_successfully')
        );
    }

    public function processPayment(
        Controller $controller,
        PurchaseRequest $purchaseRequest,
        PaymentProfile $paymentProfile,
        Purchase $purchase
    ) {
        $ppOptions = $paymentProfile->options;
        $opaqueDataJson = $controller->filter('opaque_data', 'str');
        if (empty($opaqueDataJson)) {
            throw $controller->exception($controller->error(\XF::phrase('something_went_wrong_please_try_again')));
        }

        $inputFilters = [];
        if (!empty($ppOptions['require_names'])) {
            $inputFilters['first_name'] = 'str';
            $inputFilters['last_name'] = 'str';
        }
        if (!empty($ppOptions['require_email'])) {
            $inputFilters['email'] = 'str';
        }
        if (!empty($ppOptions['require_address'])) {
            $inputFilters['address'] = 'str';
            $inputFilters['city'] = 'str';
            $inputFilters['state'] = 'str';
            $inputFilters['zip'] = 'str';
        }
        $inputs = $controller->filter($inputFilters);
        foreach (array_keys($inputFilters) as $inputKey) {
            if (!empty($inputs[$inputKey])) {
                continue;
            }

            switch ($inputKey) {
                case 'email':
                    $fieldPhrase = \XF::phrase($inputKey);
                    break;
                default:
                    $fieldPhrase = \XF::phrase('Xfrocks_AuthorizeNetArb_' . $inputKey);
            }

            $phrase = \XF::phrase('please_enter_value_for_required_field_x', ['field' => $fieldPhrase]);
            throw $controller->exception($controller->error($phrase));
        }

        $chargeResult = Sdk::charge($purchaseRequest, $purchase, $opaqueDataJson, $inputs);

        if (!$chargeResult->isOk()) {
            $chargeErrors = $chargeResult->getTransactionErrors();
            if (count($chargeErrors) > 0) {
                $errorPhrase = \XF::phrase('Xfrocks_AuthorizeNetArb_charge_errors_x', [
                    'errors' => implode('</li><li>', $chargeErrors)
                ]);
                throw $controller->exception($controller->error($errorPhrase));
            }

            \XF::logError(implode("\n", $chargeResult->getErrors()));
            throw $controller->exception($controller->error(\XF::phrase('something_went_wrong_please_try_again')));
        }

        /** @var Payment $paymentRepo */
        $paymentRepo = \XF::repository('XF:Payment');
        $paymentRepo->logCallback(
            $purchaseRequest->request_key,
            $this->getProviderId(),
            $chargeResult->getTransId(),
            'info',
            'Authorize.Net charge ok',
            ['charge' => $chargeResult->toArray()]
        );

        if ($purchase->recurring) {
            try {
                $customerProfile = Sdk::createCustomerProfileFromTransaction($paymentProfile, $chargeResult);
                if ($customerProfile->isOk()) {
                    $subscribeResult = Sdk::subscribe($purchaseRequest, $purchase, $customerProfile);

                    if (!$subscribeResult->isOk()) {
                        \XF::logError(implode(', ', $subscribeResult->getErrors()));
                    } else {
                        $paymentRepo->logCallback(
                            $purchaseRequest->request_key,
                            $this->getProviderId(),
                            $chargeResult->getTransId(),
                            'info',
                            'Authorize.Net subscribe ok',
                            [
                                'customerProfile' => $customerProfile->toArray(),
                                'subscribe' => $subscribeResult->toArray()
                            ],
                            $subscribeResult->getSubscriptionId()
                        );
                    }
                }
            } catch (\Exception $e) {
                \XF::logException($e);
            }
        }

        return $controller->redirect($purchase->returnUrl);
    }

    public function renderCancellation(\XF\Entity\UserUpgradeActive $active)
    {
        $data = [
            'active' => $active,
            'purchaseRequest' => $active->PurchaseRequest
        ];

        return \XF::app()->templater()->renderTemplate(
            'public:Xfrocks_AuthorizeNetArb_payment_cancel_recurring',
            $data
        );
    }

    public function setupCallback(\XF\Http\Request $request)
    {
        $state = new CallbackState();

        $headerXAnetSignatureKey = 'X-ANET-Signature';
        $headerXAnetSignatureKey = str_replace('-', '_', $headerXAnetSignatureKey);
        $headerXAnetSignatureKey = strtoupper($headerXAnetSignatureKey);
        /** @noinspection PhpUndefinedFieldInspection */
        $state->headerXAnetSignature = $request->getServer('HTTP_' . $headerXAnetSignatureKey);

        /** @noinspection PhpUndefinedFieldInspection */
        $state->inputRaw = $inputRaw = $request->getInputRaw();
        $input = @json_decode($inputRaw, true);
        $filtered = \XF::app()->inputFilterer()->filterArray($input ?: [], [
            'eventType' => 'str',
            'payload' => 'array',
        ]);

        /** @noinspection PhpUndefinedFieldInspection */
        $state->eventType = $filtered['eventType'];

        /** @noinspection PhpUndefinedFieldInspection */
        $state->payload = $payload = $filtered['payload'];
        if (empty($payload)) {
            return $state;
        }

        switch ($payload['entityName']) {
            case 'transaction':
                $state->transactionId = $payload['id'];
                break;
        }

        return $state;
    }

    public function verifyCurrency(PaymentProfile $paymentProfile, $currencyCode)
    {
        return $currencyCode === 'USD';
    }

    public function validateCallback(CallbackState $state)
    {
        /** @var Payment $paymentRepo */
        $paymentRepo = \XF::repository('XF:Payment');

        $allPaymentProfiles = $paymentRepo->finder('XF:PaymentProfile')
            ->where('active', true)
            ->where('provider_id', $this->getProviderId());
        $paymentProfile = null;

        foreach ($allPaymentProfiles as $_paymentProfile) {
            /** @noinspection PhpUndefinedFieldInspection */
            if (Sdk::verifyWebhookSignature($_paymentProfile, $state->headerXAnetSignature, $state->inputRaw)) {
                $paymentProfile = $_paymentProfile;
                break;
            }
        }

        if ($paymentProfile === null) {
            $state->logType = 'error';
            $state->logMessage = 'Webhook data cannot be trusted / verified.';
            return false;
        }
        $state->paymentProfile = $paymentProfile;

        if ($state->transactionId && !$state->requestKey) {
            $infoLogs = $paymentRepo->findLogsByTransactionId($state->transactionId, 'info');
            foreach ($infoLogs as $infoLog) {
                if ($infoLog->provider_id !== $this->getProviderId()) {
                    continue;
                }

                $state->requestKey = $infoLog->purchase_request_key;
            }
        }

        if (!$state->getPurchaseRequest()) {
            $state->logType = 'error';
            $state->logMessage = 'Purchase request cannot be detected.';
            return false;
        }

        return true;
    }

    public function verifyConfig(array &$options, &$errors = [])
    {
        $requiredOptionKeys = [
            'api_login_id',
            'transaction_key',
            'signature_key',
            'secret_key',
            'public_client_key'
        ];
        foreach ($requiredOptionKeys as $requiredOptionKey) {
            if (empty($options[$requiredOptionKey])) {
                $errors[] = \XF::phrase(
                    'Xfrocks_AuthorizeNetArb_you_must_provide_option_x_to_setup_this_payment',
                    [
                        'option' => \XF::phrase('Xfrocks_AuthorizeNetArb_' . $requiredOptionKey)
                    ]
                );
            }
        }

        try {
            Sdk::assertWebhookExists($options['api_login_id'], $options['transaction_key'], $this->getCallbackUrl());
        } catch (\Exception $e) {
            \XF::logException($e);

            $errors[] = \XF::phrase('Xfrocks_AuthorizeNetArb_cannot_create_webhook', [
                'callbackUrl' => $this->getCallbackUrl()
            ]);
        }

        if (count($errors) > 0) {
            return false;
        }

        return parent::verifyConfig($options, $errors);
    }
}