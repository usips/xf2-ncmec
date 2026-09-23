<?php

namespace USIPS\NCMEC\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Resume an NCMEC case whose submission was interrupted (e.g. the browser was
 * closed while the finalize job runner was mid-send, or the job halted because
 * evidence could not be sent).
 *
 * The FinalizeCase job is a resumable, idempotent state machine:
 *   - subjects whose report is already finished (submitted_on) are skipped,
 *   - ensureReportOpened() doesn't reopen a report that already has an
 *     ncmec_report_id,
 *   - evidence files are keyed by (report_id, data_id): uploaded files aren't
 *     uploaded again, and a missing /fileinfo is re-sent,
 *   - the report is finished only once no evidence is left unsent.
 * This command drives that job to completion server-side, where a closed
 * browser can't interrupt it.
 *
 * Safety:
 *   - Only finalized, not-yet-submitted cases are resumed. A draft (never
 *     finalized) case is refused: running the job would finalize it, ban its
 *     subjects, file reports and delete content. --force-unfinalized overrides
 *     that for one explicit case ID, and still requires the same validation
 *     as the admin finalize flow.
 *   - An existing finalize job that is locked by another runner, or ran
 *     recently, is refused. An idle one is resumed by its unique key from its
 *     saved state, without being re-enqueued (which would overwrite that state).
 *   - Dry run by default; --execute actually files.
 */
class ResumeCase extends Command
{
    /**
     * A finalize job that ran within this many seconds may still be driven by
     * an admin's browser (tools/run-job), so don't take it over.
     */
    public const RECENT_RUN_WINDOW = 300;

    protected function configure()
    {
        $this
            ->setName('usips-ncmec:resume-case')
            ->setDescription('Resume an interrupted NCMEC case submission (finalized but not submitted).')
            ->addArgument('case-id', InputArgument::OPTIONAL, 'Case ID to resume')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Resume every interrupted case (finalized_on set, submitted_on empty)')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Actually run the resume. Without this flag the command only analyses and reports the gap (dry run).')
            ->addOption('force-unfinalized', null, InputOption::VALUE_NONE, 'Allow an explicit case ID that is NOT finalized. This FINALIZES it (bans subjects, files reports, deletes content), after the same validation as the admin finalize flow. Only when the admin flow cannot be used.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $app = \XF::app();

        // The CLI request has no IP, and XF's error logger then fails silently
        // (Ip::stringToBinary('') throws inside Error::logException). Without an
        // IP, the job's HALTED / unfinished-report errors would never reach the
        // admin error log.
        if (empty($_SERVER['REMOTE_ADDR']))
        {
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $app->container()->decache('request');
        }

        $caseId = $input->getArgument('case-id');
        $all    = $input->getOption('all');
        $exec   = $input->getOption('execute');
        $force  = $input->getOption('force-unfinalized');

        if (!$caseId && !$all)
        {
            $output->writeln('<error>Provide a case ID, or --all to resume every interrupted case.</error>');
            return 1;
        }

        if ($force && ($all || !$caseId))
        {
            $output->writeln('<error>--force-unfinalized requires one explicit case ID and cannot be combined with --all.</error>');
            return 1;
        }

        // Build the target case list.
        $finder = $app->finder('USIPS\NCMEC:CaseFile');
        if ($caseId)
        {
            $finder->where('case_id', (int) $caseId);
        }
        else
        {
            // Interrupted = finalized but not yet submitted. submitted_on is NULL
            // on a never-submitted case (but tolerate a literal 0 too).
            $finder->where('finalized_on', '>', 0)
                ->whereOr(
                    ['submitted_on', null],
                    ['submitted_on', 0]
                );
        }
        $cases = $finder->fetch();

        if (!$cases->count())
        {
            $output->writeln('<comment>No matching case(s) found.</comment>');
            return 0;
        }

        $anyWork = false;
        $anyRefused = false;
        foreach ($cases as $case)
        {
            $outcome = $this->handleCase($app, $case, $exec, $force, $output);
            if ($outcome === 'work')
            {
                $anyWork = true;
            }
            elseif ($outcome === 'refused')
            {
                $anyRefused = true;
            }
        }

        if (!$exec && $anyWork)
        {
            $output->writeln('');
            $output->writeln('<comment>Dry run only. Re-run with --execute to file the missing report(s) and mark the case submitted.</comment>');
        }

        return $anyRefused ? 1 : 0;
    }

