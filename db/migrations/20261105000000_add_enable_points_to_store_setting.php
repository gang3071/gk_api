<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为存量店家/代理添加积分开关配置（默认开启）
 */
class AddEnablePointsToStoreSetting extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $adminUserTable = 'admin_users';
        $storeSettingTable = 'store_setting';

        // 获取所有店家（type=4）/代理（type=3）的 admin_user_id 和 department_id
        $stores = $this->fetchAll("
            SELECT id, department_id
            FROM {$adminUserTable}
            WHERE type IN (3, 4)
              AND deleted_at IS NULL
        ");

        if (empty($stores)) {
            echo "没有找到存量店家/代理，跳过迁移\n";
            return;
        }

        $now = date('Y-m-d H:i:s');
        $values = [];
        $count = 0;

        foreach ($stores as $store) {
            $adminUserId = (int)$store['id'];
            $departmentId = (int)$store['department_id'];

            // 检查是否已存在该账号的 enable_points 配置
            $exists = $this->fetchRow("
                SELECT id FROM {$storeSettingTable}
                WHERE feature = 'enable_points'
                  AND department_id = {$departmentId}
                  AND admin_user_id = {$adminUserId}
                LIMIT 1
            ");

            // 如果不存在，则添加到插入列表（num=1、status=1 默认开启）
            if (!$exists) {
                $values[] = "({$departmentId}, {$adminUserId}, 'enable_points', 1, 1, '{$now}', '{$now}')";
                $count++;
            }
        }

        // 批量插入
        if (!empty($values)) {
            $valuesStr = implode(',', $values);
            $this->execute("
                INSERT INTO {$storeSettingTable}
                (department_id, admin_user_id, feature, num, status, created_at, updated_at)
                VALUES {$valuesStr}
            ");
            echo "成功为 {$count} 个店家/代理添加 enable_points 配置\n";
        } else {
            echo "所有店家/代理已存在 enable_points 配置，无需添加\n";
        }
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("
            DELETE FROM store_setting WHERE feature = 'enable_points'
        ");

        echo "已删除所有店家/代理的 enable_points 配置\n";
    }
}
