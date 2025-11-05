<?php

namespace USIPS\NCMEC\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class IncidentUser extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_usips_ncmec_incident_user';
        $structure->shortName = 'USIPS\NCMEC:IncidentUser';
        $structure->primaryKey = ['incident_id', 'user_id'];
        $structure->columns = [
            'incident_id' => ['type' => self::UINT, 'required' => true],
            'user_id' => ['type' => self::UINT, 'required' => true],
        ];
        $structure->relations = [
            'Incident' => [
                'entity' => 'USIPS\NCMEC:Incident',
                'type' => self::TO_ONE,
                'conditions' => [['incident_id', '=', '$incident_id']],
                'primary' => true,
            ],
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$user_id']],
                'primary' => true,
            ],
        ];

        return $structure;
    }
}