<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加 remark 字段到 player_game_log 表
 *
 * 功能：为 player_game_log 表添加 remark 字段，用于记录管理员操作备注信息
 * 使用场景：
 * - 管理员强制踢出玩家时记录原因
 * - 管理员没收分数时记录说明
 * - 其他管理员操作的备注信息
 *
 * @date 2026-08-20
 */
class AddRemarkToPlayerGameLog extends AbstractMigration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        $table = $this->table($this->getTable());

        // 检查字段是否已存在
        if (!$table->hasColumn('remark')) {
            $table->addColumn('remark', 'text', [
                'null' => true,
                'comment' => '备注信息（如管理员操作说明：强制踢出原因、没收分数说明等）',
                'after' => 'is_test'
            ])->update();

            $this->output->writeln('<info>✓ 已添加 remark 字段到 player_game_log 表</info>');
        } else {
            $this->output->writeln('<comment>! remark 字段已存在，跳过添加</comment>');
        }
    }

    /**
     * 获取表名
     */
    private function getTable(): string
    {
        return 'player_game_log';
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        $table = $this->table($this->getTable());

        // 检查字段是否存在
        if ($table->hasColumn('remark')) {
            $table->removeColumn('remark')->update();
            $this->output->writeln('<info>✓ 已删除 player_game_log 表的 remark 字段</info>');
        } else {
            $this->output->writeln('<comment>! remark 字段不存在，跳过删除</comment>');
        }
    }
}