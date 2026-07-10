<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为存量店家添加菜单图片配置
 */
class AddMenuImageToStoreSetting extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $adminUserTable = 'admin_user';
        $storeSettingTable = 'store_setting';

        // 获取所有店家的 admin_user_id 和 department_id
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
        $count = 0;

        foreach ($stores as $store) {
            $adminUserId = $store['id'];
            $departmentId = $store['department_id'];

            // 检查是否已存在该店家的 menu_image 配置
            $exists = $this->fetchRow("
                SELECT id FROM {$storeSettingTable}
                WHERE feature = 'menu_image'
                  AND department_id = {$departmentId}
                  AND admin_user_id = {$adminUserId}
                LIMIT 1
            ");

            // 如果不存在，则添加到插入列表
            if (!$exists) {
                $values[] = "({$departmentId}, {$adminUserId}, 'menu_image', 0, '', '00:00:00', '23:59:59', 1, '{$now}', '{$now}')";
                $count++;
            }
        }

        // 批量插入
        if (!empty($values)) {
            $valuesStr = implode(',', $values);
            $this->execute("
                INSERT INTO {$storeSettingTable}
                (department_id, admin_user_id, feature, num, content, date_start, date_end, status, created_at, updated_at)
                VALUES {$valuesStr}
            ");
            echo "成功为 {$count} 个店家添加 menu_image 配置\n";
        } else {
            echo "所有店家已存在 menu_image 配置，无需添加\n";
        }
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("
            DELETE FROM store_setting WHERE feature = 'menu_image'
        ");

        echo "已删除所有店家的 menu_image 配置\n";
    }
}
