<?php

namespace USIPS\NCMEC\Admin\Controller;

use USIPS\NCMEC\Entity\Incident;;
use \XF;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class IncidentController extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('usips_ncmec');
    }

    protected function assertIncidentExists($id, $with = null)
    {
        return $this->assertRecordExists('USIPS\NCMEC:Incident', $id, $with);
    }

    public function actionIndex(ParameterBag $params)
    {
        $page = $this->filterPage();
        $perPage = 100;

        $finder = $this->finder('USIPS\NCMEC:Incident')
            ->order('created_date', 'DESC')
            ->limitByPage($page, $perPage);

        $total = $finder->total();
        $this->assertValidPage($page, $perPage, $total, 'ncmec-incidents');

        $viewParams = [
            'incidents' => $finder->fetch(),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
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
                $incident = $creator->createIncident($input['title'], \XF::visitor()->user_id, \XF::visitor()->username, $attachments);

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

    public function actionUpdate(ParameterBag $params)
    {
        $this->assertPostOnly();

        $incident = $this->assertIncidentExists($params->incident_id);

        $input = $this->filter([
            'title' => 'str',
            'additional_info' => 'str',
        ]);

        $incident->bulkSet($input);
        $incident->save();

        return $this->message('Incident updated successfully.');
    }

    public function actionView(ParameterBag $params)
    {
        $incident = $this->assertIncidentExists($params->incident_id, ['User', 'Report']);

        // Manually load TO_MANY relations with nested User preloading
        $incident->hydrateRelation('IncidentUsers', $this->finder('USIPS\NCMEC:IncidentUser')
            ->where('incident_id', $incident->incident_id)
            ->with('User')
            ->fetch()
        );
        
        $incident->hydrateRelation('IncidentContents', $this->finder('USIPS\NCMEC:IncidentContent')
            ->where('incident_id', $incident->incident_id)
            ->with('User')
            ->fetch()
        );
        
        $incident->hydrateRelation('IncidentAttachmentDatas', $this->finder('USIPS\NCMEC:IncidentAttachmentData')
            ->where('incident_id', $incident->incident_id)
            ->with('User')
            ->fetch()
        );

        $viewParams = [
            'incident' => $incident,
        ];

        return $this->view('USIPS\NCMEC:Incident\View', 'usips_ncmec_incident_view', $viewParams);
    }
}
