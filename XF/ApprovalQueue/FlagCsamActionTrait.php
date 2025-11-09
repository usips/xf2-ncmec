<?php

namespace USIPS\NCMEC\XF\ApprovalQueue;

use XF\Mvc\Entity\Entity;

/**
 * Shared helper for approval queue handlers that expose the Flag as CSAM action.
 */
trait FlagCsamActionTrait
{
    protected function flagContentForNcmec(Entity $content): void
    {
        try
        {
            /** @var \USIPS\NCMEC\Service\Incident\ModeratorFlagger $flagger */
            $flagger = \XF::service('USIPS\\NCMEC:Incident\\ModeratorFlagger', $content);
            $flagger->flag();
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Failed to flag CSAM content: ');
        }
    }
}
