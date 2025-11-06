<?php

namespace USIPS\NCMEC\XF\Admin\Controller;

use USIPS\NCMEC\Job\AssociateUser;
use XF\Admin\Controller\UserController as BaseUserController;
use XF\Searcher\User;

class UserController extends BaseUserController
{
    public function actionBatchUpdateConfirm()
    {
        $response = parent::actionBatchUpdateConfirm();

        if ($response instanceof \XF\Mvc\Reply\View)
        {
            $viewParams = $response->getParams();
            $viewParams['nonFinalizedIncidents'] = $this->finder('USIPS\NCMEC:Incident')
                ->where('is_finalized', 0)
                ->order('created_date', 'DESC')
                ->fetch();
            $response->setParams($viewParams);
        }

        return $response;
    }

    public function actionBatchUpdateAction()
    {
        $actions = $this->filter('actions', 'array');

        if ($this->request->exists('ncmec_incident') && empty($actions['ncmec_incident']))
        {
            return $this->error(\XF::phrase('usips_ncmec_you_must_confirm_to_proceed'));
        }

        if (!empty($actions['ncmec_incident']))
        {
            $this->assertPostOnly();

            if ($this->request->exists('user_ids'))
            {
                $userIds = $this->filter('user_ids', 'json-array');
            }
            else
            {
                $criteria = $this->filter('criteria', 'json-array');
                $searcher = $this->searcher(User::class, $criteria);
                $userIds = $searcher->getFinder()->fetch()->pluckNamed('user_id');
            }

            if (!$userIds)
            {
                throw $this->exception($this->error(\XF::phraseDeferred('no_items_matched_your_filter')));
            }

            $incidentId = $this->filter('incident_id', 'uint');
            $timeLimitSeconds = $this->filter('time_limit_seconds', 'uint');
            $incident = null;

            if ($incidentId)
            {
                $incident = $this->em()->find('USIPS\NCMEC:Incident', $incidentId);
            }

            if (!$incident)
            {
                // Create new incident
                $creator = $this->service('USIPS\NCMEC:Incident\Creator');
                $incident = $creator->createIncident(\XF::visitor()->user_id, \XF::visitor()->username);
            }

            // Enqueue AssociateUser job to handle user association and content/attachment collection
            \XF::app()->jobManager()->enqueue('USIPS\NCMEC:AssociateUser', [
                'incident_id' => $incident->incident_id,
                'user_ids' => $userIds,
                'time_limit_seconds' => $timeLimitSeconds
            ]);

            return $this->redirect($this->buildLink('users/batch-update', null, ['success' => true]));
        }

        return parent::actionBatchUpdateAction();
    }
}