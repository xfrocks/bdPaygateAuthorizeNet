<?php

class bdPaygateAuthorizeNet_Processor extends bdPaygate_Processor_Abstract
{
    public function getSupportedCurrencies()
    {
        return array(
            bdPaygate_Processor_Abstract::CURRENCY_USD,
        );
    }

    public function isRecurringSupported()
    {
        return false;
    }

    public function validateCallback(
        Zend_Controller_Request_Http $request,
        &$transactionId,
        &$paymentStatus,
        &$transactionDetails,
        &$itemId
    ) {
        $amount = false;
        $currency = false;

        return $this->validateCallback2(
            $request,
            $transactionId,
            $paymentStatus,
            $transactionDetails,
            $itemId,
            $amount,
            $currency
        );
    }

    public function validateCallback2(
        Zend_Controller_Request_Http $request,
        &$transactionId,
        &$paymentStatus,
        &$transactionDetails,
        &$itemId,
        &$amount,
        &$currency
    ) {
        $input = new XenForo_Input($request);
        $filtered = $input->filter(array(
            'x_amount' => XenForo_Input::STRING,
            'x_trans_id' => XenForo_Input::STRING,
            'x_description' => XenForo_Input::STRING,
            'x_invoice_num' => XenForo_Input::STRING,
            'x_MD5_Hash' => XenForo_Input::STRING,
            'x_response_code' => XenForo_Input::UNUM,
            'x_response_reason_text' => XenForo_Input::STRING,
            'x_response_reason_code' => XenForo_Input::UINT,
            'x_custom' => XenForo_Input::STRING
        ));

        $transactionId = (!empty($filtered['x_trans_id']) ? ('authnet_' . $filtered['x_trans_id']) : '');
        $paymentStatus = bdPaygate_Processor_Abstract::PAYMENT_STATUS_OTHER;
        $transactionDetails = array_merge($_POST, $filtered);
        $itemId = $filtered['x_custom'];
        $amount = $filtered['x_amount'];
        $currency = 'n/a';

        /** @var bdPaygate_Model_Processor $processorModel */
        $processorModel = $this->getModelFromCache('bdPaygate_Model_Processor');
        $options = XenForo_Application::getOptions();

        $log = $processorModel->getLogByTransactionId($transactionId);
        if (!empty($log)) {
            $this->_setError("Transaction {$transactionId} has already been processed");
            return false;
        }

        $hash = strtoupper(md5(
            $options->get('bdPaygateAuthorizeNet_md5hash')
            . $options->get('bdPaygateAuthorizeNet_id')
            . $filtered['x_trans_id']
            . $filtered['x_amount']
        ));

        if ($hash != $filtered['x_MD5_Hash']) {
            $this->_setError('Request not validated');
            return false;
        }

        // according to http://www.authorize.net/support/merchant/Transaction_Response/Response_Reason_Codes_and_Response_Reason_Text.htm
        switch ($filtered['x_response_code']) {
            case 1:
                // This transaction has been approved.
                $paymentStatus = bdPaygate_Processor_Abstract::PAYMENT_STATUS_ACCEPTED;
                break;
            default:
                $paymentStatus = bdPaygate_Processor_Abstract::PAYMENT_STATUS_REJECTED;
        }

        return true;
    }

    public function generateFormData(
        $amount,
        $currency,
        $itemName,
        $itemId,
        $recurringInterval = false,
        $recurringUnit = false,
        array $extraData = array()
    ) {
        $this->_assertAmount($amount);
        $this->_assertCurrency($currency);
        $this->_assertItem($itemName, $itemId);
        $this->_assertRecurring($recurringInterval, $recurringUnit);

        $formAction = $this->_sandboxMode()
            ? 'https://test.authorize.net/gateway/transact.dll'
            : 'https://secure.authorize.net/gateway/transact.dll';
        $callToAction = new XenForo_Phrase('bdpaygateauthorizenet_call_to_action');

        $options = XenForo_Application::getOptions();
        $id = $options->get('bdPaygateAuthorizeNet_id');
        $key = $options->get('bdPaygateAuthorizeNet_key');
        $sequence = rand(0, 1000);
        $timestamp = XenForo_Application::$time;
        $currencyAuthorizeNet = utf8_strtoupper($currency);
        $callbackUrl = $this->_generateCallbackUrl($extraData);

        if (strpos($callbackUrl, '?') === false) {
            $callbackUrl .= '?';
        } else {
            $callbackUrl .= '&';
        }
        $callbackUrl .= 'returnUrl=' . rawurlencode($this->_generateReturnUrl($extraData));

        $hashParts = array(
            $id,
            $sequence,
            $timestamp,
            $amount,
            $currencyAuthorizeNet
        );
        $hash = hash_hmac('md5', implode('^', $hashParts), $key);

        $form = <<<EOF
<form action="{$formAction}" method="POST">
	<input type="hidden" name="x_login" value="{$id}" />
	<input type="hidden" name="x_fp_sequence" value="{$sequence}" />
	<input type="hidden" name="x_fp_timestamp" value="{$timestamp}" />
	<input type="hidden" name="x_show_form" value="PAYMENT_FORM" />
	<input type="hidden" name="x_amount" value="{$amount}" />
	<input type="hidden" name="x_currency_code" value="{$currencyAuthorizeNet}" />
	<input type="hidden" name="x_custom" value="{$itemId}" />
	<input type="hidden" name="x_description" value="{$itemName}" />
	<input type="hidden" name="x_relay_response" value="TRUE" />
	<input type="hidden" name="x_relay_url" value="{$callbackUrl}" />
	<input type="hidden" name="x_fp_hash" value="{$hash}" />
	
	<input type="submit" value="{$callToAction}" class="button" />
</form>
EOF;

        return $form;
    }

    public function redirectOnCallback(Zend_Controller_Request_Http $request, $paymentStatus, $processMessage)
    {
        // for Authorize.Net relay response architecture, always redirect back to index page
        // TODO: find a better way to do this?
        if ($paymentStatus == bdPaygate_Processor_Abstract::PAYMENT_STATUS_ACCEPTED) {
            $returnUrl = XenForo_Link::buildPublicLink('canonical:misc/authorize-net-complete');

            if (isset($_REQUEST['returnUrl'])) {
                $returnUrl = $_REQUEST['returnUrl'];
            }

            echo sprintf('<meta http-equiv="refresh" content="0;url=%s"/>', $returnUrl);
            return true;
        }

        $input = new XenForo_Input($request);
        $filtered = $input->filter(array(
            'x_response_reason_text' => XenForo_Input::STRING,
        ));
        echo $filtered['x_response_reason_text'];
        exit;
    }
}
