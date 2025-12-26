<?php

namespace USIPS\NCMEC\XF\Entity;

class UserProfile extends XFCP_UserProfile
{
    /**
     * Override to return null (no banner URL) when user media should be hidden
     */
    public function getBannerUrl($sizeCode, $canonical = false)
    {
        // Get the User entity to check visibility
        $user = $this->User;
        
        if ($user && method_exists($user, 'shouldHideUserMedia') && $user->shouldHideUserMedia())
        {
            return null;
        }

        return parent::getBannerUrl($sizeCode, $canonical);
    }
}
