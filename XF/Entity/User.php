<?php

namespace USIPS\NCMEC\XF\Entity;

use XF\Mvc\Entity\Structure;
use USIPS\NCMEC\Repository\UserMediaVisibility;

class User extends XFCP_User
{
    /**
     * Check if this user's avatar/banner should be hidden from the current viewer
     */
    public function shouldHideUserMedia(): bool
    {
        if (!$this->user_id)
        {
            return false;
        }

        // Admins and moderators can always see avatars/banners
        $visitor = \XF::visitor();
        if ($visitor->is_admin || $visitor->is_moderator)
        {
            return false;
        }

        return \XF::repository('USIPS\NCMEC:UserMediaVisibility')->shouldHideUserMedia($this->user_id);
    }

    /**
     * Override to return 'default' type when user media should be hidden
     */
    public function getAvatarType()
    {
        if ($this->shouldHideUserMedia())
        {
            return 'default';
        }

        return parent::getAvatarType();
    }

    /**
     * Override to return null (no avatar URL) when user media should be hidden
     */
    public function getAvatarUrl($sizeCode, $forceType = null, $canonical = false)
    {
        if ($this->shouldHideUserMedia())
        {
            return null;
        }

        return parent::getAvatarUrl($sizeCode, $forceType, $canonical);
    }

    /**
     * Override to return empty string when user media should be hidden
     */
    public function getAvatarUrl2x($sizeCode, $forceType = null, $canonical = false)
    {
        if ($this->shouldHideUserMedia())
        {
            return '';
        }

        return parent::getAvatarUrl2x($sizeCode, $forceType, $canonical);
    }

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
