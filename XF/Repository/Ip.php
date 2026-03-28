<?php

namespace USIPS\NCMEC\XF\Repository;

class Ip extends XFCP_Ip
{
    protected function getIpPreservedUserSubqueries(): array
    {
        $subqueries = parent::getIpPreservedUserSubqueries();

        $subqueries[] = "SELECT DISTINCT iu.user_id
            FROM xf_usips_ncmec_incident_user AS iu
            INNER JOIN xf_usips_ncmec_incident AS i ON (iu.incident_id = i.incident_id)
            WHERE i.submitted_on IS NOT NULL";

        return $subqueries;
    }
}
