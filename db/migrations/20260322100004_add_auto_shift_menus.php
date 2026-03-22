<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加自动交班菜单和权限
 */
class AddAutoShiftMenus extends AbstractMigration
{
    /**
     * 菜单类型常量
     */
    const TYPE_STORE = 4; // 店家后台

    /**
     * 菜单数据
     * @var array
     */
    private $menus = [
        [
            'name' => 'auto_shift_management',
            'title' => '自动交班',
            'icon' => 'ClockCircleOutlined',
            'url' => '',
            'plugin' => '',
            'pid' => 0, // 父级菜单ID，需要根据实际情况调整
            'sort' => 150,
            'status' => 1,
            'open' => 1,
            'type' => self::TYPE_STORE, // 店家后台菜单
            'children' => [
                [
                    'name' => 'auto_shift_config',
                    'title' => '交班配置',
                    'icon' => 'SettingOutlined',
                    'url' => 'ex-admin/addons-webman-controller-ChannelAutoShiftController/config',
                    'plugin' => '',
                    'sort' => 1,
                    'status' => 1,
                    'open' => 1,
                    'type' => self::TYPE_STORE,
                ],
                [
                    'name' => 'auto_shift_logs',
                    'title' => '执行日志',
                    'icon' => 'FileTextOutlined',
                    'url' => 'ex-admin/addons-webman-controller-ChannelAutoShiftController/logs',
                    'plugin' => '',
                    'sort' => 2,
                    'status' => 1,
                    'open' => 1,
                    'type' => self::TYPE_STORE,
                ],
            ],
        ],
    ];

    /**
     * 迁移 Up
     */
    public function up()
    {
        $menuTable = $this->table('admin_menus');
        $now = date('Y-m-d H:i:s');

        foreach ($this->menus as $menu) {
            // 检查父级菜单是否已存在
            $existingParent = $this->fetchRow(
                "SELECT id FROM admin_menus WHERE name = ? AND deleted_at IS NULL LIMIT 1",
                [$menu['name']]
            );

            if ($existingParent) {
                // 菜单已存在，跳过
                $this->output->writeln("<comment>菜单 '{$menu['name']}' 已存在，跳过创建</comment>");
                $parentId = $existingParent['id'];
            } else {
                // 插入父级菜单
                $menuTable->insert([
                    'name' => $menu['name'],
                    'icon' => $menu['icon'],
                    'url' => $menu['url'],
                    'plugin' => $menu['plugin'],
                    'pid' => $menu['pid'],
                    'sort' => $menu['sort'],
                    'status' => $menu['status'],
                    'open' => $menu['open'],
                    'type' => $menu['type'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->saveData();

                $parentId = $this->getAdapter()->getConnection()->lastInsertId();
                $this->output->writeln("<info>创建父级菜单: {$menu['title']} (ID: {$parentId}, Type: {$menu['type']})</info>");
            }

            // 插入子菜单
            if (isset($menu['children']) && is_array($menu['children'])) {
                foreach ($menu['children'] as $child) {
                    // 检查子菜单是否已存在
                    $existingChild = $this->fetchRow(
                        "SELECT id FROM admin_menus WHERE name = ? AND deleted_at IS NULL LIMIT 1",
                        [$child['name']]
                    );

                    if ($existingChild) {
                        $this->output->writeln("<comment>子菜单 '{$child['name']}' 已存在，跳过创建</comment>");
                        continue;
                    }

                    $menuTable->insert([
                        'name' => $child['name'],
                        'icon' => $child['icon'],
                        'url' => $child['url'],
                        'plugin' => $child['plugin'],
                        'pid' => $parentId,
                        'sort' => $child['sort'],
                        'status' => $child['status'],
                        'open' => $child['open'],
                        'type' => $child['type'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->saveData();

                    $childId = $this->getAdapter()->getConnection()->lastInsertId();
                    $this->output->writeln("<info>创建子菜单: {$child['title']} (ID: {$childId}, Type: {$child['type']})</info>");
                }
            }
        }

        $this->output->writeln("<info>自动交班菜单创建完成</info>");
    }

    /**
     * 迁移 Down (回滚)
     */
    public function down()
    {
        // 删除子菜单（仅删除店家后台的）
        $this->execute("
            DELETE FROM admin_menus
            WHERE name IN ('auto_shift_config', 'auto_shift_logs')
              AND type = " . self::TYPE_STORE . "
        ");

        // 删除父级菜单（仅删除店家后台的）
        $this->execute("
            DELETE FROM admin_menus
            WHERE name = 'auto_shift_management'
              AND type = " . self::TYPE_STORE . "
        ");

        // 同时删除相关的角色菜单关联
        $this->execute("
            DELETE FROM admin_role_menus
            WHERE menu_id NOT IN (SELECT id FROM admin_menus)
        ");

        $this->output->writeln("<info>自动交班菜单已删除</info>");
    }
}
