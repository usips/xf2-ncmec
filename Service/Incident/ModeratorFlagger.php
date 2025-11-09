<?php

namespace USIPS\NCMEC\Service\Incident;

use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Service\AbstractService;

class ModeratorFlagger extends AbstractService
{
    /** @var Entity */
    protected $content;

    /** @var User|null */
    protected $contentUser;

    public function __construct(\XF\App $app, Entity $content)
    {
        parent::__construct($app);
        $this->content = $content;
        $this->contentUser = $this->resolveContentUser();
    }

    public function flag(): void
    {
        $actor = \XF::visitor();
        if (!$actor->user_id)
        {
            return;
        }

        $contentUser = $this->contentUser;
        if (!$contentUser)
        {
            return;
        }

        /** @var Creator $creator */
        $creator = $this->service(Creator::class);

        $incident = $this->findExistingIncident($actor->user_id);
        if ($incident)
        {
            $creator->setIncident($incident);
        }
        else
        {
            $incident = $creator->createIncident($actor->user_id, $actor->username, null);
        }

        if (!$incident)
        {
            return;
        }

        $primaryItem = $this->buildContentItem($this->content, $contentUser);

        $contentItems = [$primaryItem];

        try
        {
            $creator->associateContentByIds($contentItems);
            $creator->associateUsersByIds([$contentUser->user_id]);

            $collectedItems = $creator->collectUserContentWithinTimeLimit($contentUser->user_id, 0);
            if (!empty($collectedItems))
            {
                $contentItems = $this->mergeContentItems($contentItems, $collectedItems);
            }
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Failed to associate content to incident: ');
        }

        $incident->fastUpdate('last_update_date', \XF::$time);

        $this->queueAssociationJobs($incident->incident_id, $contentUser->user_id, $contentItems);
    }

    protected function resolveContentUser(): ?User
    {
        if ($this->content instanceof User)
        {
            return $this->content;
        }

        if (isset($this->content->User) && $this->content->User instanceof User)
        {
            return $this->content->User;
        }

        if ($this->content->isValidColumn('user_id'))
        {
            $userId = (int)$this->content->get('user_id');
            if ($userId)
            {
                return $this->em()->find('XF:User', $userId);
            }
        }

        return null;
    }

    protected function buildContentItem(Entity $entity, User $owner): array
    {
        return [
            'content_type' => $entity->getEntityContentType(),
            'content_id' => $entity->getEntityId(),
            'user_id' => $owner->user_id,
            'username' => \XF\Util\Str::substr($owner->username, 0, 50),
        ];
    }

    protected function findExistingIncident(int $moderatorId)
    {
        return $this->finder('USIPS\NCMEC:Incident')
            ->where('user_id', $moderatorId)
            ->where('is_finalized', 0)
            ->order('last_update_date', 'DESC')
            ->fetchOne();
    }

    protected function queueAssociationJobs(int $incidentId, int $userId, array $contentItems): void
    {
        $jobManager = $this->app->jobManager();

        $jobManager->enqueue('USIPS\NCMEC:AssociateContent', [
            'incident_id' => $incidentId,
            'content_items' => $contentItems,
            'time_limit_seconds' => 0,
        ]);

        $jobManager->enqueue('USIPS\NCMEC:AssociateUser', [
            'incident_id' => $incidentId,
            'user_ids' => [$userId],
            'time_limit_seconds' => 0,
        ]);
    }

    protected function mergeContentItems(array $existing, array $additional): array
    {
        $indexed = [];

        foreach ($existing as $item)
        {
            $key = $item['content_type'] . '-' . $item['content_id'];
            $indexed[$key] = $item;
        }

        foreach ($additional as $item)
        {
            if (!isset($item['content_type'], $item['content_id']))
            {
                continue;
            }

            $key = $item['content_type'] . '-' . $item['content_id'];
            if (!isset($indexed[$key]))
            {
                $indexed[$key] = $item;
            }
        }

        return array_values($indexed);
    }
}
