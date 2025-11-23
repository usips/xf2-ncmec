<?php

namespace USIPS\NCMEC\Service\Incident;

use USIPS\NCMEC\Entity\Incident;
use USIPS\NCMEC\Util\TimeLimit;
use XF\Entity\Report;
use XF\Entity\User;

/**
 * Flags a report's content as CSAM, creates or updates an incident,
 * associates content, and closes related reports.
 */
class ReportFlagger extends AbstractFlagger
{
    /** @var Report */
    protected $report;

    public function __construct(\XF\App $app, Report $report)
    {
        parent::__construct($app);
        $this->report = $report;
        $this->content = $report->Content;
        $this->contentUser = $this->resolveContentUser();
    }

    protected function collectAdditionalContent(User $user): array
    {
        $timeLimitSeconds = TimeLimit::getDefaultSeconds();
        
        return $this->collectUserContentItems($user, $timeLimitSeconds);
    }

    protected function closeReportsForContent(array $contentItems, Incident $incident, array $processed = []): void
    {
        $processed[$this->report->report_id] = true;
        parent::closeReportsForContent($contentItems, $incident, $processed);
    }
}
