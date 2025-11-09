<?php

namespace USIPS\NCMEC\XF\ApprovalQueue;

use XF\Entity\ProfilePost;

class ProfilePostHandler extends XFCP_ProfilePostHandler
{
    use FlagCsamActionTrait;

    public function actionFlagCsam(ProfilePost $profilePost): void
    {
        parent::actionDelete($profilePost);
        $this->flagContentForNcmec($profilePost);
    }
}
