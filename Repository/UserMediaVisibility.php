<?php

namespace USIPS\NCMEC\Repository;

use XF\Mvc\Entity\Repository;

class UserMediaVisibility extends Repository
{
    /**
     * Cache of user IDs that should have hidden avatars/banners
     * @var array|null
     */
    protected static $incidentUserIds = null;

    /**
     * Cache of user IDs with open profile reports
     * @var array|null
     */
    protected static $reportedUserIds = null;

    /**
     * Check if a user's avatar/banner should be hidden
     */
    public function shouldHideUserMedia($userId): bool
    {
        if (!$userId)
        {
            return false;
        }

        return $this->isUserInIncident($userId) || $this->hasOpenProfileReport($userId);
    }

    /**
     * Check if user is involved in any NCMEC incident
     */
    public function isUserInIncident($userId): bool
    {
        if (self::$incidentUserIds === null)
        {
            self::$incidentUserIds = $this->getIncidentUserIds();
        }

        return isset(self::$incidentUserIds[$userId]);
    }

    /**
     * Check if user has an open report against their profile
     */
    public function hasOpenProfileReport($userId): bool
    {
        if (self::$reportedUserIds === null)
        {
            self::$reportedUserIds = $this->getReportedUserIds();
        }

        return isset(self::$reportedUserIds[$userId]);
    }

    /**
     * Get all user IDs involved in NCMEC incidents
     */
    protected function getIncidentUserIds(): array
    {
        $userIds = $this->db()->fetchAllColumn("
            SELECT DISTINCT user_id
            FROM xf_usips_ncmec_incident_user
        ");

        return array_fill_keys($userIds, true);
    }

    /**
     * Get all user IDs with open profile reports
     */
    protected function getReportedUserIds(): array
    {
        $userIds = $this->db()->fetchAllColumn("
            SELECT DISTINCT content_id
            FROM xf_report
            WHERE content_type = 'user'
            AND report_state = 'open'
        ");

        return array_fill_keys($userIds, true);
    }

    /**
     * Clear the cached user IDs (call after incident/report changes)
     */
    public static function clearCache()
    {
        self::$incidentUserIds = null;
        self::$reportedUserIds = null;
    }
}
