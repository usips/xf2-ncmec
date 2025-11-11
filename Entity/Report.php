<?php

namespace USIPS\NCMEC\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int $report_id
 * @property string $activity_summary
 * @property int $created_date
 * @property int $last_update_date
 * @property int $user_id
 * @property string $username
 * @property bool $is_finished
 *
 * RELATIONS
 * @property-read \XF\Mvc\Entity\AbstractCollection<Incident> $Incidents
 * @property-read \XF\Entity\User $User
 * @property-read \XF\Mvc\Entity\AbstractCollection<\USIPS\NCMEC\Entity\ReportLog> $ReportLogs
 */
class Report extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_usips_ncmec_report';
        $structure->shortName = 'USIPS\NCMEC:Report';
        $structure->primaryKey = 'report_id';
        $structure->columns = [
            'report_id' => ['type' => self::UINT, 'required' => true, 'autoIncrement' => false],
            'activity_summary' => ['type' => self::STR, 'default' => '', 'maxLength' => 65535],
            'created_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'last_update_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'user_id' => ['type' => self::UINT, 'required' => true],
            'username' => ['type' => self::STR, 'maxLength' => 50, 'required' => true],
            'is_finished' => ['type' => self::BOOL, 'default' => false],
        ];
        $structure->relations = [
            'Incidents' => [
                'entity' => 'USIPS\NCMEC:Incident',
                'type' => self::TO_MANY,
                'conditions' => 'report_id',
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