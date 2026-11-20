<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * vip_level 表新增每日奖励领取打码量字段
 *
 * 用途：领取每日登入奖励需要满足的昨日打码量。
 * 0 = 不限制，>0 = 昨日打码量达到该值才可领取每日登入奖励。
 */
final class AddDailyLoginBonusBetAmountToVipLevel extends AbstractMigration
{
    public function up(): void
    {
        $this->table('vip_level')
            ->addColumn('daily_login_bonus_bet_amount', 'decimal', [
                'precision' => 20,
                'scale'     => 2,
                'null'      => false,
                'default'   => 0,
                'comment'   => '每日奖励领取打码量(领取每日登入奖励需满足的昨日打码量)',
                'after'     => 'daily_login_bonus',
            ])
            ->save();

        echo "✓ vip_level.daily_login_bonus_bet_amount 字段添加完成\n";
    }

    public function down(): void
    {
        $this->table('vip_level')
            ->removeColumn('daily_login_bonus_bet_amount')
            ->save();

        echo "✓ vip_level.daily_login_bonus_bet_amount 字段已回滚\n";
    }
}
