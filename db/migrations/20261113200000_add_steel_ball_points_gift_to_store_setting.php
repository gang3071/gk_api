<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为存量店家/代理添加钢珠下珠赠送积分开关配置（默认关闭）
 */
class AddSteelBallPointsGiftToStoreSetting extends AbstractMigration
{
    public function up()
    {
        $adminUserTable = 'admin_users';
        $storeSettingTable = 'store_setting';

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

            $exists = $this->fetchRow("
                SELECT id FROM {$storeSettingTable}
                WHERE feature = 'steel_ball_points_gift'
                  AND department_id = {$departmentId}
                  AND admin_user_id = {$adminUserId}
                LIMIT 1
            ");

            if (!$exists) {
                // num=1 表示每颗球赠1积分（比例），status=0 默认关闭
                $values[] = "({$departmentId}, {$adminUserId}, 'steel_ball_points_gift', 1, 0, '{$now}', '{$now}')";
                $count++;
            }
        }

        if (!empty($values)) {
            $valuesStr = implode(',', $values);
            $this->execute("
                INSERT INTO {$storeSettingTable}
                (department_id, admin_user_id, feature, num, status, created_at, updated_at)
                VALUES {$valuesStr}
            ");
            echo "成功为 {$count} 个店家/代理添加 steel_ball_points_gift 配置\n";
        } else {
            echo "所有店家/代理已存在 steel_ball_points_gift 配置，无需添加\n";
        }
    }

    public function down()
    {
        $this->execute("DELETE FROM store_setting WHERE feature = 'steel_ball_points_gift'");
        echo "已删除所有店家/代理的 steel_ball_points_gift 配置\n";
    }
}