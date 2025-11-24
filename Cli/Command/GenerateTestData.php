<?php

namespace USIPS\NCMEC\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\AllowInactiveAddOnCommandInterface;
use XF\Util\Color;
use XF\Util\File;

class GenerateTestData extends Command implements AllowInactiveAddOnCommandInterface
{
    protected function configure()
    {
        $this
            ->setName('usips-ncmec:generate-test-data')
            ->setDescription('Generates test data for NCMEC testing.')
            ->addArgument(
                'user-ids',
                InputArgument::REQUIRED,
                'Comma separated list of User IDs to use'
            )
            ->addArgument(
                'count',
                InputArgument::REQUIRED,
                'Number of posts to generate'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $app = \XF::app();
        
        if (!\XF::$debugMode)
        {
            $output->writeln('<error>This command can only be run in debug mode.</error>');
            return 1;
        }

        $userIds = explode(',', $input->getArgument('user-ids'));
        $userIds = array_map('intval', $userIds);
        $userIds = array_filter($userIds); // Remove 0s
        
        if (empty($userIds))
        {
             $output->writeln('<error>No valid user IDs provided.</error>');
             return 1;
        }

        $count = (int)$input->getArgument('count');
        if ($count < 1)
        {
            $output->writeln('<error>Count must be positive.</error>');
            return 1;
        }

        $output->writeln("Generating $count posts for users: " . implode(', ', $userIds));

        for ($i = 0; $i < $count; $i++)
        {
            $userId = $userIds[array_rand($userIds)];
            /** @var \XF\Entity\User $user */
            $user = $app->em()->find('XF:User', $userId);
            if (!$user)
            {
                $output->writeln("<comment>User ID $userId not found, skipping.</comment>");
                continue;
            }

            \XF::asVisitor($user, function() use ($app, $user, $output) {
                
                // Find a random forum visible to the user
                $forum = $this->getRandomForum($user);
                
                if (!$forum)
                {
                    $output->writeln("<comment>No visible forum found for user {$user->username}, skipping.</comment>");
                    return;
                }

                // Decide whether to create a thread or reply
                $threads = $app->finder('XF:Thread')
                    ->where('node_id', $forum->node_id)
                    ->where('discussion_state', 'visible')
                    ->where('discussion_open', 1)
                    ->fetch();

                $createThread = false;
                if ($threads->count() == 0)
                {
                    $createThread = true;
                }
                else
                {
                    // 15% chance to create new thread if threads exist
                    if (rand(1, 100) <= 15)
                    {
                        $createThread = true;
                    }
                }

                // If we want to create a thread but can't, try to reply. 
                // If we can't reply either, skip.
                if ($createThread && !$forum->canCreateThread())
                {
                    $createThread = false;
                }

                if (!$createThread && $threads->count() > 0)
                {
                    // Check if we can reply to at least one thread? 
                    // Generally if we can view the forum and it's open, we can reply.
                    // But let's just proceed and catch errors if service fails.
                }
                elseif (!$createThread && $threads->count() == 0)
                {
                    // Can't create thread (perms) and no threads to reply to.
                    return;
                }

                $tempHash = \XF::generateRandomString(32);
                $attachments = [];
                
                // Attachment Logic: 33% chance to add attachments
                if (rand(1, 100) <= 33)
                {
                    $numAttachments = rand(1, 2);
                    for ($j = 0; $j < $numAttachments; $j++)
                    {
                        $attachment = $this->generateAttachment($user, $tempHash);
                        if ($attachment)
                        {
                            $attachments[] = $attachment;
                        }
                    }
                }

                $message = $this->generateGibberish(rand(20, 100));
                
                // Embed attachments
                foreach ($attachments as $att)
                {
                    $outcome = rand(1, 3);
                    if ($outcome == 1)
                    {
                        // Thumbnail
                        $message .= "\n\n[ATTACH]{$att->attachment_id}[/ATTACH]";
                    }
                    elseif ($outcome == 2)
                    {
                        // Full size
                        $message .= "\n\n[ATTACH=full]{$att->attachment_id}[/ATTACH]";
                    }
                    // 3 is dangling (not included in text)
                }

                try
                {
                    if ($createThread)
                    {
                        /** @var \XF\Service\Thread\Creator $creator */
                        $creator = $app->service('XF:Thread\Creator', $forum);
                        $creator->setContent($this->generateGibberish(rand(3, 10)), $message);
                        $creator->setAttachmentHash($tempHash);
                        $creator->setIsAutomated();
                        $creator->save();
                        $output->writeln("Created thread by {$user->username} in {$forum->title}");
                    }
                    else
                    {
                        $threadsArray = $threads->toArray();
                        $thread = $threadsArray[array_rand($threadsArray)];
                        /** @var \XF\Service\Thread\Replier $replier */
                        $replier = $app->service('XF:Thread\Replier', $thread);
                        $replier->setMessage($message);
                        $replier->setAttachmentHash($tempHash);
                        $replier->setIsAutomated();
                        $replier->save();
                        $output->writeln("Created post by {$user->username} in thread {$thread->title}");
                    }
                }
                catch (\Exception $e)
                {
                    $output->writeln("<error>Error creating content: " . $e->getMessage() . "</error>");
                }
            });
        }

        return 0;
    }

    protected function getRandomForum(\XF\Entity\User $user)
    {
        $nodeRepo = \XF::repository('XF:Node');
        $nodeList = $nodeRepo->getNodeList();
        
        $forums = [];
        foreach ($nodeList as $node)
        {
            if ($node->node_type_id !== 'Forum') continue;
            
            /** @var \XF\Entity\Forum $forum */
            $forum = $node->Data;
            if (!$forum) continue;

            if ($forum->canView())
            {
                $forums[] = $forum;
            }
        }

        if (empty($forums)) return null;

        return $forums[array_rand($forums)];
    }

    protected function generateGibberish($length = 20)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ   ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return trim($randomString);
    }

