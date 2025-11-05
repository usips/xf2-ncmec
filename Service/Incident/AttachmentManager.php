<?php

namespace USIPS\NCMEC\Service\Incident;

use XF\Service\AbstractService;

class AttachmentManager extends AbstractService
{
    /**
     * Add an attachment to an incident
     *
     * @param int $incidentId
     * @param int $dataId
     * @param int $userId
     * @param string $username
     * @return \USIPS\NCMEC\Entity\IncidentAttachmentData|null
     */
    public function addAttachmentToIncident($incidentId, $dataId, $userId, $username)
    {
        // Check if it already exists
        $existing = $this->finder('USIPS\NCMEC:IncidentAttachmentData')
            ->where('incident_id', $incidentId)
            ->where('data_id', $dataId)
            ->fetchOne();

        if ($existing)
        {
            return null; // Already exists
        }

        $incidentAttachment = $this->em()->create('USIPS\NCMEC:IncidentAttachmentData');
        $incidentAttachment->incident_id = $incidentId;
        $incidentAttachment->data_id = $dataId;
        $incidentAttachment->user_id = $userId;
        $incidentAttachment->username = $username;
        $incidentAttachment->save();

        return $incidentAttachment;
    }

    /**
     * Remove an attachment from an incident
     *
     * @param int $incidentId
     * @param int $dataId
     * @return bool
     */
    public function removeAttachmentFromIncident($incidentId, $dataId)
    {
        $incidentAttachment = $this->finder('USIPS\NCMEC:IncidentAttachmentData')
            ->where('incident_id', $incidentId)
            ->where('data_id', $dataId)
            ->fetchOne();

        if (!$incidentAttachment)
        {
            return false;
        }

        $incidentAttachment->delete();
        return true;
    }
}