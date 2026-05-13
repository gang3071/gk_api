<?php

use Phinx\Migration\AbstractMigration;

class AddT9slotAccountForPlayer83 extends AbstractMigration
{
    /**
     * 为玩家ID 83创建T9SLOT平台账号记录
     *
     * 问题：T9SLOT的API请求延迟导致checkPlayer()中的save()没有执行
     * 解决：手动插入缺失的账号记录
     */
    public function up()
    {
        // 查询T9SLOT平台ID
        $platform = $this->fetchRow("SELECT id FROM game_platform WHERE code = 'TNINE_SLOT' LIMIT 1");

        if (!$platform) {
            echo "错误：未找到T9SLOT平台配置\n";
            return;
        }

        $platformId = $platform['id'];

        // 查询玩家信息
        $player = $this->fetchRow("SELECT id, uuid, name, department_id FROM player WHERE id = 83 LIMIT 1");

        if (!$player) {
            echo "错误：未找到玩家ID 83\n";
            return;
        }

        // 查询web_id（从channel_game_web表，根据channel_id和platform_id）
        $channelId = $player['department_id']; // department_id即为channel_id
        $channelGameWeb = $this->fetchRow(
            "SELECT web_id FROM channel_game_web
             WHERE channel_id = {$channelId} AND platform_id = {$platformId}
             LIMIT 1"
        );
        $webId = $channelGameWeb ? $channelGameWeb['web_id'] : 'super9';

        // 检查是否已存在记录
        $existing = $this->fetchRow(
            "SELECT id FROM player_game_platform
             WHERE player_id = 83 AND platform_id = {$platformId}
             AND deleted_at IS NULL
             LIMIT 1"
        );

        if ($existing) {
            echo "玩家ID 83在T9SLOT平台已有账号记录，跳过\n";
            return;
        }

        // 插入账号记录
        $now = date('Y-m-d H:i:s');

        // 对webId进行正确的SQL转义
        $webIdValue = is_numeric($webId) ? $webId : "'{$webId}'";

        $this->execute("
            INSERT INTO player_game_platform (
                player_id,
                platform_id,
                player_name,
                player_code,
                web_id,
                player_password,
                created_at,
                updated_at
            ) VALUES (
                83,
                {$platformId},
                '{$player['name']}',
                '{$player['uuid']}',
                {$webIdValue},
                '',
                '{$now}',
                '{$now}'
            )
        ");

        echo "✅ 已为玩家ID 83 ({$player['name']}) 创建T9SLOT平台账号记录\n";
        echo "   - Platform ID: {$platformId}\n";
        echo "   - Player Code: {$player['uuid']}\n";
        echo "   - Web ID: {$webId}\n";
    }

    public function down()
    {
        // 查询T9SLOT平台ID
        $platform = $this->fetchRow("SELECT id FROM game_platform WHERE code = 'TNINE_SLOT' LIMIT 1");

        if (!$platform) {
            return;
        }

        $platformId = $platform['id'];

        // 删除记录
        $this->execute("
            DELETE FROM player_game_platform
            WHERE player_id = 83 AND platform_id = {$platformId}
        ");

        echo "已删除玩家ID 83的T9SLOT平台账号记录\n";
    }
}
