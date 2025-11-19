<?php

namespace USIPS\NCMEC\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int $file_id
 * @property int $report_id
 * @property int $case_id
 * @property int $ncmec_report_id
 * @property string|null $ncmec_file_id
 * @property string $original_file_name
 * @property string $location_of_file
 * @property bool $publicly_available
 * @property string $ip_capture_event
 *
 * RELATIONS
 * @property-read \USIPS\NCMEC\Entity\Report $Report
 * @property-read \USIPS\NCMEC\Entity\CaseFile $Case
 * @property-read \XF\Mvc\Entity\AbstractCollection<\USIPS\NCMEC\Entity\ApiLog> $ApiLogs
 * 
 * NOTES
 * This entity represents a file being processed for submission to NCMEC via the /fileDetails endpoint.
 * It links internal XenForo content (via Report/Case) to NCMEC's file tracking system.
 * 
 * - ncmec_report_id: Mandatory. The report must be opened on NCMEC before files can be attached.
 * - ncmec_file_id: Populated after NCMEC responds to the file upload/details submission.
 * - ip_capture_event: Stores the IP address associated with the file upload in binary format (like xf_ip).
 */
class ReportFile extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_usips_ncmec_report_file';
        $structure->shortName = 'USIPS\NCMEC:ReportFile';
        $structure->primaryKey = 'file_id';
        $structure->columns = [
            'file_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'report_id' => ['type' => self::UINT, 'required' => true],
            'case_id' => ['type' => self::UINT, 'required' => true],
            'ncmec_report_id' => ['type' => self::UINT, 'required' => true],
            'ncmec_file_id' => ['type' => self::STR, 'maxLength' => 100, 'nullable' => true, 'default' => null],
            'original_file_name' => ['type' => self::STR, 'maxLength' => 255, 'default' => ''],
            'location_of_file' => ['type' => self::STR, 'maxLength' => 2048, 'default' => ''],
            'publicly_available' => ['type' => self::BOOL, 'default' => false],
            'ip_capture_event' => ['type' => self::BINARY, 'maxLength' => 16, 'default' => ''],
        ];
        $structure->relations = [
            'Report' => [
                'entity' => 'USIPS\NCMEC:Report',
                'type' => self::TO_ONE,
                'conditions' => 'report_id',
                'primary' => true,
            ],
            'Case' => [
                'entity' => 'USIPS\NCMEC:CaseFile',
                'type' => self::TO_ONE,
                'conditions' => 'case_id',
                'primary' => true,
            ],
            'ApiLogs' => [
                'entity' => 'USIPS\NCMEC:ApiLog',
                'type' => self::TO_MANY,
                'conditions' => 'file_id',
            ],
        ];

        return $structure;
    }
}