    /**
     * @return string 'none' (nothing to do), 'refused', or 'work'
     */
    protected function handleCase(\XF\App $app, \USIPS\NCMEC\Entity\CaseFile $case, bool $exec, bool $force, OutputInterface $output): string
    {
        $output->writeln('');
        $output->writeln(sprintf('=== Case #%d: %s ===', $case->case_id, $case->title));
        $output->writeln(sprintf('  incident_type=%s  finalized=%s  submitted=%s',
            $case->incident_type ?: '(none)',
            $case->finalized_on ? date('Y-m-d H:i:s', $case->finalized_on) : 'no',
            $case->submitted_on ? date('Y-m-d H:i:s', $case->submitted_on) : 'NO'
        ));

        if ($case->submitted_on)
        {
            $output->writeln('  <info>Already submitted; nothing to resume.</info>');
            return 'none';
        }

        if (!$case->finalized_on)
        {
            if (!$force)
            {
                $output->writeln('  <error>Case not finalized; refusing.</error> Only a finalized case with an interrupted submission can be resumed.');
                $output->writeln('  Finalize it from the admin panel (NCMEC > Cases > Finalize), which validates it and shows a preview first.');
                return 'refused';
            }

            $errors = $case->getFinalizationErrors();
            if ($errors)
            {
                $output->writeln('  <error>Case not finalized and fails finalization validation; refusing:</error>');
                foreach ($errors as $error)
                {
                    $output->writeln('    - ' . $error);
                }
                return 'refused';
            }

            $output->writeln('  <comment>WARNING: --force-unfinalized: this case is NOT finalized. --execute will FINALIZE it: ban its subjects, file NCMEC reports and delete their content.</comment>');
        }

        // Subjects on the case = distinct incident users. Map each to its report state.
        $db = $app->db();
        $subjects = $db->fetchAll("
            SELECT DISTINCT iu.user_id, iu.username
            FROM xf_usips_ncmec_incident_user AS iu
            INNER JOIN xf_usips_ncmec_incident AS i ON (iu.incident_id = i.incident_id)
            WHERE i.case_id = ?
            ORDER BY iu.user_id
        ", $case->case_id);

        if (!$subjects)
        {
            $output->writeln('  <error>Case has no subjects (no incident users); refusing.</error> There is nothing to file.');
            return 'refused';
        }

        $reports = [];
        foreach ($app->finder('USIPS\NCMEC:Report')->where('case_id', $case->case_id)->fetch() as $r)
        {
            $reports[$r->subject_user_id] = $r;
        }

        $isSingleReport = (bool) $case->reported_person_id;
        $singleReport = $isSingleReport ? (reset($reports) ?: null) : null;

        $missing = [];
        $output->writeln('  Subjects:' . ($isSingleReport ? ' (single-report mode: one report covers all subjects)' : ''));
        foreach ($subjects as $s)
        {
            $r = $isSingleReport ? $singleReport : ($reports[$s['user_id']] ?? null);
            if ($r && $r->ncmec_report_id && $r->submitted_on)
            {
                $state = sprintf('filed (ncmec_report_id=%d)', $r->ncmec_report_id);
            }
            elseif ($r && $r->ncmec_report_id)
            {
                $state = sprintf('opened but NOT finished (ncmec_report_id=%d)', $r->ncmec_report_id);
                $missing[] = $s;
            }
            else
            {
                $state = 'NO report';
                $missing[] = $s;
            }

            if (!$isSingleReport)
            {
                $state .= sprintf('; %d evidence file(s) not yet sent',
                    $this->countOutstandingEvidence($db, $case->case_id, (int) $s['user_id']));
            }

            $output->writeln(sprintf('    - %-24s (user %d): %s',
                $s['username'], $s['user_id'], $state));
        }

        if ($isSingleReport)
        {
            $output->writeln(sprintf('  %d evidence file(s) not yet sent.',
                $this->countOutstandingEvidence($db, $case->case_id, null)));
        }

        if (!$missing)
        {
            $output->writeln('  <info>All subjects have finished reports; only the case-level submitted flag is pending. --execute will stamp it.</info>');
        }
        else
        {
            $output->writeln(sprintf('  <comment>%d subject(s) still need a report filed.</comment>', count($missing)));
        }

        // Inspect the existing finalize job, if any, before touching it.
        $uniqueId = 'usipsNcmecFinalize' . $case->case_id;
        $jobManager = $app->jobManager();
        $job = $jobManager->getUniqueJob($uniqueId);
        $jobProblem = $job ? $this->getJobProblem($job, (int) $case->case_id) : null;

        if (!$job)
        {
            $output->writeln(sprintf('  Finalize job %s: none queued; --execute will start a fresh one (it resumes idempotently).', $uniqueId));
        }
        elseif ($jobProblem)
        {
            $output->writeln(sprintf('  <error>Finalize job %s (job_id %d): %s</error>', $uniqueId, $job['job_id'], $jobProblem));
        }
        else
        {
            $output->writeln(sprintf('  Finalize job %s (job_id %d): idle; --execute will resume it from its saved state (last run %s).',
                $uniqueId,
                $job['job_id'],
                $job['last_run_date'] ? date('Y-m-d H:i:s', $job['last_run_date']) : 'never'
            ));
        }

        if (!$exec)
        {
            return $jobProblem ? 'refused' : 'work';
        }

        if ($jobProblem)
        {
            $output->writeln('  <error>Refusing to run while that job may still be active. Try again later.</error>');
            return 'refused';
        }

        if (!$job)
        {
            $jobManager->enqueueUnique($uniqueId, 'USIPS\NCMEC:FinalizeCase', ['case_id' => $case->case_id]);
        }

        // The CLI visitor is a guest. Run as the case's creator, as the admin
        // finalize flow would, so reports and API log rows name a real user.
        $actor = $case->User;
        $output->writeln(sprintf('  <info>Running %s to completion (as %s)...</info>',
            $uniqueId, $actor ? $actor->username : 'System'));
        $run = function () use ($uniqueId, $output)
        {
            return $this->runFinalizeJob($uniqueId, $output);
        };
        $completed = $actor ? \XF::asVisitor($actor, $run) : $run();
        if (!$completed)
        {
            return 'refused';
        }

        // Re-read and report the outcome.
        $app->em()->clearEntityCache();
        $fresh = $app->em()->find('USIPS\NCMEC:CaseFile', $case->case_id);
        if ($fresh && $fresh->submitted_on)
        {
            $output->writeln(sprintf('  <info>DONE: case #%d submitted at %s.</info>',
                $case->case_id, date('Y-m-d H:i:s', $fresh->submitted_on)));
        }
        else
        {
            $output->writeln(sprintf('  <error>Case #%d still not marked submitted — check the XF error log and xf_usips_ncmec_api_log.</error>', $case->case_id));
        }
        return 'work';
    }

    /**
     * Returns why an existing finalize job must not be taken over, or null if
     * it is idle and safe to resume.
     */
    protected function getJobProblem(array $job, int $caseId): ?string
    {
        if ($job['execute_class'] !== 'USIPS\NCMEC:FinalizeCase')
        {
            return sprintf('unexpected job class %s', $job['execute_class']);
        }

        $data = @unserialize($job['execute_data']);
        if (!is_array($data) || (int) ($data['case_id'] ?? 0) !== $caseId)
        {
            return 'job data does not match this case';
        }

        $now = time();
        if ($job['trigger_date'] > $now)
        {
            return sprintf('locked by another runner (lock held until %s)', date('Y-m-d H:i:s', $job['trigger_date']));
        }

        if ($job['last_run_date'] && $job['last_run_date'] > $now - self::RECENT_RUN_WINDOW)
        {
            return sprintf('ran at %s, less than %d seconds ago; it may still be driven by an admin browser. Wait until %s.',
                date('Y-m-d H:i:s', $job['last_run_date']),
                self::RECENT_RUN_WINDOW,
                date('Y-m-d H:i:s', $job['last_run_date'] + self::RECENT_RUN_WINDOW)
            );
        }

        return null;
    }

    /**
     * Run the unique job until it is dequeued, without re-enqueueing it (which
     * would overwrite its saved state). Each chunk is claimed atomically via
     * runJobEntry(). Stops if another runner holds the lock:
     * XF's runUnique() doesn't check trigger_date, so it could run a job that
     * another runner already holds.
     */
    protected function runFinalizeJob(string $uniqueId, OutputInterface $output): bool
    {
        $app = \XF::app();
        $jobManager = $app->jobManager();
        $maxRunTime = max(2, (int) \XF::config('jobMaxRunTime'));

        while ($job = $jobManager->getUniqueJob($uniqueId))
        {
            if ($job['trigger_date'] > time())
            {
                $output->writeln(sprintf('  <error>Job %s was taken by another runner (lock held until %s); stopping.</error>',
                    $uniqueId, date('Y-m-d H:i:s', $job['trigger_date'])));
                return false;
            }

            $result = $jobManager->runJobEntry($job, $maxRunTime);
            if ($result && $result->statusMessage)
            {
                $output->writeln('  ' . $result->statusMessage);
            }

            // keep the memory limit down on long running jobs
            $app->em()->clearEntityCache();
            \XF::updateTime();
        }

        return true;
    }

    /**
     * Evidence attachments still held locally (copies are deleted only after
     * upload + fileinfo succeed), for one subject or the whole case.
     */
    protected function countOutstandingEvidence(\XF\Db\AbstractAdapter $db, int $caseId, ?int $userId): int
    {
        $sql = "
            SELECT COUNT(DISTINCT iad.data_id)
            FROM xf_usips_ncmec_incident_attachment_data AS iad
            INNER JOIN xf_usips_ncmec_incident AS i ON (iad.incident_id = i.incident_id)
            INNER JOIN xf_attachment_data AS ad ON (ad.data_id = iad.data_id)
            WHERE i.case_id = ?
        ";
        $params = [$caseId];
        if ($userId !== null)
        {
            $sql .= " AND iad.user_id = ?";
            $params[] = $userId;
        }
        return (int) $db->fetchOne($sql, $params);
    }
}
