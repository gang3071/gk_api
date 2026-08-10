<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加 wallet_locked 字段到玩家钱包表
 *
 * 用于福利卷/体验卷使用后的钱包锁定功能
 * 锁定期间无法开分/上分，余额低于配置值或下分后自动解锁
 *
 * @date 2026-08-07
 */
class AddWalletLockedToPlayerPlatformCash extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change()
    {
        $table = $this->table('player_platform_cash');

        // 添加 wallet_locked 字段
        $table->addColumn('wallet_locked', 'integer', [
                'null' => false,
                'default' => 0,
                'signed' => false,
                'after' => 'is_crashed',
                'comment' => '钱包锁定状态 0=未锁定 1=锁定',
            ])
            ->addIndex(['wallet_locked'], ['name' => 'idx_wallet_locked'])
            ->update();
    }
}
