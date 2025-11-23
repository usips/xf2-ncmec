<?php

namespace USIPS\NCMEC\XF\ApprovalQueue;

use XF\Entity\Post;

class PostHandler extends XFCP_PostHandler
{
    use FlagCsamActionTrait;

    public function actionFlagCsam(Post $post): void
    {
        $this->flagContentForNcmec($post);
    }
}
