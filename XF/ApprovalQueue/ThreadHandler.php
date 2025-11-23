<?php

namespace USIPS\NCMEC\XF\ApprovalQueue;

use XF\Entity\Thread;

class ThreadHandler extends XFCP_ThreadHandler
{
    use FlagCsamActionTrait;

    public function actionFlagCsam(Thread $thread): void
    {
        $this->flagContentForNcmec($thread);
    }
}
