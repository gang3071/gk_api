<?php

use Phinx\Migration\AbstractMigration;

class LotteryRecordDistributionFields extends AbstractMigration
{
    /**
     * 摸奖券中奖记录表新增字段
     * 支持人工审核和发放流程
     */
    public function change()
    {
        $table = $this->table('lottery_ticket_record');

        // 检查表是否存在
        if (!$table->exists()) {
            $this->output->writeln('<error>表 lottery_ticket_record 不存在</error>');
            return;
        }

        // 新增字段：发放操作人ID
        if (!$table->hasColumn('distributed_by')) {
            $table->addColumn('distributed_by', 'integer', [
                'null' => true,
                'comment' => '发放操作人ID（admin_user_id）',
                'after' => 'status'
            ]);
        }

        // 新增字段：发放时间
        if (!$table->hasColumn('distributed_at')) {
            $table->addColumn('distributed_at', 'datetime', [
                'null' => true,
                'comment' => '发放时间',
                'after' => 'distributed_by'
            ]);
        }

        // 新增字段：发放备注
        if (!$table->hasColumn('distribution_note')) {
            $table->addColumn('distribution_note', 'string', [
                'limit' => 500,
                'null' => true,
                'comment' => '发放备注',
                'after' => 'distributed_at'
            ]);
        }

        // 新增字段：最后修改人ID
        if (!$table->hasColumn('modified_by')) {
            $table->addColumn('modified_by', 'integer', [
                'null' => true,
                'comment' => '最后修改人ID',
                'after' => 'distribution_note'
            ]);
        }

        // 新增字段：最后修改时间
        if (!$table->hasColumn('modified_at')) {
            $table->addColumn('modified_at', 'datetime', [
                'null' => true,
                'comment' => '最后修改时间',
                'after' => 'modified_by'
            ]);
        }

        // 新增字段：修改原因
        if (!$table->hasColumn('modification_reason')) {
            $table->addColumn('modification_reason', 'string', [
                'limit' => 500,
                'null' => true,
                'comment' => '修改原因',
                'after' => 'modified_at'
            ]);
        }

        // 新增索引：状态+发放时间（在update前添加）
        if (!$table->hasIndex(['status', 'distributed_at'])) {
            $table->addIndex(['status', 'distributed_at'], [
                'name' => 'idx_status_distributed'
            ]);
        }

        // 新增索引：发放操作人
        if (!$table->hasIndex(['distributed_by'])) {
            $table->addIndex(['distributed_by'], [
                'name' => 'idx_distributed_by'
            ]);
        }

        // 一次性更新（字段+索引）
        $table->update();

        $this->output->writeln('<info>中奖记录表字段新增完成</info>');
        $this->output->writeln('<comment>注意: status字段需新增值4 (PROCESSING 发放中) 和 5 (FAILED 发放失败)</comment>');
        $this->output->writeln('<comment>需在Model中定义常量:</comment>');
        $this->output->writeln('<comment>  const STATUS_PENDING = 0;    // 待发放（含义变更）</comment>');
        $this->output->writeln('<comment>  const STATUS_CLAIMED = 1;    // 已发放（含义变更）</comment>');
        $this->output->writeln('<comment>  const STATUS_PROCESSING = 4; // 发放中（新增）</comment>');
        $this->output->writeln('<comment>  const STATUS_FAILED = 5;     // 发放失败（新增）</comment>');
    }
}
