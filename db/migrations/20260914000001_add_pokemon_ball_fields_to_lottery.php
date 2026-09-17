<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 为 lottery 表新增精灵球配置字段
 *
 * 新增字段：
 * - pokemon_ball_status: 精灵球开关（0=禁用 1=启用）
 * - pokemon_ball_machine_id: 精灵球机台ID（一对一绑定）
 */
final class AddPokemonBallFieldsToLottery extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        $table = $this->table('lottery');

        // 1. 新增精灵球开关字段
        if (!$table->hasColumn('pokemon_ball_status')) {
            $table->addColumn('pokemon_ball_status', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '精灵球开关 0=禁用 1=启用',
                'after' => 'burst_trigger_config',
            ])->save();
            $this->output->writeln('   ✅ 已新增 pokemon_ball_status 字段');
        } else {
            $this->output->writeln('   ⏭  pokemon_ball_status 字段已存在，跳过');
        }

        // 2. 新增精灵球机台ID字段
        if (!$table->hasColumn('pokemon_ball_machine_id')) {
            $table->addColumn('pokemon_ball_machine_id', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '精灵球机台ID（一对一绑定）',
                'after' => 'pokemon_ball_status',
            ])->save();
            $this->output->writeln('   ✅ 已新增 pokemon_ball_machine_id 字段');
        } else {
            $this->output->writeln('   ⏭  pokemon_ball_machine_id 字段已存在，跳过');
        }

        // 3. 新增索引
        if (!$table->hasIndex('pokemon_ball_status')) {
            $table->addIndex('pokemon_ball_status', [
                'name' => 'idx_lottery_pokemon_ball_status',
                'unique' => false,
            ])->save();
            $this->output->writeln('   ✅ 已新增 pokemon_ball_status 索引');
        }

        if (!$table->hasIndex('pokemon_ball_machine_id')) {
            $table->addIndex('pokemon_ball_machine_id', [
                'name' => 'idx_lottery_pokemon_ball_machine_id',
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
        $table = $this->table('lottery');

        if ($table->hasColumn('pokemon_ball_machine_id')) {
            $table->removeColumn('pokemon_ball_machine_id')->save();
            $this->output->writeln('   ✅ 已移除 pokemon_ball_machine_id 字段');
        }

        if ($table->hasColumn('pokemon_ball_status')) {
            $table->removeColumn('pokemon_ball_status')->save();
            $this->output->writeln('   ✅ 已移除 pokemon_ball_status 字段');
        }
    }
}
