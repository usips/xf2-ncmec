<?php

namespace USIPS\NCMEC\XF\ApprovalQueue;

use XF\Entity\ProfilePostComment;

class ProfilePostCommentHandler extends XFCP_ProfilePostCommentHandler
{
    use FlagCsamActionTrait;

    public function actionFlagCsam(ProfilePostComment $comment): void
    {
        parent::actionDelete($comment);
        $this->flagContentForNcmec($comment);
    }
}
