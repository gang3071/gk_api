<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加玩家积分管理菜单（主站/渠道/代理/门店）
 *
 * 菜单结构：
 * - 积分变动记录 (在玩家列表中通过操作按钮调用)
 *
 * @author Claude Code
 * @date 2026-09-08
 */
final class AddPlayerPointsMenus extends AbstractMigration
{
    /**
     * 菜单类型常量
     */
    private const TYPE_MAIN = 1;     // 主站后台
    private const TYPE_CHANNEL = 2;  // 渠道后台
    private const TYPE_AGENT = 3;    // 代理后台
    private const TYPE_STORE = 4;    // 门店后台

    /**
     * 菜单定义
     */
    private const MENUS = [
        // ==================== 主站后台 ====================
        [
            'name' => 'player_points_records',
            'icon' => 'far fa-circle',
            'url' => 'ex-admin/addons-webman-controller-PlayerPointsController/index',
            'pid' => 0,  // 需要找到 player_manage 的ID
            'sort' => 100,  // 放在玩家管理的最后
            'status' => 1,
            'open' => 1,
            'type' => self::TYPE_MAIN,
            'parent_name' => 'player_manage',  // 用于查找父菜单
        ],

        // ==================== 渠道后台 ====================
        [
            'name' => 'player_points_records',
            'icon' => 'far fa-circle',
            'url' => 'ex-admin/addons-webman-controller-PlayerPointsController/index',
            'pid' => 0,  // 需要找到 channel_player_manage 的ID
            'sort' => 100,
            'status' => 1,
            'open' => 1,
            'type' => self::TYPE_CHANNEL,
            'parent_name' => 'channel_player_manage',
        ],

        // ==================== 代理后台 ====================
        [
            'name' => 'player_points_records',
            'icon' => 'far fa-circle',
            'url' => 'ex-admin/addons-webman-controller-PlayerPointsController/index',
            'pid' => 0,  // 需要找到 agent_player_manage 的ID
            'sort' => 100,
            'status' => 1,
            'open' => 1,
            'type' => self::TYPE_AGENT,
            'parent_name' => 'agent_player_manage',
        ],

        // ==================== 门店后台 ====================
        [
            'name' => 'player_points_records',
            'icon' => 'far fa-circle',
            'url' => 'ex-admin/addons-webman-controller-PlayerPointsController/index',
            'pid' => 0,  // 需要找到 store_player_manage 的ID
            'sort' => 100,
            'status' => 1,
            'open' => 1,
            'type' => self::TYPE_STORE,
            'parent_name' => 'store_player_manage',
        ],
    ];

    /**
     * Migrate Up.
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::MENUS as $menu) {
            // 检查菜单是否已存在
            $existing = $this->query(
                "SELECT id FROM admin_menus WHERE name = '{$menu['name']}' AND type = {$menu['type']} LIMIT 1"
            )->fetch();

            if (!$existing) {
                // 查找父菜单ID
                $parentId = $menu['pid'];
                if (isset($menu['parent_name'])) {
                    $parent = $this->query(
                        "SELECT id FROM admin_menus WHERE name = '{$menu['parent_name']}' AND type = {$menu['type']} LIMIT 1"
                    )->fetch();

                    if ($parent) {
                        $parentId = $parent['id'];
                    } else {
                        echo "⚠ 警告：找不到父菜单 {$menu['parent_name']} (type={$menu['type']})，跳过 {$menu['name']}\n";
                        continue;
                    }
                }

                // 插入菜单
                $this->execute("
                    INSERT INTO admin_menus (name, icon, url, plugin, pid, sort, status, open, type, created_at, updated_at)
                    VALUES (
                        '{$menu['name']}',
                        '{$menu['icon']}',
                        '{$menu['url']}',
                        '',
                        {$parentId},
                        {$menu['sort']},
                        {$menu['status']},
                        {$menu['open']},
                        {$menu['type']},
                        '{$now}',
                        '{$now}'
                    )
                ");

                $typeName = $this->getTypeName($menu['type']);
                echo "✓ 菜单创建成功: {$menu['name']} ({$typeName})\n";
            } else {
                $typeName = $this->getTypeName($menu['type']);
                echo "- 菜单已存在: {$menu['name']} ({$typeName})\n";
            }
        }

        echo "\n✅ 玩家积分菜单迁移完成！\n";
        echo "📝 说明：积分管理功能集成在各后台的玩家列表中\n";
        echo "   - 列表显示：可用积分、冻结积分、总积分\n";
        echo "   - 操作按钮：增加积分、扣除积分、冻结积分、积分记录\n";
        echo "⚠️  注意：需要在角色管理中为相应角色分配菜单权限\n";
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        // 删除所有类型的积分菜单
        foreach ([self::TYPE_MAIN, self::TYPE_CHANNEL, self::TYPE_AGENT, self::TYPE_STORE] as $type) {
            $this->execute("
                DELETE FROM admin_menus
                WHERE name = 'player_points_records'
                  AND type = {$type}
            ");

            $typeName = $this->getTypeName($type);
            echo "✓ 菜单已删除: player_points_records ({$typeName})\n";
        }

        // 清理孤立的角色菜单关联
        $this->execute("
            DELETE FROM admin_role_menus
            WHERE menu_id NOT IN (SELECT id FROM admin_menus)
        ");

        echo "\n✅ 玩家积分菜单回滚完成！\n";
    }

    /**
     * 获取类型名称
     */
    private function getTypeName(int $type): string
    {
        $names = [
            self::TYPE_MAIN => '主站',
            self::TYPE_CHANNEL => '渠道',
            self::TYPE_AGENT => '代理',
            self::TYPE_STORE => '门店',
        ];

        return $names[$type] ?? '未知';
    }
}
