<?php

namespace USIPS\NCMEC\XF\ApprovalQueue;

use XF\Entity\ProfilePostComment;

class ProfilePostCommentHandler extends XFCP_ProfilePostCommentHandler
{
    use FlagCsamActionTrait;

    public function actionFlagCsam(ProfilePostComment $comment): void
    {
        $this->flagContentForNcmec($comment);
    }
}
