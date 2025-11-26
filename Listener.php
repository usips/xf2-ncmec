<?php

namespace USIPS\NCMEC;

use XF\Mvc\Entity\Entity;

class Listener
{
    public static function entityPreDelete(Entity $entity)
    {
        // Check if the entity has a user_id and is a content type
        if (isset($entity->user_id) && $entity->user_id && $entity->getEntityContentType())
        {
            // Check if the user is preserved
            if (\XF::repository('USIPS\NCMEC:Preservation')->isUserPreserved($entity->user_id))
            {
                $entity->error(\XF::phrase('usips_ncmec_cannot_delete_preservation_required'));
            }
        }
    }

    public static function finderSetup(\XF\Mvc\Entity\Finder $finder)
    {
        $app = \XF::app();
        
        // Only apply in Public App (not Admin, API, CLI)
        if (!$app instanceof \XF\Pub\App)
        {
            return;
        }

        // Check if we are in a context where we should see the content
        $request = $app->request();
        $route = $request->getRoutePath();
        
        // Allow in Reports, Approval Queue, Warnings
        // These routes usually start with these prefixes
        if (strpos($route, 'reports/') === 0 || 
            strpos($route, 'approval-queue/') === 0 || 
            strpos($route, 'warnings/') === 0)
        {
            return;
        }

        // List of content types to filter
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

        if (in_array($contentType, $supportedTypes))
        {
            $primaryKey = $structure->primaryKey;
            if (is_array($primaryKey))
            {
                return; // Composite keys not supported easily
            }

            // Add the filter
            // We use a subquery to exclude content in the incident table
            // Note: columnSqlName handles the table alias (e.g. `xf_post`.`post_id`)
            $finder->whereSql("
                NOT EXISTS (
                    SELECT 1 
                    FROM xf_usips_ncmec_incident_content AS nic 
                    WHERE nic.content_type = " . \XF::db()->quote($contentType) . "
                    AND nic.content_id = " . $finder->columnSqlName($primaryKey) . "
                )
            ");

            // Special handling for Threads: also hide if the First Post (OP) is in an incident
            if ($contentType === 'thread')
            {
                $finder->whereSql("
                    NOT EXISTS (
                        SELECT 1 
                        FROM xf_usips_ncmec_incident_content AS nic 
                        WHERE nic.content_type = 'post'
                        AND nic.content_id = " . $finder->columnSqlName('first_post_id') . "
                    )
                ");
            }
        }
    }
}
