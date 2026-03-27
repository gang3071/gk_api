<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加设备爆机金额配置
 * 为所有店家添加默认爆机金额配置
 */
final class AddMachineCrashAmountSetting extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        echo "================================================================================\n";
        echo "添加设备爆机金额配置\n";
        echo "================================================================================\n";

        $now = date('Y-m-d H:i:s');

        // 获取所有店家账号（admin_user_id > 0 的唯一店家配置）
        $stores = $this->query("
            SELECT DISTINCT department_id, admin_user_id
            FROM store_setting
            WHERE admin_user_id > 0
            ORDER BY department_id, admin_user_id
        ")->fetchAll();

        echo "找到 " . count($stores) . " 个店家需要添加爆机配置\n";

        $insertCount = 0;
        foreach ($stores as $store) {
            $departmentId = $store['department_id'];
            $adminUserId = $store['admin_user_id'];

            // 检查是否已存在爆机配置
            $existing = $this->query("
                SELECT id
                FROM store_setting
                WHERE department_id = {$departmentId}
                  AND admin_user_id = {$adminUserId}
                  AND feature = 'machine_crash_amount'
                LIMIT 1
            ")->fetch();

            if (!$existing) {
                // 插入默认爆机金额配置（默认100万）
                $this->execute("
                    INSERT INTO store_setting
                    (department_id, admin_user_id, feature, num, content, date_start, date_end, status, created_at, updated_at)
                    VALUES
                    ({$departmentId}, {$adminUserId}, 'machine_crash_amount', 1000000, '', '', '', 1, '{$now}', '{$now}')
                ");
                $insertCount++;
                echo "✓ 为店家 department_id={$departmentId}, admin_user_id={$adminUserId} 添加爆机配置（默认100万）\n";
            }
        }

        echo "================================================================================\n";
        echo "迁移完成！共添加 {$insertCount} 条爆机配置记录\n";
        echo "================================================================================\n";
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        echo "================================================================================\n";
        echo "移除设备爆机金额配置\n";
        echo "================================================================================\n";

        $result = $this->execute("
            DELETE FROM store_setting
            WHERE feature = 'machine_crash_amount'
        ");

        echo "✓ 已移除所有爆机金额配置\n";
        echo "================================================================================\n";
    }
}
