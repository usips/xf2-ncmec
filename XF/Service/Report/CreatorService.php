<?php

namespace USIPS\NCMEC\XF\Service\Report;

use Throwable;
use XF\Entity\Report;
use XF\Entity\ReportComment;
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

        if ($changed)
        {
            try
            {
                $content->save(true, false);
            }
            catch (Throwable $e)
            {
                \XF::logException($e, false, 'Failed to moderate content for emergency report: ');
            }
        }

        $this->usipsEmergencyContent = null;
    }
}
