<?php

namespace USIPS\NCMEC\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class IncidentContent extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_usips_ncmec_incident_content';
        $structure->shortName = 'USIPS\NCMEC:IncidentContent';
        $structure->primaryKey = ['incident_id', 'content_type', 'content_id'];
        $structure->columns = [
            'incident_id' => ['type' => self::UINT, 'required' => true],
            'content_type' => ['type' => self::STR, 'maxLength' => 25, 'required' => true],
            'content_id' => ['type' => self::UINT, 'required' => true],
            'user_id' => ['type' => self::UINT, 'required' => true],
            'username' => ['type' => self::STR, 'maxLength' => 50, 'required' => true],
        ];
        $structure->relations = [
            'Incident' => [
                'entity' => 'USIPS\NCMEC:Incident',
                'type' => self::TO_ONE,
                'conditions' => [['incident_id', '=', '$incident_id']],
                'primary' => true,
            ],
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$user_id']],
            ],
        ];

        return $structure;
    }

    /**
     * @return Entity|null
     */
    public function getContent()
    {
        $contentType = $this->content_type;
        $contentId = $this->content_id;

        // Handle legacy thread associations - convert to first post
        if ($contentType === 'thread')
        {
            $thread = $this->em()->find('XF:Thread', $contentId);
            if ($thread && $thread->first_post_id)
            {
                $contentType = 'post';
                $contentId = $thread->first_post_id;
            }
        }

        return $this->app()->findByContentType($contentType, $contentId);
    }
}