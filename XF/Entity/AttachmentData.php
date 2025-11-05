<?php

namespace USIPS\NCMEC\XF\Entity;

use XF\Mvc\Entity\Structure;

class AttachmentData extends XFCP_AttachmentData
{
    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $structure->relations['Incidents'] = [
            'entity' => 'USIPS\NCMEC:Incident',
            'type' => self::TO_MANY,
            'conditions' => [
                ['attachment_id', '=', '$attachment_id']
            ],
            'table' => 'xf_usips_ncmec_incident_attachment_data',
        ];

        return $structure;
    }
}