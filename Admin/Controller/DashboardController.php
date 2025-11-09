<?php

namespace USIPS\NCMEC\Admin\Controller;

use USIPS\NCMEC\Entity\Incident;
use \XF;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class DashboardController extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('usips_ncmec');
    }

    public function actionIndex(ParameterBag $params)
    {
        $viewParams = [];
        return $this->view('USIPS\NCMEC:Dashboard', 'usips_ncmec_dashboard', $viewParams);
    }

    public function actionRegistrationToggle()
    {
        $redirect = $this->getDynamicRedirect($this->buildLink('ncmec'), false);

        if ($this->isPost())
        {
            /** @var \XF\Repository\Option $optionRepo */
            $optionRepo = $this->repository('XF:Option');

            $registrationSetup = \XF::options()->registrationSetup;
            $isEnabled = !empty($registrationSetup['enabled']);

            // Toggle the state
            $registrationSetup['enabled'] = !$isEnabled;
            $optionRepo->updateOption('registrationSetup', $registrationSetup);

            $message = $registrationSetup['enabled'] 
                ? \XF::phrase('registration_enabled')
                : \XF::phrase('registration_disabled');

            $reply = $this->redirect($redirect, $message);
            $reply->setJsonParam('switchKey', $registrationSetup['enabled'] ? 'disable' : 'enable');
            return $reply;
        }
        else
        {
            return $this->error(\XF::phrase('this_action_is_only_available_via_post'));
        }
    }

    public function actionInviteOnlyToggle()
    {
        $redirect = $this->getDynamicRedirect($this->buildLink('ncmec'), false);

        if ($this->isPost())
        {
            /** @var \XF\Repository\Option $optionRepo */
            $optionRepo = $this->repository('XF:Option');

            $isInviteOnly = \XF::options()->siropuReferralContestsInvitationOnly;

            // Toggle the state
            $optionRepo->updateOption('siropuReferralContestsInvitationOnly', !$isInviteOnly);

            $message = !$isInviteOnly
                ? \XF::phrase('invite_only_enabled')
                : \XF::phrase('invite_only_disabled');

            $reply = $this->redirect($redirect, $message);
            $reply->setJsonParam('switchKey', !$isInviteOnly ? 'disable' : 'enable');
            return $reply;
        }
        else
        {
            return $this->error(\XF::phrase('this_action_is_only_available_via_post'));
        }
    }
}