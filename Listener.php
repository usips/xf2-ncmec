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
}
