<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 同步ATG游戏到ATG2和ATG3
 *
 * 背景：
 * - ATG、ATG2、ATG3使用相同的游戏API
 * - 需要将ATG平台的游戏列表同步到ATG2和ATG3
 * - 同步范围：game_extend, game, game_content 三个表
 */
final class SyncAtgGamesToAtg2Atg3 extends AbstractMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 获取平台ID
        $platforms = $this->fetchAll("
            SELECT id, code FROM game_platform
            WHERE code IN ('ATG', 'ATG2', 'ATG3')
            ORDER BY code
        ");

        $platformMap = [];
        foreach ($platforms as $platform) {
            $platformMap[$platform['code']] = $platform['id'];
        }

        if (!isset($platformMap['ATG'])) {
            $this->output->writeln('<error>Error: ATG platform not found</error>');
            return;
        }

        if (!isset($platformMap['ATG2']) || !isset($platformMap['ATG3'])) {
            $this->output->writeln('<error>Error: ATG2 or ATG3 platform not found. Please run 20260708000000_add_atg2_atg3_platforms.php first</error>');
            return;
        }

        $atgId = $platformMap['ATG'];
        $atg2Id = $platformMap['ATG2'];
        $atg3Id = $platformMap['ATG3'];

        $this->output->writeln(sprintf('<info>Platform IDs: ATG=%d, ATG2=%d, ATG3=%d</info>', $atgId, $atg2Id, $atg3Id));

        // ========== 预检查：查看现有数据 ==========
        $this->output->writeln('');
        $this->output->writeln('<info>Pre-check: Existing data...</info>');

        $existingData = $this->fetchAll("
            SELECT
                gp.code,
                COUNT(DISTINCT ge.id) as game_extend_count,
                COUNT(DISTINCT g.id) as game_count,
                COUNT(DISTINCT gc.id) as game_content_count
            FROM game_platform gp
            LEFT JOIN game_extend ge ON ge.platform_id = gp.id
            LEFT JOIN game g ON g.platform_id = gp.id AND g.deleted_at IS NULL
            LEFT JOIN game_content gc ON gc.game_id = g.id
            WHERE gp.code IN ('ATG', 'ATG2', 'ATG3')
            GROUP BY gp.code
            ORDER BY gp.code
        ");

        foreach ($existingData as $row) {
            $this->output->writeln(sprintf(
                '  %s: game_extend=%d, game=%d, game_content=%d',
                $row['code'],
                $row['game_extend_count'],
                $row['game_count'],
                $row['game_content_count']
            ));
        }

        // ========== 清理现有的ATG2/ATG3数据 ==========
        $atg2ExistingExtend = $this->fetchRow("SELECT COUNT(*) as count FROM game_extend WHERE platform_id = {$atg2Id}");
        $atg3ExistingExtend = $this->fetchRow("SELECT COUNT(*) as count FROM game_extend WHERE platform_id = {$atg3Id}");

        if ($atg2ExistingExtend['count'] > 0 || $atg3ExistingExtend['count'] > 0) {
            $this->output->writeln('');
            $this->output->writeln('<comment>Found existing ATG2/ATG3 data, cleaning up before sync...</comment>');

            // 删除 game_content（必须先删除，因为有外键）
            $this->execute("
                DELETE gc FROM game_content gc
                JOIN game g ON gc.game_id = g.id
                WHERE g.platform_id IN ({$atg2Id}, {$atg3Id})
            ");
            $this->output->writeln('  ✓ Cleaned game_content');

            // 删除 game
            $this->execute("DELETE FROM game WHERE platform_id IN ({$atg2Id}, {$atg3Id})");
            $this->output->writeln('  ✓ Cleaned game');

            // 删除 game_extend
            $this->execute("DELETE FROM game_extend WHERE platform_id IN ({$atg2Id}, {$atg3Id})");
            $this->output->writeln('  ✓ Cleaned game_extend');
        } else {
            $this->output->writeln('');
            $this->output->writeln('<info>No existing ATG2/ATG3 data found, proceeding with fresh sync...</info>');
        }

        // ========== 1. 同步 game_extend 表（扩展游戏库） ==========
        $this->output->writeln('');
        $this->output->writeln('<info>Step 1: Syncing game_extend (扩展游戏库)...</info>');

        // 统计ATG的game_extend数量
        $atgExtendCount = $this->fetchRow("
            SELECT COUNT(*) as count FROM game_extend WHERE platform_id = {$atgId}
        ");
        $this->output->writeln(sprintf('  - ATG has %d games in game_extend', $atgExtendCount['count']));

        // 同步到ATG2
        $this->execute("
            INSERT INTO game_extend (
                platform_id,
                cate_id,
                name,
                code,
                logo,
                is_new,
                is_hot,
                status,
                org_data,
                game_id,
                table_name,
                created_at,
                updated_at
            )
            SELECT
                {$atg2Id} as platform_id,
                cate_id,
                name,
                code,
                logo,
                is_new,
                is_hot,
                status,
                org_data,
                game_id,
                table_name,
                '{$now}' as created_at,
                '{$now}' as updated_at
            FROM game_extend
            WHERE platform_id = {$atgId}
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                logo = VALUES(logo),
                is_new = VALUES(is_new),
                is_hot = VALUES(is_hot),
                status = VALUES(status),
                org_data = VALUES(org_data),
                updated_at = '{$now}'
        ");

        // 同步到ATG3
        $this->execute("
            INSERT INTO game_extend (
                platform_id,
                cate_id,
                name,
                code,
                logo,
                is_new,
                is_hot,
                status,
                org_data,
                game_id,
                table_name,
                created_at,
                updated_at
            )
            SELECT
                {$atg3Id} as platform_id,
                cate_id,
                name,
                code,
                logo,
                is_new,
                is_hot,
                status,
                org_data,
                game_id,
                table_name,
                '{$now}' as created_at,
                '{$now}' as updated_at
            FROM game_extend
            WHERE platform_id = {$atgId}
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                logo = VALUES(logo),
                is_new = VALUES(is_new),
                is_hot = VALUES(is_hot),
                status = VALUES(status),
                org_data = VALUES(org_data),
                updated_at = '{$now}'
        ");

        // 统计同步结果
        $atg2ExtendCount = $this->fetchRow("SELECT COUNT(*) as count FROM game_extend WHERE platform_id = {$atg2Id}");
        $atg3ExtendCount = $this->fetchRow("SELECT COUNT(*) as count FROM game_extend WHERE platform_id = {$atg3Id}");

        $this->output->writeln(sprintf('  ✓ ATG2 now has %d games in game_extend', $atg2ExtendCount['count']));
        $this->output->writeln(sprintf('  ✓ ATG3 now has %d games in game_extend', $atg3ExtendCount['count']));

        // ========== 2. 同步 game 表（正式游戏） ==========
        $this->output->writeln('');
        $this->output->writeln('<info>Step 2: Syncing game (正式游戏)...</info>');

        // 统计ATG的game数量
        $atgGameCount = $this->fetchRow("
            SELECT COUNT(*) as count FROM game WHERE platform_id = {$atgId} AND deleted_at IS NULL
        ");
        $this->output->writeln(sprintf('  - ATG has %d games in game table', $atgGameCount['count']));

        // 为ATG2和ATG3的game创建game_extend_id映射
        // 策略：通过code字段匹配对应的game_extend记录

        // 同步到ATG2
        $this->execute("
            INSERT INTO game (
                platform_id,
                cate_id,
                game_extend_id,
                status,
                sort,
                is_new,
                is_hot,
                is_ios,
                display_mode,
                channel_hidden,
                created_at,
                updated_at
            )
            SELECT
                {$atg2Id} as platform_id,
                g.cate_id,
                ge2.id as game_extend_id,
                g.status,
                g.sort,
                g.is_new,
                g.is_hot,
                g.is_ios,
                g.display_mode,
                g.channel_hidden,
                '{$now}' as created_at,
                '{$now}' as updated_at
            FROM game g
            JOIN game_extend ge1 ON g.game_extend_id = ge1.id AND ge1.platform_id = {$atgId}
            JOIN game_extend ge2 ON ge2.code = ge1.code AND ge2.platform_id = {$atg2Id}
            WHERE g.platform_id = {$atgId} AND g.deleted_at IS NULL
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                sort = VALUES(sort),
                is_new = VALUES(is_new),
                is_hot = VALUES(is_hot),
                is_ios = VALUES(is_ios),
                display_mode = VALUES(display_mode),
                updated_at = '{$now}'
        ");

        // 同步到ATG3
        $this->execute("
            INSERT INTO game (
                platform_id,
                cate_id,
                game_extend_id,
                status,
                sort,
                is_new,
                is_hot,
                is_ios,
                display_mode,
                channel_hidden,
                created_at,
                updated_at
            )
            SELECT
                {$atg3Id} as platform_id,
                g.cate_id,
                ge3.id as game_extend_id,
                g.status,
                g.sort,
                g.is_new,
                g.is_hot,
                g.is_ios,
                g.display_mode,
                g.channel_hidden,
                '{$now}' as created_at,
                '{$now}' as updated_at
            FROM game g
            JOIN game_extend ge1 ON g.game_extend_id = ge1.id AND ge1.platform_id = {$atgId}
            JOIN game_extend ge3 ON ge3.code = ge1.code AND ge3.platform_id = {$atg3Id}
            WHERE g.platform_id = {$atgId} AND g.deleted_at IS NULL
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                sort = VALUES(sort),
                is_new = VALUES(is_new),
                is_hot = VALUES(is_hot),
                is_ios = VALUES(is_ios),
                display_mode = VALUES(display_mode),
                updated_at = '{$now}'
        ");

        // 统计同步结果
        $atg2GameCount = $this->fetchRow("SELECT COUNT(*) as count FROM game WHERE platform_id = {$atg2Id} AND deleted_at IS NULL");
        $atg3GameCount = $this->fetchRow("SELECT COUNT(*) as count FROM game WHERE platform_id = {$atg3Id} AND deleted_at IS NULL");

        $this->output->writeln(sprintf('  ✓ ATG2 now has %d games in game table', $atg2GameCount['count']));
        $this->output->writeln(sprintf('  ✓ ATG3 now has %d games in game table', $atg3GameCount['count']));

        // ========== 3. 同步 game_content 表（游戏多语言内容） ==========
        $this->output->writeln('');
        $this->output->writeln('<info>Step 3: Syncing game_content (游戏多语言内容)...</info>');

        // 统计ATG的game_content数量
        $atgContentCount = $this->fetchRow("
            SELECT COUNT(*) as count
            FROM game_content gc
            JOIN game g ON gc.game_id = g.id
            WHERE g.platform_id = {$atgId} AND g.deleted_at IS NULL
        ");
        $this->output->writeln(sprintf('  - ATG has %d game_content records', $atgContentCount['count']));

        // 同步到ATG2
        $this->execute("
            INSERT INTO game_content (
                game_id,
                platform_id,
                name,
                lang,
                description,
                picture,
                created_at,
                updated_at
            )
            SELECT
                g2.id as game_id,
                {$atg2Id} as platform_id,
                gc.name,
                gc.lang,
                gc.description,
                gc.picture,
                '{$now}' as created_at,
                '{$now}' as updated_at
            FROM game_content gc
            JOIN game g1 ON gc.game_id = g1.id AND g1.platform_id = {$atgId} AND g1.deleted_at IS NULL
            JOIN game_extend ge1 ON g1.game_extend_id = ge1.id
            JOIN game_extend ge2 ON ge2.code = ge1.code AND ge2.platform_id = {$atg2Id}
            JOIN game g2 ON g2.game_extend_id = ge2.id AND g2.platform_id = {$atg2Id} AND g2.deleted_at IS NULL
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                description = VALUES(description),
                picture = VALUES(picture),
                updated_at = '{$now}'
        ");

        // 同步到ATG3
        $this->execute("
            INSERT INTO game_content (
                game_id,
                platform_id,
                name,
                lang,
                description,
                picture,
                created_at,
                updated_at
            )
            SELECT
                g3.id as game_id,
                {$atg3Id} as platform_id,
                gc.name,
                gc.lang,
                gc.description,
                gc.picture,
                '{$now}' as created_at,
                '{$now}' as updated_at
            FROM game_content gc
            JOIN game g1 ON gc.game_id = g1.id AND g1.platform_id = {$atgId} AND g1.deleted_at IS NULL
            JOIN game_extend ge1 ON g1.game_extend_id = ge1.id
            JOIN game_extend ge3 ON ge3.code = ge1.code AND ge3.platform_id = {$atg3Id}
            JOIN game g3 ON g3.game_extend_id = ge3.id AND g3.platform_id = {$atg3Id} AND g3.deleted_at IS NULL
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                description = VALUES(description),
                picture = VALUES(picture),
                updated_at = '{$now}'
        ");

        // 统计同步结果
        $atg2ContentCount = $this->fetchRow("
            SELECT COUNT(*) as count
            FROM game_content gc
            JOIN game g ON gc.game_id = g.id
            WHERE g.platform_id = {$atg2Id} AND g.deleted_at IS NULL
        ");
        $atg3ContentCount = $this->fetchRow("
            SELECT COUNT(*) as count
            FROM game_content gc
            JOIN game g ON gc.game_id = g.id
            WHERE g.platform_id = {$atg3Id} AND g.deleted_at IS NULL
        ");

        $this->output->writeln(sprintf('  ✓ ATG2 now has %d game_content records', $atg2ContentCount['count']));
        $this->output->writeln(sprintf('  ✓ ATG3 now has %d game_content records', $atg3ContentCount['count']));

        // ========== 总结 ==========
        $this->output->writeln('');
        $this->output->writeln('<info>Sync Summary:</info>');
        $this->output->writeln('┌─────────────┬──────────────┬────────┐');
        $this->output->writeln('│ Platform    │ Table        │ Count  │');
        $this->output->writeln('├─────────────┼──────────────┼────────┤');
        $this->output->writeln(sprintf('│ ATG         │ game_extend  │ %6d │', $atgExtendCount['count']));
        $this->output->writeln(sprintf('│ ATG2        │ game_extend  │ %6d │', $atg2ExtendCount['count']));
        $this->output->writeln(sprintf('│ ATG3        │ game_extend  │ %6d │', $atg3ExtendCount['count']));
        $this->output->writeln('├─────────────┼──────────────┼────────┤');
        $this->output->writeln(sprintf('│ ATG         │ game         │ %6d │', $atgGameCount['count']));
        $this->output->writeln(sprintf('│ ATG2        │ game         │ %6d │', $atg2GameCount['count']));
        $this->output->writeln(sprintf('│ ATG3        │ game         │ %6d │', $atg3GameCount['count']));
        $this->output->writeln('├─────────────┼──────────────┼────────┤');
        $this->output->writeln(sprintf('│ ATG         │ game_content │ %6d │', $atgContentCount['count']));
        $this->output->writeln(sprintf('│ ATG2        │ game_content │ %6d │', $atg2ContentCount['count']));
        $this->output->writeln(sprintf('│ ATG3        │ game_content │ %6d │', $atg3ContentCount['count']));
        $this->output->writeln('└─────────────┴──────────────┴────────┘');
        $this->output->writeln('');
        $this->output->writeln('<info>✓ Game sync completed successfully!</info>');
    }

    public function down(): void
    {
        // 获取平台ID
        $platforms = $this->fetchAll("
            SELECT id, code FROM game_platform
            WHERE code IN ('ATG2', 'ATG3')
            ORDER BY code
        ");

        $platformIds = [];
        foreach ($platforms as $platform) {
            $platformIds[] = $platform['id'];
        }

        if (empty($platformIds)) {
            $this->output->writeln('<comment>No ATG2/ATG3 platforms found, nothing to rollback</comment>');
            return;
        }

        $platformIdList = implode(',', $platformIds);

        // 删除game_content表中的ATG2和ATG3游戏内容
        $this->execute("
            DELETE gc FROM game_content gc
            JOIN game g ON gc.game_id = g.id
            WHERE g.platform_id IN ({$platformIdList})
        ");
        $this->output->writeln('<info>Removed game_content records</info>');

        // 删除game表中的ATG2和ATG3游戏
        $this->execute("DELETE FROM game WHERE platform_id IN ({$platformIdList})");
        $this->output->writeln('<info>Removed games from game table</info>');

        // 删除game_extend表中的ATG2和ATG3游戏
        $this->execute("DELETE FROM game_extend WHERE platform_id IN ({$platformIdList})");
        $this->output->writeln('<info>Removed games from game_extend table</info>');

        $this->output->writeln('<info>Rollback completed</info>');
    }
}
