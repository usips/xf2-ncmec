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
        'phase' => 'submit', // submit, cleanup
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

        if (!$case->finalized_on)
        {
            $case->finalized_on = \XF::$time;
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
            $this->data['phase'] = 'submit';
        }

        $userIds = $this->data['user_ids'];
        $count = count($userIds);

        // If we are in single-report mode (reported_person_id set), we treat the whole batch as one item
        // But we still need to iterate through files for all users
        $isSingleReport = (bool)$case->reported_person_id;
        
        if ($this->data['current_user_index'] >= ($isSingleReport ? 1 : $count))
        {
            // End of current phase loop
            
            if ($this->data['phase'] === 'submit')
            {
                // Switch to cleanup phase
                $this->data['phase'] = 'cleanup';
                $this->data['current_user_index'] = 0;
                $this->data['state'] = 'content';
                return $this->resume();
            }

            // All users processed - clean up and finalize
            $this->cleanupFailedReports($case);
            
            // Check if we have any successful reports
            $successfulReports = $this->app->finder('USIPS\NCMEC:Report')
                ->where('case_id', $case->case_id)
                ->where('ncmec_report_id', '>', 0)
                ->total();
            
            if ($successfulReports > 0)
            {
                // At least one report succeeded, mark case as submitted
                $case->submitted_on = \XF::$time;
                $case->save();

                // Finalize all incidents
                foreach ($case->Incidents as $incident)
                {
                    $incident->finalized_on = \XF::$time;
                    $incident->submitted_on = \XF::$time;
                    $incident->save();
                }
            }
            else
            {
                // All reports failed, reset case to allow resubmission
                $case->finalized_on = null;
                $case->submitted_on = null;
                $case->save();
                
                \XF::logError("NCMEC Case #{$case->case_id}: All reports failed. Case has been unlocked for resubmission.");
            }

            return $this->complete();
        }

        $userId = $userIds[$this->data['current_user_index']];
        
        // Prepare subjects for Submitter
        if ($isSingleReport)
        {
            // In single report mode, we pass ALL users to the submitter
            $users = $this->app->em()->findByIds('XF:User', $userIds);
            if (!$users->count())
            {
                // No users found? Should not happen if userIds is populated
                $this->data['current_user_index']++;
                return $this->resume();
            }
            $submitterSubjects = $users;
            $logUserId = 'ALL (' . count($userIds) . ' users)';
        }
        else
        {
            // In multi report mode, we pass one user at a time
            /** @var \XF\Entity\User $user */
            $user = $this->app->em()->find('XF:User', $userId);

            if (!$user)
            {
                // User missing, skip
                $this->data['current_user_index']++;
                $this->data['state'] = ($this->data['phase'] === 'submit') ? 'init' : 'content';
                return $this->resume();
            }
            $submitterSubjects = $user;
            $logUserId = $userId;
        }

        /** @var \USIPS\NCMEC\Service\Report\Submitter $submitter */
        $submitter = $this->app->service('USIPS\NCMEC:Report\Submitter', $case, $submitterSubjects);

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
                    $lastDataId = $this->data['last_data_id'] ?? 0;
                    
                    $fileQuery = "
                        SELECT iad.data_id, iad.incident_id
                        FROM xf_usips_ncmec_incident_attachment_data AS iad
                        INNER JOIN xf_usips_ncmec_incident AS i ON (iad.incident_id = i.incident_id)
                        WHERE i.case_id = ? 
                        AND iad.data_id > ?
                    ";
                    $fileParams = [$case->case_id, $lastDataId];
                    
                    if (!$isSingleReport)
                    {
                        $fileQuery .= " AND iad.user_id = ?";
                        $fileParams[] = $userId;
                    }
                    
                    $fileQuery .= " ORDER BY iad.data_id ASC LIMIT 1";
                    
                    $row = $db->fetchRow($fileQuery, $fileParams);

                    if ($row)
                    {
                        $nextAttachmentId = $row['data_id'];
                        $this->data['last_data_id'] = $nextAttachmentId;

                        /** @var \XF\Entity\AttachmentData $attachmentData */
                        $attachmentData = $this->app->em()->find('XF:AttachmentData', $nextAttachmentId);
                        
                        if ($attachmentData)
                        {
                            $submitter->processAttachment($attachmentData);
                        }
                        
                        // Stay in 'files' state to process next file
                    }
                    else
                    {
                        // No more files for this user
                        $this->data['state'] = 'finish';
                        $this->data['last_data_id'] = 0; // Reset for next user
                    }
                    break;

                case 'finish':
                    $submitter->finishReport();
                    
                    // Move to next user (or finish if single report)
                    $this->data['current_user_index']++;
                    $this->data['state'] = 'init';
                    $this->data['last_data_id'] = 0;
                    break;

                case 'content':
                    $submitter->deleteContent();
                    
                    // Move to next user
                    $this->data['current_user_index']++;
                    $this->data['state'] = 'content';
                    break;
            }
        }
        catch (\Exception $e)
        {
            $errorMsg = $e->getMessage();
            
            // Log the full error
            \XF::logException($e, false, "NCMEC Finalization Error (Case {$case->case_id}, User $logUserId, State {$this->data['state']}): ");
            
            // If this is an XML validation error, create an admin notice so it's visible
            if (strpos($errorMsg, 'XML validation failed') !== false || strpos($errorMsg, 'validation failed') !== false)
            {
                // Create a prominent error notice
                $notice = sprintf(
                    "NCMEC Case #%d XML Validation Failed for User %s:\n\n%s",
                    $case->case_id,
                    $logUserId,
                    $errorMsg
                );
                
                // Log as error so it appears in logs
                \XF::logError($notice);
            }
            
            // Move to next user on error to avoid infinite loop
            $this->data['current_user_index']++;
            $this->data['state'] = ($this->data['phase'] === 'submit') ? 'init' : 'content';
            $this->data['last_data_id'] = 0;
        }

        if (microtime(true) - $startTime > $maxRunTime)
        {
            return $this->resume();
        }

        // If we are here, we finished a step quickly, loop again immediately
        return $this->resume();
    }

    /**
     * Clean up failed reports (those without ncmec_report_id)
     * 
     * @param \USIPS\NCMEC\Entity\CaseFile $case
     */
    protected function cleanupFailedReports(\USIPS\NCMEC\Entity\CaseFile $case)
    {
        $failedReports = $this->app->finder('USIPS\NCMEC:Report')
            ->where('case_id', $case->case_id)
            ->where('ncmec_report_id', 0)
            ->fetch();
        
        foreach ($failedReports as $report)
        {
            \XF::logError("NCMEC: Deleting failed report #{$report->report_id} for Case #{$case->case_id}, User #{$report->subject_user_id}");
            $report->delete();
        }
    }

    public function getStatusMessage()
    {
        $total = count($this->data['user_ids']);
        $current = $this->data['current_user_index'] + 1;
        $state = $this->data['state'];
        $phase = $this->data['phase'] ?? 'submit';
        return sprintf('Finalizing NCMEC Case... User %d / %d (%s - %s)', $current, $total, $phase, $state);
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
