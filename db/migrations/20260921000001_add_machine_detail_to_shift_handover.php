<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 交班记录新增实体机台汇总字段 + 新建实体机台详情表
 */
final class AddMachineDetailToShiftHandover extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        // ==================== 1. 主表新增汇总字段 ====================
        $table = $this->table('store_agent_shift_handover_record');

        if (!$table->hasColumn('machine_open_point_total')) {
            $table->addColumn('machine_open_point_total', 'decimal', [
                'null' => false,
                'precision' => 12,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '实体机台上分总计',
                'after' => 'machine_bet_amount',
            ]);
        }

        if (!$table->hasColumn('machine_wash_point_total')) {
            $table->addColumn('machine_wash_point_total', 'decimal', [
                'null' => false,
                'precision' => 12,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '实体机台下分总计',
                'after' => 'machine_open_point_total',
            ]);
        }

        if (!$table->hasColumn('machine_profit_total')) {
            $table->addColumn('machine_profit_total', 'decimal', [
                'null' => false,
                'precision' => 12,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '实体机台利润总计（上分-下分）',
                'after' => 'machine_wash_point_total',
            ]);
        }

        if (!$table->hasColumn('machine_score_total')) {
            $table->addColumn('machine_score_total', 'decimal', [
                'null' => false,
                'precision' => 12,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '实体机台得分总计',
                'after' => 'machine_profit_total',
            ]);
        }

        $table->update();

        // ==================== 2. 新建实体机台详情表 ====================
        $machineDetail = $this->table('store_shift_machine_detail', [
            'id' => true,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '交班实体机台详情',
        ]);

        $machineDetail
            ->addColumn('shift_record_id', 'integer', [
                'null' => false,
                'comment' => '交班记录ID',
            ])
            ->addColumn('department_id', 'integer', [
                'null' => false,
                'default' => 0,
                'comment' => '部门/渠道ID',
            ])
            ->addColumn('bind_admin_user_id', 'integer', [
                'null' => false,
                'default' => 0,
                'comment' => '绑定管理员ID',
            ])
            ->addColumn('machine_id', 'integer', [
                'null' => false,
                'default' => 0,
                'comment' => '机台ID',
            ])
            ->addColumn('machine_code', 'string', [
                'null' => false,
                'default' => '',
                'limit' => 100,
                'comment' => '机台编号',
            ])
            ->addColumn('machine_name', 'string', [
                'null' => false,
                'default' => '',
                'limit' => 100,
                'comment' => '机台名称',
            ])
            ->addColumn('type', 'integer', [
                'null' => false,
                'default' => 0,
                'comment' => '类型',
            ])
            ->addColumn('open_point', 'decimal', [
                'null' => false,
                'precision' => 12,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '上分',
            ])
            ->addColumn('wash_point', 'decimal', [
                'null' => false,
                'precision' => 12,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '下分',
            ])
            ->addColumn('profit', 'decimal', [
                'null' => false,
                'precision' => 12,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '利润（上分-下分）',
            ])
            ->addColumn('pressure', 'decimal', [
                'null' => false,
                'precision' => 12,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '押分',
            ])
            ->addColumn('score', 'decimal', [
                'null' => false,
                'precision' => 12,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '得分',
            ])
            ->addColumn('created_at', 'datetime', [
                'null' => true,
                'comment' => '创建时间',
            ])
            ->addColumn('updated_at', 'datetime', [
                'null' => true,
                'comment' => '更新时间',
            ])
            ->addIndex(['shift_record_id'], ['name' => 'idx_shift_record_id'])
            ->addIndex(['department_id'], ['name' => 'idx_department_id'])
            ->addIndex(['machine_id'], ['name' => 'idx_machine_id'])
            ->addIndex(['bind_admin_user_id'], ['name' => 'idx_bind_admin_user_id'])
            ->create();
    }

    /**
     * Migrate Up.
     */
    public function up(): void
    {
        parent::up();

        // 初始化已有记录的汇总字段为 0
        $this->execute("
            UPDATE `store_agent_shift_handover_record`
            SET
                `machine_open_point_total` = COALESCE(`machine_open_point_total`, 0.00),
                `machine_wash_point_total` = COALESCE(`machine_wash_point_total`, 0.00),
                `machine_profit_total` = COALESCE(`machine_profit_total`, 0.00),
                `machine_score_total` = COALESCE(`machine_score_total`, 0.00)
            WHERE
                `machine_open_point_total` IS NULL
                OR `machine_wash_point_total` IS NULL
                OR `machine_profit_total` IS NULL
                OR `machine_score_total` IS NULL
        ");
    }
}
