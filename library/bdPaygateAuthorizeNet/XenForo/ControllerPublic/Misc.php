<?php

class bdPaygateAuthorizeNet_XenForo_ControllerPublic_Misc extends XFCP_bdPaygateAuthorizeNet_XenForo_ControllerPublic_Misc
{
    public function actionAuthorizeNetComplete()
    {
        $viewParams = array();

        return $this->responseView(
            'bdPaygateAuthorizeNet_ViewPublic_Misc_AuthorizeNetComplete',
            'bdpaygate_authnet_misc_complete',
            $viewParams
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
