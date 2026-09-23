<?php

namespace USIPS\NCMEC\Job;

use XF\Job\AbstractJob;
use XF\Job\JobResult;

class FinalizeCase extends AbstractJob
{
    /**
     * How many times one evidence file may fail (upload or fileinfo) within a
     * run before the submission halts instead of finishing the NCMEC report.
     */
    public const MAX_FILE_ATTEMPTS = 5;

    protected $defaultData = [
        'case_id' => 0,
        'user_ids' => [],
        'current_user_index' => 0,
        'state' => 'init', // init, ban, report, files, export_user, export_content_init, export_content_loop, finish, content
        'phase' => 'submit', // submit, cleanup
        'content_types' => [],
        'content_type_index' => 0,
        'last_data_id' => 0,
        'total_files' => 0,
        'processed_files' => 0,
        'file_failures' => [], // data_id => failed attempts, for the current subject
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

            $openedReports = $this->app->finder('USIPS\NCMEC:Report')
                ->where('case_id', $case->case_id)
                ->where('ncmec_report_id', '>', 0)
                ->fetch();

            $unfinishedReports = $openedReports->filter(function ($report)
            {
                return !$report->submitted_on;
            });

            if ($unfinishedReports->count())
            {
                // A subject's report was opened with NCMEC but never finished
                // (an earlier step errored). Don't mark the case submitted:
                // leave it finalized-but-not-submitted so it can be resumed.
                // Content for those subjects was kept by the cleanup guard.
                $ids = [];
                foreach ($unfinishedReports as $report)
                {
                    $ids[] = "#{$report->report_id} (NCMEC {$report->ncmec_report_id}, user {$report->subject_user_id})";
                }
                \XF::logError(sprintf(
                    'NCMEC Case #%d: %d NCMEC report(s) were opened but not finished: %s. The case has NOT been marked submitted and those subjects\' content was not deleted. See the earlier errors, then resume with "php cmd.php usips-ncmec:resume-case %d --execute" or re-finalize the case in the admin panel.',
                    $case->case_id,
                    count($ids),
                    implode(', ', $ids),
                    $case->case_id
                ));

                return $this->complete();
            }

            if ($openedReports->count() > 0)
            {
                // Every opened report was finished: mark the case submitted
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
                    $existingReport = $submitter->getExistingReport();
                    if ($existingReport && $existingReport->ncmec_report_id && $existingReport->submitted_on)
                    {
                        // Filed on an earlier run (resume). Don't ban, upload or
                        // export again: re-exporting would overwrite the
                        // preserved internal-data exports.
                        $this->advanceToNextSubject();
                        break;
                    }

                    $submitter->banUser();
                    $this->data['state'] = 'report';
                    break;

                case 'report':
                    $submitter->ensureReportOpened();

                    // Calculate file counts for progress tracking
                    $db = $this->app->db();
                    $countQuery = "
                        SELECT COUNT(DISTINCT iad.data_id)
                        FROM xf_usips_ncmec_incident_attachment_data AS iad
                        INNER JOIN xf_usips_ncmec_incident AS i ON (iad.incident_id = i.incident_id)
                        WHERE i.case_id = ?
                    ";
                    $countParams = [$case->case_id];

                    if (!$isSingleReport)
                    {
                        $countQuery .= " AND iad.user_id = ?";
                        $countParams[] = $userId;
                    }

                    $this->data['total_files'] = $db->fetchOne($countQuery, $countParams);
                    $this->data['processed_files'] = 0;
                    $this->data['last_data_id'] = 0;
                    $this->data['file_failures'] = [];

                    $this->data['state'] = 'files';
                    break;

                case 'files':
                    // Process a bounded batch of files per iteration, uploading
                    // them to NCMEC with the network I/O parallelized across
                    // $lanes concurrent requests. DB/entity work stays serial.
                    $db = $this->app->db();
                    $lastDataId = $this->data['last_data_id'] ?? 0;
                    $lanes = $this->getUploadLanes();

                    $fileQuery = "
                        SELECT DISTINCT iad.data_id
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

                    $fileQuery .= " ORDER BY iad.data_id ASC LIMIT " . $lanes;

                    $dataIds = array_map('intval', $db->fetchAllColumn($fileQuery, $fileParams));

                    if ($dataIds)
                    {
                        // The cursor moves past the whole batch. Failed files go
                        // into file_failures and are retried below; they are
                        // never dropped. Successes are idempotent on re-run via
                        // the ReportFile (report_id, data_id) row.
                        $this->data['last_data_id'] = end($dataIds);
                        $this->processFileBatch($submitter, $dataIds, $lanes);
                        $this->data['processed_files'] += count($dataIds);

                        // Stay in 'files' state to process the next batch
                    }
                    elseif (!empty($this->data['file_failures']))
                    {
                        // First pass done. Retry the failures a bounded number
                        // of times before halting.
                        $retry = [];
                        $maxAttemptsSoFar = 0;
                        foreach ($this->data['file_failures'] as $dataId => $attempts)
                        {
                            if ($attempts < self::MAX_FILE_ATTEMPTS)
                            {
                                $retry[] = (int) $dataId;
                                $maxAttemptsSoFar = max($maxAttemptsSoFar, $attempts);
                            }
                        }

                        if (!$retry)
                        {
                            return $this->haltSubmission($case, $logUserId, sprintf(
                                '%d evidence file(s) could not be sent after %d attempts each (data_id: %s).',
                                count($this->data['file_failures']),
                                self::MAX_FILE_ATTEMPTS,
                                implode(', ', array_keys($this->data['file_failures']))
                            ));
                        }

                        $retry = array_slice($retry, 0, $lanes);

                        // Short backoff inside the time budget. A JobResult
                        // reattempt with a future trigger_date would strand the
                        // manual job: tools/run-job drops jobs that aren't due,
                        // and manual jobs are never auto-run.
                        $delay = (int) min(2 ** max(0, $maxAttemptsSoFar - 1), 8, max(1, floor($maxRunTime / 2)));
                        if ((microtime(true) - $startTime) + $delay >= $maxRunTime)
                        {
                            // Not enough time left in this chunk: back off in the next one.
                            return $this->resume();
                        }
                        sleep($delay);

                        $this->processFileBatch($submitter, $retry, $lanes);
                    }
                    else
                    {
                        // Every file for this subject is sent
                        $this->data['state'] = 'export_user';
                        $this->data['last_data_id'] = 0; // Reset for next user
                    }
                    break;

                case 'export_user':
                    $submitter->exportUserData();
                    $this->data['state'] = 'export_content_init';
                    break;

                case 'export_content_init':
                    $this->data['content_types'] = $submitter->getContentTypesToExport();
                    $this->data['content_type_index'] = 0;
                    $this->data['state'] = 'export_content_loop';
                    break;

                case 'export_content_loop':
                    if (!isset($this->data['content_types'][$this->data['content_type_index']]))
                    {
                        // Done with all content types
                        $this->data['state'] = 'finish';
                        $this->data['content_types'] = [];
                        $this->data['content_type_index'] = 0;
                    }
                    else
                    {
                        $contentType = $this->data['content_types'][$this->data['content_type_index']];
                        $submitter->exportContentType($contentType);
                        $this->data['content_type_index']++;
                        // Stay in loop
                    }
                    break;

                case 'finish':
                    // Last check before the report is closed: a finished NCMEC
                    // report can't take more files. Local copies are deleted
                    // only after upload + fileinfo, so any left means unsent
                    // evidence.
                    if (!empty($this->data['file_failures']))
                    {
                        return $this->haltSubmission($case, $logUserId, sprintf(
                            'evidence file(s) still failing (data_id: %s).',
                            implode(', ', array_keys($this->data['file_failures']))
                        ));
                    }

                    $outstanding = $submitter->countOutstandingEvidence();
                    if ($outstanding > 0)
                    {
                        return $this->haltSubmission($case, $logUserId, sprintf(
                            '%d evidence file(s) are still held locally and were not confirmed sent to NCMEC.',
                            $outstanding
                        ));
                    }

                    $submitter->finishReport();

                    // Move to next user (or finish if single report)
                    $this->advanceToNextSubject();
                    break;

                case 'content':
                    // Delete a subject's content only after their NCMEC report
                    // is confirmed finished. A subject whose submission errored
                    // (skipped by the catch below) keeps their content.
                    $report = $submitter->getExistingReport();
                    if (!$report || !$report->ncmec_report_id || !$report->submitted_on)
                    {
                        \XF::logError("NCMEC Case #{$case->case_id}: not deleting content for user {$logUserId} because their NCMEC report was not finished.");
                        $this->data['current_user_index']++;
                        $this->data['state'] = 'content';
                        break;
                    }

                    $remainingTime = $maxRunTime - (microtime(true) - $startTime);
                    if ($remainingTime < 1) $remainingTime = 1;

                    $done = $submitter->deleteContent($remainingTime);

                    if ($done)
                    {
                        // Move to next user
                        $this->data['current_user_index']++;
                        $this->data['state'] = 'content';
                    }
                    // Else stay in 'content' state and resume
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

            // Move to next user on error to avoid an infinite loop. This
            // subject's report stays unfinished: the cleanup phase keeps their
            // content and the case is not marked submitted.
            $this->data['current_user_index']++;
            $this->data['state'] = ($this->data['phase'] === 'submit') ? 'init' : 'content';
            $this->data['last_data_id'] = 0;
            $this->data['file_failures'] = [];
        }

        if (microtime(true) - $startTime > $maxRunTime)
        {
            return $this->resume();
        }

        // If we are here, we finished a step quickly, loop again immediately
        return $this->resume();
    }

