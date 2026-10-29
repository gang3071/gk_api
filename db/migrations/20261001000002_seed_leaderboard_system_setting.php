<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 初始化排行榜榜單設定（system_setting）
 *
 * feature 以 leaderboard_ 開頭，department_id=0 為全站設定，content 為 JSON。
 */
final class SeedLeaderboardSystemSetting extends AbstractMigration
{
    /**
     * 榜單設定資料
     *
     * @var array<int, array{feature: string, content: string}>
     */
    private array $rows = [
        [
            'feature' => 'leaderboard_golden_weekly',
            'content' => '{"code":"golden_weekly","name":"金樽遊戲量週排行","period_type":1,"scope_type":1,"threshold":0,"prizes":[{"rank":1,"amount":8888},{"rank":2,"amount":5888},{"rank":3,"amount":2888}]}',
        ],
        [
            'feature' => 'leaderboard_store_weekly',
            'content' => '{"code":"store_weekly","name":"各店週排行","period_type":1,"scope_type":3,"threshold":1000000,"prizes":[{"rank":1,"amount":5888},{"rank":2,"amount":3888},{"rank":3,"amount":1888}]}',
        ],
        [
            'feature' => 'leaderboard_golden_monthly',
            'content' => '{"code":"golden_monthly","name":"金樽遊戲量月排行","period_type":2,"scope_type":1,"threshold":10000000,"prizes":[{"rank":1,"amount":38888},{"rank":2,"amount":28888},{"rank":3,"amount":18888}]}',
        ],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $insert = [];

        foreach ($this->rows as $row) {
            // 已存在則跳過（可重複執行）
            $exists = $this->fetchRow(
                "SELECT id FROM system_setting WHERE department_id = 0 AND feature = '{$row['feature']}' LIMIT 1"
            );
            if ($exists) {
                continue;
            }

            $insert[] = [
                'department_id' => 0,
                'feature'       => $row['feature'],
                'num'           => 0,
                'content'       => $row['content'],
                'status'        => 1,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        if (!empty($insert)) {
            $this->table('system_setting')->insert($insert)->saveData();
        }
    }

    public function down(): void
    {
        $this->execute(
            "DELETE FROM system_setting
             WHERE department_id = 0
               AND feature IN ('leaderboard_golden_weekly', 'leaderboard_store_weekly', 'leaderboard_golden_monthly')"
        );
    }
}
