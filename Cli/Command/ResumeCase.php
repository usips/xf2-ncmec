<?php

namespace USIPS\NCMEC\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\JobRunnerTrait;

/**
 * Resume an NCMEC case whose submission was interrupted (e.g. the browser was
 * closed while the finalize job runner was mid-send).
 *
 * The FinalizeCase job is already a resumable, idempotent state machine:
 *   - ensureReportOpened() skips subjects whose report already has an
 *     ncmec_report_id (already opened with NCMEC),
 *   - processAttachment() skips report files that already have an ncmec_file_id
 *     (already uploaded),
 *   - finishReport() skips reports that already have submitted_on set.
 * The only fragile part was that it ran inside the admin browser via
 * tools/run-job. This command drives the same job to completion server-side,
 * where a closed browser can't interrupt it. If the original interrupted job is
 * still queued (same unique key) it is picked up and resumed from its saved
 * state; otherwise a fresh job resumes idempotently, only filing the subjects
 * that still lack a finished report.
 */
class ResumeCase extends Command
{
    use JobRunnerTrait;

    protected function configure()
    {
        $this
            ->setName('usips-ncmec:resume-case')
            ->setDescription('Resume an interrupted NCMEC case submission (finalized but not submitted).')
            ->addArgument('case-id', InputArgument::OPTIONAL, 'Case ID to resume')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Resume every interrupted case (finalized_on set, submitted_on empty)')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Actually run the resume. Without this flag the command only analyses and reports the gap (dry run).');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $app = \XF::app();

        $caseId = $input->getArgument('case-id');
        $all    = $input->getOption('all');
        $exec   = $input->getOption('execute');

        if (!$caseId && !$all)
        {
            $output->writeln('<error>Provide a case ID, or --all to resume every interrupted case.</error>');
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
        foreach ($cases as $case)
        {
            $anyWork = $this->handleCase($app, $case, $exec, $output) || $anyWork;
        }

        if (!$exec && $anyWork)
        {
            $output->writeln('');
            $output->writeln('<comment>Dry run only. Re-run with --execute to file the missing report(s) and mark the case submitted.</comment>');
        }
        return 0;
    }

    protected function handleCase(\XF\App $app, $case, bool $exec, OutputInterface $output): bool
    {
        $output->writeln('');
        $output->writeln(sprintf('=== Case #%d: %s ===', $case->case_id, $case->title));
        $output->writeln(sprintf('  incident_type=%s  finalized=%s  submitted=%s',
            $case->incident_type,
            $case->finalized_on ? date('Y-m-d H:i:s', $case->finalized_on) : 'no',
            $case->submitted_on ? date('Y-m-d H:i:s', $case->submitted_on) : 'NO'
        ));

        if ($case->submitted_on)
        {
            $output->writeln('  <info>Already submitted; nothing to resume.</info>');
            return false;
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

        $reports = [];
        foreach ($app->finder('USIPS\NCMEC:Report')->where('case_id', $case->case_id)->fetch() as $r)
        {
            $reports[$r->subject_user_id] = $r;
        }

        $missing = [];
        $output->writeln('  Subjects:');
        foreach ($subjects as $s)
        {
            $r = $reports[$s['user_id']] ?? null;
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
            $output->writeln(sprintf('    - %-24s (user %d): %s',
                $s['username'], $s['user_id'], $state));
        }

        if (!$missing)
        {
            $output->writeln('  <info>All subjects have finished reports; only the case-level submitted flag is pending. --execute will stamp it.</info>');
        }
        else
        {
            $output->writeln(sprintf('  <comment>%d subject(s) still need a report filed.</comment>', count($missing)));
        }

        if (!$exec)
        {
            return true;
        }

        // Run the addon's own finalize job to completion. enqueueUnique picks up
        // the original interrupted job (same key) and resumes it if still queued.
        $uniqueId = 'usipsNcmecFinalize' . $case->case_id;
        $output->writeln(sprintf('  <info>Running %s to completion...</info>', $uniqueId));
        $this->setupAndRunJob($uniqueId, 'USIPS\NCMEC:FinalizeCase', ['case_id' => $case->case_id], $output);

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
            $output->writeln(sprintf('  <error>Case #%d still not marked submitted — check admin logs (xf_usips_ncmec_api_log / XF error log).</error>', $case->case_id));
        }
        return true;
    }
}
