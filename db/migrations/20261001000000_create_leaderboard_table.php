<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * 建立排行榜期別表
 */
final class CreateLeaderboardTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('leaderboard', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '排行榜期別表',
        ]);

        $table
            ->addColumn('id', 'biginteger', [
                'identity' => true,
                'signed' => false,
                'comment' => '主鍵',
            ])
            ->addColumn('code', 'string', [
                'limit' => 50,
                'null' => false,
                'comment' => '榜單代碼：golden_weekly/golden_monthly/store_weekly',
            ])
            ->addColumn('name', 'string', [
                'limit' => 100,
                'null' => false,
                'comment' => '榜單名稱（結算時快照）',
            ])
            ->addColumn('period_type', 'integer', [
                'limit' => MysqlAdapter::INT_TINY,
                'null' => false,
                'default' => 1,
                'comment' => '週期：1=週榜 2=月榜',
            ])
            ->addColumn('scope_type', 'integer', [
                'limit' => MysqlAdapter::INT_TINY,
                'null' => false,
                'default' => 1,
                'comment' => '排名範圍：1=全站 3=店家(店內各自)',
            ])
            ->addColumn('period_key', 'string', [
                'limit' => 20,
                'null' => false,
                'comment' => '期別標識：週=起始週一日期(2026-08-31)、月=2026-08',
            ])
            ->addColumn('start_at', 'datetime', [
                'null' => false,
                'comment' => '期開始（週一08:00 / 每月1號08:00）',
            ])
            ->addColumn('end_at', 'datetime', [
                'null' => false,
                'comment' => '期結束（下週一08:00 / 下月1號08:00）',
            ])
            ->addColumn('settle_at', 'datetime', [
                'null' => true,
                'comment' => '結算完成時間（NULL=未結算）',
            ])
            ->addColumn('threshold', 'decimal', [
                'precision' => 18,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
                'comment' => '門檻分數（0=不限，結算時快照）',
            ])
            ->addColumn('prize_config', 'json', [
                'null' => true,
                'comment' => '獎勵金額設定（結算時快照）',
            ])
            ->addColumn('entry_count', 'integer', [
                'null' => false,
                'default' => 0,
                'comment' => '本期名次筆數',
            ])
            ->addColumn('created_at', 'datetime', [
                'null' => true,
                'comment' => '建立時間',
            ])
            ->addColumn('updated_at', 'datetime', [
                'null' => true,
                'comment' => '更新時間',
            ])
            ->addIndex(['code', 'period_key'], ['unique' => true, 'name' => 'uk_code_period'])
            ->addIndex(['period_type', 'start_at', 'end_at'], ['name' => 'idx_period'])
            ->create();
    }
}
