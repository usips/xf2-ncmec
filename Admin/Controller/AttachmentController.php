<?php

namespace USIPS\NCMEC\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Entity\Attachment;
use XF\Entity\OptionGroup;
use XF\Filterer\Attachment as AttachmentFilterer;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Repository\AttachmentRepository;

class AttachmentController extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('attachment');
    }

    public function actionIndex(ParameterBag $params)
    {
        if ($params->attachment_id)
        {
            return $this->rerouteController(self::class, 'view', $params);
        }

        if ($this->request->exists('delete_attachments'))
        {
            return $this->rerouteController(self::class, 'delete');
        }

        $attachmentRepo = $this->getAttachmentRepo();

        $page = $this->filterPage();
        $perPage = 100;

        $filterer = $this->setupAttachmentFilterer();
        $finder = $filterer->apply()->limitByPage($page, $perPage);

        $linkParams = $filterer->getLinkParams();

        if ($this->isPost())
        {
            return $this->redirect($this->buildLink('attachments', null, $linkParams), '');
        }

        // Only get exact total if filters are applied, otherwise use cached/estimated count
        // to avoid slow COUNT(*) queries on millions of attachments
        if (!empty($linkParams))
        {
            // Filters applied, get exact count
            $total = $finder->total();
        }
        else
        {
            // No filters, use fast approximate count from table stats
            $total = $this->getApproximateAttachmentCount();
        }
        
        $this->assertValidPage($page, $perPage, $total, 'attachments');

        $viewParams = [
            'attachments' => $finder->fetch(),
            'handlers' => $attachmentRepo->getAttachmentHandlers(),

            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,

            'linkParams' => $linkParams,
            'filterDisplay' => $filterer->getDisplayValues(),
        ];
        return $this->view('USIPS\NCMEC:Attachment\Listing', 'usips_ncmec_attachment_list', $viewParams);
    }

    public function actionLookup(ParameterBag $params)
    {
        if ($this->isPost())
        {
            $input = $this->filter('input', 'str');
            $existingAttachmentIds = $this->filter('attachment_ids', 'str');

            $attachmentIds = [];
            $dataIds = [];
            $failedLines = [];

            $lines = explode("\n", $input);
            foreach ($lines as $line)
            {
                $line = trim($line);
                if (!$line)
                {
                    continue;
                }

                $found = false;

                // Parse attachment IDs from /attachments/ patterns
                if (preg_match('#/attachments/[^/]*\.(\d+)/#', $line, $matches))
                {
                    $attachmentIds[] = $matches[1];
                    $found = true;
                }

                // Parse data IDs from various patterns
                if (preg_match('#/data/(?:attachments|video|audio)/\d+/(\d+)-#', $line, $matches))
                {
                    $dataIds[] = $matches[1];
                    $found = true;
                }

                // Direct filenames: (\d+)-[^.]+\.
                if (preg_match('#(\d+)-[^.]+\.#', $line, $matches))
                {
                    $dataIds[] = $matches[1];
                    $found = true;
                }

                // internal_data: internal_data/attachments/\d+/(\d+)-
                if (preg_match('#internal_data/attachments/\d+/(\d+)-#', $line, $matches))
                {
                    $dataIds[] = $matches[1];
                    $found = true;
                }

                if (!$found)
                {
                    $failedLines[] = $line;
                }
            }

            // Add existing successful attachment IDs
            if ($existingAttachmentIds)
            {
                $existingIds = array_map('intval', array_filter(explode(',', $existingAttachmentIds)));
                $attachmentIds = array_merge($attachmentIds, $existingIds);
            }

            // Remove duplicates
            $attachmentIds = array_unique(array_map('intval', $attachmentIds));
            $dataIds = array_unique(array_map('intval', $dataIds));

            // Load attachments
            $attachments = [];
            if ($attachmentIds)
            {
                $attachments = array_merge($attachments, $this->finder('XF:Attachment')
                    ->where('attachment_id', $attachmentIds)
                    ->with('Data')
                    ->fetch()
                    ->toArray());
            }
            if ($dataIds)
            {
                $attachments = array_merge($attachments, $this->finder('XF:Attachment')
                    ->where('data_id', $dataIds)
                    ->with('Data')
                    ->fetch()
                    ->toArray());
            }

            $viewParams = [
                'attachments' => $attachments,
                'attachmentIds' => array_column($attachments, 'attachment_id'),
                'failedLinesText' => implode("\n", $failedLines),
                'failedLinesCount' => max(4, count($failedLines)),
            ];

            return $this->view('USIPS\NCMEC:Attachment\LookupResults', 'usips_ncmec_attachment_lookup_results', $viewParams);
        }

        $viewParams = [
            'title' => '{{ phrase(\'usips_ncmec_lookup_title\') }}',
            'description' => '{{ phrase(\'usips_ncmec_lookup_description\') }}',
            'submitText' => '{{ phrase(\'usips_ncmec_lookup_submit\') }}',
        ];

        return $this->view('USIPS\NCMEC:Attachment\Lookup', 'usips_ncmec_attachment_lookup', $viewParams);
    }

    public function actionView(ParameterBag $params)
    {
        /**
         * Reminder: Attachment entities are the objects exposed to the UI. Their AttachmentData
         * payloads contain the binary content but cannot be viewed directly without an Attachment
         * wrapper. Always resolve the Attachment first when serving files.
         */
        /** @var \XF\Entity\Attachment $attachment */
        $attachment = $this->em()->find(Attachment::class, $params->attachment_id);
        if (!$attachment)
        {
            throw $this->exception($this->notFound());
        }

        if (!$attachment->Data || !$attachment->Data->isDataAvailable())
        {
            return $this->error(\XF::phrase('attachment_cannot_be_shown_at_this_time'));
        }

        $this->setResponseType('raw');

        $eTag = $this->request->getServer('HTTP_IF_NONE_MATCH');
        $return304 = ($eTag && $eTag == '"' . $attachment['attach_date'] . '"');

        $viewParams = [
            'attachment' => $attachment,
            'return304' => $return304,
        ];
        return $this->view('XF:Attachment\View', '', $viewParams);
    }

    /**
     * Get approximate attachment count using MySQL table statistics.
     * This is much faster than COUNT(*) on large tables.
     *
     * @return int
     */
    protected function getApproximateAttachmentCount()
    {
        // Try to get from cache first (cache for 1 hour)
        $cache = \XF::app()->cache();
        $cacheKey = 'attachmentApproxCount';
        
        if ($cache)
        {
            $cached = $cache->fetch($cacheKey);
            if ($cached !== false)
            {
                return (int)$cached;
            }
        }
        
        // Get approximate count from MySQL table statistics
        // This is nearly instant even on tables with millions of rows
        $db = $this->app->db();
        $count = $db->fetchOne("
            SELECT TABLE_ROWS 
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'xf_attachment'
        ");
        
        $count = $count ?: 0;
        
        // Cache the result for 1 hour
        if ($cache)
        {
            $cache->save($cacheKey, $count, 3600);
        }
        
        return (int)$count;
    }

    /**
     * @return AttachmentRepository
     */
    protected function getAttachmentRepo()
    {
        return $this->repository(AttachmentRepository::class);
    }

    /**
     * @return Attachment
     */
    protected function setupAttachmentFilterer(): AttachmentFilterer
    {
        /** @var Attachment $filterer */
        $filterer = $this->app->filterer(AttachmentFilterer::class);
        $filterer->addFilters($this->request, $this->filter('_skipFilter', 'str'));

        return $filterer;
    }
}