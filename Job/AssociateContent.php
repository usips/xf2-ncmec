<?php

namespace USIPS\NCMEC\Job;

use XF\Job\AbstractJob;

class AssociateContent extends AbstractJob
{
    protected $defaultData = [
        'incident_id' => 0,
        'content_items' => [], // Flexible: array of [content_type, content_id], or ['content_type' => [content_ids]], or iterable content objects
        'time_limit_seconds' => 0,
    ];

    public function run($maxRunTime)
    {
        $incidentId = $this->data['incident_id'];
        $contentItems = $this->data['content_items'];

        if (!$incidentId || empty($contentItems))
        {
            return $this->complete();
        }

        $app = \XF::app();
        $creator = $app->service('USIPS\NCMEC:Incident\Creator');
        $incident = $app->find('USIPS\NCMEC:Incident', $incidentId);
        if (!$incident)
        {
            return $this->complete();
        }

        $creator->setIncident($incident);

        try {
            // Normalize content items to array of [type, id] pairs
            $normalizedItems = $this->normalizeContentItems($contentItems);

            // Associate content
            $creator->associateContentByIds($normalizedItems);

            // Associate users from attachment data of the content
            // This is done to truthfully track who uploaded attachments to content,
            // even if the attachment uploader is different from the content author
            $attachmentUserIds = $this->getAttachmentUserIdsFromContent($normalizedItems);
            if (!empty($attachmentUserIds))
            {
                $creator->associateUsersByIds($attachmentUserIds);
            }

            // Also associate attachment data from the content
            $attachmentDataIds = $this->getAttachmentDataIdsFromContent($normalizedItems);
            if (!empty($attachmentDataIds))
            {
                $creator->associateAttachmentsByDataIds($attachmentDataIds);
            }
        } catch (\Exception $e) {
            // Log the error but don't fail the job
            \XF::logError('NCMEC AssociateContent job failed: ' . $e->getMessage());
        }

        return $this->complete();
    }

    protected function normalizeContentItems($contentItems)
    {
        $normalized = [];

        // Check if the array is associative (not a sequential array starting from 0)
        $isAssociative = array_keys($contentItems) !== range(0, count($contentItems) - 1);

        if ($isAssociative) {
            // Handle associative array format: ['content_type' => [content_ids]]
            foreach ($contentItems as $type => $ids) {
                if (is_array($ids)) {
                    foreach ($ids as $id) {
                        $normalized[] = [$type, $id];
                    }
                }
            }
        } else {
            // Handle array of pairs or objects (sequential array)
            foreach ($contentItems as $item) {
                if (is_array($item)) {
                    if (isset($item['content_type'], $item['content_id'])) {
                        $normalized[] = [$item['content_type'], $item['content_id']];
                    } elseif (count($item) == 2) {
                        $normalized[] = $item;
                    }
                }
            }
        }

        return $normalized;
    }

    protected function getAttachmentUserIdsFromContent($normalizedItems)
    {
        $userIds = [];
        $app = \XF::app();

        foreach ($normalizedItems as [$type, $id]) {
            // Find attachments for this content
            $attachments = $app->finder('XF:Attachment')
                ->where('content_type', $type)
                ->where('content_id', $id)
                ->fetch();

            foreach ($attachments as $attachment) {
                $data = $attachment->Data;
                if ($data && $data->user_id) {
                    $userIds[] = $data->user_id;
                }
            }
        }

        return array_unique($userIds);
    }

    protected function getAttachmentDataIdsFromContent($normalizedItems)
    {
        $dataIds = [];
        $app = \XF::app();

        foreach ($normalizedItems as [$type, $id]) {
            // Find attachments for this content
            $attachments = $app->finder('XF:Attachment')
                ->where('content_type', $type)
                ->where('content_id', $id)
                ->fetch();

            foreach ($attachments as $attachment) {
                $data = $attachment->Data;
                if ($data) {
                    $dataIds[] = $data->data_id;
                }
            }
        }

        return array_unique($dataIds);
    }

    public function getStatusMessage()
    {
        return \XF::phrase('usips_ncmec_associating_content_with_incident');
    }

    public function canCancel()
    {
        return true;
    }

    public function canTriggerByChoice()
    {
        return false;
    }
}