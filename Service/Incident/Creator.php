<?php

namespace USIPS\NCMEC\Service\Incident;

use USIPS\NCMEC\Entity\Incident;
use XF\Service\AbstractService;
use XF\Mvc\Entity\Finder;

class Creator extends AbstractService
{
    protected $incident;

    public function __construct(\XF\App $app)
    {
        parent::__construct($app);
    }

    public function setIncident(Incident $incident)
    {
        $this->incident = $incident;
    }

    public function createIncident($title, $userId, $username, iterable $attachments)
    {
        $this->db()->beginTransaction();

        if (!trim($title))
        {
            $title = 'Incident created on ' . \XF::language()->dateTime(\XF::$time);
        }

        $incident = $this->em()->create('USIPS\NCMEC:Incident');
        $incident->title = $title;
        $incident->user_id = $userId;
        $incident->username = $username;
        $incident->save();

        $this->setIncident($incident);

        $this->associateAttachments($attachments);
        $this->associateUsers($attachments);
        $this->associateContent($attachments);

        $this->db()->commit();

        return $incident;
    }

    public function associateAttachments(iterable $attachments)
    {
        foreach ($attachments as $attachment)
        {
            $incidentAttachment = $this->em()->create('USIPS\NCMEC:IncidentAttachmentData');
            $incidentAttachment->incident_id = $this->incident->incident_id;
            $incidentAttachment->data_id = $attachment->Data->data_id;
            $incidentAttachment->user_id = $attachment->Data->user_id;
            $incidentAttachment->username = $attachment->Data->User->username;
            $incidentAttachment->save();
        }
    }

    public function disassociateAttachments(array $dataIds)
    {
        $this->db()->delete('xf_usips_ncmec_incident_attachment_data', 'incident_id = ? AND data_id IN (' . $this->db()->quote($dataIds) . ')', $this->incident->incident_id);
    }

    public function associateUsers(iterable $attachments)
    {
        $userIds = [];
        $users = [];
        foreach ($attachments as $attachment)
        {
            $userId = $attachment->Data->user_id;
            if (!isset($users[$userId]))
            {
                $users[$userId] = $attachment->Data->User;
            }
        }
        foreach ($users as $user)
        {
            $incidentUser = $this->em()->create('USIPS\NCMEC:IncidentUser');
            $incidentUser->incident_id = $this->incident->incident_id;
            $incidentUser->user_id = $user->user_id;
            $incidentUser->username = $user->username;
            $incidentUser->save();
        }
    }

    public function disassociateUsers(array $userIds)
    {
        if (!$userIds)
        {
            return;
        }

        // Find all content associated with this incident and the specified users
        $incidentContents = $this->finder('USIPS\NCMEC:IncidentContent')
            ->where('incident_id', $this->incident->incident_id)
            ->where('user_id', $userIds)
            ->fetch();

        // Collect content pairs
        $contentPairs = [];
        foreach ($incidentContents as $ic)
        {
            $contentPairs[] = [$ic->content_type, $ic->content_id];
        }

        // Disassociate the content
        $this->disassociateContent($contentPairs);

        // Finally, disassociate the users
        $this->db()->delete('xf_usips_ncmec_incident_user', 'incident_id = ? AND user_id IN (' . $this->db()->quote($userIds) . ')', $this->incident->incident_id);
    }

    protected function getContentEntity($contentType)
    {
        $entities = [
            'post' => 'XF:Post',
            'thread' => 'XF:Thread',
            'profile_post' => 'XF:ProfilePost',
            // Add more as needed
        ];
        return $entities[$contentType] ?? null;
    }

    public function associateContent(iterable $attachments)
    {
        $contentKeys = [];
        foreach ($attachments as $attachment)
        {
            if ($attachment->content_id != 0)
            {
                $key = $attachment->content_type . '-' . $attachment->content_id;
                if (!isset($contentKeys[$key]))
                {
                    $contentKeys[$key] = [
                        'content_type' => $attachment->content_type,
                        'content_id' => $attachment->content_id,
                        'attachment' => $attachment
                    ];
                }
            }
        }

        foreach ($contentKeys as $key => $data)
        {
            $entity = $this->getContentEntity($data['content_type']);
            if (!$entity)
            {
                continue;
            }

            $content = $this->em()->find($entity, $data['content_id']);
            if (!$content)
            {
                continue;
            }

            $incidentContent = $this->em()->create('USIPS\NCMEC:IncidentContent');
            $incidentContent->incident_id = $this->incident->incident_id;
            $incidentContent->content_type = $data['content_type'];
            $incidentContent->content_id = $data['content_id'];
            $incidentContent->user_id = $content->user_id;
            $incidentContent->username = $content->User->username;
            $incidentContent->save();
        }
    }

    public function disassociateContent($contentPairs)
    {
        if (!$contentPairs)
        {
            return;
        }

        // Find all attachments on this content
        $attachmentConditions = [];
        foreach ($contentPairs as [$contentType, $contentId])
        {
            $attachmentConditions[] = ['content_type' => $contentType, 'content_id' => $contentId];
        }
        $attachments = $this->finder('XF:Attachment')
            ->whereOr($attachmentConditions)
            ->fetch();

        $dataIds = [];
        foreach ($attachments as $attachment)
        {
            $dataIds[] = $attachment->data_id;
        }

        // Disassociate attachment data
        if ($dataIds)
        {
            $this->disassociateAttachments($dataIds);
        }

        // Disassociate content
        foreach ($contentPairs as [$contentType, $contentId])
        {
            $this->db()->delete('xf_usips_ncmec_incident_content', 'incident_id = ? AND content_type = ? AND content_id = ?', [$this->incident->incident_id, $contentType, $contentId]);
        }
    }
}