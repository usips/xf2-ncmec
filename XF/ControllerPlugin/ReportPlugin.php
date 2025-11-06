<?php

namespace USIPS\NCMEC\XF\ControllerPlugin;

use XF\Mvc\Entity\Entity;
use XF\Service\Report\CreatorService;

class ReportPlugin extends XFCP_ReportPlugin
{
    protected function setupReportCreate($contentType, \XF\Mvc\Entity\Entity $content)
    {
        $reportType = $this->request->filter('report_type', 'str');

        if ($reportType === 'emergency')
        {
            echo "emergency hook works";
            exit;
        }

        // If report_type is 'standard' or not set, proceed with standard logic
        return parent::setupReportCreate($contentType, $content);
    }

    public function actionReport($contentType, Entity $content, $confirmUrl, $returnUrl, $options = [])
    {
        $options = array_merge([
            'view' => 'XF:Report\Report',
            'template' => 'usips_ncmec_report_create',
            'extraViewParams' => [],
        ], $options);

        return parent::actionReport($contentType, $content, $confirmUrl, $returnUrl, $options);
    }
}