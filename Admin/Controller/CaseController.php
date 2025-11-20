<?php

namespace USIPS\NCMEC\Admin\Controller;

use \XF;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

use USIPS\NCMEC\Service\Api\Client;

class CaseController extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('usips_ncmec');
    }

    protected function assertCaseExists($id, $with = null)
    {
        return $this->assertRecordExists('USIPS\NCMEC:CaseFile', $id, $with);
    }

    /**
     * Shared logic for saving case data from form input
     */
    protected function saveCaseFromInput(\USIPS\NCMEC\Entity\CaseFile $case)
    {
        $input = $this->filter([
            'title' => 'str',
            'incident_type' => 'str',
            'report_annotations' => 'array-str',
            'incident_date_time_desc' => 'str',
            'reporter_person_id' => 'uint',
            'reported_person_id' => 'uint',
            'reported_additional_info' => 'str',
            'additional_info' => 'str',
        ]);

        $case->bulkSet($input);
        $case->save();

        return $case;
    }

    /**
     * Validate that case has all required fields for NCMEC submission
     * 
     * @param \USIPS\NCMEC\Entity\CaseFile $case
     * @return array Array of error messages (empty if valid)
     */
    protected function validateCaseForFinalization(\USIPS\NCMEC\Entity\CaseFile $case)
    {
        $errors = [];

        // Check incident_type (required by NCMEC API)
        if (empty($case->incident_type))
        {
            $errors[] = \XF::phrase('usips_ncmec_error_incident_type_required')->render();
        }

        // Check reporter_person_id (required for reportingPerson)
        if (empty($case->reporter_person_id))
        {
            $errors[] = \XF::phrase('usips_ncmec_error_reporter_person_required')->render();
        }

        // Check that case has at least one incident
        $incidentCount = $this->finder('USIPS\NCMEC:Incident')
            ->where('case_id', $case->case_id)
            ->total();

        if ($incidentCount === 0)
        {
            $errors[] = \XF::phrase('usips_ncmec_error_no_incidents')->render();
        }

        return $errors;
    }

    public function actionEdit(ParameterBag $params)
    {
        $case = $this->assertCaseExists($params->case_id);

        if (!$case->canEdit($error))
        {
            return $this->error($error);
        }

        if ($this->isPost())
        {
            $this->saveCaseFromInput($case);
            return $this->redirect($this->buildLink('ncmec-cases/view', $case));
        }

        // Calculate incident date time range from incidents
        $earliest = null;
        $latest = null;

        $incidents = $this->finder('USIPS\NCMEC:Incident')
            ->where('case_id', $case->case_id)
            ->fetch();

        foreach ($incidents as $incident)
        {
            // We need to look at the content associated with the incident to find the dates
            // But for now, let's use the incident creation date as a proxy if content dates aren't easily available
            // Or better, let's try to get content dates if possible.
            // The user said "incidentDateTime (mandatory, form should show a static field which represents the EARLIEST of associated Incident.Content)"
            
            $contents = $this->finder('USIPS\NCMEC:IncidentContent')
                ->where('incident_id', $incident->incident_id)
                ->fetch();

            foreach ($contents as $content)
            {
                $entity = $content->getContent();
                if ($entity && isset($entity->post_date))
                {
                    $date = $entity->post_date;
                    if ($earliest === null || $date < $earliest) $earliest = $date;
                    if ($latest === null || $date > $latest) $latest = $date;
                }
                elseif ($entity && isset($entity->message_date)) // Profile posts
                {
                    $date = $entity->message_date;
                    if ($earliest === null || $date < $earliest) $earliest = $date;
                    if ($latest === null || $date > $latest) $latest = $date;
                }
                // Fallback to incident creation date if no content date found?
            }
        }

        // If no content dates found, maybe use case creation date?
        if ($earliest === null)
        {
            $earliest = $case->created_date;
        }
        if ($latest === null)
        {
            $latest = $case->created_date;
        }

        $incidentDateTime = $earliest;
        
        // Auto-fill description if empty
        if (empty($case->incident_date_time_desc) && $earliest && $latest)
        {
            $lang = \XF::language();
            $earliestStr = $lang->date($earliest, 'absolute') . ' ' . $lang->time($earliest);
            $latestStr = $lang->date($latest, 'absolute') . ' ' . $lang->time($latest);
            
            if ($earliest == $latest)
            {
                $case->incident_date_time_desc = \XF::phrase('usips_ncmec_content_posted_on_x', ['date' => $earliestStr])->render();
            }
            else
            {
                $case->incident_date_time_desc = \XF::phrase('usips_ncmec_content_posted_between_x_and_y', [
                    'start' => $earliestStr,
                    'end' => $latestStr
                ])->render();
            }
        }

        // Fetch persons for select box
        $persons = $this->finder('USIPS\NCMEC:Person')
            ->order('last_update_date', 'DESC')
            ->fetch();

        $options = $this->app->options();
        $contactPerson = null;
        $contactPersonId = (int) ($options->usipsNcmecReporterContactPerson ?? 0);
        if ($contactPersonId)
        {
            $contactPerson = $this->em()->find('USIPS\NCMEC:Person', $contactPersonId);
        }

        $termsLink = $this->app()->router('public')->buildLink('canonical:help/terms');
        $reporterCompanyTemplate = (string) ($options->usipsNcmecReporterCompanyTemplate ?? '');

        $viewParams = [
            'case' => $case,
            'incidentTypes' => Client::INCIDENT_TYPE_VALUES,
            'reportAnnotations' => Client::REPORT_ANNOTATION_VALUES,
            'reportAnnotationLabels' => Client::REPORT_ANNOTATION_LABELS,
            'incidentDateTime' => $incidentDateTime,
            'persons' => $persons,
            'defaultContactPerson' => $contactPerson,
            'termsOfServiceLink' => $termsLink,
            'reporterCompanyTemplate' => $reporterCompanyTemplate,
        ];

        return $this->view('USIPS\NCMEC:Case\Edit', 'usips_ncmec_case_edit', $viewParams);
    }

    public function actionIndex(ParameterBag $params)
    {
        $page = $this->filterPage();
        $perPage = 100;

        $finder = $this->finder('USIPS\NCMEC:CaseFile')
            ->with('User')
            ->where('is_finalized', false)
            ->order('created_date', 'DESC')
            ->limitByPage($page, $perPage);

        $total = $finder->total();
        $this->assertValidPage($page, $perPage, $total, 'ncmec-cases');

        $viewParams = [
            'cases' => $finder->fetch(),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
        ];

        return $this->view('USIPS\NCMEC:Case\Listing', 'usips_ncmec_case_list', $viewParams);
    }

    public function actionArchive(ParameterBag $params)
    {
        $page = $this->filterPage();
        $perPage = 100;

        $finder = $this->finder('USIPS\NCMEC:CaseFile')
            ->with('User')
            ->where('is_finalized', true)
            ->order('created_date', 'DESC')
            ->limitByPage($page, $perPage);

        $total = $finder->total();
        $this->assertValidPage($page, $perPage, $total, 'ncmec-cases/archive');

        $viewParams = [
            'cases' => $finder->fetch(),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
        ];

        return $this->view('USIPS\NCMEC:Case\Archive', 'usips_ncmec_case_list_archive', $viewParams);
    }

    public function actionCreate(ParameterBag $params)
    {
        if ($this->isPost())
        {
            $input = $this->filter([
                'incident_ids' => 'array-uint',
            ]);

            // Sanity check: verify incidents exist and are not already assigned
            $incidents = $this->finder('USIPS\NCMEC:Incident')
                ->whereIds($input['incident_ids'])
                ->where('case_id', 0)
                ->fetch();

            if (!$incidents->count())
            {
                return $this->error(\XF::phrase('usips_ncmec_no_valid_incidents_selected'));
            }

            // Generate auto title like "Case created on [date] at [time]"
            $title = \XF::phrase('usips_ncmec_case_created_on_x', [
                'datetime' => \XF::phrase('date_x_at_time_y', [
                    'date' => \XF::language()->date(\XF::$time),
                    'time' => \XF::language()->time(\XF::$time)
                ])
            ])->render();

            // Create the case
            $case = $this->em()->create('USIPS\NCMEC:CaseFile');
            $case->title = $title;
            $case->user_id = \XF::visitor()->user_id;
            $case->username = \XF::visitor()->username;
            $case->save();

            // Assign incidents to case
            foreach ($incidents as $incident)
            {
                $incident->case_id = $case->case_id;
                $incident->save();
            }

            return $this->redirect($this->buildLink('ncmec-cases/view', $case));
        }

        // Get pre-selected incident IDs from request
        $preSelectedIds = $this->filter('incident_ids', 'array-uint');

        // Load all unassigned incidents
        $incidents = $this->finder('USIPS\NCMEC:Incident')
            ->where('case_id', 0)
            ->with('User')
            ->order('created_date', 'DESC')
            ->fetch();

        // Sanity check pre-selected IDs
        $validPreSelected = [];
        if ($preSelectedIds)
        {
            foreach ($preSelectedIds as $id)
            {
                if ($incidents->offsetExists($id))
                {
                    $validPreSelected[] = $id;
                }
            }
        }

        $viewParams = [
            'incidents' => $incidents,
            'preSelectedIds' => $validPreSelected,
        ];

        return $this->view('USIPS\NCMEC:Case\Create', 'usips_ncmec_case_create', $viewParams);
    }

    public function actionFinalize(ParameterBag $params)
    {
        $case = $this->assertCaseExists($params->case_id);

        // Check permissions - either can edit OR can resubmit
        if (!$case->canEdit($error) && !$case->canResubmit($resubmitError))
        {
            return $this->error($error ?: $resubmitError);
        }

        if ($this->isPost())
        {
            // Check if this is the confirmation step
            if ($this->filter('confirm', 'bool'))
            {
                // Validate required fields before starting job
                $errors = $this->validateCaseForFinalization($case);
                if (!empty($errors))
                {
                    return $this->error(implode("\n", $errors));
                }

                // If this is a resubmission (finalized but not finished), clean up first
                if ($case->is_finalized && !$case->is_finished)
                {
                    // Clean up any existing failed reports before resubmitting
                    $failedReports = $this->finder('USIPS\NCMEC:Report')
                        ->where('case_id', $case->case_id)
                        ->where('ncmec_report_id', 0)
                        ->fetch();
                    
                    foreach ($failedReports as $report)
                    {
                        $report->delete();
                    }
                    
                    // Reset finalized flag so job can proceed
                    $case->is_finalized = false;
                    $case->save();
                }

                // Start the finalization job
                $jobId = $this->app->jobManager()->enqueueUnique(
                    'usipsNcmecFinalize' . $case->case_id,
                    'USIPS\NCMEC:FinalizeCase',
                    ['case_id' => $case->case_id]
                );

                return $this->redirect($this->buildLink('tools/run-job', null, ['only_id' => $jobId]));
            }
            else
            {
                // First step: save the case data (only if not finalized)
                if (!$case->is_finalized)
                {
                    $this->saveCaseFromInput($case);
                }

                // Validate required fields before showing confirmation
                $errors = $this->validateCaseForFinalization($case);
                if (!empty($errors))
                {
                    return $this->error(implode("\n", $errors));
                }

                $viewParams = [
                    'case' => $case,
                    'apiEnvironment' => $this->app->options()->usipsNcmecApi['environment'] ?? 'test'
                ];

                return $this->view('USIPS\NCMEC:Case\Finalize', 'usips_ncmec_case_finalize', $viewParams);
            }
        }

        $viewParams = [
            'case' => $case,
            'apiEnvironment' => $this->app->options()->usipsNcmecApi['environment'] ?? 'test'
        ];

        return $this->view('USIPS\NCMEC:Case\Finalize', 'usips_ncmec_case_finalize', $viewParams);
    }

    public function actionView(ParameterBag $params)
    {
        $case = $this->assertCaseExists($params->case_id, ['User']);

        // Allow editing if case is not finalized
        if ($case->canEdit())
        {
            return $this->redirect($this->buildLink('ncmec-cases/edit', $case));
        }

        // Allow resubmission if case is finalized but failed (not finished)
        if ($case->canResubmit())
        {
            return $this->redirect($this->buildLink('ncmec-cases/finalize', $case));
        }

        // Load incidents for this case
        $incidents = $this->finder('USIPS\NCMEC:Incident')
            ->where('case_id', $case->case_id)
            ->with('User')
            ->order('created_date', 'DESC')
            ->fetch();

        $case->hydrateRelation('Incidents', $incidents);

        // Load reports for this case
        $reports = $this->finder('USIPS\NCMEC:Report')
            ->where('case_id', $case->case_id)
            ->with(['User', 'SubjectUser'])
            ->order('created_date', 'DESC')
            ->fetch();

        $case->hydrateRelation('Reports', $reports);

        // Get counts
        $incidentCount = $incidents->count();
        $reportCount = $reports->count();

        // Aggregate statistics from all incidents
        $totalUsers = 0;
        $totalContent = 0;
        $totalAttachments = 0;
        $uniqueUserIds = [];

        $incidentRepo = $this->repository('USIPS\NCMEC:Incident');
        foreach ($incidents as $incident)
        {
            $counts = $incidentRepo->getIncidentCounts($incident->incident_id);
            $totalUsers += $counts['users'];
            $totalContent += $counts['content'];
            $totalAttachments += $counts['attachments'];

            // Collect unique user IDs across all incidents
            $incidentUsers = $this->finder('USIPS\NCMEC:IncidentUser')
                ->where('incident_id', $incident->incident_id)
                ->fetch();
            
            foreach ($incidentUsers as $incidentUser)
            {
                $uniqueUserIds[$incidentUser->user_id] = true;
            }
        }

        $viewParams = [
            'case' => $case,
            'incidents' => $incidents,
            'reports' => $reports,
            'incidentCount' => $incidentCount,
            'reportCount' => $reportCount,
            'totalUsers' => $totalUsers,
            'totalContent' => $totalContent,
            'totalAttachments' => $totalAttachments,
            'uniqueUserCount' => count($uniqueUserIds),
        ];

        return $this->view('USIPS\NCMEC:Case\View', 'usips_ncmec_case_view', $viewParams);
    }
    
    /**
     * Test XSD download and XML validation
     * Debug action to verify XSD functionality
     */
    public function actionTestXsd()
    {
        $options = $this->app->options()->usipsNcmecApi;
        
        /** @var Client $apiClient */
        $apiClient = $this->service('USIPS\NCMEC:Api\Client',
            $options['username'] ?? '',
            $options['password'] ?? '',
            $options['environment'] ?? 'test'
        );
        
        // Download XSD
        $xsdContent = $apiClient->downloadXsd();
        
        if (!$xsdContent)
        {
            return $this->error('Failed to download XSD from NCMEC API');
        }
        
        // Test XML validation with a simple valid XML
        $testXml = '<?xml version="1.0" encoding="UTF-8"?>
<report xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="https://report.cybertip.org/ispws/xsd">
    <incidentSummary>
        <incidentType>Child Pornography (possession, manufacture, and distribution)</incidentType>
        <incidentDateTime>2025-01-01T12:00:00Z</incidentDateTime>
    </incidentSummary>
    <reporter>
        <reportingPerson>
            <email>test@example.com</email>
        </reportingPerson>
    </reporter>
</report>';
        
        // Validate
        $dom = new \DOMDocument();
        $dom->loadXML($testXml);
        
        $xsdFile = \XF\Util\File::getTempFile();
        file_put_contents($xsdFile, $xsdContent);
        
        try
        {
            libxml_use_internal_errors(true);
            libxml_clear_errors();
            
            $valid = $dom->schemaValidate($xsdFile);
            
            $errors = libxml_get_errors();
            $errorMessages = array_map(function($error) {
                return "Line {$error->line}: " . trim($error->message);
            }, $errors);
            
            libxml_clear_errors();
            
            $message = "XSD Downloaded: " . strlen($xsdContent) . " bytes\n\n";
            $message .= "Validation Result: " . ($valid ? "VALID" : "INVALID") . "\n\n";
            
            if (!empty($errorMessages))
            {
                $message .= "Errors:\n" . implode("\n", $errorMessages);
            }
            
            return $this->message($message);
        }
        finally
        {
            @unlink($xsdFile);
        }
    }
}
