<?php

namespace USIPS\NCMEC\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class Report extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_usips_ncmec_report';
        $structure->shortName = 'USIPS\NCMEC:Report';
        $structure->primaryKey = 'report_id';
        $structure->columns = [
            'report_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'incident_id' => ['type' => self::UINT, 'required' => true],
            'created_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'last_update_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'user_id' => ['type' => self::UINT, 'required' => true],
            'username' => ['type' => self::STR, 'maxLength' => 50, 'required' => true],
            'ncmec_report_id' => ['type' => self::STR, 'maxLength' => 255],
            'is_finished' => ['type' => self::BOOL, 'default' => false],
        ];
        $structure->relations = [
            'Incident' => [
                'entity' => 'USIPS\NCMEC:Incident',
                'type' => self::TO_ONE,
                'conditions' => 'incident_id',
                'primary' => true,
            ],
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => 'user_id',
                'primary' => true,
            ],
            'ReportLogs' => [
                'entity' => 'USIPS\NCMEC:ReportLog',
                'type' => self::TO_MANY,
                'conditions' => 'report_id',
            ],
        ];

        return $structure;
    }
}