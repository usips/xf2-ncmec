<?php

namespace USIPS\NCMEC\XF\Entity;

use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property bool $emergency_report
 */
class Report extends XFCP_Report
{
    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $structure->columns['emergency_report'] = [
            'type' => self::BOOL,
            'default' => false,
        ];

        return $structure;
    }
}
