<?php

namespace USIPS\NCMEC\XF\Pub\Controller;

use USIPS\NCMEC\Service\Incident\ModeratorFlagger;
use USIPS\NCMEC\Service\Incident\ReportFlagger;
use XF\Entity\Report;
use XF\Mvc\ParameterBag;
use XF\Service\Report\CommenterService;

class ReportController extends XFCP_ReportController
{
    public function actionFlagCsam(ParameterBag $params)
    {
        $this->assertPostOnly();

        $report = $this->assertViewableReport($params->report_id, ['Content']);
        $content = $report->Content;

        if (!$content)
        {
            return $this->error(\XF::phrase('requested_content_not_found'));
        }

        /** @var ModeratorFlagger $flagger */
        $flagger = $this->service('USIPS\\NCMEC:Incident\\ModeratorFlagger', $content);
        $incident = $flagger->flag();

        if (!$incident)
        {
            return $this->error(\XF::phrase('usips_ncmec_unable_to_flag_content'));
        }

        return $this->redirect(
            $this->buildLink('reports', $report),
            \XF::phrase('usips_ncmec_report_flagged_as_csam')
        );
    }

    protected function setupReportComment(Report $report)
    {
        $newState = $this->filter('report_state', 'str');
        
        // Handle flag_as_csam before parent processing
        if ($newState === 'flag_as_csam')
        {
            return $this->setupCSAMFlagging($report);
        }
        
        return parent::setupReportComment($report);
    }

    protected function setupCSAMFlagging(Report $report): CommenterService
    {
        /** @var ReportFlagger $flagger */
        $flagger = $this->service('USIPS\\NCMEC:Incident\\ReportFlagger', $report);
        $incident = $flagger->flag();

        /** @var CommenterService $commenter */
        $commenter = $this->service(CommenterService::class, $report);

        if ($incident)
        {
            // Build the CSAM flagging message
            $url = $this->app->router('admin')->buildLink('canonical:ncmec-incidents/view', $incident);
            $title = \XF::escapeString($incident->title);
            $message = sprintf('[B]Flagged as CSAM[/B] in [url="%s"]%s[/url].', $url, $title);
            
            $commenter->setMessage($message, false);
            $commenter->setReportState('resolved');
        }
        else
        {
            // Fallback if flagging failed
            $commenter->setMessage('[B]Failed to flag as CSAM[/B] - please check logs.', false);
            $commenter->setReportState('resolved');
        }

        return $commenter;
    }
}