    protected function advanceToNextSubject(): void
    {
        $this->data['current_user_index']++;
        $this->data['state'] = 'init';
        $this->data['last_data_id'] = 0;
        $this->data['file_failures'] = [];
    }

    /**
     * Send one batch of evidence files and update the per-file failure counts.
     *
     * @param \USIPS\NCMEC\Service\Report\Submitter $submitter
     * @param int[] $dataIds
     * @param int $lanes
     */
    protected function processFileBatch($submitter, array $dataIds, int $lanes): void
    {
        $attachments = $this->app->em()->findByIds('XF:AttachmentData', $dataIds);

        $batch = [];
        $missing = [];
        foreach ($dataIds as $dataId)
        {
            if (isset($attachments[$dataId]))
            {
                $batch[] = $attachments[$dataId];
            }
            else
            {
                $missing[] = $dataId;
            }
        }

        if ($missing)
        {
            // Our copy is gone. Normally that's because it was sent and then
            // deleted (resume). If there's no record of it being sent, it was
            // removed some other way: nothing is left to upload, so log it.
            foreach ($submitter->getMissingAttachmentSendState($missing) as $dataId => $sent)
            {
                unset($this->data['file_failures'][$dataId]);
                if (!$sent)
                {
                    \XF::logError("NCMEC Case #{$this->data['case_id']}: evidence attachment data_id {$dataId} no longer exists locally and this report has no record of it being sent to NCMEC. It cannot be uploaded.");
                }
            }
        }

        if (!$batch)
        {
            return;
        }

        $results = $submitter->processAttachmentsBatch($batch, $lanes);
        foreach ($batch as $attachmentData)
        {
            $dataId = $attachmentData->data_id;
            $result = $results[$dataId] ?? ['ok' => false, 'error' => 'no result'];
            if ($result['ok'])
            {
                unset($this->data['file_failures'][$dataId]);
            }
            else
            {
                $this->data['file_failures'][$dataId] = ($this->data['file_failures'][$dataId] ?? 0) + 1;
            }
        }
    }

