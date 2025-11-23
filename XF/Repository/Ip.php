<?php

namespace USIPS\NCMEC\XF\Repository;

class Ip extends XFCP_Ip
{
    public function pruneIps($cutOff = null)
    {
        if ($cutOff === null)
        {
            $cutOff = \XF::$time - ($this->options()->ipLogLength * 86400);
        }

        $preservedIds = \XF::repository('USIPS\NCMEC:Preservation')->getPreservedUserIds();

        if (empty($preservedIds))
        {
            return parent::pruneIps($cutOff);
        }

        $db = $this->db();
        
        // 18 U.S. Code § 2703
        // Standard pruning but exclude preserved users
        // We can't easily call parent::pruneIps with a filter, so we reimplement the delete
        // XF2 pruneIps logic is basically: DELETE FROM xf_ip WHERE log_date < ?
        
        $idsQuoted = $db->quote($preservedIds);
        
        $db->query("
            DELETE FROM xf_ip
            WHERE log_date < ?
            AND user_id NOT IN ($idsQuoted)
        ", $cutOff);

        return $db->affectedRows();
    }
}
