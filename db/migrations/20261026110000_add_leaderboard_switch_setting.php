<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 为所有渠道添加「排行榜开关」配置（system_setting）
 *
 * feature = leaderboard_switch，按渠道 department_id 各建一笔，
 * status=1 默认开启，可在渠道后台系统配置中切换。
 */
final class AddLeaderboardSwitchSetting extends AbstractMigration
{
    /** 设定 feature 代码 */
    private const FEATURE = 'leaderboard_switch';

    public function up(): void
    {
        $this->execute("
            INSERT INTO `system_setting`
                (`department_id`, `feature`, `num`, `content`, `date_start`, `date_end`, `status`, `created_at`, `updated_at`)
            SELECT DISTINCT
                c.`department_id`,
                '" . self::FEATURE . "',
                0,
                '',
                '',
                '',
                1,
                NOW(),
                NOW()
            FROM `channel` c
            WHERE c.`deleted_at` IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM `system_setting` s
                  WHERE s.`department_id` = c.`department_id`
                    AND s.`feature` = '" . self::FEATURE . "'
              )
        ");

        $row = $this->fetchRow(
            "SELECT COUNT(*) AS c FROM `system_setting` WHERE `feature` = '" . self::FEATURE . "'"
        );
        $this->output->writeln('✓ 排行榜开关配置完成，共 ' . (int)($row['c'] ?? 0) . ' 笔');
    }

    public function down(): void
    {
        $this->execute(
            "DELETE FROM `system_setting` WHERE `feature` = '" . self::FEATURE . "'"
        );
        $this->output->writeln('✓ 已删除所有排行榜开关配置');
    }
}
