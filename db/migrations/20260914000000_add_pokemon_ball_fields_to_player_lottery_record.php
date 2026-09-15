<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 为 player_lottery_record 表新增精灵球派发相关字段
 *
 * 新增字段：
 * - distribute_type: 派发类型（0=正常派发 1=精灵球派发）
 * - pokemon_ball_machine_id: 精灵球机台ID（仅精灵球派发时记录）
 */
final class AddPokemonBallFieldsToPlayerLotteryRecord extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        $table = $this->table('player_lottery_record');

        // 1. 新增派发类型字段
        if (!$table->hasColumn('distribute_type')) {
            $table->addColumn('distribute_type', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '派发类型 0=正常派发 1=精灵球派发',
                'after' => 'source',
            ])->save();
            $this->output->writeln('   ✅ 已新增 distribute_type 字段');
        } else {
            $this->output->writeln('   ⏭  distribute_type 字段已存在，跳过');
        }

        // 2. 新增精灵球机台ID字段
        if (!$table->hasColumn('pokemon_ball_machine_id')) {
            $table->addColumn('pokemon_ball_machine_id', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '精灵球机台ID（仅精灵球派发时记录）',
                'after' => 'distribute_type',
            ])->save();
            $this->output->writeln('   ✅ 已新增 pokemon_ball_machine_id 字段');
        } else {
            $this->output->writeln('   ⏭  pokemon_ball_machine_id 字段已存在，跳过');
        }

        // 3. 新增索引
        if (!$table->hasIndex('distribute_type')) {
            $table->addIndex('distribute_type', [
                'name' => 'idx_distribute_type',
                'unique' => false,
            ])->save();
            $this->output->writeln('   ✅ 已新增 distribute_type 索引');
        }

        if (!$table->hasIndex('pokemon_ball_machine_id')) {
            $table->addIndex('pokemon_ball_machine_id', [
                'name' => 'idx_pokemon_ball_machine_id',
                'unique' => false,
            ])->save();
            $this->output->writeln('   ✅ 已新增 pokemon_ball_machine_id 索引');
        }
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        $table = $this->table('player_lottery_record');

        if ($table->hasColumn('pokemon_ball_machine_id')) {
            $table->removeColumn('pokemon_ball_machine_id')->save();
            $this->output->writeln('   ✅ 已移除 pokemon_ball_machine_id 字段');
        }

        if ($table->hasColumn('distribute_type')) {
            $table->removeColumn('distribute_type')->save();
            $this->output->writeln('   ✅ 已移除 distribute_type 字段');
        }
    }
}
