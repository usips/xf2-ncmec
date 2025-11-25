<?php

namespace USIPS\NCMEC\Job;

use XF\Job\AbstractJob;

class AssociateAttachmentData extends AbstractJob
{
    protected $defaultData = [
        'incident_id' => 0,
        'attachment_data_ids' => [], // Array of attachment data IDs to associate
        'time_limit_seconds' => 0,
    ];

    public function run($maxRunTime)
    {
        $incidentId = $this->data['incident_id'];
        $attachmentDataIds = $this->data['attachment_data_ids'];

        if ($attachmentDataIds instanceof \XF\Mvc\Entity\ArrayCollection)
        {
            $attachmentDataIds = $attachmentDataIds->toArray();
        }

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
            // Associate attachment data
            $creator->associateAttachmentsByDataIds($attachmentDataIds);

            // Find attachments to associate content and users
            $attachments = $app->finder('XF:Attachment')
                ->where('data_id', $attachmentDataIds)
                ->fetch();

            if ($attachments->count())
            {
                $creator->associateAttachmentUsers($attachments);
                $associatedItems = $creator->associateContent($attachments);
                $creator->closeReportsForContent($associatedItems);
                $creator->deleteContent($associatedItems);
            }
        } catch (\Exception $e) {
            // Log the error but don't fail the job
            \XF::logError('NCMEC AssociateAttachmentData job failed: ' . $e->getMessage());
        }

        return $this->complete();
    }

    public function getStatusMessage()
    {
        return \XF::phrase('usips_ncmec_associating_attachment_data_with_incident');
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