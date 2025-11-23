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
        $reason = 'Flagged as CSAM';
        $user = \XF::visitor();

        foreach ($contentItems as $item)
        {
            if (empty($item['content_type']) || empty($item['content_id']))
            {
                continue;
            }

            $entity = $this->app->findByContentType($item['content_type'], $item['content_id']);
            if (!$entity)
            {
                continue;
            }

            if (method_exists($entity, 'softDelete'))
            {
                $entity->softDelete($reason, $user);

                // If this is a First Post and it was in the approval queue (moderated),
                // softDelete() delegated to the Thread and didn't update the Post's state.
                // We must manually update the post state to 'deleted' to trigger the
                // removal of the Post's ApprovalQueue record.
                if ($entity instanceof \XF\Entity\Post
                    && $entity->isFirstPost()
                    && $entity->message_state == 'moderated'
                )
                {
                    $entity->message_state = 'deleted';
                    $entity->save();
                }
            }
            else
            {
                if ($entity->isValidColumn('message_state'))
                {
                    $entity->message_state = 'deleted';
                    $entity->save();
                }
                elseif ($entity->isValidColumn('discussion_state'))
                {
                    $entity->discussion_state = 'deleted';
                    $entity->save();
                }
            }
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
        foreach ($contentItems as $item)
        {
            if (empty($item['content_type']) || empty($item['content_id']))
            {
                continue;
            }

            $this->closeReportsByContentRef($item['content_type'], $item['content_id'], $incident, $processed);

            if ($item['content_type'] !== 'thread')
            {
                continue;
            }

            $thread = $this->em()->find('XF:Thread', (int) $item['content_id']);
            if (!$thread instanceof Thread || !$thread->first_post_id)
            {
                continue;
            }

            $firstPost = $thread->FirstPost;
            if (!$firstPost instanceof Post)
            {
                $firstPost = $this->em()->find('XF:Post', $thread->first_post_id);
            }

            if (!$firstPost instanceof Post)
            {
                continue;
            }

            $threadOwnerId = (int) ($item['user_id'] ?? 0);
            if ($threadOwnerId && $firstPost->user_id !== $threadOwnerId)
            {
                continue;
            }

            $this->closeReportsByContentRef('post', $firstPost->post_id, $incident, $processed);
        }
    }

    protected function closeReportsByContentRef(string $contentType, int $contentId, Incident $incident, array &$processed): void
    {
        $reports = $this->finder('XF:Report')
            ->where('content_type', $contentType)
            ->where('content_id', $contentId)
            ->where('report_state', ['open', 'assigned'])
            ->fetch();

        /** @var Report $report */
        foreach ($reports as $report)
        {
            if (isset($processed[$report->report_id]))
            {
                continue;
            }

            $this->closeReport($report, $incident);
            $processed[$report->report_id] = true;
        }
    }

    protected function closeReport(Report $report, Incident $incident): void
    {
        $commentMessage = $this->buildIncidentReferenceMessage($incident);

        try
        {
            /** @var CommenterService $commenter */
            $commenter = $this->service(CommenterService::class, $report);
            $commenter->setMessage($commentMessage);
            $commenter->setReportState('resolved');

            if (!$commenter->validate($errors))
            {
                \XF::logError('Failed to close report #' . $report->report_id . ' while flagging CSAM: ' . implode('; ', $errors));
                return;
            }

            $commenter->save();
            $commenter->sendNotifications();
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Failed to close report while flagging CSAM: ');
        }
    }

    protected function buildIncidentReferenceMessage(Incident $incident): string
    {
        $url = $this->app->router('admin')->buildLink('canonical:ncmec-incidents/view', $incident);
        $title = \XF::escapeString($incident->title);

        return sprintf('[B]Flagged as CSAM[/B] in [url="%s"]%s[/url].', $url, $title);
    }
}
