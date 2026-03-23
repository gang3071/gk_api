<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 删除自动交班配置表中的废弃字段
 * 删除复杂交班模式相关字段，保留简化的3时间段模式
 */
final class RemoveDeprecatedAutoShiftFields extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        $table = $this->table('store_auto_shift_config');

        // 删除索引
        if ($table->hasIndex(['is_enabled', 'status'])) {
            $table->removeIndex(['is_enabled', 'status']);
        }

        // 删除废弃字段
        $table->removeColumn('shift_mode')
            ->removeColumn('shift_time')
            ->removeColumn('shift_weekdays')
            ->removeColumn('shift_interval_hours')
            ->removeColumn('notify_on_failure')
            ->removeColumn('notify_phones')
            ->removeColumn('status')
            ->save();
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        $table = $this->table('store_auto_shift_config');

        // 恢复字段
        $table->addColumn('shift_mode', 'boolean', [
                'null' => false,
                'default' => 1,
                'after' => 'is_enabled',
                'comment' => '交班模式（1=每日，2=每周，3=自定义周期）',
            ])
            ->addColumn('shift_time', 'time', [
                'null' => false,
                'default' => '02:00:00',
                'after' => 'shift_mode',
                'comment' => '交班时间（已废弃，格式：HH:mm:ss）',
            ])
            ->addColumn('shift_weekdays', 'string', [
                'null' => true,
                'limit' => 20,
                'default' => null,
                'after' => 'shift_time_3',
                'comment' => '每周交班日期（逗号分隔，0=周日，1=周一...6=周六）',
            ])
            ->addColumn('shift_interval_hours', 'integer', [
                'null' => true,
                'signed' => false,
                'default' => 24,
                'after' => 'shift_weekdays',
                'comment' => '自定义交班周期（单位：小时）',
            ])
            ->addColumn('notify_on_failure', 'boolean', [
                'null' => false,
                'default' => 1,
                'after' => 'auto_settlement',
                'comment' => '失败时是否通知（0=否，1=是）',
            ])
            ->addColumn('notify_phones', 'string', [
                'null' => true,
                'limit' => 255,
                'default' => null,
                'after' => 'notify_on_failure',
                'comment' => '通知手机号（逗号分隔）',
            ])
            ->addColumn('status', 'boolean', [
                'null' => false,
                'default' => 1,
                'after' => 'next_shift_time',
                'comment' => '状态（1=正常，2=暂停，3=异常）',
            ])
            ->save();

        // 恢复索引
        $table->addIndex(['is_enabled', 'status'], [
            'name' => 'idx_enabled_status',
        ])->save();
    }
}
