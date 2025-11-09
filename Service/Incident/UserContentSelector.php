<?php

namespace USIPS\NCMEC\Service\Incident;

use USIPS\NCMEC\Entity\Incident;
use USIPS\NCMEC\Entity\IncidentAttachmentData;
use USIPS\NCMEC\Entity\IncidentContent;
use XF\Entity\Attachment;
use XF\Entity\User;
use XF\Mvc\Entity\ArrayCollection;
use XF\Mvc\Entity\Entity;
use XF\Service\AbstractService;

class UserContentSelector extends AbstractService
{
    /** @var Incident */
    protected $incident;

    /** @var User */
    protected $user;

    /** @var array */
    protected $associatedContentIds = [];

    /** @var array */
    protected $associatedAttachmentIds = [];

    public function __construct(\XF\App $app, Incident $incident, User $user)
    {
        parent::__construct($app);
        $this->incident = $incident;
        $this->user = $user;
    }

    /**
     * @return ArrayCollection|IncidentContent[]
     */
    public function getAssociatedContent(): ArrayCollection
    {
        return $this->finder('USIPS\NCMEC:IncidentContent')
            ->where('incident_id', $this->incident->incident_id)
            ->where('user_id', $this->user->user_id)
            ->fetch();
    }

    /**
     * @return ArrayCollection|IncidentAttachmentData[]
     */
    public function getAssociatedAttachments(): ArrayCollection
    {
        return $this->finder('USIPS\NCMEC:IncidentAttachmentData')
            ->where('incident_id', $this->incident->incident_id)
            ->where('user_id', $this->user->user_id)
            ->fetch();
    }

    /**
     * @param int|null $timeLimitSeconds
     *
     * @return array<int, array<string,mixed>>
     */
    public function getAvailableContent(?int $timeLimitSeconds = null, ?ArrayCollection $associatedContent = null): array
    {
        $contentItems = $this->collectUserContent($timeLimitSeconds);

        if ($associatedContent)
        {
            foreach ($associatedContent as $associated)
            {
                $contentItems[] = [
                    'content_type' => $associated->content_type,
                    'content_id' => $associated->content_id,
                    'date' => null,
                ];
            }
        }

        return $this->hydrateContentEntities($contentItems, $associatedContent);
    }

    /**
     * @param int|null $timeLimitSeconds
     *
     * @return Attachment[]
     */
    public function getAvailableAttachments(?int $timeLimitSeconds = null, ?ArrayCollection $associatedAttachments = null): array
    {
        $dataIds = $this->collectUserAttachmentData($timeLimitSeconds);

        if ($associatedAttachments)
        {
            foreach ($associatedAttachments as $attachment)
            {
                $dataIds[] = $attachment->data_id;
            }
        }

        $dataIds = array_unique(array_map('intval', $dataIds));

        $fetchIds = $dataIds ?: array_keys($this->getAssociatedAttachmentsMap($associatedAttachments));

        if (!$fetchIds)
        {
            return [];
        }

        /**
         * AttachmentData rows represent the binary payload only. Always surface attachments
         * (with their Data relation) so downstream callers work with the correct entity type.
         */
        /** @var ArrayCollection|Attachment[] $attachments */
        $attachments = $this->finder('XF:Attachment')
            ->where('data_id', $fetchIds)
            ->with(['Data', 'Data.User'])
            ->fetch();

        $filtered = [];
        foreach ($attachments as $attachment)
        {
            $data = $attachment->Data;
            if ($data && $data->user_id && $data->user_id !== $this->user->user_id)
            {
                continue;
            }

            $filtered[] = $attachment;
        }

        usort($filtered, static function (Attachment $a, Attachment $b): int
        {
            $aDate = $a->Data->upload_date ?? 0;
            $bDate = $b->Data->upload_date ?? 0;

            return $bDate <=> $aDate;
        });

        return $filtered;
    }

