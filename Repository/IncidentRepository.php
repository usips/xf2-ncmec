<?php

namespace USIPS\NCMEC\Repository;

use XF\Mvc\Entity\Repository;

class IncidentRepository extends Repository
{
    /**
     * Count users associated with an incident
     */
    public function countIncidentUsers($incidentId)
    {
        return $this->db()->fetchOne('
            SELECT COUNT(*)
            FROM xf_usips_ncmec_incident_user
            WHERE incident_id = ?
        ', $incidentId);
    }

    /**
     * Count content pieces associated with an incident
     */
    public function countIncidentContent($incidentId)
    {
        return $this->db()->fetchOne('
            SELECT COUNT(*)
            FROM xf_usips_ncmec_incident_content
            WHERE incident_id = ?
        ', $incidentId);
    }

    /**
     * Count attachment data associated with an incident
     */
    public function countIncidentAttachments($incidentId)
    {
        return $this->db()->fetchOne('
            SELECT COUNT(*)
            FROM xf_usips_ncmec_incident_attachment_data
            WHERE incident_id = ?
        ', $incidentId);
    }

    /**
     * Get all counts for an incident
     */
    public function getIncidentCounts($incidentId)
    {
        return [
            'users' => $this->countIncidentUsers($incidentId),
            'content' => $this->countIncidentContent($incidentId),
            'attachments' => $this->countIncidentAttachments($incidentId),
        ];
    }
}