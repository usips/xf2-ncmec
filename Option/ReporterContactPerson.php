<?php

namespace USIPS\NCMEC\Option;

use XF\Entity\Option;
use XF\Option\AbstractOption;

class ReporterContactPerson extends AbstractOption
{
    public static function renderSelect(Option $option, array $htmlParams)
    {
        $choices = static::getChoices();

        return static::getTemplater()->formSelectRow(
            static::getControlOptions($option, $htmlParams),
            $choices,
            static::getRowOptions($option, $htmlParams)
        );
    }

    protected static function getChoices(): array
    {
        $choices = [
            [
                'value' => 0,
                'label' => (string) \XF::phrase('none'),
            ],
        ];

        $persons = \XF::finder('USIPS\NCMEC:Person')
            ->order('last_update_date', 'DESC')
            ->fetch();

        foreach ($persons as $person)
        {
            $choices[] = [
                'value' => $person->person_id,
                'label' => \XF::escapeString($person->display_name),
            ];
        }

        return $choices;
    }
}
