<?php

namespace USIPS\NCMEC\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class Incident extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_usips_ncmec_incident';
        $structure->shortName = 'USIPS\NCMEC:Incident';
        $structure->primaryKey = 'incident_id';
        $structure->columns = [
            'incident_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'title' => ['type' => self::STR, 'maxLength' => 255],
            'additional_info' => ['type' => self::STR, 'default' => ''],
            'created_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'last_update_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'user_id' => ['type' => self::UINT, 'required' => true],
            'username' => ['type' => self::STR, 'maxLength' => 50, 'required' => true],
            'is_finalized' => ['type' => self::BOOL, 'default' => false],
        ];
        $structure->relations = [
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => 'user_id',
                'primary' => true,
            ],
            'Reports' => [
                'entity' => 'USIPS\NCMEC:Report',
                'type' => self::TO_MANY,
                'conditions' => 'incident_id',
            ],
            'IncidentUsers' => [
                'entity' => 'USIPS\NCMEC:IncidentUser',
                'type' => self::TO_MANY,
                'conditions' => 'incident_id',
            ],
            'IncidentContents' => [
                'entity' => 'USIPS\NCMEC:IncidentContent',
                'type' => self::TO_MANY,
                'conditions' => 'incident_id',
            ],
            'IncidentAttachmentDatas' => [
                'entity' => 'USIPS\NCMEC:IncidentAttachmentData',
                'type' => self::TO_MANY,
                'conditions' => 'incident_id',
            ],
        ];

        return $structure;
    }
}