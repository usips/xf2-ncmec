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

        if (!$input['incident_type'])
        {
            throw $this->exception($this->error(\XF::phrase('usips_ncmec_error_incident_type_required')));
        }

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

    public function actionDelete(ParameterBag $params)
    {
        $case = $this->assertCaseExists($params->case_id);

        if ($case->finalized_on || $case->submitted_on)
        {
            return $this->error(\XF::phrase('usips_ncmec_case_finalized_cannot_delete'));
        }

        $reportCount = $this->finder('USIPS\NCMEC:Report')
            ->where('case_id', $case->case_id)
            ->total();

        if ($reportCount > 0)
        {
            return $this->error(\XF::phrase('usips_ncmec_case_has_reports_cannot_delete'));
        }

        if ($this->isPost())
        {
            $incidents = $this->finder('USIPS\NCMEC:Incident')
                ->where('case_id', $case->case_id)
                ->fetch();

            foreach ($incidents as $incident)
            {
                $incident->case_id = 0;
                $incident->save();
            }

            $case->delete();

            return $this->redirect($this->buildLink('ncmec-cases'));
        }
        else
        {
            $viewParams = [
                'case' => $case
            ];
            return $this->view('USIPS\NCMEC:Case\Delete', 'usips_ncmec_case_delete', $viewParams);
        }
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

        $finder = $this->finder('USIPS\NCMEC:CaseFile')
            ->with('User')
            ->order('created_date', 'DESC')
            ->limitByPage($page, $perPage);

        if ($state === 'archive')
        {
            $finder->where('finalized_on', '!=', null);
        }
        else
        {
            $finder->where('finalized_on', null);
        }

        $total = $finder->total();
        $this->assertValidPage($page, $perPage, $total, 'ncmec-cases');

        $viewParams = [
            'cases' => $finder->fetch(),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'state' => $state,
            'tabs' => [
                'open' => \XF::phrase('open'),
                'archive' => \XF::phrase('usips_ncmec_archive')
            ]
        ];

        return $this->view('USIPS\NCMEC:Case\Listing', 'usips_ncmec_case_list', $viewParams);
    }

    public function actionCreate(ParameterBag $params)
    {
        if ($this->isPost())
        {
            $input = $this->filter([
                'incident_ids' => 'array-uint',
                'assign_action' => 'str',
                'new_case_title' => 'str',
                'title' => 'str',
                'case_id' => 'uint'
            ]);

            // Sanity check: verify incidents exist
            $incidents = $this->finder('USIPS\NCMEC:Incident')
                ->whereIds($input['incident_ids'])
                ->fetch();

            if (!$incidents->count())
            {
                return $this->error(\XF::phrase('usips_ncmec_no_valid_incidents_selected'));
            }

            $case = null;

            if ($input['assign_action'] === 'existing')
            {
                if (!$input['case_id'])
                {
                    return $this->error(\XF::phrase('usips_ncmec_invalid_case_selection'));
                }

                $case = $this->assertCaseExists($input['case_id']);
                if ($case->finalized_on || $case->submitted_on)
                {
                    return $this->error(\XF::phrase('usips_ncmec_case_finalized_cannot_edit'));
                }
            }
            else
            {
                // Generate auto title like "Case created on [date] at [time]"
                // Support both 'new_case_title' (from assign dialog) and 'title' (from create form)
                $titleInput = $input['new_case_title'] ?: $input['title'];
                $title = $titleInput ?: $this->generateAutoCaseTitle();

                // Create the case
                $case = $this->em()->create('USIPS\NCMEC:CaseFile');
                $case->title = $title;
                $case->user_id = \XF::visitor()->user_id;
                $case->username = \XF::visitor()->username;
                $case->save();
            }

            // Assign incidents to case
            foreach ($incidents as $incident)
            {
                // Skip if already assigned to this case
                if ($incident->case_id == $case->case_id)
                {
                    continue;
                }
                
                $incident->case_id = $case->case_id;
                $incident->save();
            }

            return $this->redirect($this->buildLink('ncmec-cases/view', $case));
        }

        // Check if we are coming from a specific incident (e.g. "Create Case" button on incident view)
        $incidentId = $this->filter('incident_id', 'uint');
        if ($incidentId)
        {
            $incident = $this->em()->find('USIPS\NCMEC:Incident', $incidentId);
            if ($incident)
            {
                $viewParams = [
                    'incidentIds' => [$incident->incident_id],
                    'incidentCount' => 1,
                    'availableCases' => $this->getAssignableCaseFinder()->fetch(),
                    'defaultTitle' => $this->generateAutoCaseTitle(),
                ];
                return $this->view('USIPS\NCMEC:Case\CreateFromIncident', 'usips_ncmec_incident_create_case', $viewParams);
            }
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

    protected function getAssignableCaseFinder()
    {
        return $this->finder('USIPS\NCMEC:CaseFile')
            ->where('finalized_on', null)
            ->where('submitted_on', null)
            ->order('created_date', 'DESC');
    }

    protected function generateAutoCaseTitle(): string
    {
        $lang = \XF::language();
        $datePhrase = \XF::phrase('date_x_at_time_y', [
            'date' => $lang->date(\XF::$time),
            'time' => $lang->time(\XF::$time)
        ]);

        return \XF::phrase('usips_ncmec_case_created_on_x', [
            'datetime' => $datePhrase
        ])->render();
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

                // If this is a resubmission (finalized but not submitted), clean up first
                if ($case->finalized_on && !$case->submitted_on)
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
                    $case->finalized_on = null;
                    $case->save();
                }

                // Start the finalization job
                $jobId = $this->app->jobManager()->enqueueUnique(
                    'usipsNcmecFinalize' . $case->case_id,
                    'USIPS\NCMEC:FinalizeCase',
                    ['case_id' => $case->case_id]
                );

                return $this->redirect($this->buildLink('tools/run-job', null, [
                    'only_id' => $jobId,
                    'redirect' => $this->buildLink('ncmec')
                ]));
            }
            else
            {
                // First step: save the case data (only if not finalized)
                if (!$case->finalized_on)
                {
                    $this->saveCaseFromInput($case);
                }

                // Validate required fields before showing confirmation
                $errors = $this->validateCaseForFinalization($case);
                if (!empty($errors))
                {
                    return $this->error(implode("\n", $errors));
                }
            }
        }

        // Generate XML previews
        $previews = [];
        $previewWarning = null;
        $previewLimit = 10;
        
        // Get all users involved in the case
        $db = $this->app->db();
        $userIds = $db->fetchAllColumn("
            SELECT DISTINCT iu.user_id
            FROM xf_usips_ncmec_incident_user AS iu
            INNER JOIN xf_usips_ncmec_incident AS i ON (iu.incident_id = i.incident_id)
            WHERE i.case_id = ?
        ", [$case->case_id]);

        $users = $this->em()->findByIds('XF:User', $userIds);
        $totalUsers = $users->count();

        if ($case->reported_person_id)
        {
            $previewUsers = $users;
            if ($totalUsers > $previewLimit)
            {
                $previewUsers = $users->slice(0, $previewLimit);
                $previewWarning = "Preview limited to {$previewLimit} users (out of {$totalUsers}). The final report will include all users.";
            }

            // Single Report Mode: Pass all users (or subset) to one submitter
            /** @var \USIPS\NCMEC\Service\Report\Submitter $submitter */
            $submitter = $this->service('USIPS\NCMEC:Report\Submitter', $case, $previewUsers);
            try
            {
                $xml = $submitter->getPreviewXml();
                // Format XML for display
                $dom = new \DOMDocument();
                $dom->preserveWhiteSpace = false;
                $dom->formatOutput = true;
                $dom->loadXML($xml);
                $formattedXml = $dom->saveXML();
                
                $previews[] = [
                    'user' => $users->first(),
                    'xml' => $formattedXml
                ];
            }
            catch (\Exception $e)
            {
                $previews[] = [
                    'user' => $users->first(),
                    'error' => $e->getMessage()
                ];
            }
        }
        else
        {
            $count = 0;
            foreach ($users as $user)
            {
                $count++;
                if ($count > $previewLimit)
                {
                    $previewWarning = "Preview showing first {$previewLimit} reports (out of {$totalUsers}).";
                    break;
                }

                /** @var \USIPS\NCMEC\Service\Report\Submitter $submitter */
                $submitter = $this->service('USIPS\NCMEC:Report\Submitter', $case, $user);
                try
                {
                    $xml = $submitter->getPreviewXml();
                    // Format XML for display
                    $dom = new \DOMDocument();
                    $dom->preserveWhiteSpace = false;
                    $dom->formatOutput = true;
                    $dom->loadXML($xml);
                    $formattedXml = $dom->saveXML();
                    
                    $previews[] = [
                        'user' => $user,
                        'xml' => $formattedXml
                    ];
                }
                catch (\Exception $e)
                {
                    $previews[] = [
                        'user' => $user,
                        'error' => $e->getMessage()
                    ];
                }
            }
        }

        $viewParams = [
            'case' => $case,
            'apiEnvironment' => !empty($this->app->options()->usipsNcmecApi['environment']) ? $this->app->options()->usipsNcmecApi['environment'] : 'test',
            'previews' => $previews,
            'previewWarning' => $previewWarning
        ];

        return $this->view('USIPS\NCMEC:Case\Finalize', 'usips_ncmec_case_finalize', $viewParams);
    }

    public function actionView(ParameterBag $params)
    {
        $case = $this->assertCaseExists($params->case_id, ['User']);

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
            !empty($options['environment']) ? $options['environment'] : 'test'
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
