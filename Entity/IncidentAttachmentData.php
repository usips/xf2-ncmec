<?php

namespace USIPS\NCMEC\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int $incident_id
 * @property int $data_id
 * @property int $user_id
 * @property string $username
 *
 * RELATIONS
 * @property-read \USIPS\NCMEC\Entity\Incident $Incident
 * @property-read \XF\Entity\AttachmentData $Data
 * @property-read \XF\Entity\User $User
 */
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
            'Data' => [
                'entity' => 'XF:AttachmentData',
                'type' => self::TO_ONE,
                'conditions' => [['data_id', '=', '$data_id']],
                'primary' => true,
            ],
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$user_id']],
            ],
        ];

        return $structure;
    }

    protected function _preSave()
    {
        parent::_preSave();

        if ($this->Incident && $this->Incident->finalized_on)
        {
            $this->error(\XF::phrase('usips_ncmec_incident_finalized_cannot_delete'));
        }
    }

    protected function _postSave()
    {
        parent::_postSave();
        
        /** @var \USIPS\NCMEC\Service\Incident\AttachmentManager $attachmentManager */
        $attachmentManager = \XF::app()->service('USIPS\NCMEC:Incident\AttachmentManager');
        $attachmentManager->updateIncidentCount($this->data_id);


    }

    protected function _preDelete()
    {
        if ($this->Incident && $this->Incident->finalized_on)
        {
            $this->error(\XF::phrase('usips_ncmec_incident_finalized_cannot_delete'));
        }

        parent::_postDelete();
        
        /** @var \USIPS\NCMEC\Service\Incident\AttachmentManager $attachmentManager */
        $attachmentManager = \XF::app()->service('USIPS\NCMEC:Incident\AttachmentManager');
        $attachmentManager->updateIncidentCount($this->data_id);

    }
}