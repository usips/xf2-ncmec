<?php

namespace USIPS\NCMEC\Admin\Controller;

use USIPS\NCMEC\Entity\Incident;
use USIPS\NCMEC\Entity\IncidentUser;
use USIPS\NCMEC\Service\Incident\UserContentSelector;
use XF\Admin\Controller\AbstractController;
use XF\Entity\User;
use XF\Mvc\ParameterBag;
use XF\Util\Str;

class IncidentUserController extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('usips_ncmec');
    }

    public function actionIndex(ParameterBag $params)
    {
        $incident = $this->assertIncidentExists($params->incident_id);
        $user = $this->assertUserExists($params->user_id);
        $incidentUser = $this->assertIncidentUserExists($incident, $user);

        $timeLimit = $this->filter('time_limit', 'uint');
        if (!$timeLimit)
        {
            $timeLimit = 172800; // default to 48 hours
        }

        /** @var UserContentSelector $selector */
        $selector = $this->service(UserContentSelector::class, $incident, $user);

        $associatedContent = $selector->getAssociatedContent();
        $associatedAttachments = $selector->getAssociatedAttachments();

        $availableContent = $selector->getAvailableContent($timeLimit, $associatedContent);
        $availableAttachments = $selector->getAvailableAttachments($timeLimit, $associatedAttachments);

        $associatedDataIds = [];
        foreach ($associatedAttachments as $assoc)
        {
            $associatedDataIds[(int) $assoc->data_id] = true;
        }

        $associatedAttachmentIds = [];
        foreach ($availableAttachments as $attachment)
        {
            if (isset($associatedDataIds[(int) $attachment->data_id]))
            {
                $associatedAttachmentIds[] = $attachment->attachment_id;
            }
        }

        $viewParams = [
            'incident' => $incident,
            'user' => $user,
            'incidentUser' => $incidentUser,
            'timeLimit' => $timeLimit,
            'availableContent' => $availableContent,
            'availableAttachments' => $availableAttachments,
            'associatedContent' => $associatedContent,
            'associatedAttachmentIds' => $associatedAttachmentIds,
        ];

        return $this->view('USIPS\NCMEC:Incident\UserContent', 'usips_ncmec_incident_user_content', $viewParams);
    }

    public function actionAssociateTimestamp(ParameterBag $params)
    {
        $this->assertPostOnly();

        $incident = $this->assertIncidentExists($params->incident_id);
        $user = $this->assertUserExists($params->user_id);
        $incidentUser = $this->assertIncidentUserExists($incident, $user);

        $timeLimitSeconds = $this->filter('time_limit_seconds', 'uint');

        /** @var \USIPS\NCMEC\Service\Incident\Creator $creator */
        $creator = $this->service('USIPS\\NCMEC:Incident\\Creator');
        $creator->setIncident($incident);

        $contentItems = $creator->collectUserContentWithinTimeLimit($user->user_id, $timeLimitSeconds);
        if ($contentItems)
        {
            $creator->associateContentByIds($contentItems);
        }

        $attachmentDataIds = $creator->collectUserAttachmentDataWithinTimeLimit($user->user_id, $timeLimitSeconds);
        if ($attachmentDataIds)
        {
            $attachmentDataIds = array_unique(array_map('intval', $attachmentDataIds));

            /** @var \USIPS\NCMEC\Service\Incident\AttachmentManager $attachmentManager */
            $attachmentManager = $this->service('USIPS\\NCMEC:Incident\\AttachmentManager');

            $attachmentData = $this->finder('XF:AttachmentData')
                ->where('data_id', $attachmentDataIds)
                ->with('User')
                ->fetch();

            foreach ($attachmentData as $data)
            {
                if ((int) $data->user_id !== $user->user_id)
                {
                    continue;
                }

                $username = $data->User ? $data->User->username : $user->username;

                $created = $attachmentManager->addAttachmentToIncident(
                    $incident->incident_id,
                    $data->data_id,
                    $data->user_id,
                    Str::substr($username, 0, 50)
                );

                if ($created)
                {
                    $attachmentManager->updateIncidentCount($data->data_id);
                }
            }
        }

        $desiredUsername = Str::substr($user->username, 0, 50);
        if ($incidentUser->username !== $desiredUsername)
        {
            $incidentUser->username = $desiredUsername;
            $incidentUser->save();
        }

        return $this->redirect(
            $this->buildLink('ncmec-incidents/user', $incidentUser),
            \XF::phrase('changes_saved')
        );
    }

    public function actionUpdateAttachments(ParameterBag $params)
    {
        $this->assertPostOnly();

        $incidentId = $params->incident_id ?: $this->filter('incident_id', 'uint');
        $userId = $params->user_id ?: $this->filter('user_id', 'uint');

        if (!$incidentId || !$userId)
        {
            throw $this->exception($this->notFound());
        }

        $incident = $this->assertIncidentExists($incidentId);
        $user = $this->assertUserExists($userId);
        $incidentUser = $this->assertIncidentUserExists($incident, $user);

        /** @var UserContentSelector $selector */
        $selector = $this->service(UserContentSelector::class, $incident, $user);

        $postedAttachmentIds = $this->filter('attachment_ids', 'array-uint');
        $postedAttachmentIds = array_values(array_unique($postedAttachmentIds));

        $attachmentsByDataId = [];

        if ($postedAttachmentIds)
        {
            $attachments = $this->finder('XF:Attachment')
                ->where('attachment_id', $postedAttachmentIds)
                ->with(['Data', 'Data.User'])
                ->fetch();

            foreach ($attachments as $attachment)
            {
                $data = $attachment->Data;
                if (!$data || $data->user_id !== $user->user_id)
                {
                    continue;
                }

                $attachmentsByDataId[(int) $attachment->data_id] = $attachment;
            }
        }

        $associatedAttachments = $selector->getAssociatedAttachments();

        $currentDataIds = [];
        foreach ($associatedAttachments as $associated)
        {
            $currentDataIds[] = (int) $associated->data_id;
        }

        $desiredDataIds = array_keys($attachmentsByDataId);

        $dataIdsToRemove = array_diff($currentDataIds, $desiredDataIds);
        $dataIdsToAdd = array_diff($desiredDataIds, $currentDataIds);

        /** @var \USIPS\NCMEC\Service\Incident\AttachmentManager $attachmentManager */
        $attachmentManager = $this->service('USIPS\\NCMEC:Incident\\AttachmentManager');

        foreach ($dataIdsToRemove as $dataId)
        {
            $attachmentManager->removeAttachmentFromIncident($incident->incident_id, $dataId);
        }

        foreach ($dataIdsToAdd as $dataId)
        {
            $attachment = $attachmentsByDataId[$dataId];
            $data = $attachment->Data;

            $attachmentManager->addAttachmentToIncident(
                $incident->incident_id,
                $dataId,
                $data->user_id,
                $data->User ? $data->User->username : $user->username
            );
        }

        return $this->redirect(
            $this->buildLink('ncmec-incidents/user', $incidentUser),
            \XF::phrase('changes_saved')
        );
    }

    protected function assertIncidentExists(int $incidentId): Incident
    {
        return $this->assertRecordExists('USIPS\NCMEC:Incident', $incidentId);
    }

    protected function assertUserExists(int $userId): User
    {
        return $this->assertRecordExists('XF:User', $userId);
    }

    protected function assertIncidentUserExists(Incident $incident, User $user): IncidentUser
    {
        return $this->assertRecordExists(
            'USIPS\\NCMEC:IncidentUser',
            [
                'incident_id' => $incident->incident_id,
                'user_id' => $user->user_id,
            ]
        );
    }
}