<?php

namespace USIPS\NCMEC\Admin\Controller;

use \XF;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class IncidentController extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('usips_ncmec');
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
                XF::db()->beginTransaction();

                $title = $input['title'];
                if (!trim($title))
                {
                    $title = 'Incident created on ' . \XF::language()->dateTime(\XF::$time);
                }

                $incident = $this->em()->create('USIPS\NCMEC:Incident');
                $incident->title = $title;
                $incident->user_id = \XF::visitor()->user_id;
                $incident->username = \XF::visitor()->username;
                $incident->save();

                // Associate attachments
                foreach ($attachments as $attachment)
                {
                    $incidentAttachment = $this->em()->create('USIPS\NCMEC:IncidentAttachmentData');
                    $incidentAttachment->incident_id = $incident->incident_id;
                    $incidentAttachment->data_id = $attachment->Data->data_id;
                    $incidentAttachment->save();
                }

                // Associate unique users
                $userIds = [];
                foreach ($attachments as $attachment)
                {
                    $userIds[] = $attachment->Data->user_id;
                }
                $userIds = array_unique($userIds);
                foreach ($userIds as $userId)
                {
                    $incidentUser = $this->em()->create('USIPS\NCMEC:IncidentUser');
                    $incidentUser->incident_id = $incident->incident_id;
                    $incidentUser->user_id = $userId;
                    $incidentUser->save();
                }

                // Associate content where content_id is not 0
                foreach ($attachments as $attachment)
                {
                    if ($attachment->content_id != 0)
                    {
                        $incidentContent = $this->em()->create('USIPS\NCMEC:IncidentContent');
                        $incidentContent->incident_id = $incident->incident_id;
                        $incidentContent->content_type = $attachment->content_type;
                        $incidentContent->content_id = $attachment->content_id;
                        $incidentContent->save();
                    }
                }

                XF::db()->commit();

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
}
