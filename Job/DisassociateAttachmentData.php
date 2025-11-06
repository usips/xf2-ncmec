<?php

namespace USIPS\NCMEC\Job;

use XF\Job\AbstractJob;

class DisassociateAttachmentData extends AbstractJob
{
    protected $defaultData = [
        'incident_id' => 0,
        'attachment_data_ids' => [], // Array of attachment data IDs to disassociate
        'time_limit_seconds' => 0,
    ];

    public function run($maxRunTime)
    {
        $incidentId = $this->data['incident_id'];
        $attachmentDataIds = $this->data['attachment_data_ids'];

        if (!$incidentId || empty($attachmentDataIds))
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
            // Disassociate attachment data
            $creator->disassociateAttachmentsByDataIds($attachmentDataIds);
        } catch (\Exception $e) {
            // Log the error but don't fail the job
            \XF::logError('NCMEC DisassociateAttachmentData job failed: ' . $e->getMessage());
        }

        return $this->complete();
    }

    public function getStatusMessage()
    {
        return \XF::phrase('usips_ncmec_disassociating_attachment_data_from_incident');
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