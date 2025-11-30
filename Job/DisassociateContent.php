<?php

namespace USIPS\NCMEC\Job;

use XF\Job\AbstractJob;

class DisassociateContent extends AbstractJob
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

        try
        {
            // Normalize content items to array of [type, id] pairs
            $normalizedItems = $this->normalizeContentItems($contentItems);

            if ($normalizedItems)
            {
                $creator->disassociateContent($normalizedItems);
            }
        }
        catch (\Throwable $e)
        {
            // Log the error but don't fail the job
            \XF::logError('NCMEC DisassociateContent job failed: ' . $e->getMessage());
        }

        return $this->complete();
    }

    protected function normalizeContentItems($contentItems)
    {
        $normalized = [];

        // Check if the array is associative (not a sequential array starting from 0)
        $isAssociative = array_keys($contentItems) !== range(0, count($contentItems) - 1);

        if ($isAssociative) {
            // Handle associative array format: [content_type => [content_ids,]]
            foreach ($contentItems as $type => $ids) {
                if (is_array($ids)) {
                    foreach ($ids as $id) {
                        $normalized[] = [$type, $id];
                    }
                }
            }
        }
        else {
            // Handle array of pairs or objects (sequential array)
            foreach ($contentItems as $item) {
                if (is_array($item)) {
                    // Handle explicit array format: ['content_type' => content_type, 'content_id' => content_id]
                    if (isset($item['content_type'], $item['content_id'])) {
                        $normalized[] = [$item['content_type'], $item['content_id']];
                    }
                    // Handle implicit array format: [content_type, content_id]
                    elseif (count($item) == 2) {
                        $normalized[] = $item;
                    }
                }
            }
        }

        return $normalized;
    }

    public function getStatusMessage()
    {
        return \XF::phrase('usips_ncmec_disassociating_content_from_incident');
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