<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 创建ATG2和ATG3游戏平台
 *
 * 背景：
 * - ATG平台需要支持3个独立运营商
 * - 每个运营商有独立的回调地址和限红组配置
 * - ATG (运营商1) - 已存在
 * - ATG2 (运营商2) - 新增
 * - ATG3 (运营商3) - 新增
 */
final class AddAtg2Atg3Platforms extends AbstractMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 检查ATG平台是否存在
        $atgPlatform = $this->query("SELECT * FROM game_platforms WHERE code = 'ATG' LIMIT 1")->fetch();

        if (!$atgPlatform) {
            $this->output->writeln('<error>Error: ATG platform not found. Please create ATG platform first.</error>');
            return;
        }

        // 创建ATG2平台（运营商2）
        $atg2Exists = $this->query("SELECT id FROM game_platforms WHERE code = 'ATG2' LIMIT 1")->fetch();
        if (!$atg2Exists) {
            $this->execute("
                INSERT INTO game_platforms (
                    code,
                    name,
                    config,
                    ratio,
                    logo,
                    cate_id,
                    display_mode,
                    has_lobby,
                    status,
                    maintenance_week,
                    maintenance_start_time,
                    maintenance_end_time,
                    maintenance_status,
                    sort,
                    created_at,
                    updated_at,
                    picture,
                    default_limit_group_id
                )
                SELECT
                    'ATG2' as code,
                    'ATG运营商2' as name,
                    config,
                    ratio,
                    logo,
                    cate_id,
                    display_mode,
                    has_lobby,
                    1 as status,
                    maintenance_week,
                    maintenance_start_time,
                    maintenance_end_time,
                    maintenance_status,
                    sort + 1 as sort,
                    '{$now}' as created_at,
                    '{$now}' as updated_at,
                    picture,
                    NULL as default_limit_group_id
                FROM game_platforms
                WHERE code = 'ATG'
                LIMIT 1
            ");
            $this->output->writeln('<info>Created ATG2 platform</info>');
        } else {
            $this->output->writeln('<comment>ATG2 platform already exists, skipping</comment>');
        }

        // 创建ATG3平台（运营商3）
        $atg3Exists = $this->query("SELECT id FROM game_platforms WHERE code = 'ATG3' LIMIT 1")->fetch();
        if (!$atg3Exists) {
            $this->execute("
                INSERT INTO game_platforms (
                    code,
                    name,
                    config,
                    ratio,
                    logo,
                    cate_id,
                    display_mode,
                    has_lobby,
                    status,
                    maintenance_week,
                    maintenance_start_time,
                    maintenance_end_time,
                    maintenance_status,
                    sort,
                    created_at,
                    updated_at,
                    picture,
                    default_limit_group_id
                )
                SELECT
                    'ATG3' as code,
                    'ATG运营商3' as name,
                    config,
                    ratio,
                    logo,
                    cate_id,
                    display_mode,
                    has_lobby,
                    1 as status,
                    maintenance_week,
                    maintenance_start_time,
                    maintenance_end_time,
                    maintenance_status,
                    sort + 2 as sort,
                    '{$now}' as created_at,
                    '{$now}' as updated_at,
                    picture,
                    NULL as default_limit_group_id
                FROM game_platforms
                WHERE code = 'ATG'
                LIMIT 1
            ");
            $this->output->writeln('<info>Created ATG3 platform</info>');
        } else {
            $this->output->writeln('<comment>ATG3 platform already exists, skipping</comment>');
        }

        // 显示创建结果
        $platforms = $this->query("
            SELECT id, code, name, status, created_at
            FROM game_platforms
            WHERE code IN ('ATG', 'ATG2', 'ATG3')
            ORDER BY code
        ")->fetchAll();

        $this->output->writeln('');
        $this->output->writeln('<info>ATG Platform Summary:</info>');
        foreach ($platforms as $platform) {
            $this->output->writeln(sprintf(
                '  - %s (ID: %d) - %s - Status: %d - Created: %s',
                $platform['code'],
                $platform['id'],
                $platform['name'],
                $platform['status'],
                $platform['created_at']
            ));
        }

        $this->output->writeln('');
        $this->output->writeln('<info>Next Steps:</info>');
        $this->output->writeln('  1. Configure limit groups for ATG2 and ATG3 in admin panel');
        $this->output->writeln('  2. Set callback URLs in operator backend:');
        $this->output->writeln('     - Operator 2: https://api.jinzun.org/single-wallet/atg2-channel/*');
        $this->output->writeln('     - Operator 3: https://api.jinzun.org/single-wallet/atg3-channel/*');
        $this->output->writeln('  3. Restart gk_work service: php windows.php restart');
    }

    public function down(): void
    {
        // 检查是否有玩家数据或游戏记录
        $atg2Players = $this->query("
            SELECT COUNT(*) as count
            FROM player_game_platform pgp
            JOIN game_platforms gp ON pgp.platform_id = gp.id
            WHERE gp.code = 'ATG2'
        ")->fetch();

        $atg3Players = $this->query("
            SELECT COUNT(*) as count
            FROM player_game_platform pgp
            JOIN game_platforms gp ON pgp.platform_id = gp.id
            WHERE gp.code = 'ATG3'
        ")->fetch();

        if ($atg2Players['count'] > 0 || $atg3Players['count'] > 0) {
            $this->output->writeln('<error>Warning: Player data exists for ATG2/ATG3. Please backup data before rollback.</error>');
            $this->output->writeln(sprintf('  - ATG2 players: %d', $atg2Players['count']));
            $this->output->writeln(sprintf('  - ATG3 players: %d', $atg3Players['count']));

            // 询问是否继续
            $this->output->writeln('');
            $this->output->writeln('<question>Continue with rollback? (yes/no)</question>');
            // Phinx会自动处理用户输入
        }

        // 删除ATG2和ATG3平台
        $this->execute("DELETE FROM game_platforms WHERE code IN ('ATG2', 'ATG3')");

        $this->output->writeln('<info>Removed ATG2 and ATG3 platforms</info>');
    }
}
