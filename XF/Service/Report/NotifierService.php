<?php

namespace USIPS\NCMEC\XF\Service\Report;

use Throwable;
use XF\Entity\User;
use XF\Repository\ReportRepository;
use XF\Repository\UserAlertRepository;

class NotifierService extends XFCP_NotifierService
{
    /** @var bool */
    protected $usipsEmergency = false;

    public function enableEmergencyMode(): void
    {
        $this->usipsEmergency = true;
    }

    public function notifyCreate()
    {
        parent::notifyCreate();

        if ($this->usipsEmergency)
        {
            $this->sendEmergencyNotifications();
        }
    }

    protected function sendEmergencyNotifications(): void
    {
        $recipients = $this->gatherEmergencyRecipients();
        if (!$recipients)
        {
            return;
        }

        foreach ($recipients as $user)
        {
            $this->sendEmergencyEmail($user);
            $this->sendEmergencyAlert($user);
        }
    }

    /**
     * @return array<int, User>
     */
    protected function gatherEmergencyRecipients(): array
    {
        $recipients = [];

        /** @var ReportRepository $reportRepo */
        $reportRepo = $this->repository(ReportRepository::class);
        $moderators = $reportRepo->getModeratorsWhoCanHandleReport($this->report, false);

        foreach ($moderators as $moderator)
        {
            $user = $moderator->User;
            if (!$user)
            {
                continue;
            }

            if (!\XF::asVisitor($user, function () { return $this->report->canView(); }))
            {
                continue;
            }

            $recipients[$user->user_id] = $user;
        }

        $adminFinder = $this->app->finder('XF:User');
        $adminFinder->where('is_admin', 1)
            ->where('user_state', 'valid')
            ->with('PermissionCombination');

        foreach ($adminFinder->fetch() as $admin)
        {
            if (!\XF::asVisitor($admin, function () { return $this->report->canView(); }))
            {
                continue;
            }

            $recipients[$admin->user_id] = $admin;
        }

        return $recipients;
    }

    protected function sendEmergencyEmail(User $user): void
    {
        if (!empty($this->usersEmailed[$user->user_id]))
        {
            return;
        }

        if ($user->user_id === $this->comment->user_id)
        {
            return;
        }

        if (!$user->email)
        {
            return;
        }

        $report = $this->report;
        $comment = $this->comment;
        $reporter = $comment->User ?: $this->em()->find('XF:User', $comment->user_id);

        try
        {
            $mail = $this->app->mailer()
                ->newMail()
                ->setToUser($user)
                ->setTemplate('report_create', [
                    'receiver' => $user,
                    'reporter' => $reporter,
                    'comment' => $comment,
                    'report' => $report,
                    'message' => $report->getContentMessage(),
                ]);

            $mail->queue();
            $this->usersEmailed[$user->user_id] = true;
        }
        catch (Throwable $e)
        {
            \XF::logException($e, false, 'Emergency report email failed: ');
        }
    }

    protected function sendEmergencyAlert(User $user): void
    {
        if (!empty($this->usersAlerted[$user->user_id]))
        {
            return;
        }

        if ($user->user_id === $this->comment->user_id)
        {
            return;
        }

        /** @var UserAlertRepository $alertRepo */
        $alertRepo = $this->repository(UserAlertRepository::class);
        $alerted = $alertRepo->alert(
            $user,
            $this->comment->user_id,
            $this->comment->username,
            'report',
            $this->report->report_id,
            'emergency',
            ['comment' => $this->comment->toArray()],
            ['autoRead' => false]
        );

        if ($alerted)
        {
            $this->usersAlerted[$user->user_id] = true;
        }
    }
}
