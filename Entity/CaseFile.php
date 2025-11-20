<?php

namespace USIPS\NCMEC\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int $case_id
 * @property string $title
 * @property string $additional_info
 * @property int $created_date
 * @property int $last_update_date
 * @property int $user_id
 * @property string $username
 * @property string $incident_type
 * @property array $report_annotations
 * @property string $incident_date_time_desc
 * @property int $reporter_person_id
 * @property int $reported_person_id
 * @property string $reported_additional_info
 * @property bool $is_finalized - Case is closed and in the process of being submitted (no further modifications allowed)
 * @property bool $is_finished - Case has been fully submitted to NCMEC (final state)
 * 
 * RELATIONS
 * @property-read \XF\Entity\User $User
 * @property-read \USIPS\NCMEC\Entity\Person $Reporter
 * @property-read \USIPS\NCMEC\Entity\Person $ReportedPerson
 * @property-read \XF\Mvc\Entity\AbstractCollection<\USIPS\NCMEC\Entity\Incident> $Incidents
 * @property-read \XF\Mvc\Entity\AbstractCollection<\USIPS\NCMEC\Entity\Report> $Reports
 */
class CaseFile extends Entity
{
    /**
     * Determines if this case can still be edited.
     *
     * @param null|string $error
     * @param \XF\Entity\User|null $user
     *
     * @return bool
     */
    public function canEdit(&$error = null, \XF\Entity\User $user = null): bool
    {
        $user = $user ?: \XF::visitor();

        if (!$user || !$user->hasAdminPermission('usips_ncmec'))
        {
            $error = \XF::phrase('no_permission');
            return false;
        }

        if ($this->is_finalized || $this->is_finished)
        {
            $error = \XF::phrase('usips_ncmec_case_finalized_cannot_edit');
            return false;
        }

        return true;
    }

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
            'incident_type' => ['type' => self::STR, 'default' => '', 'maxLength' => 100],
            'report_annotations' => ['type' => self::JSON_ARRAY, 'default' => []],
            'incident_date_time_desc' => ['type' => self::STR, 'default' => '', 'maxLength' => 3000],
            'reporter_person_id' => ['type' => self::UINT, 'default' => 0],
            'reported_person_id' => ['type' => self::UINT, 'default' => 0],
            'reported_additional_info' => ['type' => self::STR, 'default' => '', 'maxLength' => 16777215], // MEDIUMTEXT
            'is_finalized' => ['type' => self::BOOL, 'default' => false],
            'is_finished' => ['type' => self::BOOL, 'default' => false],
        ];
        $structure->relations = [
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => 'user_id',
                'primary' => true,
            ],
            'Reporter' => [
                'entity' => 'USIPS\\NCMEC:Person',
                'type' => self::TO_ONE,
                'conditions' => [['person_id', '=', '$reporter_person_id']],
            ],
            'ReportedPerson' => [
                'entity' => 'USIPS\\NCMEC:Person',
                'type' => self::TO_ONE,
                'conditions' => [['person_id', '=', '$reported_person_id']],
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
