<?php

namespace USIPS\NCMEC\Service;

use XF\Finder\UserFinder;
use XF\Repository\UserGroupPromotionRepository;

class UserPromotion extends \XF\Service\AbstractService
{
    /** @var UserGroupPromotionRepository|null */
    protected $promotionRepo = null;

    /** @var array|null */
    protected $activePromotions = null;

    public function updateUser(int $userId): void
    {
        $this->updateUsers([$userId]);
    }

    public function updateUsers(array $userIds): void
    {
        $userIds = array_unique(array_map('intval', $userIds));
        $userIds = array_filter($userIds); // drop zeros

        if (!$userIds)
        {
            return;
        }

        $promotionRepo = $this->getPromotionRepo();
        $promotions = $this->getActivePromotions();

        if (!$promotions)
        {
            return;
        }

        /** @var UserFinder $userFinder */
        $userFinder = $this->finder('XF:User');
        $users = $userFinder
            ->where('user_id', $userIds)
            ->with(['Profile', 'Option'])
            ->order('user_id')
            ->fetch();

        if (!$users->count())
        {
            return;
        }

        $promotionLogs = $promotionRepo->getUserGroupPromotionLogsForUsers($users->keys());

        foreach ($users as $user)
        {
            $promotionRepo->updatePromotionsForUser(
                $user,
                $promotionLogs[$user->user_id] ?? [],
                $promotions
            );
        }
    }

    protected function getPromotionRepo(): UserGroupPromotionRepository
    {
        if ($this->promotionRepo === null)
        {
            $this->promotionRepo = $this->repository('XF:UserGroupPromotion');
        }

        return $this->promotionRepo;
    }

    protected function getActivePromotions(): array
    {
        if ($this->activePromotions === null)
        {
            $this->activePromotions = $this->getPromotionRepo()->getActiveUserGroupPromotions();
        }

        return $this->activePromotions;
    }
}
