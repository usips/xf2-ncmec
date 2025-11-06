<?php

namespace USIPS\NCMEC\XF\ControllerPlugin;

use XF\Mvc\Entity\Entity;
use XF\Service\Report\CreatorService;

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
        $options = array_merge([
            'view' => 'XF:Report\\Report',
            'template' => 'usips_ncmec_report_create',
            'extraViewParams' => [],
        ], $options);

        return parent::actionReport($contentType, $content, $confirmUrl, $returnUrl, $options);
    }
}