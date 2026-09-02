<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为 player_game_log 表添加门店相关字段
 *
 * 用途：支持实体按键操作时记录门店和门店代理信息
 * - 当 player_id=0（无玩家）且 source_type=2（线下实体按键）时
 * - 需要记录机台所属门店ID和门店绑定的代理ID
 *
 * 使用场景：
 * - B5/B7协议外部按键开洗分操作（无玩家登录）
 * - 线下钢珠机门店统计和分润计算
 * - 门店代理业绩统计
 *
 * 新增字段：
 * 1. store_id - 门店ID（可为NULL，仅门店机台记录时填充）
 * 2. store_agent_id - 门店所属代理玩家ID（可为NULL，用于门店代理分润）
 *
 * @author Claude Code
 * @date 2026-09-02
 */
class AddStoreFieldsToPlayerGameLog extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     */
    public function change()
    {
        $table = $this->table('player_game_log');

        // 添加 store_id 字段
        if (!$table->hasColumn('store_id')) {
            $table->addColumn('store_id', 'integer', [
                'null' => true,
                'default' => null,
                'comment' => '门店ID（机台所属门店，可为NULL）',
                'after' => 'department_id',
            ]);
        }

        // 添加 store_agent_id 字段
        if (!$table->hasColumn('store_agent_id')) {
            $table->addColumn('store_agent_id', 'integer', [
                'null' => true,
                'default' => null,
                'comment' => '门店所属代理玩家ID（用于门店代理分润，可为NULL）',
                'after' => 'store_id',
            ]);
        }

        // 添加索引（优化查询性能）
        if (!$table->hasIndex(['store_id'])) {
            $table->addIndex(['store_id'], [
                'name' => 'idx_store_id',
            ]);
        }

        if (!$table->hasIndex(['store_agent_id'])) {
            $table->addIndex(['store_agent_id'], [
                'name' => 'idx_store_agent_id',
            ]);
        }

        // 添加复合索引（用于门店统计查询）
        if (!$table->hasIndex(['store_id', 'created_at'])) {
            $table->addIndex(['store_id', 'created_at'], [
                'name' => 'idx_store_created',
            ]);
        }

        // 应用更改
        $table->update();
    }

    /**
     * Migrate Up.
     *
     * 执行迁移时的额外操作
     */
    public function up()
    {
        // 先执行 change() 方法创建字段和索引
        parent::up();

        // 输出成功信息
        $this->output->writeln('');
        $this->output->writeln('<info>✓ 已为 player_game_log 表添加门店相关字段</info>');
        $this->output->writeln('');
        $this->output->writeln('<comment>新增字段：</comment>');
        $this->output->writeln('  - store_id: 门店ID（可为NULL）');
        $this->output->writeln('  - store_agent_id: 门店所属代理玩家ID（可为NULL）');
        $this->output->writeln('');
        $this->output->writeln('<comment>使用场景：</comment>');
        $this->output->writeln('  - 实体按键操作时（player_id=0, source_type=2）记录门店信息');
        $this->output->writeln('  - 门店代理分润统计');
        $this->output->writeln('  - 门店业绩报表查询');
        $this->output->writeln('');
        $this->output->writeln('<comment>已添加索引：</comment>');
        $this->output->writeln('  - idx_store_id: 单字段索引');
        $this->output->writeln('  - idx_store_agent_id: 单字段索引');
        $this->output->writeln('  - idx_store_created: 复合索引（store_id, created_at）');
    }

    /**
     * Migrate Down.
     *
     * 回滚迁移
     */
    public function down()
    {
        $table = $this->table('player_game_log');

        // 删除索引
        if ($table->hasIndex(['store_id', 'created_at'])) {
            $table->removeIndex(['store_id', 'created_at']);
        }
        if ($table->hasIndex(['store_agent_id'])) {
            $table->removeIndex(['store_agent_id']);
        }
        if ($table->hasIndex(['store_id'])) {
            $table->removeIndex(['store_id']);
        }

        // 删除字段
        if ($table->hasColumn('store_agent_id')) {
            $table->removeColumn('store_agent_id');
        }
        if ($table->hasColumn('store_id')) {
            $table->removeColumn('store_id');
        }

        $table->update();

        $this->output->writeln('<info>✓ 已回滚门店相关字段</info>');
    }

    /**
     * 获取迁移涉及的表名（用于文档说明）
     *
     * @return string
     */
    protected function getTableName(): string
    {
        return 'player_game_log';
    }

    /**
     * 验证迁移是否成功
     *
     * @return bool
     */
    protected function validateMigration(): bool
    {
        $table = $this->table('player_game_log');

        $hasStoreId = $table->hasColumn('store_id');
        $hasStoreAgentId = $table->hasColumn('store_agent_id');

        return $hasStoreId && $hasStoreAgentId;
    }
}
