<?php

namespace USIPS\NCMEC\Job;

use XF\Job\AbstractUserCriteriaJob;
use XF\Entity\User;

class IncidentBatch extends AbstractUserCriteriaJob
{
    protected $defaultData = [
        'incidentId' => 0,
        'userIds' => [],
        'contentIds' => [],
    ];

    protected function executeAction(User $user)
    {
        // Collect user IDs and their content for batch processing
        $this->data['userIds'][] = $user->user_id;

        // Collect content from this user
        $this->collectUserContent($user);
    }

    protected function collectUserContent(User $user)
    {
        // Get posts by this user
        $posts = $this->app->finder('XF:Post')
            ->where('user_id', $user->user_id)
            ->fetch();

        foreach ($posts as $post)
        {
            $this->data['contentIds'][] = [
                'content_type' => 'post',
                'content_id' => $post->post_id,
                'user_id' => $user->user_id,
            ];
        }

        // Get threads started by this user
        $threads = $this->app->finder('XF:Thread')
            ->where('user_id', $user->user_id)
            ->fetch();

        foreach ($threads as $thread)
        {
            $this->data['contentIds'][] = [
                'content_type' => 'thread',
                'content_id' => $thread->thread_id,
                'user_id' => $user->user_id,
            ];
        }

        // Get profile posts by this user
        $profilePosts = $this->app->finder('XF:ProfilePost')
            ->where('user_id', $user->user_id)
            ->fetch();

        foreach ($profilePosts as $profilePost)
        {
            $this->data['contentIds'][] = [
                'content_type' => 'profile_post',
                'content_id' => $profilePost->profile_post_id,
                'user_id' => $user->user_id,
            ];
        }
    }

    protected function finalizeJob()
    {
        // After collecting all users and content, create/update the incident
        $this->createOrUpdateIncident();
    }

    protected function createOrUpdateIncident()
    {
        $userIds = array_unique($this->data['userIds']);
        $contentIds = $this->data['contentIds'];

        if (!$userIds)
        {
            return; // Nothing to do
        }

        $db = $this->app->db();
        $db->beginTransaction();

        try
        {
            $incident = null;

            if ($this->data['incidentId'])
            {
                // Add to existing incident
                $incident = $this->app->em()->find('USIPS\NCMEC:Incident', $this->data['incidentId']);
                if (!$incident)
                {
                    throw new \InvalidArgumentException("Incident not found: {$this->data['incidentId']}");
                }
            }
            else
            {
                // Create new incident
                /** @var \USIPS\NCMEC\Service\Incident\Creator $creator */
                $creator = $this->app->service('USIPS\NCMEC:Incident\Creator');
                $incident = $creator->createIncident(null, \XF::visitor()->user_id, \XF::visitor()->username, []);
            }

            // Add users to incident
            $userService = $this->app->service('USIPS\NCMEC:Incident\Creator', $incident);
            $userService->associateUsersByIds($userIds);

            // Add content to incident
            $userService->associateContentByIds($contentIds);

            $db->commit();
        }
        catch (\Exception $e)
        {
            $db->rollback();
            throw $e;
        }
    }

    protected function getActionDescription()
    {
        $actionPhrase = \XF::phrase('usips_ncmec_adding_users_to_incident');
        $typePhrase = \XF::phrase('users');

        return sprintf('%s... %s', $actionPhrase, $typePhrase);
    }

    public function canCancel()
    {
        return true;
    }

    public function canTriggerByChoice()
    {
        return false;
    }
}