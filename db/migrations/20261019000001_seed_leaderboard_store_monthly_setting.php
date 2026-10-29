<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 新增「各店月排行」榜單設定（system_setting）
 *
 * 門檻與獎金預設比照門市週榜（threshold=1000000、5888/3888/1888），
 * 之後可於後台排行榜設定調整。
 */
final class SeedLeaderboardStoreMonthlySetting extends AbstractMigration
{
    /** 設定 feature 代碼 */
    private const FEATURE = 'leaderboard_store_monthly';

    /** 榜單設定內容（period_type=2 月榜、scope_type=3 店內各自） */
    private const CONTENT = '{"code":"store_monthly","name":"各店月排行","period_type":2,"scope_type":3,"threshold":1000000,"prizes":[{"rank":1,"amount":5888},{"rank":2,"amount":3888},{"rank":3,"amount":1888}]}';

    public function up(): void
    {
        $exists = $this->fetchRow(
            "SELECT id FROM system_setting WHERE department_id = 0 AND feature = '" . self::FEATURE . "' LIMIT 1"
        );
        if ($exists) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->table('system_setting')->insert([
            [
                'department_id' => 0,
                'feature'       => self::FEATURE,
                'num'           => 0,
                'content'       => self::CONTENT,
                'status'        => 1,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
        ])->saveData();
    }

    public function down(): void
    {
        $this->execute(
            "DELETE FROM system_setting WHERE department_id = 0 AND feature = '" . self::FEATURE . "'"
        );
    }
}
