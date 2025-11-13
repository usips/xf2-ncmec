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

        $viewParams = [
            'content' => $content,
            'contentText' => $contentText,
            'contentType' => $contentType,
        ];

        return $this->view('USIPS\NCMEC:IncidentContent\Preview', 'usips_ncmec_content_preview', $viewParams);
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
}
