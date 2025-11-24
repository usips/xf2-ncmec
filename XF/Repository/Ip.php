<?php

namespace USIPS\NCMEC\XF\Repository;

class Ip extends XFCP_Ip
{
    public function pruneIps($cutOff = null)
    {
        if ($cutOff === null)
        {
            $options = $this->options()->ipLogCleanUp;
            if (empty($options['enabled']))
            {
                return 0;
            }

            $cutOff = \XF::$time - ($options['delay'] * 86400);
        }

        $db = $this->db();

        // Use a single query with subquery/join to avoid fetching IDs and handling large lists
        $db->query("
            DELETE ip
            FROM xf_ip AS ip
            LEFT JOIN (
                SELECT DISTINCT iu.user_id
                FROM xf_usips_ncmec_incident_user AS iu
                INNER JOIN xf_usips_ncmec_incident AS i ON (iu.incident_id = i.incident_id)
                WHERE i.submitted_on IS NOT NULL
            ) AS preserved ON (ip.user_id = preserved.user_id)
            WHERE ip.log_date < ?
            AND preserved.user_id IS NULL
        ", $cutOff);

        return $db->affectedRows();
    }
}
