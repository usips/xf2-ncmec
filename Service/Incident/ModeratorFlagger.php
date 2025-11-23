<?php

namespace USIPS\NCMEC\Service\Incident;

use USIPS\NCMEC\Util\TimeLimit;
use XF\Entity\ApprovalQueue;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;

class ModeratorFlagger extends AbstractFlagger
{
    public function __construct(\XF\App $app, Entity $content)
    {
        parent::__construct($app);
        $this->content = $content;
        $this->contentUser = $this->resolveContentUser();
    }

    protected function collectAdditionalContent(User $user): array
    {
        $items = [];

        // 1. Collect items from Approval Queue
        /** @var \XF\Mvc\Entity\Finder $finder */
        $finder = $this->finder('XF:ApprovalQueue');

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

        // 2. Collect recent content based on Time Limit (same as ReportFlagger)
        $timeLimitSeconds = TimeLimit::getDefaultSeconds();
        
        $recentItems = $this->collectUserContentItems($user, $timeLimitSeconds);
        if ($recentItems)
        {
            $items = $this->mergeContentItems($items, $recentItems);
        }

        return $items;
    }
}
