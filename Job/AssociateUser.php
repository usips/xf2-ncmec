<?php

namespace USIPS\NCMEC\Job;

use XF\Job\AbstractRebuildJob;

class AssociateUser extends AbstractRebuildJob
{
    /** @var \USIPS\NCMEC\Service\UserPromotion|null */
    protected $userPromotionService = null;

    protected $defaultData = [
        'incident_id' => 0,
        'user_ids' => [], // Array of user IDs to associate
        'time_limit_seconds' => 172800, // 48 hours default
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
        $timeLimitSeconds = $this->data['time_limit_seconds'] ?? 172800;

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
            // Associate this specific user
            $creator->associateUsersByIds([$id]);

            // Collect and associate their content and attachments within time limit
            $contentItems = $creator->collectUserContentWithinTimeLimit($id, $timeLimitSeconds);
            if (!empty($contentItems))
            {
                $creator->associateContentByIds($contentItems);
            }

            $attachmentDataIds = $creator->collectUserAttachmentDataWithinTimeLimit($id, $timeLimitSeconds);
            if (!empty($attachmentDataIds))
            {
                $creator->associateAttachmentsByDataIds($attachmentDataIds);
            }

            // Update promotions for this user after association is complete
            $this->getUserPromotionService()->updateUser($id);
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