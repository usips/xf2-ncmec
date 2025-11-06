<?php

namespace USIPS\NCMEC\Job;

use XF\Job\AbstractJob;

class AssociateUser extends AbstractJob
{
    protected $defaultData = [
        'incident_id' => 0,
        'user_id' => 0, // For single user association
        'user_ids' => [], // For batch user association
        'time_limit_seconds' => 172800, // 48 hours default
    ];

    public function run($maxRunTime)
    {
        $incidentId = $this->data['incident_id'];
        $userId = $this->data['user_id'];
        $userIds = $this->data['user_ids'];
        $timeLimitSeconds = $this->data['time_limit_seconds'] ?? 172800; // 48 hours default

        if (!$incidentId)
        {
            return $this->complete();
        }

        // Determine which users to process
        $usersToProcess = [];
        if ($userId)
        {
            $usersToProcess[] = $userId;
        }
        if (!empty($userIds))
        {
            $usersToProcess = array_merge($usersToProcess, $userIds);
        }
        $usersToProcess = array_unique($usersToProcess);

        if (empty($usersToProcess))
        {
            return $this->complete();
        }

        $app = \XF::app();
        $creator = $app->service('USIPS\NCMEC:Incident\Creator');
        $incident = $app->find('USIPS\NCMEC:Incident', $incidentId);
        if (!$incident)
        {
            return $this->complete();
        }

        $creator->setIncident($incident);

        try {
            // Associate all users
            $creator->associateUsersByIds($usersToProcess);

            // For each user, collect and associate their content and attachments within time limit
            foreach ($usersToProcess as $uid)
            {
                // Collect and associate user content within time limit
                $contentItems = $creator->collectUserContentWithinTimeLimit($uid, $timeLimitSeconds);
                if (!empty($contentItems))
                {
                    $creator->associateContentByIds($contentItems);
                }

                // Collect and associate user attachments within time limit
                $attachmentDataIds = $creator->collectUserAttachmentDataWithinTimeLimit($uid, $timeLimitSeconds);
                if (!empty($attachmentDataIds))
                {
                    $creator->associateAttachmentsByDataIds($attachmentDataIds);
                }
            }
        } catch (\Exception $e) {
            // Log the error but don't fail the job
            \XF::logError('NCMEC AssociateUser job failed: ' . $e->getMessage());
        }

        return $this->complete();
    }

    public function getStatusMessage()
    {
        return \XF::phrase('usips_ncmec_associating_user_with_incident');
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