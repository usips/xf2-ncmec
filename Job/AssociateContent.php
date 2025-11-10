<?php

namespace USIPS\NCMEC\Job;

use XF\Job\AbstractJob;

class AssociateContent extends AbstractJob
{
    protected $defaultData = [
        'incident_id' => 0,
        'content_items' => [], // Flexible: array of [content_type, content_id], or ['content_type' => [content_ids]], or iterable content objects
        'time_limit_seconds' => 0,
    ];

    public function run($maxRunTime)
    {
        $incidentId = $this->data['incident_id'];
        $contentItems = $this->data['content_items'];

        if (!$incidentId || empty($contentItems))
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
            $creator->associateContentCascade($contentItems);
        } catch (\Exception $e) {
            // Log the error but don't fail the job
            \XF::logError('NCMEC AssociateContent job failed: ' . $e->getMessage());
        }

        return $this->complete();
    }

    public function getStatusMessage()
    {
        return \XF::phrase('usips_ncmec_associating_content_with_incident');
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