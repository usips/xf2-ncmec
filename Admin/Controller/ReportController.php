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
                $visitor = \XF::visitor();

                $report->bulkSet([
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

            return $this->redirect($this->buildLink('ncmec-reports/view', $report), XF::phrase('changes_saved'));
        }

        $viewParams = [
            'incidents' => $incidents,
            'availableReports' => $availableReports,
            'incidentIds' => $incidentIds,
        ];

        return $this->view('USIPS\NCMEC:Report\Assign', 'usips_ncmec_report_assign', $viewParams);
    }

    public function actionView(ParameterBag $params)
    {
        $report = $this->assertReportExists($params->report_id, 'User');

        // Manually load TO_MANY relation
        $incidents = $this->finder('USIPS\NCMEC:Incident')
            ->where('report_id', $report->report_id)
            ->with('User')
            ->order('created_date', 'DESC')
            ->fetch();

        $report->hydrateRelation('Incidents', $incidents);

        $viewParams = [
            'report' => $report,
        ];

        return $this->view('USIPS\NCMEC:Report\View', 'usips_ncmec_report_view', $viewParams);
    }

    public function actionEdit(ParameterBag $params)
    {
        $report = $this->assertReportExists($params->report_id);

        if ($report->is_finished)
        {
            return $this->error(XF::phrase('usips_ncmec_cannot_edit_finished_report'));
        }

        /** @var \USIPS\NCMEC\Service\Api\Client $apiClient */
        $apiClient = $this->app()->service('USIPS\NCMEC:Api\Client', '', '', 'test');
        $annotationLabels = $apiClient::REPORT_ANNOTATION_LABELS;

        if ($this->isPost())
        {
            $form = $this->formAction();
            $form->basicEntitySave($report, $this->filter([
                'report_annotations' => 'array-str',
            ]));

            $report->last_update_date = \XF::$time;
            $form->run();

            return $this->redirect($this->buildLink('ncmec-reports/view', $report));
        }

        $viewParams = [
            'report' => $report,
            'annotationLabels' => $annotationLabels,
        ];

        return $this->view('USIPS\NCMEC:Report\Edit', 'usips_ncmec_report_edit', $viewParams);
    }

    public function actionStep1(ParameterBag $params)
    {
        $report = $this->assertReportExists($params->report_id);

        if ($report->is_finished)
        {
            return $this->error(XF::phrase('usips_ncmec_cannot_edit_finished_report'));
        }

        /** @var \USIPS\NCMEC\Service\Api\Client $apiClient */
        $apiClient = $this->app()->service('USIPS\NCMEC:Api\Client', '', '', 'test');
        $incidentTypeLabels = $apiClient::INCIDENT_TYPE_VALUES;
        $annotationLabels = $apiClient::REPORT_ANNOTATION_LABELS;

        // Load incidents manually
        $incidents = $this->finder('USIPS\NCMEC:Incident')
            ->where('report_id', $report->report_id)
            ->fetch();

        // Calculate earliest incident date/time from all associated incident content
        $earliestDateTime = null;
        foreach ($incidents as $incident)
        {
            $incidentContents = $this->finder('USIPS\NCMEC:IncidentContent')
                ->where('incident_id', $incident->incident_id)
                ->fetch();

            foreach ($incidentContents as $incidentContent)
            {
                $content = $incidentContent->getContent();
                if ($content)
                {
                    $contentDate = $this->getContentDate($content);
                    if ($contentDate && ($earliestDateTime === null || $contentDate < $earliestDateTime))
                    {
                        $earliestDateTime = $contentDate;
                    }
                }
            }
        }

        // Default to report created date if no content found
        if ($earliestDateTime === null)
        {
            $earliestDateTime = $report->created_date;
        }

        if ($this->isPost())
        {
            $input = $this->filter([
                'incident_summary' => [
                    'incident_type' => 'str',
                    'report_annotations' => 'array-str',
                    'incident_date_time_desc' => 'str',
                ],
            ]);

            $incidentSummary = $input['incident_summary'];

            if (empty($incidentSummary['incident_type']))
            {
                return $this->error(XF::phrase('please_select_valid_option'));
            }

            // Save to entity
            $report->bulkSet([
                'incident_type' => $incidentSummary['incident_type'],
                'report_annotations' => $incidentSummary['report_annotations'],
                'incident_date_time_desc' => $incidentSummary['incident_date_time_desc'],
                'last_update_date' => \XF::$time,
            ]);
            $report->save();

            // TODO: Redirect to step 2 when implemented
            return $this->redirect($this->buildLink('ncmec-reports/view', $report), XF::phrase('changes_saved'));
        }

        $viewParams = [
            'report' => $report,
            'incidentTypeLabels' => $incidentTypeLabels,
            'annotationLabels' => $annotationLabels,
            'earliestDateTime' => $earliestDateTime,
        ];

        return $this->view('USIPS\NCMEC:Report\Step1', 'usips_ncmec_report_step1', $viewParams);
    }

    /**
     * Get the date from a content entity
     * Tries various common date fields
     */
    protected function getContentDate($entity)
    {
        if (!$entity)
        {
            return null;
        }

        $candidateFields = [
            'post_date',
            'message_date',
            'media_date',
            'album_date',
            'comment_date',
            'discussion_open',
            'publish_date',
            'creation_date',
            'created_date',
            'date',
        ];

        foreach ($candidateFields as $field)
        {
            if ($entity->isValidColumn($field))
            {
                $value = (int) $entity->get($field);
                if ($value)
                {
                    return $value;
                }
            }
        }

        return null;
    }
}
