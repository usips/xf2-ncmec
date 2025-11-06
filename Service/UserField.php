<?php

namespace USIPS\NCMEC\Service;

class UserField extends \XF\Service\AbstractService
{
    protected $verifyOption = true;

    public function __construct(\XF\App $app, $verifyOption = false)
    {
        parent::__construct($app);

        $this->verifyOption = $verifyOption;
    }

    public function updateIncidentField($userId, $inIncident)
    {
        if ($this->verifyOption && !\XF::options()->usips_ncmec_enabled)
        {
            return;
        }

        $userFieldValue = $this->em()->findOne('XF:UserFieldValue', [
            'field_id' => 'usips_ncmec_in_incident',
            'user_id' => $userId
        ]);

        if (!$userFieldValue)
        {
            $userFieldValue = $this->em()->create('XF:UserFieldValue');
            $userFieldValue->field_id = 'usips_ncmec_in_incident';
            $userFieldValue->user_id = $userId;
        }

        $userFieldValue->field_value = $inIncident ? '1' : '0';
        $userFieldValue->saveIfChanged();

        /** @var \XF\Repository\UserFieldRepository $userFieldRepo */
        $userFieldRepo = $this->repository('XF:UserField');
        $cache = $userFieldRepo->getUserFieldValues($userId);

        // Persist the denormalized cache to ensure criteria checks see the latest state
        $this->db()->update(
            'xf_user_profile',
            ['custom_fields' => json_encode($cache)],
            'user_id = ?',
            $userId
        );

        // Update any in-memory entity instances so subsequent processes read the new value immediately
        $user = $this->em()->find('XF:User', $userId, ['Profile']);
        if ($user)
        {
            $user->Profile->setAsSaved('custom_fields', $cache);
        }
    }

    public function checkUserInAnyIncident($userId)
    {
        $count = $this->db()->fetchOne("
            SELECT COUNT(*)
            FROM xf_usips_ncmec_incident_user
            WHERE user_id = ?
        ", $userId);

        return $count > 0;
    }
}
