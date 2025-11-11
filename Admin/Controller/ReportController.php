<?php

namespace USIPS\NCMEC\Admin\Controller;

use USIPS\NCMEC\Entity\Incident;
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

        $finder = $this->finder('USIPS\NCMEC:Report')
            ->order('created_date', 'DESC')
            ->limitByPage($page, $perPage);

        $total = $finder->total();
        $this->assertValidPage($page, $perPage, $total, 'ncmec-reports');

        $viewParams = [
            'reports' => $finder->fetch(),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
        ];

        return $this->view('USIPS\NCMEC:Report\Listing', 'usips_ncmec_report_list', $viewParams);
    }

    public function actionArchive(ParameterBag $params)
    {
        $page = $this->filterPage();
        $perPage = 100;

        $finder = $this->finder('USIPS\NCMEC:Report')
            ->where('is_finished', true)
            ->order('created_date', 'DESC')
            ->limitByPage($page, $perPage);

        $total = $finder->total();
        $this->assertValidPage($page, $perPage, $total, 'ncmec-reports/archive');

        $viewParams = [
            'reports' => $finder->fetch(),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
        ];

        return $this->view('USIPS\NCMEC:Report\Listing', 'usips_ncmec_report_list_archive', $viewParams);
    }

    public function actionCreate(ParameterBag $params)
    {
        $incidentIds = $this->filter('incident_ids', 'array-int');
        $incidentIds = array_values(array_unique(array_filter($incidentIds)));

        if (!$incidentIds)
        {
            return $this->error(XF::phrase('please_select_valid_option'));
        }

        $incidents = $this->finder('USIPS\NCMEC:Incident')
            ->where('incident_id', $incidentIds)
            ->fetch();

        if (!$incidents->count() || $incidents->count() !== count($incidentIds))
        {
            return $this->error(XF::phrase('requested_page_not_found'));
        }

        if ($incidents->filter(function(Incident $incident) { return $incident->is_finalized; })->count())
        {
            return $this->error(XF::phrase('usips_ncmec_incident_finalized_cannot_delete'));
        }

        /** @var \USIPS\NCMEC\Repository\ReportRepository $reportRepo */
        $reportRepo = $this->repository('USIPS\NCMEC:Report');
        $availableReports = $reportRepo->getOpenReports();

        if ($this->isPost())
        {
            $input = $this->filter([
                'assignment_type' => 'str',
                'existing_report_id' => 'uint',
            ]);

            $assignmentType = $input['assignment_type'] ?: 'new';
            $report = null;

            if ($assignmentType === 'existing')
            {
                if (!$input['existing_report_id'])
                {
                    return $this->error(XF::phrase('please_select_valid_option'));
                }

                $report = $this->em()->find('USIPS\NCMEC:Report', $input['existing_report_id']);
                if (!$report || $report->is_finished)
                {
                    return $this->error(XF::phrase('requested_page_not_found'));
                }
            }
            else
            {
                /** @var \USIPS\NCMEC\Entity\Report $report */
                $report = $this->em()->create('USIPS\NCMEC:Report');
                $reportId = $reportRepo->getNextReportId();
                $visitor = \XF::visitor();

                $report->bulkSet([
                    'report_id' => $reportId,
                    'user_id' => $visitor->user_id,
                    'username' => $visitor->username,
                ]);
                $report->save();
            }

            foreach ($incidents as $incident)
            {
                $incident->report_id = $report->report_id;
                $incident->last_update_date = \XF::$time;
                $incident->save();
            }

            return $this->redirect($this->buildLink('ncmec-incidents'), XF::phrase('changes_saved'));
        }

        $viewParams = [
            'incidents' => $incidents,
            'availableReports' => $availableReports,
            'incidentIds' => $incidentIds,
        ];

        return $this->view('USIPS\NCMEC:Report\Assign', 'usips_ncmec_report_assign', $viewParams);
    }
}
