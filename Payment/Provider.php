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

    public function processPayment(
        Controller $controller,
        PurchaseRequest $purchaseRequest,
        PaymentProfile $paymentProfile,
        Purchase $purchase
    ) {
        $opaqueDataJson = $controller->filter('opaque_data', 'str');
        $inputs = $controller->filter([
            'email' => 'str',
            'address' => 'str',
            'city' => 'str',
            'state' => 'str',
        ]);

        /** @var Payment $paymentRepo */
        $paymentRepo = \XF::repository('XF:Payment');
        $transactionId = null;
        $subId = null;

        $chargeResult = Sdk::charge($paymentProfile, $purchase, $opaqueDataJson, $inputs);

        if (!$chargeResult->isOk()) {
            /** @var Sdk\ChargeError $chargeError */
            $chargeError = $chargeResult;
            \XF::logError(implode("\n", $chargeError->getErrors()), true);
            throw $controller->exception($controller->error(\XF::phrase('something_went_wrong_please_try_again')));
        }

        /** @var Sdk\ChargeOk $chargeOk */
        $chargeOk = $chargeResult;
        $transactionId = $chargeOk->getTransactionId();

        $paymentRepo->logCallback(
            $purchaseRequest->request_key,
            $this->providerId,
            $transactionId,
            'info',
            'Authorize.Net charge ok',
            [
                'charge' => $chargeOk->toArray()
            ],
            $subId
        );

        return $controller->redirect($purchase->returnUrl);
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