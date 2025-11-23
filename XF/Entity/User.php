<?php

namespace USIPS\NCMEC\XF\Entity;

use XF\Mvc\Entity\Structure;

class User extends XFCP_User
{
    public function canDelete(&$error = null)
    {
        // 18 U.S. Code § 2703
        if ($this->user_id && \XF::repository('USIPS\NCMEC:Preservation')->isUserPreserved($this->user_id))
        {
            $error = \XF::phrase('usips_ncmec_cannot_delete_preservation_required');
            return false;
        }

        return parent::canDelete($error);
    }
}
