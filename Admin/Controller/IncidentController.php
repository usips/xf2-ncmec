<?php

namespace USIPS\NCMEC\Admin\Controller;

use \XF;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;
use USIPS\NCMEC\Entity\CaseFile;

class IncidentController extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('usips_ncmec');
    }

    protected function assertIncidentExists($id, array $with = null)
    {
        return $this->assertRecordExists('USIPS\NCMEC:Incident', $id, ['User', 'Case'] + (array)$with);
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

        $finder = $this->finder('USIPS\NCMEC:Incident')
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
        $this->assertValidPage($page, $perPage, $total, 'ncmec-incidents');

        $viewParams = [
            'incidents' => $finder->fetch(),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'state' => $state,
            'tabs' => [
                'open' => \XF::phrase('open'),
                'archive' => \XF::phrase('usips_ncmec_archive')
            ]
        ];

        return $this->view('USIPS\NCMEC:Incident\Listing', 'usips_ncmec_incident_list', $viewParams);
    }

    public function actionCreate(ParameterBag $params)
    {
        if ($this->isPost())
        {
            $input = $this->filter([
                'title' => 'str',
                'submit' => 'bool',
                'attachment_ids' => 'array-int',
            ]);

            $attachments = [];
            if ($input['attachment_ids'])
            {
                $attachments = $this->finder('XF:Attachment')
                    ->where('attachment_id', $input['attachment_ids'])
                    ->with('Data.User')
                    ->fetch();
            }
            $attachmentsChecked = $attachments->keys();

            // Get unique user IDs from attachment data
            $userIds = [];
            foreach ($attachments as $attachment)
            {
                $userIds[] = $attachment->Data->user_id;
            }
            $userIds = array_unique($userIds);

            // Load users
            $users = $this->finder('XF:User')->where('user_id', $userIds)->fetch();

            // Group attachments by user
            $attachmentsByUser = [];
            foreach ($users as $user)
            {
                $attachmentsByUser[$user->user_id] = [];
            }
            foreach ($attachments as $attachment)
            {
                $attachmentsByUser[$attachment->Data->user_id][$attachment->attachment_id] = $attachment;
            }

            // Load last 20 attachments for each user
            $suppliedIds = array_column($attachments->toArray(), 'attachment_id');
            foreach ($users as $user)
            {
                $recent = $this->finder('XF:Attachment')
                    ->where('Data.user_id', $user->user_id)
                    ->with('Data')
                    ->order('attach_date', 'DESC')
                    ->limit(100)
                    ->fetch();

                foreach ($recent as $attachment)
                {
                    if (in_array($attachment->attachment_id, $suppliedIds))
                    {
                        continue;
                    }
                    $attachmentsByUser[$user->user_id][$attachment->attachment_id] = $attachment;
                }
            }

            if (!$input['submit'])
            {
                $viewParams = [
                    'attachments' => $attachments,
                    'attachmentsByUser' => $attachmentsByUser,
                    'attachmentsChecked' => $attachmentsChecked,
                    'users' => $users,
                    'title' => $input['title'],
                ];

                return $this->view('USIPS\NCMEC:Incident\Create', 'usips_ncmec_incident_create', $viewParams);
            }
            else
            {
                /** @var \USIPS\NCMEC\Service\Incident\Creator $creator */
                $creator = $this->service('USIPS\NCMEC:Incident\Creator');
                $incident = $creator->createIncident(\XF::visitor()->user_id, \XF::visitor()->username, $input['title']);

                $creator->setIncident($incident);

                if ($attachments)
                {
                    // Extract data IDs from attachments
                    $dataIds = $attachments->pluck('data_id');

                    // Enqueue AssociateAttachmentData job
                    \XF::app()->jobManager()->enqueue('USIPS\NCMEC:AssociateAttachmentData', [
                        'incident_id' => $incident->incident_id,
                        'attachment_data_ids' => $dataIds
                    ]);

                    // Associate users and content synchronously
                    $creator->associateAttachmentUsers($attachments);
                    $creator->associateContent($attachments);
                }

                return $this->redirect($this->buildLink('ncmec-incidents', $incident));
            }
        }

        $viewParams = [
            'attachments' => [],
            'attachmentsByUser' => [],
            'attachmentChecked' => [],
            'title' => '',
        ];

        return $this->view('USIPS\NCMEC:Incident\Create', 'usips_ncmec_incident_create', $viewParams);

    }

    public function actionDelete(ParameterBag $params)
    {
        $incident = $this->assertIncidentExists($params->incident_id);

        if ($this->isPost())
        {
            // Check if incident is finalized
            if ($incident->finalized_on)
            {
                return $this->error(\XF::phrase('usips_ncmec_incident_finalized_cannot_delete'));
            }

            /** @var \USIPS\NCMEC\Service\Incident\Deleter $deleter */
            $deleter = $this->service('USIPS\NCMEC:Incident\Deleter', $incident);
            $deleter->delete();

            return $this->redirect($this->buildLink('ncmec-incidents'));
        }
        else
        {
            $viewParams = [
                'incident' => $incident,
            ];
            return $this->view('USIPS\NCMEC:Incident\Delete', 'usips_ncmec_incident_delete', $viewParams);
        }
    }

    public function actionUpdate(ParameterBag $params)
    {
        $this->assertPostOnly();

        $incident = $this->assertIncidentExists($params->incident_id);

        $input = $this->filter([
            'title' => 'str',
            'case_id' => 'uint',
        ]);

        $incident->bulkSet(['title' => $input['title']]);

        $caseId = $input['case_id'];
        $currentCaseId = (int) $incident->case_id;

        if ($caseId && $caseId !== $currentCaseId)
        {
            $case = $this->getAssignableCaseFinder()
                ->where('case_id', $caseId)
                ->fetchOne();

            if (!$case)
            {
                return $this->error(\XF::phrase('usips_ncmec_invalid_case_selection'));
            }

            $incident->case_id = $caseId;
        }
        elseif (!$caseId && $currentCaseId)
        {
            $incident->case_id = 0;
        }

        $incident->save();

        return $this->message('Incident updated successfully.');
    }

    public function actionView(ParameterBag $params)
    {
        $incident = $this->assertIncidentExists($params->incident_id);

        // Get counts efficiently without hydrating relationships
        $counts = $this->repository('USIPS\NCMEC:Incident')->getIncidentCounts($incident->incident_id);

        // Manually load TO_MANY relations with nested User preloading (but limit for display)
        $incident->hydrateRelation('IncidentUsers', $this->finder('USIPS\NCMEC:IncidentUser')
            ->where('incident_id', $incident->incident_id)
            ->with('User')
            ->limit(50) // Limit for display performance
            ->fetch()
        );
        
        $incidentContents = $this->finder('USIPS\NCMEC:IncidentContent')
            ->where('incident_id', $incident->incident_id)
            ->with('User')
            ->limit(100)
            ->fetch();
        
        $incident->hydrateRelation('IncidentContents', $incidentContents);
        
        // Load actual content entities and prepare display data
        $contentData = [];
        foreach ($incidentContents as $incidentContent)
        {
            $content = $incidentContent->getContent();
            if ($content)
            {
                $contentTitle = '';
                if (method_exists($content, 'getContentTitle'))
                {
                    $contentTitle = $content->getContentTitle();
                }
                else
                {
                    // Fallback for content without getContentTitle
                    $contentTitle = \XF::phrase('content_x_y', [
                        'type' => $incidentContent->content_type,
                        'id' => $incidentContent->content_id
                    ]);
                }
                
                $contentData[] = [
                    'incident_content' => $incidentContent,
                    'content' => $content,
                    'title' => $contentTitle,
                ];
            }
        }
        
        $incident->hydrateRelation('IncidentAttachmentData', $this->finder('USIPS\NCMEC:IncidentAttachmentData')
            ->where('incident_id', $incident->incident_id)
            ->with('User')
            ->limit(50) // Limit for display performance
            ->fetch()
        );

        $availableCases = $this->getAssignableCaseFinder()->fetch();

        if ($incident->case_id && !$availableCases->offsetExists($incident->case_id) && $incident->Case)
        {
            $availableCases[$incident->case_id] = $incident->Case;
        }

        $viewParams = [
            'incident' => $incident,
            'counts' => $counts,
            'contentData' => $contentData,
            'availableCases' => $availableCases,
        ];

        return $this->view('USIPS\NCMEC:Incident\View', 'usips_ncmec_incident_view', $viewParams);
    }

    public function actionRemoveContent(ParameterBag $params)
    {
        $incident = $this->assertIncidentExists($params->incident_id);

        if ($incident->finalized_on)
        {
            return $this->error(\XF::phrase('usips_ncmec_incident_finalized_cannot_delete'));
        }

        $contentType = $this->filter('content_type', 'str');
        $contentId = $this->filter('content_id', 'uint');

        if (!$contentType || !$contentId)
        {
            return $this->error(\XF::phrase('requested_page_not_found'));
        }

        $incidentContent = $this->em()->findOne('USIPS\NCMEC:IncidentContent', [
            'incident_id' => $incident->incident_id,
            'content_type' => $contentType,
            'content_id' => $contentId,
        ]);

        if (!$incidentContent)
        {
            return $this->error(\XF::phrase('requested_page_not_found'));
        }

        if ($this->isPost())
        {
            $incidentContent->delete();
            return $this->redirect($this->buildLink('ncmec-incidents/view', $incident));
        }
        else
        {
            $content = $incidentContent->getContent();
            $contentTitle = '';
            if ($content && method_exists($content, 'getContentTitle'))
            {
                $contentTitle = $content->getContentTitle();
            }
            else
            {
                $contentTitle = \XF::phrase('content_x_y', [
                    'type' => $contentType,
                    'id' => $contentId
                ]);
            }

            $viewParams = [
                'incident' => $incident,
                'incidentContent' => $incidentContent,
                'contentTitle' => $contentTitle,
            ];
            return $this->view('USIPS\NCMEC:Incident\RemoveContent', 'usips_ncmec_incident_remove_content', $viewParams);
        }
    }

    public function actionCreateCase(ParameterBag $params)
    {
        $incident = $this->assertIncidentExists($params->incident_id);

        if ($this->isPost())
        {
            $title = $this->filter('title', 'str');
            $case = $this->createCaseRecord($title);

            $incident->case_id = $case->case_id;
            $incident->save();

            return $this->redirect($this->buildLink('ncmec-incidents/view', $incident));
        }

        $viewParams = [
            'incident' => $incident,
            'defaultTitle' => $this->generateAutoCaseTitle(),
        ];

        return $this->view('USIPS\NCMEC:Incident\CreateCase', 'usips_ncmec_incident_create_case', $viewParams);
    }

    public function actionAssignCase(ParameterBag $params)
    {
        $incidentIds = $this->filter('incident_ids', 'array-uint');
        if (!$incidentIds)
        {
            $incidentIds = $this->filter('ids', 'array-uint');
        }

        $incidentIds = array_values(array_unique(array_filter($incidentIds)));

        if (!$incidentIds)
        {
            return $this->error(\XF::phrase('please_select_at_least_one_item'));
        }

        $incidents = $this->finder('USIPS\NCMEC:Incident')
            ->where('incident_id', $incidentIds)
            ->fetch();

        if (!$incidents->count())
        {
            return $this->error(\XF::phrase('requested_page_not_found'));
        }

        if ($this->isPost())
        {
            $caseId = $this->filter('case_id', 'uint');
            $newCaseTitle = $this->filter('new_case_title', 'str');

            if ($caseId)
            {
                $case = $this->getAssignableCaseFinder()
                    ->where('case_id', $caseId)
                    ->fetchOne();

                if (!$case)
                {
                    return $this->error(\XF::phrase('usips_ncmec_invalid_case_selection'));
                }
            }
            else
            {
                $case = $this->createCaseRecord($newCaseTitle);
            }

            foreach ($incidents as $incident)
            {
                if ($incident->case_id == $case->case_id)
                {
                    continue;
                }

                $incident->case_id = $case->case_id;
                $incident->save();
            }

            return $this->redirect($this->buildLink('ncmec-incidents'));
        }

        $viewParams = [
            'incidentIds' => $incidentIds,
            'incidentCount' => $incidents->count(),
            'availableCases' => $this->getAssignableCaseFinder()->fetch(),
            'defaultTitle' => $this->generateAutoCaseTitle(),
        ];

        return $this->view('USIPS\NCMEC:Incident\AssignCase', 'usips_ncmec_incident_assign_case', $viewParams);
    }

    protected function getAssignableCaseFinder()
    {
        return $this->finder('USIPS\NCMEC:CaseFile')
            ->where('finalized_on', null)
            ->where('finished_on', null)
            ->order('created_date', 'DESC');
    }

    protected function createCaseRecord(?string $title = null): CaseFile
    {
        if ($title === '')
        {
            $title = null;
        }

        $case = $this->em()->create('USIPS\NCMEC:CaseFile');
        $case->title = $title ?: $this->generateAutoCaseTitle();
        $case->user_id = \XF::visitor()->user_id;
        $case->username = \XF::visitor()->username;
        $case->save();

        return $case;
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

}
