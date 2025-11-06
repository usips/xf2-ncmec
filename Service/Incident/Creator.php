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

    public function createIncident($userId, $username, $title = null)
    {
        $this->db()->beginTransaction();

        if (!$title || !trim($title))
        {
            $title = 'Incident created on ' . \XF::language()->dateTime(\XF::$time);
        }

        $incident = $this->em()->create('USIPS\NCMEC:Incident');
        $incident->title = $title;
        $incident->user_id = $userId;
        $incident->username = \XF\Util\Str::substr($username, 0, 50);
        $incident->save();

        $this->setIncident($incident);

        $this->db()->commit();

        return $incident;
    }

    // Attachment association methods
    public function associateAttachments(iterable $attachments)
    {
        $attachmentManager = $this->service('USIPS\NCMEC:Incident\AttachmentManager');
        
        foreach ($attachments as $attachment)
        {
            $attachmentManager->addAttachmentToIncident(
                $this->incident->incident_id,
                $attachment->Data->data_id,
                $attachment->Data->user_id,
                $attachment->Data->User->username
            );
        }
    }

    public function disassociateAttachments(array $dataIds)
    {
        $attachmentManager = $this->service('USIPS\NCMEC:Incident\AttachmentManager');
        
        foreach ($dataIds as $dataId)
        {
            $attachmentManager->removeAttachmentFromIncident($this->incident->incident_id, $dataId);
        }
    }

    public function associateAttachmentsByDataIds(array $dataIds)
    {
        $attachmentManager = $this->service('USIPS\NCMEC:Incident\AttachmentManager');
        
        foreach ($dataIds as $dataId)
        {
            $attachmentData = $this->em()->find('XF:AttachmentData', $dataId);
            if ($attachmentData)
            {
                $attachmentManager->addAttachmentToIncident(
                    $this->incident->incident_id,
                    $dataId,
                    $attachmentData->user_id,
                    $attachmentData->User->username
                );
            }
        }
    }

    // User association methods
    public function associateAttachmentUsers(iterable $attachments)
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
            $incidentUser->username = \XF\Util\Str::substr($user->username, 0, 50);
            $incidentUser->save();
        }
    }

    public function associateUsersByIds(array $userIds)
    {
        foreach ($userIds as $userId)
        {
            $user = $this->em()->find('XF:User', $userId);
            if ($user)
            {
                // Check if user is already associated
                $existing = $this->finder('USIPS\NCMEC:IncidentUser')
                    ->where('incident_id', $this->incident->incident_id)
                    ->where('user_id', $userId)
                    ->fetchOne();

                if (!$existing)
                {
                    $incidentUser = $this->em()->create('USIPS\NCMEC:IncidentUser');
                    $incidentUser->incident_id = $this->incident->incident_id;
                    $incidentUser->user_id = $userId;
                    $incidentUser->username = \XF\Util\Str::substr($user->username, 0, 50);
                    $incidentUser->save();

                    // Update user field to indicate user is in incident
                    $userFieldService = $this->service('USIPS\NCMEC:UserField');
                    $userFieldService->updateIncidentField($userId, true);
                }
            }
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

        // Update user field for each disassociated user
        $userFieldService = $this->service('USIPS\NCMEC:UserField');
        foreach ($userIds as $userId)
        {
            $stillInIncident = $userFieldService->checkUserInAnyIncident($userId);
            $userFieldService->updateIncidentField($userId, $stillInIncident);
        }
    }

    // Content association methods
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
            $incidentContent->username = \XF\Util\Str::substr($content->User->username, 0, 50);
            $incidentContent->save();
        }
    }

    public function associateContentByIds(array $contentItems)
    {
        foreach ($contentItems as $contentData)
        {
            // Check if content is already associated
            $existing = $this->finder('USIPS\NCMEC:IncidentContent')
                ->where('incident_id', $this->incident->incident_id)
                ->where('content_type', $contentData['content_type'])
                ->where('content_id', $contentData['content_id'])
                ->fetchOne();

            if (!$existing)
            {
                $incidentContent = $this->em()->create('USIPS\NCMEC:IncidentContent');
                $incidentContent->incident_id = $this->incident->incident_id;
                $incidentContent->content_type = $contentData['content_type'];
                $incidentContent->content_id = $contentData['content_id'];
                $incidentContent->user_id = $contentData['user_id'];
                $incidentContent->username = \XF\Util\Str::substr($contentData['username'], 0, 50);
                $incidentContent->save();
            }
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

    // Helper methods
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

    /**
     * Collects all content for a user within a specified time limit
     * @param int $userId The user ID to collect content for
     * @param int $timeLimitSeconds Time limit in seconds (0 = no limit)
     * @return array Array of content items with type, id, and user info
     */
    public function collectUserContentWithinTimeLimit($userId, $timeLimitSeconds = 172800) // 48 hours default
    {
        $contentItems = [];

        // Get the user once to avoid multiple queries
        $user = $this->em()->find('XF:User', $userId);
        if (!$user)
        {
            return $contentItems;
        }

        // Only check content types we know have user_id and date fields
        $contentTypesToCheck = [
            'post',
            'thread', 
            'profile_post',
            'conversation_message',
            'resource_update',
            'xfmg_media',
            'xfmg_album',
            'xfmg_comment'
        ];

        foreach ($contentTypesToCheck as $contentType)
        {
            $entityClass = \XF::app()->getContentTypeEntity($contentType, false);
            if (!$entityClass)
            {
                continue;
            }

            try
            {
                // Get the date field for this content type
                $dateField = $this->getContentDateField($contentType);
                if (!$dateField)
                {
                    continue;
                }

                $finder = $this->finder($entityClass)
                    ->where('user_id', $userId);

                // Apply time limit if specified
                if ($timeLimitSeconds > 0)
                {
                    $cutoffTime = \XF::$time - $timeLimitSeconds;
                    $finder->where($dateField, '>=', $cutoffTime);
                }

                $contents = $finder->fetch();
                foreach ($contents as $content)
                {
                    $contentItems[] = [
                        'content_type' => $contentType,
                        'content_id' => $content->getEntityId(),
                        'user_id' => $content->user_id,
                        'username' => $user->username,
                        'date' => $content->{$dateField}
                    ];
                }
            }
            catch (\Exception $e)
            {
                // Skip content types that don't work with this approach
                continue;
            }
        }

        return $contentItems;
    }

    /**
     * Collects all attachments for a user within a specified time limit
     * @param int $userId The user ID to collect attachments for
     * @param int $timeLimitSeconds Time limit in seconds (0 = no limit)
     * @return array Array of attachment data IDs
     */
    public function collectUserAttachmentDataWithinTimeLimit($userId, $timeLimitSeconds = 172800) // 48 hours default
    {
        // Query attachment data directly since xf_attachment doesn't have user_id
        $finder = $this->finder('XF:AttachmentData')
            ->where('user_id', $userId);

        // Apply time limit if specified
        if ($timeLimitSeconds > 0)
        {
            $cutoffTime = \XF::$time - $timeLimitSeconds;
            $finder->where('upload_date', '>=', $cutoffTime);
        }

        $attachmentData = $finder->fetch();

        $dataIds = [];
        foreach ($attachmentData as $data)
        {
            $dataIds[] = $data->data_id;
        }

        return $dataIds;
    }

    /**
     * Gets the appropriate date field name for a content type
     * @param string $contentType The content type
     * @return string|null The date field name or null if not applicable
     */
    protected function getContentDateField($contentType)
    {
        $dateFields = [
            'post' => 'post_date',
            'thread' => 'post_date', // threads use the first post date
            'profile_post' => 'post_date',
            'conversation_message' => 'message_date',
            'resource_update' => 'post_date',
            'resource_version' => 'release_date',
            'xfmg_media' => 'media_date',
            'xfmg_album' => 'album_date',
            'xfmg_comment' => 'comment_date',
        ];

        return $dateFields[$contentType] ?? null;
    }
}