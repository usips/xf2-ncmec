<?php

namespace USIPS\NCMEC\Service\Incident;

use USIPS\NCMEC\Entity\Incident;
use XF\Service\AbstractService;

class Deleter extends AbstractService
{
    protected $incident;

    public function __construct(\XF\App $app, Incident $incident)
    {
        parent::__construct($app);
        $this->incident = $incident;
    }

    public function delete()
    {
        $this->db()->beginTransaction();

        try
        {
            // Get user IDs before deleting associations
            $userIds = $this->db()->fetchAllColumn('
                SELECT user_id 
                FROM xf_usips_ncmec_incident_user 
                WHERE incident_id = ?
            ', $this->incident->incident_id);

            // Delete report logs first (leaf table)
            $this->db()->delete('xf_usips_ncmec_report_log', 'report_id IN (
                SELECT report_id FROM xf_usips_ncmec_report WHERE incident_id = ?
            )', $this->incident->incident_id);

            // Delete reports
            $this->db()->delete('xf_usips_ncmec_report', 'incident_id = ?', $this->incident->incident_id);

            // Delete incident attachment data and update counts
            $this->deleteIncidentAttachmentData();

            // Delete incident content
            $this->db()->delete('xf_usips_ncmec_incident_content', 'incident_id = ?', $this->incident->incident_id);

            // Delete incident users
            $this->db()->delete('xf_usips_ncmec_incident_user', 'incident_id = ?', $this->incident->incident_id);

            // Update user fields for disassociated users
            if ($userIds)
            {
                $userFieldService = $this->service('USIPS\NCMEC:UserField');
                foreach ($userIds as $userId)
                {
                    $stillInIncident = $userFieldService->checkUserInAnyIncident($userId);
                    $userFieldService->updateIncidentField($userId, $stillInIncident);
                }
            }

            // Finally delete the incident itself
            $this->incident->delete();

            $this->db()->commit();
        }
        catch (\Exception $e)
        {
            $this->db()->rollback();
            throw $e;
        }
    }

    protected function deleteIncidentAttachmentData()
    {
        // Get all data_ids that will be affected
        $dataIds = $this->db()->fetchAllColumn('
            SELECT DISTINCT data_id 
            FROM xf_usips_ncmec_incident_attachment_data 
            WHERE incident_id = ?
        ', $this->incident->incident_id);

        // Delete the junction records
        $this->db()->delete('xf_usips_ncmec_incident_attachment_data', 'incident_id = ?', $this->incident->incident_id);

        // Update the denormalized counts for affected attachment data
        if ($dataIds)
        {
            $attachmentManager = $this->service('USIPS\NCMEC:Incident\AttachmentManager');
            foreach ($dataIds as $dataId)
            {
                $attachmentManager->updateIncidentCount($dataId);
            }
        }
    }
}