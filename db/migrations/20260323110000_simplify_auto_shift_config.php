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

        // 添加3个交班时间字段（默认值：早班08:00、中班16:00、晚班00:00）
        $table->addColumn('shift_time_1', 'time', [
                'null' => false,
                'default' => '08:00:00',
                'after' => 'is_enabled',
                'comment' => '早班交班时间（08:00）',
            ])
            ->addColumn('shift_time_2', 'time', [
                'null' => false,
                'default' => '16:00:00',
                'after' => 'shift_time_1',
                'comment' => '中班交班时间（16:00）',
            ])
            ->addColumn('shift_time_3', 'time', [
                'null' => false,
                'default' => '00:00:00',
                'after' => 'shift_time_2',
                'comment' => '晚班交班时间（00:00）',
            ])
            ->save();

        // 将原有的 shift_time 数据迁移到 shift_time_1，如果没有则使用默认值
        $this->execute("
            UPDATE store_auto_shift_config
            SET shift_time_1 = COALESCE(shift_time, '08:00:00'),
                shift_time_2 = '16:00:00',
                shift_time_3 = '00:00:00'
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
            ->save();
    }
}
