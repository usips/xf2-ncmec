<?php

namespace USIPS\NCMEC\Service\Incident;

use USIPS\NCMEC\Entity\Incident;
use XF\Entity\ApprovalQueue;
use XF\Entity\Post;
use XF\Entity\Report;
use XF\Entity\Thread;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Service\AbstractService;
use XF\Service\Report\CommenterService;

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

    public function flag(): ?Incident
    {
        $actor = \XF::visitor();
        if (!$actor->user_id)
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
            $additionalItems = $this->collectPendingApprovalContentItems($contentUser);
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

        $this->queueAssociationJobs($incident->incident_id, $contentUser->user_id, $contentItems);

        $this->closeReportsForContent($contentItems, $incident);

        return $incident;
    }

    protected function resolveContentUser(): ?User
    {
        return $this->extractContentOwner($this->content);
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

    protected function collectPendingApprovalContentItems(User $user): array
    {
        $items = [];

        /** @var \XF\Mvc\Entity\Finder $finder */
        $finder = $this->finder('XF:ApprovalQueue');
        $finder->with(['Content', 'Content.User']);

        /** @var ApprovalQueue $queueItem */
        foreach ($finder->fetch() as $queueItem)
        {
            $content = $queueItem->Content;
            if (!$content)
            {
                continue;
            }

            $owner = $this->extractContentOwner($content);
            if (!$owner || $owner->user_id !== $user->user_id)
            {
                continue;
            }

            $items[] = $this->buildContentItem($content, $owner);
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
        $processed = [];

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

        return sprintf('Flagged as CSAM in [url="%s"]%s[/url].', $url, $title);
    }
}
