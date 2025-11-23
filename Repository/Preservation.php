<?php

namespace USIPS\NCMEC\Repository;

use XF\Mvc\Entity\Repository;

class Preservation extends Repository
{
    public function isUserPreserved($userId)
    {
        return (bool)$this->db()->fetchOne("
            SELECT 1
            FROM xf_usips_ncmec_incident_user AS iu
            INNER JOIN xf_usips_ncmec_incident AS i ON (iu.incident_id = i.incident_id)
            WHERE iu.user_id = ?
            AND i.submitted_on IS NOT NULL
            LIMIT 1
        ", [$userId]);
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
