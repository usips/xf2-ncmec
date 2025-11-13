<?php

namespace USIPS\NCMEC\XF\Service\Report;

use Throwable;
use XF\Entity\Post;
use XF\Entity\Report;
use XF\Entity\ReportComment;
use XF\Entity\Thread;
use XF\Mvc\Entity\Entity;
use XF\Service\Report\NotifierService;

class CreatorService extends XFCP_CreatorService
{
    /** @var bool */
    protected $usipsEmergency = false;

    /** @var Entity|null */
    protected $usipsEmergencyContent;

    public function getReportEntity(): Report
    {
        return $this->report;
    }

    public function getReportComment(): ReportComment
    {
        return $this->comment;
    }

    public function enableEmergencyHandling(Entity $content): void
    {
        $this->usipsEmergency = true;
        $this->usipsEmergencyContent = $content;
    }

    public function save()
    {
        $result = parent::save();

        if ($this->usipsEmergency)
        {
            $this->handleEmergencyModeration();
        }

        return $result;
    }

    public function sendNotifications()
    {
        if ($this->threadCreator)
        {
            parent::sendNotifications();
            return;
        }

        /** @var NotifierService $notifier */
        $notifier = $this->service(NotifierService::class, $this->report, $this->comment);

        if ($this->usipsEmergency && method_exists($notifier, 'enableEmergencyMode'))
        {
            $notifier->enableEmergencyMode();
        }

        $notifier->notifyCreate();
    }

    protected function handleEmergencyModeration(): void
    {
        $content = $this->usipsEmergencyContent;
        if (!$content || !$content->exists())
        {
            return;
        }

        $threadToModerate = $this->identifyThreadForEmergencyModeration($content);

        $this->moderatePrimaryContentForEmergency($content);

        if ($threadToModerate)
        {
            $this->moderateThreadForEmergency($threadToModerate);
        }

        $this->usipsEmergencyContent = null;
    }

    protected function moderatePrimaryContentForEmergency(Entity $content): void
    {
        try
        {
            if ($content instanceof Post)
            {
                $this->moderatePostForEmergency($content);
                return;
            }

            if ($content instanceof Thread)
            {
                $this->moderateThreadForEmergency($content);
                return;
            }

            $this->moderateGenericContentForEmergency($content);
        }
        catch (Throwable $e)
        {
            \XF::logException($e, false, 'Failed to moderate primary content for emergency report: ');
        }
    }

    protected function moderatePostForEmergency(Post $post): void
    {
        if ($post->message_state === 'moderated' || $post->message_state === 'deleted')
        {
            return;
        }

        /** @var \XF\Service\Post\Editor $editor */
        $editor = $this->service('XF:Post\Editor', $post);
        $editor->setMessageState('moderated', 'Emergency report pending review');
        $editor->save();
    }

    protected function moderateGenericContentForEmergency(Entity $content): void
    {
        $stateColumns = [
            'message_state',
            'discussion_state',
            'content_state',
            'comment_state',
            'article_state',
            'resource_state',
            'media_state',
            'album_state',
            'entry_state',
        ];

        $changed = false;

        foreach ($stateColumns as $column)
        {
            if (!$content->isValidColumn($column))
            {
                continue;
            }

            $columnInfo = $content->structureColumn($column);
            if ($columnInfo && isset($columnInfo['allowedValues']) && !in_array('moderated', $columnInfo['allowedValues'], true))
            {
                continue;
            }

            $current = $content->get($column);
            if (!is_string($current) || $current === 'moderated' || $current === 'deleted')
            {
                continue;
            }

            $content->set($column, 'moderated');
            $changed = true;
        }

        if (!$changed)
        {
            return;
        }

        try
        {
            $content->save(true, false);
        }
        catch (Throwable $e)
        {
            \XF::logException($e, false, 'Failed to moderate generic content for emergency report: ');
        }
    }

    protected function identifyThreadForEmergencyModeration(Entity $content): ?Thread
    {
        if (!($content instanceof Post))
        {
            return null;
        }

        $thread = $content->Thread;
        if (!$thread || !$thread->exists())
        {
            return null;
        }

        if (!$content->isFirstPost())
        {
            return null;
        }

        if ($content->getExistingValue('message_state') !== 'visible')
        {
            return null;
        }

        if (!$thread->isValidColumn('discussion_state'))
        {
            return null;
        }

        if ($thread->discussion_state !== 'visible')
        {
            return null;
        }

        return $thread;
    }

    protected function moderateThreadForEmergency(Thread $thread): void
    {
        if ($thread->discussion_state === 'moderated' || $thread->discussion_state === 'deleted')
        {
            return;
        }

        try
        {
            /** @var \XF\Service\Thread\Editor $editor */
            $editor = $this->service('XF:Thread\Editor', $thread);
            $editor->setDiscussionState('moderated', 'Emergency report pending review');
            $editor->save();
        }
        catch (Throwable $e)
        {
            \XF::logException($e, false, 'Failed to moderate thread for emergency report: ');
        }
    }
}
