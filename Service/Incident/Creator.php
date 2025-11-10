<?php

namespace USIPS\NCMEC\Service\Incident;

use USIPS\NCMEC\Entity\Incident;
use XF\Entity\Attachment;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Service\AbstractService;
use XF\Util\Str;

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
        $dataIds = [];

        foreach ($attachments as $attachment)
        {
            if (!$attachment instanceof Attachment || !$attachment->Data)
            {
                continue;
            }

            $dataId = (int) $attachment->Data->data_id;
            if ($dataId)
            {
                $dataIds[] = $dataId;
            }
        }

        if ($dataIds)
        {
            $this->associateAttachmentsByDataIds(array_unique($dataIds));
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

    /**
     * Associate arbitrary attachment payloads by data id and ensure uploader users are linked.
     */
    public function associateAttachmentsByDataIds(array $dataIds)
    {
        $dataIds = array_unique(array_map('intval', $dataIds));
        if (!$dataIds)
        {
            return;
        }

        $attachmentManager = $this->service('USIPS\NCMEC:Incident\AttachmentManager');

        $attachmentDataSet = $this->finder('XF:AttachmentData')
            ->where('data_id', $dataIds)
            ->with('User')
            ->fetch();

        if (!$attachmentDataSet)
        {
            return;
        }

        $userIds = [];

        foreach ($attachmentDataSet as $data)
        {
            if (!$data->user_id)
            {
                continue;
            }

            $username = $data->User ? $data->User->username : '';

            $created = $attachmentManager->addAttachmentToIncident(
                $this->incident->incident_id,
                $data->data_id,
                $data->user_id,
                Str::substr($username, 0, 50)
            );

            if ($created)
            {
                $attachmentManager->updateIncidentCount($data->data_id);
            }

            $userIds[] = $data->user_id;
        }

        if ($userIds)
        {
            $this->associateUsersByIds(array_unique($userIds));
        }
    }

    // User association methods
    /**
     * Ensure each attachment uploader is recorded as an incident user without duplicating logic.
     */
    public function associateAttachmentUsers(iterable $attachments)
    {
        $userIds = [];

        foreach ($attachments as $attachment)
        {
            if (!$attachment instanceof Attachment || !$attachment->Data)
            {
                continue;
            }

            $userId = (int) $attachment->Data->user_id;
            if ($userId)
            {
                $userIds[] = $userId;
            }
        }

        if ($userIds)
        {
            $this->associateUsersByIds(array_unique($userIds));
        }
    }

    /**
     * Link users to the active incident, refreshing usernames and user-field state as needed.
     */
    public function associateUsersByIds(array $userIds)
    {
        $userIds = array_unique(array_map('intval', $userIds));
        if (!$userIds)
        {
            return;
        }

        /** @var \USIPS\NCMEC\Service\UserField $userFieldService */
        $userFieldService = $this->service('USIPS\NCMEC:UserField');

        foreach ($userIds as $userId)
        {
            $user = $this->em()->find('XF:User', $userId);
            if (!$user)
            {
                continue;
            }

            $desiredUsername = Str::substr($user->username, 0, 50);

            $existing = $this->finder('USIPS\NCMEC:IncidentUser')
                ->where('incident_id', $this->incident->incident_id)
                ->where('user_id', $userId)
                ->fetchOne();

            if ($existing)
            {
                if ($existing->username !== $desiredUsername)
                {
                    $existing->username = $desiredUsername;
                    $existing->save();
                }

                continue;
            }

            $incidentUser = $this->em()->create('USIPS\NCMEC:IncidentUser');
            $incidentUser->incident_id = $this->incident->incident_id;
            $incidentUser->user_id = $userId;
            $incidentUser->username = $desiredUsername;
            $incidentUser->save();

            $userFieldService->updateIncidentField($userId, true);
        }
    }

    /**
     * High-level helper that links a user and, optionally, their recent content and attachments.
     */
    public function associateUserCascade(int $userId, int $timeLimitSeconds = 0, bool $includeContent = true, bool $includeAttachments = true): void
    {
        $userId = (int) $userId;
        if (!$userId)
        {
            return;
        }

        $limit = max(0, (int) $timeLimitSeconds);

        $this->associateUsersByIds([$userId]);

        if ($includeContent)
        {
            $contentItems = $this->collectUserContentWithinTimeLimit($userId, $limit);
            if ($contentItems)
            {
                $this->associateContentByIds($contentItems);
            }
        }

        if ($includeAttachments)
        {
            $attachmentDataIds = $this->collectUserAttachmentDataWithinTimeLimit($userId, $limit);
            if ($attachmentDataIds)
            {
                $this->associateAttachmentsByDataIds($attachmentDataIds);
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

        // Remove any attachment data rows that were directly linked to the user (including drafts)
        $incidentAttachments = $this->finder('USIPS\NCMEC:IncidentAttachmentData')
            ->where('incident_id', $this->incident->incident_id)
            ->where('user_id', $userIds)
            ->fetch();

        if ($incidentAttachments->count())
        {
            $dataIds = $incidentAttachments->pluckNamed('data_id');
            $this->disassociateAttachments($dataIds);
        }

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

    /**
     * Attach content records to the incident, hydrating missing owner details when possible.
     */
    public function associateContentByIds(array $contentItems)
    {
        $pendingUserIds = [];

        foreach ($contentItems as $contentData)
        {
            if (empty($contentData['content_type']) || empty($contentData['content_id']))
            {
                continue;
            }

            $contentType = $contentData['content_type'];
            $contentId = (int) $contentData['content_id'];
            $userId = isset($contentData['user_id']) ? (int) $contentData['user_id'] : 0;
            $username = $contentData['username'] ?? '';

            if (!$userId || $username === '')
            {
                $entity = $this->findContentEntityInstance($contentType, $contentId);
                if ($entity)
                {
                    $owner = $this->resolveContentOwner($entity);
                    if ($owner)
                    {
                        if (!$userId)
                        {
                            $userId = $owner->user_id;
                        }
                        if ($username === '')
                        {
                            $username = Str::substr($owner->username, 0, 50);
                        }
                    }
                }
            }

            if (!$userId)
            {
                continue;
            }

            if ($userId)
            {
                $pendingUserIds[] = $userId;
            }

            $username = Str::substr($username, 0, 50);

            $existing = $this->finder('USIPS\NCMEC:IncidentContent')
                ->where('incident_id', $this->incident->incident_id)
                ->where('content_type', $contentType)
                ->where('content_id', $contentId)
                ->fetchOne();

            if ($existing)
            {
                if ($existing->username !== $username)
                {
                    $existing->username = $username;
                    $existing->save();
                }

                continue;
            }

            $incidentContent = $this->em()->create('USIPS\NCMEC:IncidentContent');
            $incidentContent->incident_id = $this->incident->incident_id;
            $incidentContent->content_type = $contentType;
            $incidentContent->content_id = $contentId;
            $incidentContent->user_id = $userId;
            $incidentContent->username = $username;
            $incidentContent->save();
        }

        if ($pendingUserIds)
        {
            $this->associateUsersByIds(array_unique($pendingUserIds));
        }
    }

    /**
     * Associate content while cascading any attachment and uploader relationships in one pass.
     */
    public function associateContentCascade(array $contentItems): void
    {
        $normalized = $this->normalizeContentItems($contentItems);
        if (!$normalized)
        {
            return;
        }

        $prepared = [];
        $attachmentDataIds = [];
        $userIds = [];

        foreach ($normalized as $item)
        {
            $contentType = $item['content_type'];
            $contentId = $item['content_id'];

            $entity = $this->findContentEntityInstance($contentType, $contentId);
            if (!$entity)
            {
                continue;
            }

            $owner = null;
            if (!empty($item['user_id']) && !empty($item['username']))
            {
                $owner = $this->em()->find('XF:User', (int) $item['user_id']);
            }
            if (!$owner)
            {
                $owner = $this->resolveContentOwner($entity);
            }

            $userId = $owner ? $owner->user_id : (int) ($item['user_id'] ?? 0);
            $username = $item['username'] ?? '';
            if ($username === '' && $owner)
            {
                $username = Str::substr($owner->username, 0, 50);
            }

            if ($userId)
            {
                $userIds[] = $userId;
            }

            $prepared[] = [
                'content_type' => $contentType,
                'content_id' => $contentId,
                'user_id' => $userId,
                'username' => $username,
            ];

            $attachments = $this->finder('XF:Attachment')
                ->where('content_type', $contentType)
                ->where('content_id', $contentId)
                ->with(['Data', 'Data.User'])
                ->fetch();

            /** @var Attachment $attachment */
            foreach ($attachments as $attachment)
            {
                if (!$attachment->Data)
                {
                    continue;
                }

                $attachmentDataIds[] = $attachment->Data->data_id;

                if ($attachment->Data->user_id)
                {
                    $userIds[] = (int) $attachment->Data->user_id;
                }
            }
        }

        if ($prepared)
        {
            $this->associateContentByIds($prepared);
        }

        if ($userIds)
        {
            $this->associateUsersByIds(array_unique(array_filter($userIds)));
        }

        if ($attachmentDataIds)
        {
            $this->associateAttachmentsByDataIds(array_unique($attachmentDataIds));
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
    /**
     * Resolve a content type to its entity class using local overrides plus XenForo's registry.
     */
    protected function getContentEntity(string $contentType): ?string
    {
        $entities = [
            'post' => 'XF:Post',
            'thread' => 'XF:Thread',
            'profile_post' => 'XF:ProfilePost',
        ];

        if (isset($entities[$contentType]))
        {
            return $entities[$contentType];
        }

        return \XF::app()->getContentTypeEntity($contentType, false) ?: null;
    }

    /**
     * Look up a concrete entity instance for the supplied content reference if possible.
     */
    protected function findContentEntityInstance(string $contentType, int $contentId): ?Entity
    {
        $entityClass = $this->getContentEntity($contentType);
        if (!$entityClass)
        {
            return null;
        }

        return $this->em()->find($entityClass, $contentId);
    }

    /**
     * Normalize mixed content descriptors into a predictable associative array structure.
     */
    protected function normalizeContentItems(array $contentItems): array
    {
        $normalized = [];

        foreach ($contentItems as $item)
        {
            if ($item instanceof Entity)
            {
                $normalized[] = [
                    'content_type' => $item->getEntityContentType(),
                    'content_id' => $item->getEntityId(),
                    'user_id' => $item->isValidColumn('user_id') ? (int) $item->get('user_id') : 0,
                    'username' => '',
                ];
                continue;
            }

            if (!is_array($item))
            {
                continue;
            }

            if (isset($item['content_type'], $item['content_id']))
            {
                $normalized[] = [
                    'content_type' => $item['content_type'],
                    'content_id' => (int) $item['content_id'],
                    'user_id' => isset($item['user_id']) ? (int) $item['user_id'] : 0,
                    'username' => $item['username'] ?? '',
                ];
                continue;
            }

            if (count($item) >= 2)
            {
                $normalized[] = [
                    'content_type' => $item[0],
                    'content_id' => (int) $item[1],
                    'user_id' => isset($item[2]) ? (int) $item[2] : 0,
                    'username' => $item[3] ?? '',
                ];
            }
        }

        return $normalized;
    }

    /**
     * Attempt to determine the owning user for an arbitrary content entity.
     */
    protected function resolveContentOwner(?Entity $entity): ?User
    {
        if (!$entity)
        {
            return null;
        }

        if ($entity instanceof User)
        {
            return $entity;
        }

        if (isset($entity->User) && $entity->User instanceof User)
        {
            return $entity->User;
        }

        if ($entity->isValidColumn('user_id'))
        {
            $userId = (int) $entity->get('user_id');
            if ($userId)
            {
                return $this->em()->find('XF:User', $userId);
            }
        }

        return null;
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
     * @param int $timeLimitSeconds Time limit in seconds (0 = no limit) (defaults to 48 hours)
     * @return array Array of attachment data IDs
     */
    public function collectUserAttachmentDataWithinTimeLimit($userId, $timeLimitSeconds = 172800)
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