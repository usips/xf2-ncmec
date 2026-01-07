<?php

namespace USIPS\NCMEC\Repository;

use XF\Mvc\Entity\Repository;

/**
 * Repository for determining user avatar/profile banner visibility.
 * 
 * IMPORTANT DISTINCTION:
 * 
 * 1. NCMEC Incident (isUserInIncident):
 *    - Full account lockdown - hides ALL user content
 *    - User's posts, threads, media, etc. are hidden from other users
 *    - This is a complete panic response
 * 
 * 2. Emergency Profile Report (hasEmergencyProfileReport):
 *    - Precautionary measure - hides avatar and profile banner ONLY
 *    - User's content remains visible
 *    - This is a lighter response for direct profile reports
 * 
 * Both conditions result in hidden avatars/banners, but they are NOT the same thing.
 */
class UserMediaVisibility extends Repository
{
    /**
     * Cache of user IDs involved in NCMEC incidents (full lockdown)
     * @var array|null
     */
    protected static $incidentUserIds = null;

    /**
     * Cache of user IDs with emergency profile reports (avatar/banner hidden only)
     * @var array|null
     */
    protected static $emergencyReportUserIds = null;

    /**
     * Check if a user's avatar/profile banner should be hidden.
     * This applies to BOTH incident users AND emergency profile reports.
     */
    public function shouldHideUserMedia($userId): bool
    {
        if (!$userId)
        {
            return false;
        }

        return $this->isUserInIncident($userId) || $this->hasEmergencyProfileReport($userId);
    }

    /**
     * Check if user is involved in any NCMEC incident.
     * This triggers FULL content lockdown, not just avatar hiding.
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
     * Check if user has an open/assigned emergency report against their profile.
     * This triggers avatar/banner hiding as a precautionary measure.
     */
    public function hasEmergencyProfileReport($userId): bool
    {
        if (self::$emergencyReportUserIds === null)
        {
            self::$emergencyReportUserIds = $this->getEmergencyProfileReportUserIds();
        }

        return isset(self::$emergencyReportUserIds[$userId]);
    }

    /**
     * Get all user IDs involved in NCMEC incidents (full lockdown).
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
     * Get all user IDs with open/assigned emergency profile reports.
     * Uses composite index (content_type, emergency_report, report_state) for performance.
     * 
     * Once a report is resolved/rejected, the user's avatar becomes visible again.
     */
    protected function getEmergencyProfileReportUserIds(): array
    {
        // Uses index: emergency_user_report (content_type, emergency_report, report_state)
        $userIds = $this->db()->fetchAllColumn("
            SELECT DISTINCT content_id
            FROM xf_report USE INDEX (emergency_user_report)
            WHERE content_type = 'user'
            AND emergency_report = 1
            AND report_state IN ('open', 'assigned')
        ");

        return array_fill_keys($userIds, true);
    }

    /**
     * Clear the cached user IDs.
     * Call this after incident or report state changes.
     */
    public static function clearCache()
    {
        self::$incidentUserIds = null;
        self::$emergencyReportUserIds = null;
    }
}
