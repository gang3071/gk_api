<?php

use Phinx\Migration\AbstractMigration;

class AddDailyLoginBonusToVipLevel extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        // VIP等级表新增每日登录奖励字段
        $table = $this->table('vip_level');

        $table->addColumn('daily_login_bonus', 'decimal', [
            'precision' => 20,
            'scale' => 2,
            'default' => 0,
            'comment' => '每日登录奖励',
            'after' => 'upgrade_bonus',
        ]);

        $table->save();
    }
}
