<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 支持线下机台
 *
 * 修改内容：
 * 1. machine 表新增字段：
 *    - machine_source: 机台来源（1=线上机台有直播，2=线下机台无直播）
 *
 * 2. channel_machine 表新增字段：
 *    - store_admin_id: 绑定店家ID（关联admin_users表，由渠道管理绑定）
 *
 * 说明：
 * - 线下机台通过 channel_machine 表关联渠道和店家
 * - 线下机台不支持直播流配置（无MachineMedia记录）
 * - 现有机台自动设置为线上机台（machine_source = 1）
 */
class AddOfflineMachineSupport extends AbstractMigration
{
    /**
     * Up Method.
     */
    public function up(): void
    {
        // 1. 修改 machine 表：添加 machine_source 字段
        $this->table('machine')
            ->addColumn('machine_source', 'integer', [
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                'null' => false,
                'default' => 1,
                'comment' => '机台来源：1=线上机台(有直播) 2=线下机台(无直播)',
                'after' => 'is_live'
            ])
            ->addIndex(['machine_source'], ['name' => 'idx_machine_source'])
            ->update();

        // 初始化现有数据：所有现有机台默认为线上机台
        $this->execute("
            UPDATE machine
            SET machine_source = 1
            WHERE machine_source IS NULL OR machine_source = 0
        ");

        // 2. 修改 channel_machine 表：添加 store_admin_id 字段
        // 用于渠道管理绑定线下机台到店家
        $this->table('channel_machine')
            ->addColumn('store_admin_id', 'integer', [
                'null' => true,
                'default' => null,
                'comment' => '绑定店家ID（关联admin_users表，仅线下机台使用）',
                'after' => 'machine_id'
            ])
            ->addIndex(['store_admin_id'], ['name' => 'idx_store_admin_id'])
            ->update();
    }

    /**
     * Down Method.
     */
    public function down(): void
    {
        // 回滚 machine 表
        $this->table('machine')
            ->removeIndexByName('idx_machine_source')
            ->removeColumn('machine_source')
            ->update();

        // 回滚 channel_machine 表
        $this->table('channel_machine')
            ->removeIndexByName('idx_store_admin_id')
            ->removeColumn('store_admin_id')
            ->update();
    }
}
