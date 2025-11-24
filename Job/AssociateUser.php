<?php

namespace USIPS\NCMEC\Job;

use USIPS\NCMEC\Util\TimeLimit;
use XF\Job\AbstractRebuildJob;

class AssociateUser extends AbstractRebuildJob
{
    /** @var \USIPS\NCMEC\Service\UserPromotion|null */
    protected $userPromotionService = null;

    protected $defaultData = [
        'incident_id' => 0,
        'user_ids' => [], // Array of user IDs to associate
        'time_limit_seconds' => -1,
    ];

    protected function getNextIds($start, $batch)
    {
        $userIds = $this->data['user_ids'];
        
        // Find the next batch of user IDs starting from $start
        $remainingIds = array_filter($userIds, function($id) use ($start) {
            return $id > $start;
        });
        
        sort($remainingIds);
        
        return array_slice($remainingIds, 0, $batch);
    }

    protected function rebuildById($id)
    {
        $incidentId = $this->data['incident_id'];
    $timeLimitSeconds = TimeLimit::resolve($this->data['time_limit_seconds'] ?? null);

        if (!$incidentId)
        {
            return;
        }

        $app = \XF::app();
        $creator = $app->service('USIPS\NCMEC:Incident\Creator');
        $incident = $app->find('USIPS\NCMEC:Incident', $incidentId);
        if (!$incident)
        {
            return;
        }

        $creator->setIncident($incident);

        try {
            $creator->associateUserCascade($id, $timeLimitSeconds);
        } catch (\Exception $e) {
            // Log the error but continue with other users
            \XF::logError('NCMEC AssociateUser job failed for user ' . $id . ': ' . $e->getMessage());
        }
    }

    protected function getUserPromotionService()
    {
        if ($this->userPromotionService === null)
        {
            $this->userPromotionService = $this->app->service('USIPS\\NCMEC:UserPromotion');
        }

        return $this->userPromotionService;
    }

    protected function getStatusType()
    {
        return \XF::phrase('users');
    }

    public function canCancel()
    {
        return true;
    }

    public function canTriggerByChoice()
    {
        return false;
    }
}