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

    protected function _postSave()
    {
        parent::_postSave();
        
        // Update the incident count for the attachment data
        $this->updateIncidentCount();
    }

    protected function _postDelete()
    {
        parent::_postDelete();
        
        // Update the incident count for the attachment data
        $this->updateIncidentCount();
    }

    /**
     * Update the incident count for the associated attachment data
     */
    protected function updateIncidentCount()
    {
        $count = $this->finder('USIPS\NCMEC:IncidentAttachmentData')
            ->where('data_id', $this->data_id)
            ->total();

        $attachmentData = $this->em()->find('XF:AttachmentData', $this->data_id);
        if ($attachmentData)
        {
            $attachmentData->usips_ncmec_incident_count = $count;
            $attachmentData->save();
        }
    }
}