<?php

namespace USIPS\NCMEC\XF\Repository;

use XF\Entity\User;

class ProfilePost extends XFCP_ProfilePost
{
    public function findProfilePostsOnProfile(User $profileUser, array $limits = [])
    {
        $finder = parent::findProfilePostsOnProfile($profileUser, $limits);
        \USIPS\NCMEC\Listener::finderSetup($finder);
        return $finder;
    }

    public function findNewestProfilePosts($newerThan)
    {
        $finder = parent::findNewestProfilePosts($newerThan);
        \USIPS\NCMEC\Listener::finderSetup($finder);
        return $finder;
    }
}
