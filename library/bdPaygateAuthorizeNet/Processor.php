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
            'x_trans_id' => XenForo_Input::STRING,
            'x_test_request' => XenForo_Input::STRING,
            'x_response_code' => XenForo_Input::STRING,
            'x_auth_code' => XenForo_Input::STRING,
            'x_cvv2_resp_code' => XenForo_Input::STRING,
            'x_cavv_response' => XenForo_Input::STRING,
            'x_avs_code' => XenForo_Input::STRING,
            'x_method' => XenForo_Input::STRING,
            'x_account_number' => XenForo_Input::STRING,
            'x_amount' => XenForo_Input::STRING,
            'x_company' => XenForo_Input::STRING,
            'x_first_name' => XenForo_Input::STRING,
            'x_last_name' => XenForo_Input::STRING,
            'x_address' => XenForo_Input::STRING,
            'x_city' => XenForo_Input::STRING,
            'x_state' => XenForo_Input::STRING,
            'x_zip' => XenForo_Input::STRING,
            'x_country' => XenForo_Input::STRING,
            'x_phone' => XenForo_Input::STRING,
            'x_fax' => XenForo_Input::STRING,
            'x_email' => XenForo_Input::STRING,
            'x_ship_to_company' => XenForo_Input::STRING,
            'x_ship_to_first_name' => XenForo_Input::STRING,
            'x_ship_to_last_name' => XenForo_Input::STRING,
            'x_ship_to_address' => XenForo_Input::STRING,
            'x_ship_to_city' => XenForo_Input::STRING,
            'x_ship_to_state' => XenForo_Input::STRING,
            'x_ship_to_zip' => XenForo_Input::STRING,
            'x_ship_to_country' => XenForo_Input::STRING,
            'x_invoice_num' => XenForo_Input::STRING,
        ));
        $xSha2Hash = $input->filterSingle('x_SHA2_Hash', XenForo_Input::STRING);
        $xCustom = $input->filterSingle('x_custom', XenForo_Input::STRING);

        $transactionId = (!empty($filtered['x_trans_id']) ? ('authnet_' . $filtered['x_trans_id']) : '');
        $paymentStatus = bdPaygate_Processor_Abstract::PAYMENT_STATUS_OTHER;
        $transactionDetails = array_merge($_POST, $filtered);
        $itemId = $xCustom;
        $amount = $filtered['x_amount'];
        $currency = 'n/a';
        $options = XenForo_Application::getOptions();

        $hashString = '^' . implode('^', $filtered) . '^';
        $signatureKey = $options->get('bdPaygateAuthorizeNet_signatureKey');
        $hash = strtoupper(hash_hmac('sha512', $hashString, hex2bin($signatureKey)));
        if ($hash !== $xSha2Hash) {
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
        $signatureKey = $options->get('bdPaygateAuthorizeNet_signatureKey');
        $sequence = rand(0, 1000);
        $timestamp = XenForo_Application::$time;
        $currencyAuthorizeNet = utf8_strtoupper($currency);
        $callbackUrl = $this->_generateCallbackUrl($extraData);

        $returnUrlCookieName = 'authnet_' . $itemId;
        XenForo_Helper_Cookie::setCookie($returnUrlCookieName, $this->_generateReturnUrl($extraData));

        $hashString = "{$id}^{$sequence}^{$timestamp}^{$amount}^{$currencyAuthorizeNet}";
        $hash = strtoupper(hash_hmac('sha512', $hashString, hex2bin($signatureKey)));

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
            $params = array();
            if (!empty($_POST['x_custom'])) {
                $params['x_custom'] = $_POST['x_custom'];
            }

            $returnUrl = XenForo_Link::buildPublicLink('canonical:misc/authorize-net-complete', null, $params);

            echo sprintf('<meta http-equiv="refresh" content="0;url=%s"/>', $returnUrl);
            return true;
        }

        $input = new XenForo_Input($request);
        $filtered = $input->filter(array('x_response_reason_text' => XenForo_Input::STRING));
        echo $filtered['x_response_reason_text'];
        exit;
    }

    protected function _generateCallbackUrl(array $extraData)
    {
        return XenForo_Application::getOptions()->get('boardUrl') . '/bdpaygate/authnet.php';
    }
}
