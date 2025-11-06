<?php

namespace USIPS\NCMEC\Job;

use XF\Job\AbstractJob;

class DisassociateUser extends AbstractJob
{
    protected $defaultData = [
        'incident_id' => 0,
        'user_ids' => [], // Array of user IDs to disassociate
    ];

    public function run($maxRunTime)
    {
        $incidentId = $this->data['incident_id'];
        $userIds = $this->data['user_ids'];

        if (!$incidentId || empty($userIds))
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
        $creator->disassociateUsers($userIds);

        return $this->complete();
    }

    public function getStatusMessage()
    {
        return \XF::phrase('usips_ncmec_disassociating_user_from_incident');
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