<?php

namespace USIPS\NCMEC\XFMG\Finder;

use XF\Mvc\Entity\Manager;
use XF\Mvc\Entity\Structure;

class MediaItem extends XFCP_MediaItem
{
    public function __construct(Manager $em, Structure $structure)
    {
        parent::__construct($em, $structure);
        \USIPS\NCMEC\Listener::finderSetup($this);
    }
}
