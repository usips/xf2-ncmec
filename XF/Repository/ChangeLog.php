<?php

namespace USIPS\NCMEC\XF\Repository;

class ChangeLog extends XFCP_ChangeLog
{
    public function pruneChangeLogs($cutOff = null)
    {
        if ($cutOff === null)
        {
            $cutOff = \XF::$time - ($this->options()->changeLogLength * 86400);
        }

        // 18 U.S. Code § 2703
        $preservedIds = \XF::repository('USIPS\NCMEC:Preservation')->getPreservedUserIds();

        if (empty($preservedIds))
        {
            return parent::pruneChangeLogs($cutOff);
        }

        $db = $this->db();
        $idsQuoted = $db->quote($preservedIds);

        // Reimplement delete with exclusion
        $db->query("
            DELETE FROM xf_change_log
            WHERE edit_date < ?
            AND edit_user_id NOT IN ($idsQuoted)
        ", $cutOff);

        return $db->affectedRows();
    }
}
