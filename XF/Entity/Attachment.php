<?php

namespace USIPS\NCMEC\XF\Entity;

use XF\Mvc\Entity\Structure;

class Attachment extends XFCP_Attachment
{
    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $structure->relations['IncidentAttachmentData'] = [
            'entity' => 'USIPS\NCMEC:IncidentAttachmentData',
            'type' => self::TO_ONE,
            'conditions' => [
                ['data_id', '=', '$data_id']
            ],
            'primary' => false,
        ];

        $structure->getters['in_incident'] = 'getInIncident';

        return $structure;
    }

    /**
     * Check if this attachment is associated with any incident
     * Uses denormalized count from attachment data for instant checking
     *
     * @return bool
     */
    public function getInIncident()
    {
        return $this->Data && $this->Data->usips_ncmec_incident_count > 0;
    }

    /**
     * Get the incident this attachment is associated with (if any)
     *
     * @return \USIPS\NCMEC\Entity\IncidentAttachmentData|null
     */
    public function getIncidentData()
    {
        if (!$this->isInIncident())
        {
            return null;
        }

        if (!$this->isRelationLoaded('IncidentAttachmentData'))
        {
            $this->hydrateRelation('IncidentAttachmentData', $this->finder('USIPS\NCMEC:IncidentAttachmentData')
                ->where('data_id', $this->data_id)
                ->fetchOne());
        }

        return $this->IncidentAttachmentData;
    }
}