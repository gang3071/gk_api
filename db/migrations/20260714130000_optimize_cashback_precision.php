<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 优化反水金额字段精度
 *
 * 将 cashback_amount、team_machine_put_amount、total_cashback_amount 等字段
 * 的精度从 DECIMAL(18,2) 提升到 DECIMAL(20,4)，支持保留到 0.0001
 */
final class OptimizeCashbackPrecision extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        $this->output->writeln('=========================================');
        $this->output->writeln('开始优化反水金额字段精度...');
        $this->output->writeln('=========================================');

        // 1. 修改 play_game_record 表
        $this->output->writeln('');
        $this->output->writeln('步骤1: 修改 play_game_record 表');
        $this->output->writeln('  - cashback_amount: DECIMAL(20,4)');
        $this->execute('ALTER TABLE play_game_record MODIFY COLUMN cashback_amount DECIMAL(20,4) DEFAULT NULL COMMENT "反水金额"');
        $this->output->writeln('  ✓ 已修改 cashback_amount 字段精度');

        $this->output->writeln('  - cashback_ratio: DECIMAL(10,4)');
        $this->execute('ALTER TABLE play_game_record MODIFY COLUMN cashback_ratio DECIMAL(10,4) DEFAULT NULL COMMENT "反水比例"');
        $this->output->writeln('  ✓ 已修改 cashback_ratio 字段精度');

        // 2. 修改 player_game_record 表
        $this->output->writeln('');
        $this->output->writeln('步骤2: 修改 player_game_record 表');
        $this->output->writeln('  - cashback_amount: DECIMAL(20,4)');
        $this->execute('ALTER TABLE player_game_record MODIFY COLUMN cashback_amount DECIMAL(20,4) DEFAULT NULL COMMENT "反水金额"');
        $this->output->writeln('  ✓ 已修改 cashback_amount 字段精度');

        $this->output->writeln('  - cashback_ratio: DECIMAL(10,4)');
        $this->execute('ALTER TABLE player_game_record MODIFY COLUMN cashback_ratio DECIMAL(10,4) DEFAULT NULL COMMENT "反水比例"');
        $this->output->writeln('  ✓ 已修改 cashback_ratio 字段精度');

        // 3. 修改 player_extend 表
        $this->output->writeln('');
        $this->output->writeln('步骤3: 修改 player_extend 表');
        $this->output->writeln('  - team_machine_put_amount: DECIMAL(20,4)');
        $this->execute('ALTER TABLE player_extend MODIFY COLUMN team_machine_put_amount DECIMAL(20,4) DEFAULT 0 COMMENT "机器投钞总金额(团队)"');
        $this->output->writeln('  ✓ 已修改 team_machine_put_amount 字段精度');

        $this->output->writeln('  - total_cashback_amount: DECIMAL(20,4)');
        $this->execute('ALTER TABLE player_extend MODIFY COLUMN total_cashback_amount DECIMAL(20,4) DEFAULT 0 COMMENT "总反水金额"');
        $this->output->writeln('  ✓ 已修改 total_cashback_amount 字段精度');

        $this->output->writeln('  - pending_cashback_amount: DECIMAL(20,4)');
        $this->execute('ALTER TABLE player_extend MODIFY COLUMN pending_cashback_amount DECIMAL(20,4) DEFAULT 0 COMMENT "待领取反水金额"');
        $this->output->writeln('  ✓ 已修改 pending_cashback_amount 字段精度');

        $this->output->writeln('');
        $this->output->writeln('=========================================');
        $this->output->writeln('字段精度优化完成！');
        $this->output->writeln('=========================================');
        $this->output->writeln('');
        $this->output->writeln('修改汇总:');
        $this->output->writeln('  play_game_record:');
        $this->output->writeln('    - cashback_amount: DECIMAL(20,4)');
        $this->output->writeln('    - cashback_ratio: DECIMAL(10,4)');
        $this->output->writeln('  player_game_record:');
        $this->output->writeln('    - cashback_amount: DECIMAL(20,4)');
        $this->output->writeln('    - cashback_ratio: DECIMAL(10,4)');
        $this->output->writeln('  player_extend:');
        $this->output->writeln('    - team_machine_put_amount: DECIMAL(20,4)');
        $this->output->writeln('    - total_cashback_amount: DECIMAL(20,4)');
        $this->output->writeln('    - pending_cashback_amount: DECIMAL(20,4)');
        $this->output->writeln('=========================================');
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        $this->output->writeln('回滚：恢复字段精度...');

        // 恢复 play_game_record 表
        $this->execute('ALTER TABLE play_game_record MODIFY COLUMN cashback_amount DECIMAL(20,2) DEFAULT NULL COMMENT "反水金额"');
        $this->execute('ALTER TABLE play_game_record MODIFY COLUMN cashback_ratio DECIMAL(10,2) DEFAULT NULL COMMENT "反水比例"');

        // 恢复 player_game_record 表
        $this->execute('ALTER TABLE player_game_record MODIFY COLUMN cashback_amount DECIMAL(20,2) DEFAULT NULL COMMENT "反水金额"');
        $this->execute('ALTER TABLE player_game_record MODIFY COLUMN cashback_ratio DECIMAL(10,2) DEFAULT NULL COMMENT "反水比例"');

        // 恢复 player_extend 表
        $this->execute('ALTER TABLE player_extend MODIFY COLUMN team_machine_put_amount DECIMAL(18,2) DEFAULT 0 COMMENT "机器投钞总金额(团队)"');
        $this->execute('ALTER TABLE player_extend MODIFY COLUMN total_cashback_amount DECIMAL(18,2) DEFAULT 0 COMMENT "总反水金额"');
        $this->execute('ALTER TABLE player_extend MODIFY COLUMN pending_cashback_amount DECIMAL(18,2) DEFAULT 0 COMMENT "待领取反水金额"');

        $this->output->writeln('回滚完成！');
    }
}
