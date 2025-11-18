<?php

namespace USIPS\NCMEC\Admin\Controller;

use USIPS\NCMEC\Entity\Incident;
use USIPS\NCMEC\Entity\IncidentUser;
use USIPS\NCMEC\Service\Incident\UserContentSelector;
use USIPS\NCMEC\Util\TimeLimit;
use XF\Admin\Controller\AbstractController;
use XF\Entity\User;
use XF\Mvc\ParameterBag;

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

        $rawTimeLimit = $this->filter('time_limit_seconds', '?int');
        if ($rawTimeLimit === null)
        {
            $rawTimeLimit = $this->filter('time_limit', '?int');
        }
        $timeLimitSelection = TimeLimit::normalizeSelection($rawTimeLimit);
        $timeLimit = TimeLimit::resolve($timeLimitSelection);

        /** @var UserContentSelector $selector */
        $selector = $this->service(UserContentSelector::class, $incident, $user);

        // Always get all associated content
        $associatedContent = $selector->getAssociatedContent();
        $associatedAttachments = $selector->getAssociatedAttachments();

        // Get available content within time limit (will include associated content)
        $availableContent = $selector->getAvailableContent($timeLimit, $associatedContent);
        
        // Get attachments from multiple sources and merge them
        $attachmentsByDataId = [];
        
        // 1. Get attachments within time limit
        $timeLimitedAttachments = $selector->getAvailableAttachments($timeLimit, $associatedAttachments);
        foreach ($timeLimitedAttachments as $attachment)
        {
            $attachmentsByDataId[$attachment->data_id] = $attachment;
        }
        
        // 2. Get last 50 attachments regardless of time
        $recentAttachments = $this->getRecentUserAttachments($user->user_id, 50);
        foreach ($recentAttachments as $attachment)
        {
            if (!isset($attachmentsByDataId[$attachment->data_id]))
            {
                $attachmentsByDataId[$attachment->data_id] = $attachment;
            }
        }
        
        // Convert to array and sort by upload date (newest first)
        $availableAttachments = array_values($attachmentsByDataId);
        usort($availableAttachments, function($a, $b) {
            $aDate = $a->Data->upload_date ?? 0;
            $bDate = $b->Data->upload_date ?? 0;
            return $bDate <=> $aDate;
        });

        // Build list of associated attachment IDs for UI highlighting
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
            'timeLimitSelection' => $timeLimitSelection,
            'timeLimitDefault' => TimeLimit::getDefaultSeconds(),
            'timeLimitDefaultDescription' => TimeLimit::describeDefault(),
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

        $timeLimitSeconds = $this->filter('time_limit_seconds', 'int');
        $timeLimitSeconds = TimeLimit::normalizeSelection($timeLimitSeconds);
        $resolvedTimeLimit = TimeLimit::resolve($timeLimitSeconds);

        /** @var \USIPS\NCMEC\Service\Incident\Creator $creator */
        $creator = $this->service('USIPS\\NCMEC:Incident\\Creator');
        $creator->setIncident($incident);
        $creator->associateUserCascade($user->user_id, $resolvedTimeLimit);

        return $this->redirect(
            $this->buildLink('ncmec-incidents/user', $incidentUser),
            \XF::phrase('changes_saved')
        );
    }

    public function actionDelete(ParameterBag $params)
    {
        $incident = $this->assertIncidentExists($params->incident_id);
        $user = $this->assertUserExists($params->user_id);
        $incidentUser = $this->assertIncidentUserExists($incident, $user);

        if ($this->isPost())
        {
            $this->assertPostOnly();

            $uniqueId = 'ncmec_disassociate_' . $incident->incident_id . '_' . $user->user_id . '_' . \XF::$time;

            \XF::app()->jobManager()->enqueueUnique($uniqueId, 'USIPS\NCMEC:DisassociateUser', [
                'incident_id' => $incident->incident_id,
                'user_ids' => [$user->user_id],
            ]);

            return $this->redirect(
                $this->buildLink('tools/run-job', null, [
                    'only' => $uniqueId,
                    '_xfRedirect' => $this->buildLink('ncmec-incidents/view', $incident),
                ]),
                \XF::phrase('changes_saved')
            );
        }

        $viewParams = [
            'incident' => $incident,
            'user' => $user,
            'incidentUser' => $incidentUser,
        ];

        return $this->view('USIPS\NCMEC:IncidentUser\Delete', 'usips_ncmec_incident_user_delete', $viewParams);
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

    /**
     * Get the most recent attachments uploaded by a user
     *
     * @param int $userId
     * @param int $limit
     * @return \XF\Entity\Attachment[]
     */
    protected function getRecentUserAttachments(int $userId, int $limit = 50): array
    {
        /** @var \XF\Finder\AttachmentFinder $finder */
        $finder = $this->finder('XF:Attachment');
        
        $attachments = $finder
            ->with(['Data', 'Data.User'])
            ->where('Data.user_id', $userId)
            ->order('Data.upload_date', 'DESC')
            ->limit($limit)
            ->fetch();
        
        return $attachments->toArray();
    }
}

