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

    protected function _preDelete()
    {
        parent::_preDelete();

        if ($this->user_id && \XF::repository('USIPS\NCMEC:Preservation')->isUserPreserved($this->user_id))
        {
            $this->error(\XF::phrase('usips_ncmec_cannot_delete_preservation_required'));
        }
    }

    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $structure->relations['IncidentUsers'] = [
            'entity' => 'USIPS\NCMEC:IncidentUser',
            'type' => self::TO_MANY,
            'conditions' => 'user_id',
            'key' => 'incident_id'
        ];

        return $structure;
    }
}
