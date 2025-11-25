<?php

namespace USIPS\NCMEC\Service\Incident;

use USIPS\NCMEC\Entity\Incident;
use XF\PrintableException;
use XF\Service\AbstractService;

/**
 * Service to handle deletion of NCMEC Incidents.
 * 
 * Deletion is only allowed if there are no associated reports,
 * to comply with data retention requirements.
 * 
 * Note: This service only deletes the incident and its direct associations.
 * Report creation (which handles users, content, and file cleanup) is done elsewhere.
 */
class Deleter extends AbstractService
{
    protected $incident;
    /** @var \USIPS\NCMEC\Service\UserPromotion|null */
    protected $userPromotionService = null;

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
            // Prevent deletion when the incident is tied to a FINALIZED report/case
            if ($this->incident->isFinalized())
            {
                throw new PrintableException(\XF::phrase('usips_ncmec_incident_finalized_cannot_delete'));
            }

            // Get user IDs before deleting associations
            $userIds = $this->db()->fetchAllColumn('
                SELECT user_id 
                FROM xf_usips_ncmec_incident_user 
                WHERE incident_id = ?
            ', $this->incident->incident_id);

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

                // Update promotions for all affected users
                $this->getUserPromotionService()->updateUsers($userIds);
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

    protected function getUserPromotionService()
    {
        if ($this->userPromotionService === null)
        {
            $this->userPromotionService = $this->service('USIPS\\NCMEC:UserPromotion');
        }

        return $this->userPromotionService;
    }
}