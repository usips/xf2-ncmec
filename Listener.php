<?php

namespace USIPS\NCMEC;

use XF\Mvc\Entity\Entity;

class Listener
{
    /**
     * Per-request cache of content IDs in incidents, keyed by content_type.
     * null = not loaded yet; empty array for a type = no content of that type in incidents.
     * @var array|null
     */
    protected static $incidentContentIds = null;

    /**
     * Per-request cache of preserved user IDs.
     * null = not loaded yet.
     * @var array|null
     */
    protected static $preservedUserIds = null;

    public static function entityPreDelete(Entity $entity)
    {
        if (isset($entity->user_id) && $entity->user_id && $entity->getEntityContentType())
        {
            if (self::isUserPreserved($entity->user_id))
            {
                $entity->error(\XF::phrase('usips_ncmec_cannot_delete_preservation_required'));
            }
        }
    }

    /**
     * Check if a user is preserved, using a per-request cache to avoid
     * a DB query on every entity deletion.
     */
    public static function isUserPreserved(int $userId): bool
    {
        if (self::$preservedUserIds === null)
        {
            self::$preservedUserIds = \XF::db()->fetchAllKeyed("
                SELECT DISTINCT iu.user_id
                FROM xf_usips_ncmec_incident_user AS iu
                INNER JOIN xf_usips_ncmec_incident AS i ON (iu.incident_id = i.incident_id)
                WHERE i.submitted_on IS NOT NULL
            ", 'user_id');
        }

        return isset(self::$preservedUserIds[$userId]);
    }

    public static function finderSetup(\XF\Mvc\Entity\Finder $finder)
    {
        $app = \XF::app();

        // Only apply in Public App (not Admin, API, CLI)
        if (!$app instanceof \XF\Pub\App)
        {
            return;
        }

        // Allow in Reports, Approval Queue, Warnings
        $request = $app->request();
        $route = $request->getRoutePath();

        if (strpos($route, 'reports/') === 0 ||
            strpos($route, 'approval-queue/') === 0 ||
            strpos($route, 'warnings/') === 0)
        {
            return;
        }

        $structure = $finder->getStructure();
        $contentType = $structure->contentType;

        $supportedTypes = [
            'post',
            'thread',
            'profile_post',
            'conversation_message',
            'xfmg_media',
            'xfmg_album',
            'xfmg_comment',
            'resource_update'
        ];

        if (!in_array($contentType, $supportedTypes))
        {
            return;
        }

        $primaryKey = $structure->primaryKey;
        if (is_array($primaryKey))
        {
            return;
        }

        $contentIds = self::getIncidentContentIds($contentType);

        // For threads, also collect post IDs to check first_post_id
        $postIds = ($contentType === 'thread') ? self::getIncidentContentIds('post') : [];

        // Nothing in incidents at all — skip filtering entirely
        if (empty($contentIds) && empty($postIds))
        {
            return;
        }

        if (!empty($contentIds))
        {
            $idList = implode(',', array_map('intval', $contentIds));
            $finder->whereSql(
                $finder->columnSqlName($primaryKey) . " NOT IN (" . $idList . ")"
            );
        }

        // Threads: also hide if the first post (OP) is in an incident
        if ($contentType === 'thread' && !empty($postIds))
        {
            $idList = implode(',', array_map('intval', $postIds));
            $finder->whereSql(
                $finder->columnSqlName('first_post_id') . " NOT IN (" . $idList . ")"
            );
        }
    }

    /**
     * Get content IDs in incidents for a given content type, cached per-request.
     * This replaces the correlated NOT EXISTS subquery with a single upfront query.
     * The incident_content table is expected to be very small (dozens of rows at most).
     */
    protected static function getIncidentContentIds(string $contentType): array
    {
        if (self::$incidentContentIds === null)
        {
            self::$incidentContentIds = [];

            $rows = \XF::db()->fetchAll("
                SELECT content_type, content_id
                FROM xf_usips_ncmec_incident_content
            ");

            foreach ($rows as $row)
            {
                self::$incidentContentIds[$row['content_type']][] = (int)$row['content_id'];
            }
        }

        return self::$incidentContentIds[$contentType] ?? [];
    }

    /**
     * Clear all per-request caches.
     * Call after modifying incident content or preserved user associations.
     */
    public static function clearCache()
    {
        self::$incidentContentIds = null;
        self::$preservedUserIds = null;
    }
}