    protected function generateAttachment(\XF\Entity\User $user, $tempHash)
    {
        $width = 500;
        $height = 500;
        
        $im = imagecreatetruecolor($width, $height);

        // Calculate base color from username (logic from XF\Template\Templater::getDefaultAvatarStyling)
        $bytes = md5($user->username, true);
        $r = dechex(round(5 * ord($bytes[0]) / 255) * 0x33);
        $g = dechex(round(5 * ord($bytes[1]) / 255) * 0x33);
        $b = dechex(round(5 * ord($bytes[2]) / 255) * 0x33);
        $hexBgColor = sprintf('%02s%02s%02s', $r, $g, $b);

        $hslBgColor = Color::hexToHsl($hexBgColor);

        $bgChanged = false;
        if ($hslBgColor[1] > 60)
        {
            $hslBgColor[1] = 60;
            $bgChanged = true;
        }
        else if ($hslBgColor[1] < 15)
        {
            $hslBgColor[1] = 15;
            $bgChanged = true;
        }

        if ($hslBgColor[2] > 85)
        {
            $hslBgColor[2] = 85;
            $bgChanged = true;
        }
        else if ($hslBgColor[2] < 15)
        {
            $hslBgColor[2] = 15;
            $bgChanged = true;
        }

        if ($bgChanged)
        {
            $hexBgColor = Color::hslToHex($hslBgColor);
        }

        [$baseR, $baseG, $baseB] = Color::hexToRgb($hexBgColor);
        
        // Noise background based on user color
        for ($x = 0; $x < $width; $x++)
        {
            for ($y = 0; $y < $height; $y++)
            {
                $r = max(0, min(255, $baseR + rand(-30, 30)));
                $g = max(0, min(255, $baseG + rand(-30, 30)));
                $b = max(0, min(255, $baseB + rand(-30, 30)));
                
                $color = imagecolorallocate($im, $r, $g, $b);
                imagesetpixel($im, $x, $y, $color);
            }
        }
        
        // High contrast box for text
        $black = imagecolorallocate($im, 0, 0, 0);
        $white = imagecolorallocate($im, 255, 255, 255);
        
        imagefilledrectangle($im, 50, 200, 450, 300, $black);
        
        $text = "User: {$user->username}\nID: {$user->user_id}";
        imagestring($im, 5, 60, 220, $text, $white);
        
        $tempFile = File::getTempFile();
        imagepng($im, $tempFile);
        imagedestroy($im);
        
        $fileName = 'test_gen_' . \XF::$time . '_' . rand(1000, 9999) . '.png';
        
        /** @var \XF\Service\Attachment\Preparer $preparer */
        $preparer = \XF::service('XF:Attachment\Preparer');
        
        $file = new \XF\FileWrapper($tempFile, $fileName);
        
        // Handler for posts is 'post'
        $handler = \XF::repository('XF:Attachment')->getAttachmentHandler('post');
        
        try
        {
            $attachment = $preparer->insertAttachment($handler, $file, $user, $tempHash);
            return $attachment;
        }
        catch (\Exception $e)
        {
            return null;
        }
    }
}
