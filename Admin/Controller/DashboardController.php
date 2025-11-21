<?php

namespace USIPS\NCMEC\Admin\Controller;

use USIPS\NCMEC\Entity\Incident;
use \XF;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

class DashboardController extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('usips_ncmec');
    }

    public function actionIndex(ParameterBag $params)
    {
        $configurer = $this->getConfigurer();
        
        $apiConfigured = $configurer->hasActiveConfig();
        $connectionStatus = false;
        $connectionError = null;
        $environment = null;
        
        if ($apiConfigured)
        {
            $config = $configurer->getConfig();
            $environment = $config['environment'] ?? 'test';
            
            // Test connection
            if (!$configurer->test($error))
            {
                $connectionError = $error;
            }
            else
            {
                $connectionStatus = true;
            }
        }

        // Fetch all non-finalized incidents (limit 50)
        $incidents = $this->finder('USIPS\NCMEC:Incident')
            ->where('finalized_on', null)
            ->order('created_date', 'DESC')
            ->limit(50)
            ->fetch();

        // Fetch last 10 reports
        $reports = $this->finder('USIPS\NCMEC:Report')
            ->order('created_date', 'DESC')
            ->limit(10)
            ->fetch();
        
        $viewParams = [
            'apiConfigured' => $apiConfigured,
            'connectionStatus' => $connectionStatus,
            'connectionError' => $connectionError,
            'environment' => $environment,
            'incidents' => $incidents,
            'reports' => $reports
        ];
        return $this->view('USIPS\NCMEC:Dashboard', 'usips_ncmec_dashboard', $viewParams);
    }

    public function actionConfig()
    {
        if ($this->isPost())
        {
            $config = $this->filter([
                'username' => 'str',
                'password' => 'str',
                'environment' => 'str'
            ]);

            $configurer = $this->getConfigurer($config);

            if (!$configurer->test($error, true))
            {
                return $this->error($error);
            }

            $viewParams = [
                'config' => $config,
                'environment' => $config['environment']
            ];
            return $this->view('USIPS\NCMEC:Config\Confirm', 'usips_ncmec_config_confirm', $viewParams);
        }
        else
        {
            $viewParams = [];
            return $this->view('USIPS\NCMEC:Config', 'usips_ncmec_config', $viewParams);
        }
    }

    public function actionConfigSave()
    {
        $this->assertPostOnly();

        $config = $this->filter([
            'username' => 'str',
            'password' => 'str',
            'environment' => 'str'
        ]);

        $configurer = $this->getConfigurer($config);

        if (!$configurer->test($error, true))
        {
            return $this->error($error);
        }

        $configurer->saveConfig();

        return $this->redirect($this->buildLink('ncmec'));
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

    /**
     * @param array|null $config
     *
     * @return \USIPS\NCMEC\Service\Configurer
     */
    protected function getConfigurer(array $config = null)
    {
        return $this->service('USIPS\NCMEC:Configurer', $config);
    }
}