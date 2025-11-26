<?php

namespace USIPS\NCMEC\XF\Repository;

use XF\Entity\ConversationMaster;
use XF\Entity\User;

class ConversationMessage extends XFCP_ConversationMessage
{
    public function findMessagesForConversationView(ConversationMaster $conversation)
    {
        $finder = parent::findMessagesForConversationView($conversation);
        \USIPS\NCMEC\Listener::finderSetup($finder);
        return $finder;
    }
}