    /**
     * @return array<string, bool>
     */
    protected function getAssociatedContentMap(?ArrayCollection $associatedContent = null): array
    {
        if ($this->associatedContentIds)
        {
            return $this->associatedContentIds;
        }

        if ($associatedContent === null)
        {
            $associatedContent = $this->finder('USIPS\NCMEC:IncidentContent')
                ->where('incident_id', $this->incident->incident_id)
                ->where('user_id', $this->user->user_id)
                ->fetch();
        }

        $map = [];
        /** @var IncidentContent $content */
        foreach ($associatedContent as $content)
        {
            $key = $this->getContentKey($content->content_type, $content->content_id);
            $map[$key] = true;
        }

        $this->associatedContentIds = $map;

        return $this->associatedContentIds;
    }

    /**
     * @return array<int,bool>
     */
    protected function getAssociatedAttachmentsMap(?ArrayCollection $associatedAttachments = null): array
    {
        if ($this->associatedAttachmentIds)
        {
            return $this->associatedAttachmentIds;
        }

        if ($associatedAttachments === null)
        {
            $associatedAttachments = $this->finder('USIPS\NCMEC:IncidentAttachmentData')
                ->where('incident_id', $this->incident->incident_id)
                ->where('user_id', $this->user->user_id)
                ->fetch();
        }

        $map = [];
        /** @var IncidentAttachmentData $attachment */
        foreach ($associatedAttachments as $attachment)
        {
            $map[(int) $attachment->data_id] = true;
        }

        $this->associatedAttachmentIds = $map;

        return $this->associatedAttachmentIds;
    }

    protected function collectUserContent(?int $timeLimitSeconds = null): array
    {
        /** @var Creator $creator */
        $creator = $this->service(Creator::class);
        $creator->setIncident($this->incident);

        $timeLimit = $timeLimitSeconds ?? 0;
        return $creator->collectUserContentWithinTimeLimit($this->user->user_id, $timeLimit);
    }

    protected function collectUserAttachmentData(?int $timeLimitSeconds = null): array
    {
        /** @var Creator $creator */
        $creator = $this->service(Creator::class);
        $creator->setIncident($this->incident);

        $timeLimit = $timeLimitSeconds ?? 0;
        return $creator->collectUserAttachmentDataWithinTimeLimit($this->user->user_id, $timeLimit);
    }

    protected function hydrateContentEntities(array $contentItems, ?ArrayCollection $associatedContent = null): array
    {
        if (!$contentItems)
        {
            return [];
        }

        $associated = $this->getAssociatedContentMap($associatedContent);
        $results = [];

        $seen = [];
        foreach ($contentItems as $item)
        {
            if (empty($item['content_type']) || empty($item['content_id']))
            {
                continue;
            }

            $contentType = $item['content_type'];
            $contentId = (int) $item['content_id'];

            $key = $this->getContentKey($contentType, $contentId);
            if (isset($seen[$key]))
            {
                continue;
            }

            $entityClass = $this->app->getContentTypeEntity($contentType, false);
            if (!$entityClass)
            {
                continue;
            }

            /** @var Entity|null $entity */
            $entity = $this->em()->find($entityClass, $contentId);
            if (!$entity)
            {
                continue;
            }

            $seen[$key] = true;

            $results[] = [
                'content_type' => $contentType,
                'content_id' => $contentId,
                'entity' => $entity,
                'date' => $this->resolveContentDate($entity, $item['date'] ?? null),
                'is_associated' => isset($associated[$this->getContentKey($contentType, $contentId)]),
            ];
        }

        usort($results, static function (array $a, array $b): int
        {
            $aDate = $a['date'] ?? 0;
            $bDate = $b['date'] ?? 0;

            return $bDate <=> $aDate;
        });

        return $results;
    }

    protected function getContentKey(string $contentType, int $contentId): string
    {
        return $contentType . '-' . $contentId;
    }

    protected function resolveContentDate(Entity $entity, ?int $fallback = null): int
    {
        if ($fallback)
        {
            return (int) $fallback;
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

        return 0;
    }
}
