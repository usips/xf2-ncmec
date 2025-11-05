<?php

namespace USIPS\NCMEC\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class IncidentAttachmentData extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_usips_ncmec_incident_attachment_data';
        $structure->shortName = 'USIPS\NCMEC:IncidentAttachmentData';
        $structure->primaryKey = ['incident_id', 'data_id'];
        $structure->columns = [
            'incident_id' => ['type' => self::UINT, 'required' => true],
            'data_id' => ['type' => self::UINT, 'required' => true],
        ];
        $structure->relations = [
            'Incident' => [
                'entity' => 'USIPS\NCMEC:Incident',
                'type' => self::TO_ONE,
                'conditions' => [['incident_id', '=', '$incident_id']],
                'primary' => true,
            ],
            'Data' => [
                'entity' => 'XF:AttachmentData',
                'type' => self::TO_ONE,
                'conditions' => [['data_id', '=', '$data_id']],
                'primary' => true,
            ],
        ];

        return $structure;
    }
}