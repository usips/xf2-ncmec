<?php

namespace USIPS\NCMEC\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class IncidentContentController extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('usips_ncmec');
    }

    public function actionPreview(ParameterBag $params)
    {
        $contentType = $params->content_type;
        $contentId = $params->content_id;

        // If content_type is 'thread', convert to first post
        if ($contentType === 'thread')
        {
            $thread = $this->em()->find('XF:Thread', $contentId);
            if ($thread && $thread->first_post_id)
            {
                $contentType = 'post';
                $contentId = $thread->first_post_id;
            }
        }

        $content = $this->assertContentExists($contentType, $contentId);

        // Get the content text/message based on content type
        $contentText = $this->getContentText($content, $contentType);

        if (!$contentText)
        {
            return $this->noPermission();
        }

        // Load attachments if content has them
        $attachments = $this->getContentAttachments($content, $contentType, $contentId);

        $viewParams = [
            'content' => $content,
            'contentText' => $contentText,
            'contentType' => $contentType,
            'attachments' => $attachments,
        ];

        return $this->view('USIPS\NCMEC:IncidentContent\Preview', 'usips_ncmec_content_preview', $viewParams);
    }

    public function actionDelete(ParameterBag $params)
    {
        $incidentContent = $this->assertIncidentContentRecord($params);
        $incident = $incidentContent->Incident;

        if ($incident && $incident->is_finalized)
        {
            return $this->error(
                \XF::phrase('usips_ncmec_incident_finalized_cannot_delete')
            );
        }

        if ($this->isPost())
        {
            $jobManager = $this->app()->jobManager();

            $jobManager->enqueue('USIPS\NCMEC:DisassociateContent', [
                'incident_id' => $incidentContent->incident_id,
                'content_items' => [[
                    'content_type' => $incidentContent->content_type,
                    'content_id' => $incidentContent->content_id,
                ]],
                'time_limit_seconds' => 0,
            ]);

            return $this->redirect($this->buildLink('ncmec-incidents/view', $incident));
        }

        $content = $incidentContent->getContent();
        if ($content && method_exists($content, 'getContentTitle'))
        {
            $contentTitle = $content->getContentTitle();
        }
        else
        {
            $contentTitle = \XF::phrase('content_x_y', [
                'type' => $incidentContent->content_type,
                'id' => $incidentContent->content_id,
            ]);
        }

        $viewParams = [
            'incident' => $incident,
            'incidentContent' => $incidentContent,
            'contentTitle' => $contentTitle,
        ];

        return $this->view('USIPS\NCMEC:Incident\RemoveContent', 'usips_ncmec_incident_remove_content', $viewParams);
    }

    /**
     * Assert that content exists
     *
     * @param string $contentType
     * @param int $contentId
     * @return \XF\Mvc\Entity\Entity
     * @throws \XF\Mvc\Reply\Exception
     */
    protected function assertContentExists($contentType, $contentId)
    {
        if (!$contentType || !$contentId)
        {
            throw $this->exception($this->notFound("Bad parameters"));
        }

        $content = $this->app()->findByContentType($contentType, $contentId);
        if (!$content)
        {
            throw $this->exception($this->notFound());
        }

        return $content;
    }

    /**
     * Get the text/message content from various content types
     *
     * @param \XF\Mvc\Entity\Entity $content
     * @param string $contentType
     * @return string
     */
    protected function getContentText($content, $contentType)
    {
        // Try common message field names
        $messageFields = ['message', 'post_body', 'message_text', 'body', 'text', 'content'];
        
        foreach ($messageFields as $field)
        {
            if ($content->isValidColumn($field))
            {
                return $content->get($field);
            }
        }

        // Fallback to empty string
        return '';
    }

    /**
     * Get attachments for the content if they exist
     *
     * @param \XF\Mvc\Entity\Entity $content
     * @param string $contentType
     * @param int $contentId
     * @return array
     */
    protected function getContentAttachments($content, $contentType, $contentId)
    {
        // Check if content has attachments via relation
        if (method_exists($content, 'hasRelation') && $content->hasRelation('Attachments'))
        {
            $attachments = $content->Attachments;
            if ($attachments && $attachments->count())
            {
                return $attachments->toArray();
            }
        }

        // Check if content has attach_count field
        if ($content->isValidColumn('attach_count') && $content->attach_count > 0)
        {
            /** @var \XF\Repository\AttachmentRepository $attachmentRepo */
            $attachmentRepo = $this->repository('XF:Attachment');
            $attachments = $attachmentRepo->findAttachmentsByContentId($contentType, $contentId)->fetch();
            
            if ($attachments && $attachments->count())
            {
                return $attachments->toArray();
            }
        }

        return [];
    }

    protected function assertIncidentContentRecord(ParameterBag $params)
    {
        $incidentId = $params->incident_id;
        $contentType = $params->content_type;
        $contentId = $params->content_id;

        if (!$incidentId || !$contentType || !$contentId)
        {
            throw $this->exception($this->notFound());
        }

        $incidentContent = $this->finder('USIPS\\NCMEC:IncidentContent')
            ->where('incident_id', $incidentId)
            ->where('content_type', $contentType)
            ->where('content_id', $contentId)
            ->with('Incident')
            ->fetchOne();

        if (!$incidentContent)
        {
            throw $this->exception($this->notFound());
        }

        return $incidentContent;
    }
}
