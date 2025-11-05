<?php

namespace USIPS\NCMEC\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class ReportLog extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_usips_ncmec_report_log';
        $structure->shortName = 'USIPS\NCMEC:ReportLog';
        $structure->primaryKey = 'log_id';
        $structure->columns = [
            'log_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'report_id' => ['type' => self::UINT, 'required' => true],
            'user_id' => ['type' => self::UINT, 'required' => true],
            'username' => ['type' => self::STR, 'maxLength' => 50, 'required' => true],
            'created_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'last_update_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'api_method' => ['type' => self::STR, 'maxLength' => 10],
            'api_endpoint' => ['type' => self::STR, 'maxLength' => 500],
            'api_data' => ['type' => self::SERIALIZED],
            'response_code' => ['type' => self::UINT],
            'response_data' => ['type' => self::SERIALIZED],
        ];
        $structure->relations = [
            'Report' => [
                'entity' => 'USIPS\NCMEC:Report',
                'type' => self::TO_ONE,
                'conditions' => 'report_id',
                'primary' => true,
            ],
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => 'user_id',
                'primary' => true,
            ],
        ];

        return $structure;
    }
}