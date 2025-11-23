<?php

namespace USIPS\NCMEC\Admin\Controller;

use \XF;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class ReportController extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('usips_ncmec');
    }

    protected function assertReportExists($id, $with = null)
    {
        return $this->assertRecordExists('USIPS\NCMEC:Report', $id, $with);
    }

    public function actionIndex(ParameterBag $params)
    {
        $page = $this->filterPage();
        $perPage = 100;
        $state = $this->filter('state', 'str');
        if (!$state)
        {
            $state = 'open';
        }

        $finder = $this->finder('USIPS\NCMEC:Report')
            ->order('created_date', 'DESC')
            ->limitByPage($page, $perPage);

        if ($state === 'archive')
        {
            $finder->where('submitted_on', '!=', null);
        }
        else
        {
            $finder->where('submitted_on', null);
        }

        $total = $finder->total();
        $this->assertValidPage($page, $perPage, $total, 'ncmec-reports');

        $viewParams = [
            'reports' => $finder->fetch(),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'state' => $state,
            'tabs' => [
                'open' => \XF::phrase('open'),
                'archive' => \XF::phrase('usips_ncmec_archive')
            ]
        ];

        return $this->view('USIPS\NCMEC:Report\Listing', 'usips_ncmec_report_list', $viewParams);
    }

    public function actionView(ParameterBag $params)
    {
        $report = $this->assertReportExists($params->report_id, ['User', 'SubjectUser', 'Case']);

        // Gather incidents associated with this report's case/subject
        $incidents = $this->getIncidentsForReport($report);

        $apiLogs = $this->finder('USIPS\NCMEC:ApiLog')
            ->where('report_id', $report->report_id)
            ->order('request_date', 'DESC')
            ->fetch();

        $viewParams = [
            'report' => $report,
            'incidents' => $incidents,
            'apiLogs' => $apiLogs,
        ];

        return $this->view('USIPS\NCMEC:Report\View', 'usips_ncmec_report_view', $viewParams);
    }

    /**
     * Fetch incidents relevant to a report based on its case and subject user.
     */
    protected function getIncidentsForReport(\USIPS\NCMEC\Entity\Report $report)
    {
        if (!$report->case_id)
        {
            return $this->finder('USIPS\NCMEC:Incident')
                ->where('incident_id', 0)
                ->fetch();
        }

        $incidentFinder = $this->finder('USIPS\NCMEC:Incident')
            ->where('case_id', $report->case_id)
            ->with('User')
            ->order('created_date', 'DESC');

        if ($report->subject_user_id)
        {
            $incidentUsers = $this->finder('USIPS\NCMEC:IncidentUser')
                ->where('user_id', $report->subject_user_id)
                ->where('Incident.case_id', $report->case_id)
                ->fetch();

            $incidentIds = $incidentUsers->pluckNamed('incident_id');
            $incidentIds = array_unique($incidentIds);

            if (!$incidentIds)
            {
                return $this->finder('USIPS\NCMEC:Incident')
                    ->where('incident_id', 0)
                    ->fetch();
            }

            $incidentFinder->where('incident_id', $incidentIds);
        }

        return $incidentFinder->fetch();
    }
}
