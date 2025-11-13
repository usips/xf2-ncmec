<?php

namespace USIPS\NCMEC\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int $report_id
 * @property int|null $ncmec_report_id
 * @property string $incident_type
 * @property string $activity_summary
 * @property array $report_annotations
 * @property string $incident_date_time_desc
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
    /**
     * Check if a value exists in the report annotations array
     *
     * @param string $value The annotation value to check
     * @return bool
     */
    public function isInReportAnnotations(string $value): bool
    {
        return is_array($this->report_annotations) && in_array($value, $this->report_annotations);
    }

    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_usips_ncmec_report';
        $structure->shortName = 'USIPS\NCMEC:Report';
        $structure->primaryKey = 'report_id';
        $structure->columns = [
            'report_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'ncmec_report_id' => ['type' => self::UINT, 'nullable' => true, 'default' => null],
            'incident_type' => ['type' => self::STR, 'default' => '', 'maxLength' => 100],
            'activity_summary' => ['type' => self::STR, 'default' => '', 'maxLength' => 65535],
            'report_annotations' => ['type' => self::JSON_ARRAY, 'default' => []],
            'incident_date_time_desc' => ['type' => self::STR, 'default' => '', 'maxLength' => 3000],
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