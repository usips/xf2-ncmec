<?php

namespace USIPS\NCMEC\Service\Incident;

use USIPS\NCMEC\Entity\Incident;
use USIPS\NCMEC\Util\TimeLimit;
use XF\Entity\Post;
use XF\Entity\Report;
use XF\Entity\Thread;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Service\AbstractService;
use XF\Service\Report\CommenterService;

abstract class AbstractFlagger extends AbstractService
{
    /** @var Entity|null */
    protected $content;

    /** @var User|null */
    protected $contentUser;

    /** @var Creator|null */
    protected $creator;

    public function __construct(\XF\App $app)
    {
        parent::__construct($app);
    }

    abstract protected function collectAdditionalContent(User $user): array;

    public function flag(): ?Incident
    {
        $actor = \XF::visitor();
        if (!$actor->user_id)
        {
            return null;
        }

        if (!$this->content)
        {
            return null;
        }

        $contentUser = $this->contentUser;
        if (!$contentUser)
        {
            return null;
        }

        /** @var Creator $creator */
        $creator = $this->service(Creator::class);
        $this->creator = $creator;

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
            return null;
        }

        $primaryItem = $this->buildContentItem($this->content, $contentUser);
        $contentItems = [$primaryItem];

        try
        {
            $additionalItems = $this->collectAdditionalContent($contentUser);
            if ($additionalItems)
            {
                $contentItems = $this->mergeContentItems($contentItems, $additionalItems);
            }

            $creator->associateContentByIds($contentItems);
            $creator->associateUsersByIds([$contentUser->user_id]);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Failed to associate content to incident: ');
        }

        $incident->fastUpdate('last_update_date', \XF::$time);

        try
        {
            $this->queueAssociationJobs($incident->incident_id, $contentUser->user_id, $contentItems);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Failed to queue association jobs: ');
        }

        try
        {
            $this->closeReportsForContent($contentItems, $incident);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Failed to close reports: ');
        }

        try
        {
            $this->deleteContent($contentItems);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Failed to delete content: ');
        }

        return $incident;
    }

    protected function deleteContent(array $contentItems): void
    {
        if ($this->creator)
        {
            $this->creator->deleteContent($contentItems);
        }
    }

    protected function resolveContentUser(): ?User
    {
        if (!$this->content)
        {
            return null;
        }
        return $this->extractContentOwner($this->content);
    }

    protected function buildContentItem(Entity $entity, User $owner): array
    {
        // If entity is a Thread, we should associate the first post instead
        if ($entity instanceof Thread && $entity->first_post_id)
        {
            $firstPost = $entity->FirstPost;
            if (!$firstPost instanceof Post)
            {
                $firstPost = $this->em()->find('XF:Post', $entity->first_post_id);
            }

            if ($firstPost instanceof Post)
            {
                $entity = $firstPost;
            }
        }

        return [
            'content_type' => $entity->getEntityContentType(),
            'content_id' => $entity->getEntityId(),
            'user_id' => $owner->user_id,
            'username' => \XF\Util\Str::substr($owner->username, 0, 50),
        ];
    }

    protected function findExistingIncident(int $moderatorId): ?Incident
    {
        return $this->finder('USIPS\NCMEC:Incident')
            ->where('user_id', $moderatorId)
            ->where('finalized_on', null)
            ->order('last_update_date', 'DESC')
            ->fetchOne();
    }

    protected function queueAssociationJobs(int $incidentId, int $userId, array $contentItems): void
    {
        $jobManager = $this->app->jobManager();

        // Default time limit for user association (can be overridden or logic changed if needed)
        $timeLimitSeconds = TimeLimit::getDefaultSeconds();

        $jobManager->enqueue('USIPS\NCMEC:AssociateContent', [
            'incident_id' => $incidentId,
            'content_items' => $contentItems,
            'time_limit_seconds' => 0,
        ]);

        $jobManager->enqueue('USIPS\NCMEC:AssociateUser', [
            'incident_id' => $incidentId,
            'user_ids' => [$userId],
            'time_limit_seconds' => $timeLimitSeconds,
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

    protected function collectUserContentItems(User $user, int $timeLimitSeconds): array
    {
        $items = [];
        $cutoffTime = ($timeLimitSeconds === 0) ? 0 : (\XF::$time - $timeLimitSeconds);

        // Collect posts
        $posts = $this->finder('XF:Post')
            ->where('user_id', $user->user_id)
            ->where('post_date', '>=', $cutoffTime)
            ->fetch();

        foreach ($posts as $post)
        {
            $items[] = $this->buildContentItem($post, $user);
        }

        // Collect threads
        $threads = $this->finder('XF:Thread')
            ->where('user_id', $user->user_id)
            ->where('post_date', '>=', $cutoffTime)
            ->fetch();

        foreach ($threads as $thread)
        {
            if ($thread->first_post_id)
            {
                $firstPost = $thread->FirstPost ?: $this->em()->find('XF:Post', $thread->first_post_id);
                if ($firstPost instanceof \XF\Entity\Post)
                {
                    $items[] = $this->buildContentItem($firstPost, $user);
                }
            }
        }

        // Collect profile posts
        $profilePosts = $this->finder('XF:ProfilePost')
            ->where('user_id', $user->user_id)
            ->where('post_date', '>=', $cutoffTime)
            ->fetch();

        foreach ($profilePosts as $profilePost)
        {
            $items[] = $this->buildContentItem($profilePost, $user);
        }

        // Collect profile post comments
        $profilePostComments = $this->finder('XF:ProfilePostComment')
            ->where('user_id', $user->user_id)
            ->where('comment_date', '>=', $cutoffTime)
            ->fetch();

        foreach ($profilePostComments as $comment)
        {
            $items[] = $this->buildContentItem($comment, $user);
        }

        return $items;
    }

    protected function extractContentOwner(Entity $entity): ?User
    {
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

    protected function closeReportsForContent(array $contentItems, Incident $incident, array $processed = []): void
    {
        if ($this->creator)
        {
            $this->creator->setIncident($incident);
            $this->creator->closeReportsForContent($contentItems, $processed);
        }
    }
}
