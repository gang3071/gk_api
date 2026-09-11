<?php

use Phinx\Migration\AbstractMigration;

/**
 * 精靈球配置欄位遷移
 * - game_lottery 新增 pokemon_ball_status（精靈球開關）
 * - game_lottery 新增 pokemon_ball_machine_id（綁定的精靈球機台ID）
 */
class AddPokemonBallToGameLottery extends AbstractMigration
{
    public function up()
    {
        $table = $this->table('game_lottery');

        if (!$table->hasColumn('pokemon_ball_status')) {
            $table->addColumn('pokemon_ball_status', 'boolean', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '精靈球開關 0=關閉 1=開啟',
                'after' => 'auto_refill_amount',
            ])->save();
        }

        if (!$table->hasColumn('pokemon_ball_machine_id')) {
            $table->addColumn('pokemon_ball_machine_id', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '綁定的精靈球機台ID',
                'after' => 'pokemon_ball_status',
            ])->save();
        }
    }

    public function down()
    {
        $table = $this->table('game_lottery');

        if ($table->hasColumn('pokemon_ball_machine_id')) {
            $table->removeColumn('pokemon_ball_machine_id')->save();
        }

        if ($table->hasColumn('pokemon_ball_status')) {
            $table->removeColumn('pokemon_ball_status')->save();
        }
    }
}
