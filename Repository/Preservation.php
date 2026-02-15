<?php

namespace USIPS\NCMEC\Repository;

use XF\Mvc\Entity\Repository;

class Preservation extends Repository
{
    /**
     * Check if a user is preserved. Delegates to Listener's per-request cache
     * to avoid a DB query on every call.
     */
    public function isUserPreserved($userId)
    {
        return \USIPS\NCMEC\Listener::isUserPreserved($userId);
    }

    public function getPreservedUserIds()
    {
        return $this->db()->fetchAllColumn("
            SELECT DISTINCT iu.user_id
            FROM xf_usips_ncmec_incident_user AS iu
            INNER JOIN xf_usips_ncmec_incident AS i ON (iu.incident_id = i.incident_id)
            WHERE i.submitted_on IS NOT NULL
        ");
    }
}
