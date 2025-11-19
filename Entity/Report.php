<?php

namespace USIPS\NCMEC\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int $report_id
 * @property int|null $ncmec_report_id
 * @property int $case_id
 * @property int $created_date
 * @property int $last_update_date
 * @property int $user_id
 * @property string $username
 * @property int $subject_user_id
 * @property string $subject_username
 * @property bool $is_finished
 *
 * RELATIONS
 * @property-read \USIPS\NCMEC\Entity\CaseFile $Case
 * @property-read \XF\Entity\User $User
 * @property-read \XF\Entity\User $SubjectUser
 * @property-read \XF\Mvc\Entity\AbstractCollection<\USIPS\NCMEC\Entity\ApiLog> $ApiLogs
 * 
 * NOTES
 * Reports track submission state:
 * - Created: Report exists but not yet opened with NCMEC (ncmec_report_id is null)
 * - Opened: Report has been opened with NCMEC (ncmec_report_id assigned, is_finished = false)
 * - Finished: Report has been submitted for review (is_finished = true)
 * 
 * Reports are immutable once created - they represent submitted data to NCMEC.
 * All case details (incident_type, activity_summary, etc.) are stored in CaseFile.
 * Each report represents one user's content submitted as part of a case.
 */
class Report extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_usips_ncmec_report';
        $structure->shortName = 'USIPS\NCMEC:Report';
        $structure->primaryKey = 'report_id';
        $structure->columns = [
            'report_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'ncmec_report_id' => ['type' => self::UINT, 'nullable' => true, 'default' => null],
            'case_id' => ['type' => self::UINT, 'required' => true],
            'created_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'last_update_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'user_id' => ['type' => self::UINT, 'required' => true],
            'username' => ['type' => self::STR, 'maxLength' => 50, 'required' => true],
            'subject_user_id' => ['type' => self::UINT, 'required' => true],
            'subject_username' => ['type' => self::STR, 'maxLength' => 50, 'required' => true],
            'is_finished' => ['type' => self::BOOL, 'default' => false],
        ];
        $structure->relations = [
            'Case' => [
                'entity' => 'USIPS\NCMEC:CaseFile',
                'type' => self::TO_ONE,
                'conditions' => 'case_id',
            ],
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => 'user_id',
                'primary' => true,
            ],
            'SubjectUser' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$subject_user_id']],
            ],
            'ApiLogs' => [
                'entity' => 'USIPS\NCMEC:ApiLog',
                'type' => self::TO_MANY,
                'conditions' => 'report_id',
            ],
        ];

        return $structure;
    }
}