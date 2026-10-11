<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加外部按键操作相关字段
 *
 * 用途：支持线下钢珠机实体按键开洗分操作记录
 * - B5协议：外部按键开分（每次100分）
 * - B7协议：外部按键洗分（每次100分）
 *
 * 涉及表：
 * 1. player_game_log - 添加 source_type 字段（区分线上/线下操作）
 * 2. player_game_record - 添加 has_external_button 字段（标记是否包含实体按键）
 *
 * @author Claude Code
 * @date 2026-09-02
 */
class AddExternalButtonFields extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     */
    public function change()
    {
        // ==================== 1. player_game_log ====================
        $gameLogTable = $this->table('player_game_log');

        // 添加 source_type 字段
        if (!$gameLogTable->hasColumn('source_type')) {
            $gameLogTable->addColumn('source_type', 'integer', [
                'null' => false,
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                'signed' => false,
                'default' => 1,
                'comment' => '来源类型 1=线上系统 2=线下实体按键',
                'after' => 'is_system',
            ]);
        }

        // 添加索引
        if (!$gameLogTable->hasIndex(['source_type'])) {
            $gameLogTable->addIndex(['source_type'], [
                'name' => 'idx_source_type',
            ]);
        }

        // 应用更改
        $gameLogTable->update();

        // ==================== 2. player_game_record ====================
        $gameRecordTable = $this->table('player_game_record');

        // 添加 has_external_button 字段
        if (!$gameRecordTable->hasColumn('has_external_button')) {
            $gameRecordTable->addColumn('has_external_button', 'integer', [
                'null' => false,
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                'signed' => false,
                'default' => 0,
                'comment' => '是否包含实体按键操作 0=否 1=是',
                'after' => 'type',
            ]);
        }

        // 添加索引
        if (!$gameRecordTable->hasIndex(['has_external_button'])) {
            $gameRecordTable->addIndex(['has_external_button'], [
                'name' => 'idx_has_external_button',
            ]);
        }

        // 应用更改
        $gameRecordTable->update();
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
        $this->output->writeln('<info>✓ 已为 player_game_log 表添加 source_type 字段</info>');
        $this->output->writeln('<info>✓ 已为 player_game_record 表添加 has_external_button 字段</info>');
        $this->output->writeln('');
        $this->output->writeln('<comment>字段说明：</comment>');
        $this->output->writeln('  - source_type: 1=线上系统 2=线下实体按键');
        $this->output->writeln('  - has_external_button: 0=无实体按键 1=有实体按键');
    }

    /**
     * Migrate Down.
     *
     * 回滚迁移
     */
    public function down()
    {
        // player_game_log 删除字段
        $gameLogTable = $this->table('player_game_log');
        if ($gameLogTable->hasColumn('source_type')) {
            $gameLogTable->removeColumn('source_type');
        }
        $gameLogTable->update();

        // player_game_record 删除字段
        $gameRecordTable = $this->table('player_game_record');
        if ($gameRecordTable->hasColumn('has_external_button')) {
            $gameRecordTable->removeColumn('has_external_button');
        }
        $gameRecordTable->update();

        $this->output->writeln('<info>✓ 已回滚外部按键字段</info>');
    }
}
