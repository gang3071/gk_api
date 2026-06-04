<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加摸奖券管理菜单（渠道后台）
 *
 * 菜单结构：
 * - lottery_ticket_manage (父菜单)
 *   - lottery_ticket_dashboard (进行中的活动)
 *   - lottery_ticket_history (历史活动记录)
 *   - lottery_ticket_records (中奖记录)
 */
final class AddLotteryTicketMenus extends AbstractMigration
{
    /**
     * 菜单类型常量
     */
    private const TYPE_CHANNEL = 2; // 渠道后台

    /**
     * 菜单定义
     */
    private const MENUS = [
        // 父菜单：摸奖券管理
        [
            'name' => 'lottery_ticket_manage',
            'icon' => 'GiftOutlined',
            'url' => '',
            'pid' => 33,  // channel_manage 的ID
            'sort' => 5,  // 在推广管理(4)之后
            'status' => 1,
            'open' => 0,  // 默认折叠
            'type' => self::TYPE_CHANNEL,
        ],
        // 子菜单：进行中的活动
        [
            'name' => 'lottery_ticket_dashboard',
            'icon' => 'far fa-circle',
            'url' => 'ex-admin/addons-webman-controller-ChannelLotteryTicketActivityController/index',
            'pid' => 0,  // 将在up()中动态设置为父菜单ID
            'sort' => 1,
            'status' => 1,
            'open' => 1,
            'type' => self::TYPE_CHANNEL,
        ],
        // 子菜单：历史活动记录
        [
            'name' => 'lottery_ticket_history',
            'icon' => 'far fa-circle',
            'url' => 'ex-admin/addons-webman-controller-ChannelLotteryTicketActivityController/historyList',
            'pid' => 0,  // 将在up()中动态设置为父菜单ID
            'sort' => 2,
            'status' => 1,
            'open' => 1,
            'type' => self::TYPE_CHANNEL,
        ],
        // 子菜单：中奖记录
        [
            'name' => 'lottery_ticket_records',
            'icon' => 'far fa-circle',
            'url' => 'ex-admin/addons-webman-controller-ChannelLotteryTicketRecordController/index',
            'pid' => 0,  // 将在up()中动态设置为父菜单ID
            'sort' => 3,
            'status' => 1,
            'open' => 1,
            'type' => self::TYPE_CHANNEL,
        ],
    ];

    /**
     * Migrate Up.
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $parentMenuId = null;

        foreach (self::MENUS as $menu) {
            // 检查菜单是否已存在
            $existing = $this->query(
                "SELECT id FROM admin_menus WHERE name = '{$menu['name']}' AND type = " . self::TYPE_CHANNEL . " LIMIT 1"
            )->fetch();

            if (!$existing) {
                // 如果是子菜单，使用父菜单ID
                $pid = $menu['pid'];
                if ($menu['name'] !== 'lottery_ticket_manage' && $parentMenuId) {
                    $pid = $parentMenuId;
                }

                // 插入菜单
                $this->execute("
                    INSERT INTO admin_menus (name, icon, url, plugin, pid, sort, status, open, type, created_at, updated_at)
                    VALUES (
                        '{$menu['name']}',
                        '{$menu['icon']}',
                        '{$menu['url']}',
                        '',
                        {$pid},
                        {$menu['sort']},
                        {$menu['status']},
                        {$menu['open']},
                        {$menu['type']},
                        '{$now}',
                        '{$now}'
                    )
                ");

                // 如果是父菜单，保存其ID
                if ($menu['name'] === 'lottery_ticket_manage') {
                    $result = $this->query(
                        "SELECT id FROM admin_menus WHERE name = 'lottery_ticket_manage' AND type = " . self::TYPE_CHANNEL . " LIMIT 1"
                    )->fetch();
                    $parentMenuId = $result['id'];
                }

                echo "✓ 菜单创建成功: {$menu['name']}\n";
            } else {
                echo "- 菜单已存在: {$menu['name']}\n";

                // 如果父菜单已存在，保存其ID
                if ($menu['name'] === 'lottery_ticket_manage') {
                    $parentMenuId = $existing['id'];
                }
            }
        }

        echo "\n摸奖券菜单迁移完成！\n";
        echo "⚠️  注意：需要在角色管理中为渠道管理员角色分配这些菜单权限\n";
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        // 按照相反顺序删除菜单（先删除子菜单，再删除父菜单）
        $menuNames = array_reverse(array_column(self::MENUS, 'name'));

        foreach ($menuNames as $name) {
            $this->execute("
                DELETE FROM admin_menus
                WHERE name = '{$name}'
                  AND type = " . self::TYPE_CHANNEL . "
            ");
            echo "✓ 菜单已删除: {$name}\n";
        }

        // 清理孤立的角色菜单关联
        $this->execute("
            DELETE FROM admin_role_menus
            WHERE menu_id NOT IN (SELECT id FROM admin_menus)
        ");

        echo "\n摸奖券菜单回滚完成！\n";
    }
}
