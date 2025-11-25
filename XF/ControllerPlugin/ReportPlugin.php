<?php

namespace USIPS\NCMEC\XF\ControllerPlugin;

use XF\Mvc\Entity\Entity;
use XF\Service\Report\CreatorService;
use USIPS\NCMEC\Service\Incident\ModeratorFlagger;

class ReportPlugin extends XFCP_ReportPlugin
{
    protected function setupReportCreate($contentType, Entity $content)
    {
        $reportType = $this->request->filter('report_type', 'str');
        $message = $this->request->filter('message', 'str');

        if ($reportType === 'emergency' && $message === '')
        {
            $this->request->set('message', 'User has submitted report as an emergency.');
        }

        /** @var CreatorService $creator */
        $creator = parent::setupReportCreate($contentType, $content);

        if ($reportType === 'emergency' && method_exists($creator, 'enableEmergencyHandling'))
        {
            $creator->enableEmergencyHandling($content);
        }

        return $creator;
    }

    public function actionReport($contentType, Entity $content, $confirmUrl, $returnUrl, $options = [])
    {
        if ($this->request->isPost() && $this->request->filter('report_type', 'str') === 'flag_as_csam')
        {
            if (!\XF::visitor()->is_moderator)
            {
                throw $this->exception($this->controller->noPermission());
            }

            /** @var ModeratorFlagger $flagger */
            $flagger = $this->service('USIPS\\NCMEC:Incident\\ModeratorFlagger', $content);
            $incident = $flagger->flag();

            if (!$incident)
            {
                throw $this->exception($this->controller->error(\XF::phrase('usips_ncmec_unable_to_flag_content')));
            }

            return $this->controller->redirect(
                $this->app->router('admin')->buildLink('ncmec-incidents/view', $incident),
                \XF::phrase('usips_ncmec_report_flagged_as_csam')
            );
        }

        $options = array_merge([
            'view' => 'XF:Report\\Report',
            'template' => 'usips_ncmec_report_create',
            'extraViewParams' => [],
        ], $options);

        return parent::actionReport($contentType, $content, $confirmUrl, $returnUrl, $options);
    }
}