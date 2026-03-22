<?php

use Phinx\Migration\AbstractMigration;

/**
 * 修改交班记录表，添加自动交班相关字段
 */
class AddAutoShiftFieldsToHandoverRecord extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     */
    public function change()
    {
        $table = $this->table('store_agent_shift_handover_record');

        // 检查字段是否已存在，避免重复添加
        if (!$table->hasColumn('is_auto_shift')) {
            $table->addColumn('is_auto_shift', 'boolean', [
                'null' => false,
                'default' => 0,
                'comment' => '是否自动交班（0=手动交班，1=自动交班）',
                'after' => 'total_profit_amount',
            ]);
        }

        if (!$table->hasColumn('auto_shift_log_id')) {
            $table->addColumn('auto_shift_log_id', 'biginteger', [
                'null' => true,
                'signed' => false,
                'default' => null,
                'comment' => '自动交班日志ID（关联 store_auto_shift_log.id，仅自动交班时有值）',
                'after' => 'is_auto_shift',
            ]);
        }

        if (!$table->hasColumn('lottery_amount')) {
            $table->addColumn('lottery_amount', 'decimal', [
                'null' => false,
                'precision' => 10,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '彩金发放金额（TYPE_LOTTERY=13）',
                'after' => 'total_out',
            ]);
        }

        // 添加索引
        if (!$table->hasIndex(['is_auto_shift'])) {
            $table->addIndex(['is_auto_shift'], [
                'name' => 'idx_is_auto_shift',
            ]);
        }

        if (!$table->hasIndex(['auto_shift_log_id'])) {
            $table->addIndex(['auto_shift_log_id'], [
                'name' => 'idx_auto_shift_log',
            ]);
        }

        $table->update();
    }

    /**
     * Migrate Up.
     *
     * 更新已有数据
     */
    public function up()
    {
        // 先执行 change() 方法创建字段和索引
        parent::up();

        // 更新已有的手动交班记录，确保 is_auto_shift = 0
        $this->execute("
            UPDATE `store_agent_shift_handover_record`
            SET `is_auto_shift` = 0
            WHERE `is_auto_shift` IS NULL OR `is_auto_shift` NOT IN (0, 1)
        ");

        // 更新已有记录的 lottery_amount 为 0（如果为 NULL）
        $this->execute("
            UPDATE `store_agent_shift_handover_record`
            SET `lottery_amount` = 0.00
            WHERE `lottery_amount` IS NULL
        ");
    }
}
