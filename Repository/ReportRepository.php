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
            ->where('is_finished', 0)
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
     * Generates the next local report identifier.
     */
    public function getNextReportId(): int
    {
        /** @var Report|null $latest */
        $latest = $this->finder('USIPS\\NCMEC:Report')
            ->order('report_id', 'DESC')
            ->fetchOne();

        if (!$latest)
        {
            return 1;
        }

        return (int) $latest->report_id + 1;
    }

    /**
     * @return array<int,string>
     */
    public function getOpenReportPairs(): array
    {
        return $this->findOpenReports()->pluckFrom('report_id', 'report_id');
    }
}
