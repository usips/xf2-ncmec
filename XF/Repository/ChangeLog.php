<?php

namespace USIPS\NCMEC\XF\Repository;

class ChangeLog extends XFCP_ChangeLog
{
    public function pruneChangeLogs($cutOff = null)
    {
        if ($cutOff === null)
        {
            $length = $this->options()->changeLogLength;
            if (!$length)
            {
                return 0;
            }

            $cutOff = \XF::$time - ($length * 86400);
        }

        $db = $this->db();

        // Use a single query with subquery/join to avoid fetching IDs and handling large lists
        // Also ensures we respect 'protected = 0'
        $statement = $db->query("
            DELETE cl
            FROM xf_change_log AS cl
            LEFT JOIN (
                SELECT DISTINCT iu.user_id
                FROM xf_usips_ncmec_incident_user AS iu
                INNER JOIN xf_usips_ncmec_incident AS i ON (iu.incident_id = i.incident_id)
                WHERE i.submitted_on IS NOT NULL
            ) AS preserved ON (cl.edit_user_id = preserved.user_id)
            WHERE cl.edit_date < ?
            AND cl.protected = 0
            AND preserved.user_id IS NULL
        ", $cutOff);

        return $statement->rowsAffected();
    }
}
