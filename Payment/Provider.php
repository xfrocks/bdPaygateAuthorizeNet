<?php

namespace Xfrocks\AuthorizeNetArb\Payment;

use XF\Entity\PaymentProfile;
use XF\Entity\PaymentProviderLog;
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

        return \XF::app()->options()->boardUrl . '/payment_callback_authorizenet.php';
    }

    public function getPaymentResult(CallbackState $state)
    {
        if (!empty($state->reversedTransId)) {
            $state->paymentResult = CallbackState::PAYMENT_REVERSED;
            return;
        }

        if (!empty($state->eventType) && $state->eventType === Sdk::WEBHOOK_EVENT_TYPE_AUTH_AND_CAPTURE) {
            $state->paymentResult = CallbackState::PAYMENT_RECEIVED;
            return;
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
        if (empty($state->logDetails)) {
            $state->logDetails = [];
        }

        if (!empty($state->inputRaw)) {
            $state->logDetails['inputRaw'] = $state->inputRaw;
        }

        if (!empty($state->apiTransaction)) {
            $state->logDetails['apiTransaction'] = $state->apiTransaction;
        }
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
        foreach ($logs as $log) {
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

        $chargeOk = ($chargeResult->isOk() && $chargeResult->getResponseCode() === Sdk::RESPONSE_CODE_TRANSACTION_APPROVED);
        $chargeLogType = $chargeOk ? 'info' : 'error';
        /** @var Payment $paymentRepo */
        $paymentRepo = \XF::repository('XF:Payment');
        $paymentRepo->logCallback(
            $purchaseRequest->request_key,
            $this->getProviderId(),
            $chargeResult->getTransId(),
            $chargeLogType,
            'Authorize.Net charge ' . $chargeLogType,
            ['charge' => $chargeResult->toArray()]
        );

        if (!$chargeOk) {
            $chargeErrors = $chargeResult->getTransactionErrors();
            if (count($chargeErrors) > 0) {
                $errorPhrase = \XF::phrase('Xfrocks_AuthorizeNetArb_charge_errors_x', [
                    'errors' => implode('</li><li>', $chargeErrors)
                ]);
            } else {
                $errorPhrase = \XF::phrase('something_went_wrong_please_try_again');
            }

            throw $controller->exception($controller->error($errorPhrase));
        }
        /** @var Sdk\ChargeResult $chargeResultOk */
        $chargeResultOk = $chargeResult;

        if ($purchase->recurring) {
            $subscribeLogType = 'error';
            $subscribeLogDetails = [];
            $subscribeLogSubId = null;

            try {
                $customerProfile = Sdk::createCustomerProfileFromTransaction($paymentProfile, $chargeResultOk);
                $subscribeLogDetails['customerProfile'] = $customerProfile->toArray();

                if ($customerProfile->isOk()) {
                    /** @var Sdk\CreateCustomerProfileResult $customerProfileOk */
                    $customerProfileOk = $customerProfile;
                    $subscribeResult = Sdk::subscribe($purchaseRequest, $purchase, $customerProfileOk);
                    $subscribeLogDetails['subscribe'] = $subscribeResult->toArray();

                    if ($subscribeResult->isOk()) {
                        /** @var Sdk\SubscribeResult $subscribeResultOk */
                        $subscribeResultOk = $subscribeResult;
                        $subscribeLogSubId = $subscribeResultOk->getSubscriptionId();
                        $subscribeLogType = 'info';
                    }
                }
            } catch (\Exception $e) {
                \XF::logException($e, false, '', true);
            }

            $paymentRepo->logCallback(
                $purchaseRequest->request_key,
                $this->getProviderId(),
                $chargeResult->getTransId(),
                $subscribeLogType,
                'Authorize.Net subscribe ' . $subscribeLogType,
                $subscribeLogDetails,
                $subscribeLogSubId
            );
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

        if (empty($filtered['payload']) || empty($filtered['payload']['entityName'])) {
            return $state;
        }
        switch ($filtered['payload']['entityName']) {
            case 'transaction':
                if (!empty($filtered['payload']['authAmount'])) {
                    /** @noinspection PhpUndefinedFieldInspection */
                    $state->authAmount = $filtered['payload']['authAmount'];
                }

                $state->transactionId = $filtered['payload']['id'];
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

            // this is required for webhook creation
            $state->httpCode = 200;

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

                if (!empty($state->eventType) && $state->eventType === Sdk::WEBHOOK_EVENT_TYPE_VOID) {
                    /** @noinspection PhpUndefinedFieldInspection */
                    $state->reversedTransId = $state->transactionId;
                    $state->transactionId .= ':voided';
                }
            }
        }

        if ($state->transactionId && !$state->requestKey) {
            $transaction = Sdk::getTransactionDetails($paymentProfile, $state->transactionId);
            if ($transaction->isOk()) {
                /** @noinspection PhpUndefinedFieldInspection */
                $state->apiTransaction = $transaction->toArray();
                /** @var Sdk\GetTransactionDetailsResult $transactionOk */
                $transactionOk = $transaction;

                $subscriptionId = $transactionOk->getSubscriptionId();
                if (!empty($subscriptionId)) {
                    $state->subscriberId = $subscriptionId;

                    /** @var PaymentProviderLog|null $providerLog */
                    $providerLog = \XF::em()->findOne('XF:PaymentProviderLog', [
                        'subscriber_id' => $subscriptionId,
                        'provider_id' => $this->getProviderId()
                    ]);
                    if ($providerLog !== null) {
                        $state->requestKey = $providerLog->purchase_request_key;
                    }
                }

                if (!$state->requestKey) {
                    $reversedTransId = $transactionOk->getReversedTransId();

                    if (!empty($reversedTransId)) {
                        $infoLogs = $paymentRepo->findLogsByTransactionId($reversedTransId, 'info');
                        foreach ($infoLogs as $infoLog) {
                            if ($infoLog->provider_id !== $this->getProviderId()) {
                                continue;
                            }

                            /** @noinspection PhpUndefinedFieldInspection */
                            $state->reversedTransId = $reversedTransId;

                            $state->requestKey = $infoLog->purchase_request_key;
                        }
                    }
                }

                if (!$state->requestKey) {
                    // Authorize.Net does not mark the first transaction for ARB as recurring billing
                    // we have to rely on the invoice number to process it
                    $invoiceNumber = $transactionOk->getInvoiceNumber();

                    if (!empty($invoiceNumber) && preg_match('/^(\d+)(:\d+)?$/', $invoiceNumber, $matches)) {
                        $purchaseRequestId = $matches[1];

                        /** @var PurchaseRequest|null $purchaseRequest */
                        $purchaseRequest = \XF::em()->find('XF:PurchaseRequest', $purchaseRequestId);

                        if ($purchaseRequest !== null) {
                            $state->purchaseRequest = $purchaseRequest;
                        }
                    }
                }
            }
        }

        if (!$state->getPurchaseRequest()) {
            $state->logType = 'error';
            $state->logMessage = 'Purchase request cannot be detected.';
            return false;
        }

        return true;
    }

    public function validateCost(CallbackState $state)
    {
        if (empty($state->eventType)) {
            $state->logType = 'error';
            $state->logMessage = 'Missing event type';
            return false;
        }

        switch ($state->eventType) {
            case Sdk::WEBHOOK_EVENT_TYPE_AUTH_AND_CAPTURE:
                if (empty($state->authAmount)) {
                    $state->logType = 'error';
                    $state->logMessage = 'Missing auth amount';
                    return false;
                }

                $purchaseRequestAmount = floatval($state->getPurchaseRequest()->cost_amount);
                $amountDelta = abs($state->authAmount - $purchaseRequestAmount);

                if ($amountDelta > 0.01) {
                    $state->logType = 'error';
                    $state->logMessage = 'Invalid cost amount';
                    return false;
                }
                break;
        }

        return true;
    }

    public function verifyConfig(array &$options, &$errors = [])
    {
        if (!is_array($errors)) {
            $errors = [];
        }

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