    /**
     * Stop the submission before finishing the subject's NCMEC report. The
     * report stays open, no content is deleted, and the case stays finalized
     * but not submitted. That state shows as resubmittable in the admin panel
     * and is what usips-ncmec:resume-case looks for.
     *
     * @param \USIPS\NCMEC\Entity\CaseFile $case
     * @param int|string $logUserId
     * @param string $reason
     */
    protected function haltSubmission(\USIPS\NCMEC\Entity\CaseFile $case, $logUserId, string $reason): JobResult
    {
        \XF::logError(sprintf(
            'NCMEC Case #%d submission HALTED before finishing the NCMEC report for user %s: %s The NCMEC report was left open (not finished), no content was deleted, and the case remains finalized but not submitted. Fix the cause (see the preceding upload/fileinfo errors and the NCMEC API log), then resume with "php cmd.php usips-ncmec:resume-case %d --execute" or re-finalize the case in the admin panel.',
            $case->case_id,
            $logUserId,
            $reason,
            $case->case_id
        ));

        return $this->complete();
    }

    /**
     * Concurrent upload lanes for the file phase. Reads the
     * usipsNcmecUploadLanes option; defaults to 5 and is clamped to a sane
     * range so a misconfiguration can't hammer NCMEC or drop to zero.
     */
    protected function getUploadLanes(): int
    {
        $lanes = (int) ($this->app->options()->usipsNcmecUploadLanes ?? 5);
        if ($lanes < 1) { $lanes = 1; }
        if ($lanes > 10) { $lanes = 10; }
        return $lanes;
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

        $detail = '';
        if ($state === 'files' && !empty($this->data['total_files']))
        {
            $detail = sprintf(' - %d/%d', $this->data['processed_files'], $this->data['total_files']);
            if (!empty($this->data['file_failures']))
            {
                $detail .= sprintf(', %d retrying', count($this->data['file_failures']));
            }
        }
        else if ($state === 'export_content_loop' && !empty($this->data['content_types']))
        {
             $type = $this->data['content_types'][$this->data['content_type_index']] ?? '';
             if ($type)
             {
                 $detail = sprintf(' - %s', $type);
             }
        }

        return sprintf('Finalizing NCMEC Case... User %d / %d (%s - %s%s)', $current, $total, $phase, $state, $detail);
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
