<?php

namespace USIPS\NCMEC\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int $log_id
 * @property int|null $report_id
 * @property int|null $file_id
 * @property int $user_id
 * @property int $request_date
 * @property string $request_method
 * @property string $request_url
 * @property string $request_endpoint
 * @property array $request_data
 * @property int|null $response_code
 * @property string|null $response_data
 * @property string $environment
 * @property bool $success
 *
 * RELATIONS
 * @property-read \USIPS\NCMEC\Entity\Report|null $Report
 * @property-read \USIPS\NCMEC\Entity\ReportFile|null $ReportFile
 * @property-read \XF\Entity\User|null $User
 */
class ApiLog extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_usips_ncmec_api_log';
        $structure->shortName = 'USIPS\NCMEC:ApiLog';
        $structure->primaryKey = 'log_id';
        $structure->columns = [
            'log_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'report_id' => ['type' => self::UINT, 'nullable' => true, 'default' => null],
            'file_id' => ['type' => self::UINT, 'nullable' => true, 'default' => null],
            'user_id' => ['type' => self::UINT, 'default' => 0],
            'request_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'request_method' => ['type' => self::STR, 'maxLength' => 10, 'default' => ''],
            'request_url' => ['type' => self::STR, 'maxLength' => 500, 'default' => ''],
            'request_endpoint' => ['type' => self::STR, 'maxLength' => 100, 'default' => ''],
            'request_data' => ['type' => self::JSON_ARRAY, 'default' => []],
            'response_code' => ['type' => self::UINT, 'nullable' => true, 'default' => null],
            'response_data' => ['type' => self::STR, 'default' => ''],
            'environment' => ['type' => self::STR, 'maxLength' => 20, 'default' => ''],
            'success' => ['type' => self::BOOL, 'default' => false],
        ];
        $structure->relations = [
            'Report' => [
                'entity' => 'USIPS\NCMEC:Report',
                'type' => self::TO_ONE,
                'conditions' => 'report_id',
                'primary' => true,
            ],
            'ReportFile' => [
                'entity' => 'USIPS\NCMEC:ReportFile',
                'type' => self::TO_ONE,
                'conditions' => 'file_id',
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