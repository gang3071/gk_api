<?php

use Phinx\Migration\AbstractMigration;

/**
 * 修复玩家代理ID绑定
 * 将所有玩家的agent_admin_id更新为其所属店家的parent_admin_id
 */
class FixPlayerAgentAdminId extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $this->execute("
            UPDATE player p
            INNER JOIN admin_users au ON p.store_admin_id = au.id
            SET p.agent_admin_id = au.parent_admin_id
            WHERE p.store_admin_id > 0
              AND au.type = 4
              AND au.parent_admin_id > 0
              AND (p.agent_admin_id = 0 OR p.agent_admin_id IS NULL OR p.agent_admin_id != au.parent_admin_id)
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 回滚操作：将agent_admin_id设置为0
        $this->execute("
            UPDATE player p
            INNER JOIN admin_users au ON p.store_admin_id = au.id
            SET p.agent_admin_id = 0
            WHERE p.store_admin_id > 0
              AND au.type = 4
              AND au.parent_admin_id > 0
        ");
    }
}
