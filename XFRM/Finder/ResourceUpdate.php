<?php

namespace USIPS\NCMEC\XFRM\Finder;

use XF\Mvc\Entity\Manager;
use XF\Mvc\Entity\Structure;

class ResourceUpdate extends XFCP_ResourceUpdate
{
    public function __construct(Manager $em, Structure $structure)
    {
        parent::__construct($em, $structure);
        \USIPS\NCMEC\Listener::finderSetup($this);
    }
}
