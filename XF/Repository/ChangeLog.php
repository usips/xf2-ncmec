<?php

namespace USIPS\NCMEC\XF\Repository;

class ChangeLog extends XFCP_ChangeLog
{
    protected function getChangeLogPreservedUserSubqueries(): array
    {
        $subqueries = parent::getChangeLogPreservedUserSubqueries();

        $subqueries[] = "SELECT DISTINCT iu.user_id
            FROM xf_usips_ncmec_incident_user AS iu
            INNER JOIN xf_usips_ncmec_incident AS i ON (iu.incident_id = i.incident_id)
            WHERE i.submitted_on IS NOT NULL";

        return $subqueries;
    }
}
