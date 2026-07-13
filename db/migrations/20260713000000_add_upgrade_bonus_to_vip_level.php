<?php

use Phinx\Migration\AbstractMigration;

class AddUpgradeBonusToVipLevel extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        // VIP等级表新增升级礼金字段
        $table = $this->table('vip_level');

        $table->addColumn('upgrade_bonus', 'decimal', [
            'precision' => 20,
            'scale' => 2,
            'default' => 0,
            'comment' => '升级礼金',
            'after' => 'birthday_bonus',
        ]);

        $table->save();
    }
}
