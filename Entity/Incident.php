<?php

namespace USIPS\NCMEC\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int $incident_id
 * @property int $case_id
 * @property string $title
 * @property string $additional_info
 * @property int $created_date
 * @property int $last_update_date
 * @property int $user_id
 * @property string $username
 * @property int|null $finalized_on
 * @property int|null $submitted_on
 *
 * RELATIONS
 * @property-read \USIPS\NCMEC\Entity\CaseFile $Case
 * @property-read \XF\Entity\User $User
 * @property-read \XF\Mvc\Entity\AbstractCollection<\USIPS\NCMEC\Entity\IncidentUser> $IncidentUsers
 * @property-read \XF\Mvc\Entity\AbstractCollection<\USIPS\NCMEC\Entity\IncidentContent> $IncidentContents
 * @property-read \XF\Mvc\Entity\AbstractCollection<\USIPS\NCMEC\Entity\IncidentAttachmentData> $IncidentAttachmentData
 */
class Incident extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_usips_ncmec_incident';
        $structure->shortName = 'USIPS\NCMEC:Incident';
        $structure->primaryKey = 'incident_id';
        $structure->columns = [
            'incident_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'case_id' => ['type' => self::UINT, 'default' => 0],
            'title' => ['type' => self::STR, 'maxLength' => 255],
            'additional_info' => ['type' => self::STR, 'default' => ''],
            'created_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'last_update_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'user_id' => ['type' => self::UINT, 'required' => true],
            'username' => ['type' => self::STR, 'maxLength' => 50, 'required' => true],
            'finalized_on' => ['type' => self::UINT, 'nullable' => true, 'default' => null],
            'submitted_on' => ['type' => self::UINT, 'nullable' => true, 'default' => null],
        ];
        $structure->relations = [
            'Case' => [
                'entity' => 'USIPS\\NCMEC:CaseFile',
                'type' => self::TO_ONE,
                'conditions' => 'case_id',
            ],
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => 'user_id',
                'primary' => true,
            ],
            'IncidentUsers' => [
                'entity' => 'USIPS\NCMEC:IncidentUser',
                'type' => self::TO_MANY,
                'conditions' => 'incident_id',
            ],
            'IncidentContents' => [
                'entity' => 'USIPS\NCMEC:IncidentContent',
                'type' => self::TO_MANY,
                'conditions' => 'incident_id',
            ],
            'IncidentAttachmentData' => [
                'entity' => 'USIPS\NCMEC:IncidentAttachmentData',
                'type' => self::TO_MANY,
                'conditions' => 'incident_id',
            ],
        ];

        return $structure;
    }

    protected function _preSave()
    {
        if ($this->isUpdate() && $this->getExistingValue('finalized_on'))
        {
            $this->error(\XF::phrase('usips_ncmec_incident_finalized_cannot_delete'));
        }
    }

    protected function _preDelete()
    {
        if ($this->finalized_on)
        {
            $this->error(\XF::phrase('usips_ncmec_incident_finalized_cannot_delete'));
        }
    }

    public static function getWithEverything()
    {
        return ['Case', 'User', 'IncidentUsers', 'IncidentContents', 'IncidentAttachmentData'];
    }
}