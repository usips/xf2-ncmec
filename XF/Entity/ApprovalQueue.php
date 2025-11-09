<?php

namespace USIPS\NCMEC\XF\Entity;

use XF\Entity\User;
use XF\Mvc\Entity\Entity;

class ApprovalQueue extends XFCP_ApprovalQueue
{
    public function getDefaultActions()
    {
        $actions = parent::getDefaultActions();

        if (!$actions || isset($actions['flag_csam']))
        {
            return $actions;
        }

        $handler = $this->getHandler();
        if (!$handler || !method_exists($handler, 'actionFlagCsam'))
        {
            return $actions;
        }

        $content = $this->getContent();
        if (!$content)
        {
            return $actions;
        }

        $visitor = \XF::visitor();
        if (!$visitor->user_id)
        {
            return $actions;
        }

        $contentOwner = $this->resolveContentOwner($content);
        if (!$contentOwner || !$contentOwner->user_id)
        {
            return $actions;
        }

        $actions['flag_csam'] = \XF::phrase('report_state.flag_as_csam');

        return $actions;
    }

    protected function resolveContentOwner(Entity $content): ?User
    {
        if ($content instanceof User)
        {
            return $content;
        }

        if (isset($content->User) && $content->User instanceof User)
        {
            return $content->User;
        }

        if ($content->isValidColumn('user_id'))
        {
            $userId = (int) $content->get('user_id');
            if ($userId)
            {
                return $this->em()->find('XF:User', $userId);
            }
        }

        return null;
    }
}
