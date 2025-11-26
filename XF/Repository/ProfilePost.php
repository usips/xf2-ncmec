<?php

namespace USIPS\NCMEC\XF\Repository;

use XF\Entity\User;

class ProfilePost extends XFCP_ProfilePost
{
    public function findProfilePostsForUser(User $user, array $limits = [])
    {
        $finder = parent::findProfilePostsForUser($user, $limits);
        \USIPS\NCMEC\Listener::finderSetup($finder);
        return $finder;
    }

    public function findProfilePostsOnProfile(User $profileUser, array $limits = [])
    {
        $finder = parent::findProfilePostsOnProfile($profileUser, $limits);
        \USIPS\NCMEC\Listener::finderSetup($finder);
        return $finder;
    }

    public function findNewestProfilePosts(array $limits = [])
    {
        $finder = parent::findNewestProfilePosts($limits);
        \USIPS\NCMEC\Listener::finderSetup($finder);
        return $finder;
    }
}
