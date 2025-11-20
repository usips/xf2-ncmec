<?php

namespace USIPS\NCMEC\Job;

use XF\Job\AbstractJob;
use XF\Job\JobResult;

class FinalizeCase extends AbstractJob
{
    protected $defaultData = [
        'case_id' => 0,
        'user_ids' => [],
        'current_user_index' => 0,
        'state' => 'init', // init, ban, report, files, content, finish
    ];

    public function run($maxRunTime)
    {
        $startTime = microtime(true);

        /** @var \USIPS\NCMEC\Entity\CaseFile $case */
        $case = $this->app->em()->find('USIPS\NCMEC:CaseFile', $this->data['case_id']);
        if (!$case)
        {
            return $this->complete();
        }

        if (!$case->is_finalized)
        {
            $case->is_finalized = true;
            $case->save();
        }

        if (empty($this->data['user_ids']))
        {
            $db = $this->app->db();
            $userIds = $db->fetchAllColumn("
                SELECT DISTINCT iu.user_id
                FROM xf_usips_ncmec_incident_user AS iu
                INNER JOIN xf_usips_ncmec_incident AS i ON (iu.incident_id = i.incident_id)
                WHERE i.case_id = ?
            ", [$case->case_id]);
            
            $this->data['user_ids'] = $userIds;
            $this->data['current_user_index'] = 0;
            $this->data['state'] = 'init';
        }

        $userIds = $this->data['user_ids'];
        $count = count($userIds);

        if ($this->data['current_user_index'] >= $count)
        {
            // All users processed
            $case->is_finished = true;
            $case->save();

            // Finalize all incidents
            foreach ($case->Incidents as $incident)
            {
                $incident->is_finalized = true;
                $incident->save();
            }

            return $this->complete();
        }

        $userId = $userIds[$this->data['current_user_index']];
        /** @var \XF\Entity\User $user */
        $user = $this->app->em()->find('XF:User', $userId);

        if (!$user)
        {
            // User missing, skip
            $this->data['current_user_index']++;
            $this->data['state'] = 'init';
            return $this->resume();
        }

        /** @var \USIPS\NCMEC\Service\Report\Submitter $submitter */
        $submitter = $this->app->service('USIPS\NCMEC:Report\Submitter', $case, $user);

        try
        {
            switch ($this->data['state'])
            {
                case 'init':
                case 'ban':
                    $submitter->banUser();
                    $this->data['state'] = 'report';
                    break;

                case 'report':
                    $submitter->ensureReportOpened();
                    $this->data['state'] = 'files';
                    break;

                case 'files':
                    // Process one file at a time to avoid timeouts
                    $db = $this->app->db();
                    $row = $db->fetchRow("
                        SELECT iad.data_id, iad.incident_id
                        FROM xf_usips_ncmec_incident_attachment_data AS iad
                        INNER JOIN xf_usips_ncmec_incident AS i ON (iad.incident_id = i.incident_id)
                        WHERE i.case_id = ? AND iad.user_id = ?
                        LIMIT 1
                    ", [$case->case_id, $userId]);

                    if ($row)
                    {
                        $nextAttachmentId = $row['data_id'];
                        $incidentId = $row['incident_id'];

                        /** @var \XF\Entity\AttachmentData $attachmentData */
                        $attachmentData = $this->app->em()->find('XF:AttachmentData', $nextAttachmentId);
                        
                        if ($attachmentData)
                        {
                            $submitter->processAttachment($attachmentData);
                        }
                        
                        // Delete the incident attachment record to move forward
                        $db->delete('xf_usips_ncmec_incident_attachment_data', 'incident_id = ? AND data_id = ?', [$incidentId, $nextAttachmentId]);
                        
                        // Stay in 'files' state to process next file
                    }
                    else
                    {
                        // No more files
                        $this->data['state'] = 'content';
                    }
                    break;

                case 'content':
                    $submitter->deleteContent();
                    $this->data['state'] = 'finish';
                    break;

                case 'finish':
                    $submitter->finishReport();
                    
                    // Move to next user
                    $this->data['current_user_index']++;
                    $this->data['state'] = 'init';
                    break;
            }
        }
        catch (\Exception $e)
        {
            \XF::logException($e, false, "NCMEC Finalization Error (User $userId, State {$this->data['state']}): ");
            // Move to next user on error to avoid infinite loop? 
            // Or maybe retry? For now, let's skip user to prevent blocking queue
            $this->data['current_user_index']++;
            $this->data['state'] = 'init';
        }

        if (microtime(true) - $startTime > $maxRunTime)
        {
            return $this->resume();
        }

        // If we are here, we finished a step quickly, loop again immediately
        return $this->resume();
    }

    public function getStatusMessage()
    {
        $total = count($this->data['user_ids']);
        $current = $this->data['current_user_index'] + 1;
        $state = $this->data['state'];
        return sprintf('Finalizing NCMEC Case... User %d / %d (%s)', $current, $total, $state);
    }

    public function canCancel()
    {
        return false;
    }

    public function canTriggerByChoice()
    {
        return false;
    }
}
