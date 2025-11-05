<?php

namespace USIPS\NCMEC\XF\Entity;

use XF\Mvc\Entity\Structure;

class AttachmentData extends XFCP_AttachmentData
{
    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $structure->columns['usips_ncmec_incident_count'] = [
            'type' => self::UINT,
            'default' => 0
        ];

        $structure->relations['Incidents'] = [
            'entity' => 'USIPS\NCMEC:Incident',
            'type' => self::TO_MANY,
            'conditions' => [
                ['data_id', '=', '$data_id']
            ],
            'table' => 'xf_usips_ncmec_incident_attachment_data',
        ];

        return $structure;
    }
}