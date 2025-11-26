<?php

namespace USIPS\NCMEC\XF\Finder;

use XF\Mvc\Entity\Manager;
use XF\Mvc\Entity\Structure;

class Thread extends XFCP_Thread
{
    public function __construct(Manager $em, Structure $structure)
    {
        parent::__construct($em, $structure);
        \USIPS\NCMEC\Listener::finderSetup($this);
    }
}
