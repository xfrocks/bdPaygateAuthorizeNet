<?php

class bdPaygateAuthorizeNet_XenForo_ControllerPublic_Misc extends XFCP_bdPaygateAuthorizeNet_XenForo_ControllerPublic_Misc
{
    public function actionAuthorizeNetComplete()
    {
        $xCustom = $this->_input->filterSingle('x_custom', XenForo_Input::STRING);

        if (!empty($xCustom)) {
            $cookiePrefix = XenForo_Application::get('config')->cookie->prefix;
            $cookieName = $cookiePrefix . 'authnet_' . $xCustom;
            $returnUrl = $this->_request->getCookie($cookieName);
            if (!empty($returnUrl)) {
                return $this->responseRedirect(
                    XenForo_ControllerResponse_Redirect::SUCCESS,
                    $returnUrl
                );
            }
        }

        return $this->responseView(
            'bdPaygateAuthorizeNet_ViewPublic_Misc_AuthorizeNetComplete',
            'bdpaygate_authnet_misc_complete'
        );
    }

    protected function _checkCsrf($action)
    {
        if ($action == 'AuthorizeNetComplete') {
            // may be coming from external payment gateway
            return;
        }

        parent::_checkCsrf($action);
    }
}
