<?php

namespace USIPS\NCMEC\XF\Admin\Controller;

use XF\Entity\OptionGroup;
use XF\Filterer\Attachment;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Repository\AttachmentRepository;

class AttachmentController extends XFCP_AttachmentController
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
        $perPage = 20;

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
        return $this->view('XF:Attachment\Listing', 'attachment_list', $viewParams);
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

}