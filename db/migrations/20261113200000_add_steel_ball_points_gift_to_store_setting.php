<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为存量店家添加钢珠下珠赠送积分配置（仅店家 type=4，默认关闭）
 * 插入两条记录：开关 + 赠送积分数
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
        $toggleValues = [];
        $amountValues = [];

        foreach ($stores as $store) {
            $adminUserId = (int)$store['id'];
            $departmentId = (int)$store['department_id'];

            // 开关记录
            $toggleExists = $this->fetchRow("
                SELECT id FROM {$storeSettingTable}
                WHERE feature = 'steel_ball_points_gift'
                  AND department_id = {$departmentId}
                  AND admin_user_id = {$adminUserId}
                LIMIT 1
            ");
            if (!$toggleExists) {
                $toggleValues[] = "({$departmentId}, {$adminUserId}, 'steel_ball_points_gift', 0, 0, '{$now}', '{$now}')";
            }

            // 赠送积分数记录
            $amountExists = $this->fetchRow("
                SELECT id FROM {$storeSettingTable}
                WHERE feature = 'steel_ball_points_gift_amount'
                  AND department_id = {$departmentId}
                  AND admin_user_id = {$adminUserId}
                LIMIT 1
            ");
            if (!$amountExists) {
                $amountValues[] = "({$departmentId}, {$adminUserId}, 'steel_ball_points_gift_amount', 100, 1, '{$now}', '{$now}')";
            }
        }

        if (!empty($toggleValues)) {
            $this->execute("
                INSERT INTO {$storeSettingTable}
                (department_id, admin_user_id, feature, num, status, created_at, updated_at)
                VALUES " . implode(',', $toggleValues)
            );
            echo "成功插入 " . count($toggleValues) . " 条 steel_ball_points_gift（开关）记录\n";
        }

        if (!empty($amountValues)) {
            $this->execute("
                INSERT INTO {$storeSettingTable}
                (department_id, admin_user_id, feature, num, status, created_at, updated_at)
                VALUES " . implode(',', $amountValues)
            );
            echo "成功插入 " . count($amountValues) . " 条 steel_ball_points_gift_amount（积分数）记录\n";
        }
    }

    public function down()
    {
        $this->execute("DELETE FROM store_setting WHERE feature IN ('steel_ball_points_gift', 'steel_ball_points_gift_amount')");
        echo "已删除所有店家的 steel_ball_points_gift 相关配置\n";
    }
}