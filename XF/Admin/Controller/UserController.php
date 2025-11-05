<?php

namespace USIPS\NCMEC\XF\Admin\Controller;

use XF\Admin\Controller\UserController as BaseUserController;
use XF\Mvc\ParameterBag;

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

        if (!empty($actions['ncmec_incident']))
        {
            $this->assertPostOnly();

            if ($this->request->exists('user_ids'))
            {
                $userIds = $this->filter('user_ids', 'json-array');
                $total = count($userIds);
                $jobCriteria = null;
            }
            else
            {
                $criteria = $this->filter('criteria', 'json-array');

                $searcher = $this->searcher(\XF\Entity\User::class, $criteria);
                $total = $searcher->getFinder()->total();
                $jobCriteria = $searcher->getFilteredCriteria();

                $userIds = null;
            }

            if (!$total)
            {
                throw $this->exception($this->error(\XF::phraseDeferred('no_items_matched_your_filter')));
            }

            $incidentId = $this->filter('incident_id', 'uint');

            $this->app->jobManager()->enqueueUnique('ncmecIncidentBatch', \USIPS\NCMEC\Job\IncidentBatch::class, [
                'total' => $total,
                'userIds' => $userIds,
                'criteria' => $jobCriteria,
                'incidentId' => $incidentId,
            ]);

            return $this->redirect($this->buildLink('users/batch-update', null, ['success' => true]));
        }

        return parent::actionBatchUpdateAction();
    }
}