<?php

namespace USIPS\NCMEC\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int|null $person_id
 * @property int $created_date
 * @property int $last_update_date
 * @property int $user_id
 * @property string $username
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $phones
 * @property string|null $emails
 * @property string|null $addresses
 * @property int|null $age
 * @property string|null $date_of_birth
 *
 * GETTERS
 * @property-read string $display_name
 *
 * RELATIONS
 * @property \XF\Entity\User $User
 */
class Person extends Entity
{
    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_usips_ncmec_person';
        $structure->shortName = 'USIPS\\NCMEC:Person';
        $structure->primaryKey = 'person_id';
        $structure->columns = [
            'person_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'created_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'last_update_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'user_id' => ['type' => self::UINT, 'default' => 0],
            'username' => ['type' => self::STR, 'maxLength' => 50, 'default' => ''],
            'created_by_user_id' => ['type' => self::UINT, 'default' => 0],
            'created_by_username' => ['type' => self::STR, 'maxLength' => 50, 'default' => ''],
            'title' => ['type' => self::STR, 'maxLength' => 255, 'nullable' => true, 'default' => null],
            'first_name' => ['type' => self::STR, 'maxLength' => 100, 'nullable' => true, 'default' => null],
            'last_name' => ['type' => self::STR, 'maxLength' => 100, 'nullable' => true, 'default' => null],
            'phones' => ['type' => self::STR, 'nullable' => true, 'default' => null],
            'emails' => ['type' => self::STR, 'nullable' => true, 'default' => null],
            'addresses' => ['type' => self::STR, 'nullable' => true, 'default' => null],
            'age' => ['type' => self::UINT, 'nullable' => true, 'default' => null],
            'date_of_birth' => ['type' => self::STR, 'maxLength' => 10, 'nullable' => true, 'default' => null],
        ];
        $structure->getters = [
            'display_name' => true,
        ];
        $structure->relations = [
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => 'user_id',
                'primary' => true
            ],
            'CreatedByUser' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$created_by_user_id']],
                'primary' => true
            ],
        ];

        return $structure;
    }

    protected function _preSave()
    {
        $this->last_update_date = \XF::$time;

        if ($this->isInsert() && !$this->created_date)
        {
            $this->created_date = \XF::$time;

            $visitor = \XF::visitor();
            $this->created_by_user_id = $visitor->user_id;
            $this->created_by_username = $visitor->username;
        }

        if ($this->title === '')
        {
            $this->title = null;
        }
    }

    public function getYearsOld(): int|null
    {
        if ($this->age !== null)
        {
            return $this->age;
        }
        else if ($this->date_of_birth)
        {
            $dob = \DateTime::createFromFormat('Y-m-d', $this->date_of_birth);
            if ($dob)
            {
                $now = new \DateTime();
                $ageInterval = $now->diff($dob);
                return (int) $ageInterval->y;
            }
        }

        return null;
    }

    public function getDisplayName(): string
    {
        if ($this->title)
        {
            return $this->title;
        }

        $last = $this->normaliseNamePart($this->last_name);
        $first = $this->normaliseNamePart($this->first_name);
        $dob = $this->date_of_birth ? trim((string) $this->date_of_birth) : '';
        $age = $this->age;

        if ($first !== '' && $last !== '')
        {
            return $this->formatFullName($last, $first);
        }

        if ($last !== '')
        {
            return $this->appendSecondaryDetails($last, $dob, $age);
        }

        if ($first !== '')
        {
            return $this->appendSecondaryDetails($first, $dob, $age);
        }

        if ($dob !== '')
        {
            return 'BORN ' . $dob;
        }

        if ($age !== null)
        {
            return 'AGE ' . $age;
        }

        $id = $this->person_id;
        if ($id)
        {
            return 'PERSON ID #' . $id;
        }

        return 'PERSON ID #NEW';
    }

    protected function normaliseNamePart(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '')
        {
            return '';
        }

        if (function_exists('mb_strtoupper'))
        {
            return mb_strtoupper($value, 'UTF-8');
        }

        return strtoupper($value);
    }

    protected function formatFullName(string $last, string $first): string
    {
        return $last . ', ' . $first;
    }

    protected function appendSecondaryDetails(string $base, string $dob, ?int $age): string
    {
        if ($dob !== '')
        {
            return $base . ' BORN ' . $dob;
        }

        if ($age !== null)
        {
            return $base . ' AGE ' . $age;
        }

        return $base;
    }
}
