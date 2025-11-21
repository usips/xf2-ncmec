<?php

namespace USIPS\NCMEC\Service\Incident;

use USIPS\NCMEC\Entity\Incident;
use USIPS\NCMEC\Util\TimeLimit;
use XF\Entity\Report;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Service\AbstractService;
use XF\Service\Report\CommenterService;

/**
 * Flags a report's content as CSAM, creates or updates an incident,
 * associates content, and closes related reports.
 */
class ReportFlagger extends AbstractService
{
    /** @var Report */
    protected $report;

    /** @var Entity|null */
    protected $content;

    /** @var User|null */
    protected $contentUser;

    public function __construct(\XF\App $app, Report $report)
    {
        parent::__construct($app);
        $this->report = $report;
        $this->content = $report->Content;
        $this->contentUser = $this->resolveContentUser();
    }

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
            // Get time limit - use default from options
            $timeLimitSeconds = TimeLimit::getDefaultSeconds();

            // Collect additional content from the user within time limit
            if ($timeLimitSeconds > 0)
            {
                $additionalItems = $this->collectUserContentItems($contentUser, $timeLimitSeconds);
                if ($additionalItems)
                {
                    $contentItems = $this->mergeContentItems($contentItems, $additionalItems);
                }
            }

            $creator->associateContentByIds($contentItems);
            $creator->associateUsersByIds([$contentUser->user_id]);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Failed to associate content to incident: ');
        }

        $incident->fastUpdate('last_update_date', \XF::$time);

        $this->queueAssociationJobs($incident->incident_id, $contentUser->user_id, $contentItems);

        $this->closeReportsForContent($contentItems, $incident);
        $this->moderateContent($contentItems);

        return $incident;
    }

    protected function moderateContent(array $contentItems): void
    {
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

            // Handle Post/Thread special case
            if ($entity instanceof \XF\Entity\Post)
            {
                if ($entity->isFirstPost())
                {
                    $thread = $entity->Thread;
                    if ($thread && $thread->isValidColumn('discussion_state') && $thread->discussion_state === 'visible')
                    {
                        $thread->discussion_state = 'moderated';
                        $thread->save();
                    }
                }
                
                if ($entity->isValidColumn('message_state') && $entity->message_state === 'visible')
                {
                    $entity->message_state = 'moderated';
                    $entity->save();
                }
            }
            // Handle generic content with message_state
            elseif ($entity->isValidColumn('message_state') && $entity->message_state === 'visible')
            {
                $entity->message_state = 'moderated';
                $entity->save();
            }
            // Handle generic content with discussion_state
            elseif ($entity->isValidColumn('discussion_state') && $entity->discussion_state === 'visible')
            {
                $entity->discussion_state = 'moderated';
                $entity->save();
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
        if ($entity instanceof \XF\Entity\Thread && $entity->first_post_id)
        {
            $firstPost = $entity->FirstPost;
            if (!$firstPost instanceof \XF\Entity\Post)
            {
                $firstPost = $this->em()->find('XF:Post', $entity->first_post_id);
            }

            if ($firstPost instanceof \XF\Entity\Post)
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
        $cutoffTime = \XF::$time - $timeLimitSeconds;

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

    protected function closeReportsForContent(array $contentItems, Incident $incident): void
    {
        $processed = [$this->report->report_id => true];

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
            if (!$thread instanceof \XF\Entity\Thread || !$thread->first_post_id)
            {
                continue;
            }

            $firstPost = $thread->FirstPost;
            if (!$firstPost instanceof \XF\Entity\Post)
            {
                $firstPost = $this->em()->find('XF:Post', $thread->first_post_id);
            }

            if (!$firstPost instanceof \XF\Entity\Post)
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
