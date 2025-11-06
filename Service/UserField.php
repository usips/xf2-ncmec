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

        // Rebuild the custom fields cache to ensure user criteria matching works
        try {
            $user = $this->em()->find('XF:User', $userId);
            if ($user)
            {
                $user->Profile->rebuildUserFieldValuesCache();
            }
        } catch (\Exception $e) {
            // Log the error but don't fail the update
            \XF::logError('Failed to rebuild user field cache for user ' . $userId . ': ' . $e->getMessage());
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
