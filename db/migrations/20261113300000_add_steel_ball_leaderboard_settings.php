<?php

use Phinx\Migration\AbstractMigration;

/**
 * 播種鋼珠排行榜 system_setting 配置（4 條：全站/各店 × 週/月）
 *
 * 計分來源：machine_lottery_record.lottery_win（鋼珠機台中獎珠數）
 * Code 前綴 steel_ball_ 供 LeaderboardService 識別並切換專用查詢
 */
class AddSteelBallLeaderboardSettings extends AbstractMigration
{
    private array $settings = [
        [
            'feature' => 'leaderboard_steel_ball_global_weekly',
            'content' => [
                'code'        => 'steel_ball_global_weekly',
                'name'        => '金樽鋼珠週排行',
                'period_type' => 1,
                'scope_type'  => 1,
                'threshold'   => 0,
                'automatic'   => 0,
                'prizes'      => [
                    ['rank' => 1, 'amount' => 8888],
                    ['rank' => 2, 'amount' => 5888],
                    ['rank' => 3, 'amount' => 2888],
                ],
            ],
        ],
        [
            'feature' => 'leaderboard_steel_ball_store_weekly',
            'content' => [
                'code'        => 'steel_ball_store_weekly',
                'name'        => '各店鋼珠週排行',
                'period_type' => 1,
                'scope_type'  => 3,
                'threshold'   => 0,
                'automatic'   => 0,
                'prizes'      => [
                    ['rank' => 1, 'amount' => 3888],
                    ['rank' => 2, 'amount' => 2388],
                    ['rank' => 3, 'amount' => 1388],
                ],
            ],
        ],
        [
            'feature' => 'leaderboard_steel_ball_global_monthly',
            'content' => [
                'code'        => 'steel_ball_global_monthly',
                'name'        => '金樽鋼珠月排行',
                'period_type' => 2,
                'scope_type'  => 1,
                'threshold'   => 0,
                'automatic'   => 0,
                'prizes'      => [
                    ['rank' => 1, 'amount' => 28888],
                    ['rank' => 2, 'amount' => 18888],
                    ['rank' => 3, 'amount' => 8888],
                ],
            ],
        ],
    ];

    public function up(): void
    {
        $table = 'system_setting';
        $now = date('Y-m-d H:i:s');
        $count = 0;

        foreach ($this->settings as $item) {
            $exists = $this->fetchRow(
                "SELECT id FROM {$table} WHERE feature = ? AND department_id = 0 LIMIT 1",
                [$item['feature']]
            );
            if ($exists) {
                continue;
            }

            $content = json_encode($item['content'], JSON_UNESCAPED_UNICODE);
            $feature = $item['feature'];

            $this->execute(
                "INSERT INTO {$table} (department_id, feature, content, status, created_at, updated_at)
                 VALUES (0, '{$feature}', ?, 0, '{$now}', '{$now}')",
                [$content]
            );
            $count++;
        }

        echo "成功插入 {$count} 條鋼珠排行榜 system_setting 配置（status=0 默認關閉）\n";
    }

    public function down(): void
    {
        $features = array_column($this->settings, 'feature');
        $in = implode("','", $features);
        $this->execute("DELETE FROM system_setting WHERE feature IN ('{$in}') AND department_id = 0");
        echo "已刪除鋼珠排行榜 system_setting 配置\n";
    }
}
