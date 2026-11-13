<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为存量店家添加钢珠下珠赠送积分配置（仅店家 type=4，默认关闭）
 * status=开关（0关/1开），num=每次赠送固定积分数（默认100）
 */
class AddSteelBallPointsGiftToStoreSetting extends AbstractMigration
{
    public function up()
    {
        $adminUserTable = 'admin_users';
        $storeSettingTable = 'store_setting';

        // 只针对店家（type=4），代理不参与此功能
        $stores = $this->fetchAll("
            SELECT id, department_id
            FROM {$adminUserTable}
            WHERE type = 4
              AND deleted_at IS NULL
        ");

        if (empty($stores)) {
            echo "没有找到存量店家，跳过迁移\n";
            return;
        }

        $now = date('Y-m-d H:i:s');
        $values = [];

        foreach ($stores as $store) {
            $adminUserId = (int)$store['id'];
            $departmentId = (int)$store['department_id'];

            $exists = $this->fetchRow("
                SELECT id FROM {$storeSettingTable}
                WHERE feature = 'steel_ball_points_gift'
                  AND department_id = {$departmentId}
                  AND admin_user_id = {$adminUserId}
                LIMIT 1
            ");

            if (!$exists) {
                // status=0 默认关闭，num=100 默认赠送100积分
                $values[] = "({$departmentId}, {$adminUserId}, 'steel_ball_points_gift', 100, 0, '{$now}', '{$now}')";
            }
        }

        if (!empty($values)) {
            $this->execute("
                INSERT INTO {$storeSettingTable}
                (department_id, admin_user_id, feature, num, status, created_at, updated_at)
                VALUES " . implode(',', $values)
            );
            echo "成功为 " . count($values) . " 个店家添加 steel_ball_points_gift 配置\n";
        } else {
            echo "所有店家已存在 steel_ball_points_gift 配置，无需添加\n";
        }
    }

    public function down()
    {
        $this->execute("DELETE FROM store_setting WHERE feature = 'steel_ball_points_gift'");
        echo "已删除所有店家的 steel_ball_points_gift 配置\n";
    }
}
