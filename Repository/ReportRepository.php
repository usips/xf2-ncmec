<?php

namespace USIPS\NCMEC\Repository;

use XF\Mvc\Entity\Repository;
use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\AbstractCollection;
use USIPS\NCMEC\Entity\Report;

class ReportRepository extends Repository
{
    /**
     * Returns a finder for reports that are not yet finalized.
     */
    public function findOpenReports(): Finder
    {
        return $this->finder('USIPS\\NCMEC:Report')
            ->where('finished_on', null)
            ->order('created_date', 'DESC');
    }

    /**
     * Returns a collection of reports that can be assigned to incidents.
     */
    public function getOpenReports(): AbstractCollection
    {
        return $this->findOpenReports()->fetch();
    }

    /**
     * @return array<int,string>
     */
    public function getOpenReportPairs(): array
    {
        return $this->findOpenReports()->pluckFrom('report_id', 'report_id');
    }
}
