<?php

namespace USIPS\NCMEC\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class CaseFile extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_usips_ncmec_case';
        $structure->shortName = 'USIPS\\NCMEC:CaseFile';
        $structure->primaryKey = 'case_id';
        $structure->columns = [
            'case_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'title' => ['type' => self::STR, 'maxLength' => 255, 'required' => true],
            'additional_info' => ['type' => self::STR, 'default' => ''],
            'created_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'last_update_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'user_id' => ['type' => self::UINT, 'required' => true],
            'username' => ['type' => self::STR, 'maxLength' => 50, 'required' => true],
            'status' => ['type' => self::STR, 'maxLength' => 25, 'default' => 'open'],
            'is_finalized' => ['type' => self::BOOL, 'default' => false],
        ];
        $structure->relations = [
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => 'user_id',
                'primary' => true,
            ],
            'Incidents' => [
                'entity' => 'USIPS\\NCMEC:Incident',
                'type' => self::TO_MANY,
                'conditions' => 'case_id',
            ],
            'Reports' => [
                'entity' => 'USIPS\\NCMEC:Report',
                'type' => self::TO_MANY,
                'conditions' => 'case_id',
            ],
        ];

        return $structure;
    }
}
