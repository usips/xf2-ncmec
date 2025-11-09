<?php

namespace USIPS\NCMEC\XF\Pub\Controller;

use USIPS\NCMEC\Service\Incident\ModeratorFlagger;
use XF\Mvc\ParameterBag;

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
}
