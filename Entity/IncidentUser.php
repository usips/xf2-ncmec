<?php

namespace USIPS\NCMEC\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int $incident_id
 * @property int $user_id
 * @property string $username
 *
 * RELATIONS
 * @property-read \USIPS\NCMEC\Entity\Incident $Incident
 * @property-read \XF\Entity\User $User
 */
class IncidentUser extends Entity
{
    protected function _preSave()
    {
        if ($this->Incident && $this->Incident->finalized_on)
        {
            $this->error(\XF::phrase('usips_ncmec_incident_finalized_cannot_delete'));
        }
    }

    protected function _preDelete()
    {
        if ($this->Incident && $this->Incident->finalized_on)
        {
            $this->error(\XF::phrase('usips_ncmec_incident_finalized_cannot_delete'));
        }
    }

    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_usips_ncmec_incident_user';
        $structure->shortName = 'USIPS\NCMEC:IncidentUser';
        $structure->primaryKey = ['incident_id', 'user_id'];
        $structure->columns = [
            'incident_id' => ['type' => self::UINT, 'required' => true],
            'user_id' => ['type' => self::UINT, 'required' => true],
            'username' => ['type' => self::STR, 'maxLength' => 50, 'required' => true],
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