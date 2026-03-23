<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 简化自动交班配置表
 * 改为每天3个时间段的简单模式
 */
final class SimplifyAutoShiftConfig extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        $table = $this->table('store_auto_shift_config');

        // 添加3个交班时间字段
        $table->addColumn('shift_time_1', 'time', [
                'null' => true,
                'after' => 'is_enabled',
                'comment' => '交班时间1（格式：HH:mm:ss）',
            ])
            ->addColumn('shift_time_2', 'time', [
                'null' => true,
                'after' => 'shift_time_1',
                'comment' => '交班时间2（格式：HH:mm:ss）',
            ])
            ->addColumn('shift_time_3', 'time', [
                'null' => true,
                'after' => 'shift_time_2',
                'comment' => '交班时间3（格式：HH:mm:ss）',
            ])
            ->addColumn('enable_notification', 'boolean', [
                'null' => false,
                'default' => 1,
                'after' => 'auto_settlement',
                'comment' => '是否启用通知（0=否，1=是）',
            ])
            ->save();

        // 将原有的 shift_time 数据迁移到 shift_time_1
        $this->execute("
            UPDATE store_auto_shift_config
            SET shift_time_1 = shift_time
            WHERE shift_time IS NOT NULL
        ");
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        $table = $this->table('store_auto_shift_config');

        $table->removeColumn('shift_time_1')
            ->removeColumn('shift_time_2')
            ->removeColumn('shift_time_3')
            ->removeColumn('enable_notification')
            ->save();
    }
}
