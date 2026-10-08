<?php

use Phinx\Migration\AbstractMigration;

/**
 * 机台操作日志表补齐列（原 MongoDB 字段迁到 MySQL）
 *
 * 表 machine_operation_log 早期是精简结构，缺 UI 需要的机台/玩家/点数/备注字段。
 * 同时补种 admin_role_permissions，让 index() 页面可访问。
 *
 * 注意：本迁移只用 up()/down()，不用 change()。
 * Phinx 的 Environment::executeMigration 一旦发现 change() 存在就只调 change()，
 * 会把 up()/down() 整个忽略掉（见 vendor .../Migration/Manager/Environment.php L95）。
 */
class AddColumnsToMachineOperationLog extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        $table = $this->table('machine_operation_log');

        if (!$table->hasColumn('producer_id')) {
            $table->addColumn('producer_id', 'integer', [
                'null' => false,
                'signed' => false,
                'default' => 0,
                'comment' => '机台厂商id',
                'after' => 'machine_id',
            ]);
        }
        if (!$table->hasColumn('machine_cate')) {
            $table->addColumn('machine_cate', 'integer', [
                'null' => false,
                'signed' => false,
                'default' => 0,
                'comment' => '机台类别id',
                'after' => 'machine_type',
            ]);
        }
        if (!$table->hasColumn('machine_name')) {
            $table->addColumn('machine_name', 'string', [
                'limit' => 100,
                'null' => false,
                'default' => '',
                'comment' => '机台名称',
                'after' => 'machine_cate',
            ]);
        }
        if (!$table->hasColumn('machine_code')) {
            $table->addColumn('machine_code', 'string', [
                'limit' => 50,
                'null' => false,
                'default' => '',
                'comment' => '机台编号',
                'after' => 'machine_name',
            ]);
        }
        if (!$table->hasColumn('uuid')) {
            $table->addColumn('uuid', 'string', [
                'limit' => 100,
                'null' => false,
                'default' => '',
                'comment' => '玩家uuid',
                'after' => 'machine_code',
            ]);
        }
        if (!$table->hasColumn('player_phone')) {
            $table->addColumn('player_phone', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => '',
                'comment' => '玩家手机',
                'after' => 'uuid',
            ]);
        }
        if (!$table->hasColumn('player_name')) {
            $table->addColumn('player_name', 'string', [
                'limit' => 50,
                'null' => false,
                'default' => '',
                'comment' => '玩家名称',
                'after' => 'player_phone',
            ]);
        }
        if (!$table->hasColumn('point')) {
            $table->addColumn('point', 'integer', [
                'null' => false,
                'signed' => false,
                'default' => 0,
                'comment' => '点数',
                'after' => 'action',
            ]);
        }
        if (!$table->hasColumn('remark')) {
            $table->addColumn('remark', 'string', [
                'limit' => 255,
                'null' => false,
                'default' => '',
                'comment' => '备注',
                'after' => 'user_name',
            ]);
        }

        if (!$table->hasIndex(['machine_code'])) {
            $table->addIndex(['machine_code'], ['name' => 'idx_machine_code']);
        }
        if (!$table->hasIndex(['action'])) {
            $table->addIndex(['action'], ['name' => 'idx_action']);
        }
        if (!$table->hasIndex(['created_at'])) {
            $table->addIndex(['created_at'], ['name' => 'idx_created_at']);
        }

        $table->update();

        // 补种 index 页面权限：沿用 MachineEditLogController\index 的角色集合
        $this->seedIndexPermissions([
            'addons\webman\controller\MachineOperationLogController\index',
            'addons\webman\controller\ChannelMachineOperationLogController\index',
        ]);
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        $this->execute(
            "DELETE FROM `admin_role_permissions` WHERE `node_id` IN (?, ?)",
            [
                'addons\webman\controller\MachineOperationLogController\index',
                'addons\webman\controller\ChannelMachineOperationLogController\index',
            ]
        );

        $table = $this->table('machine_operation_log');

        foreach (['idx_machine_code' => 'machine_code', 'idx_action' => 'action', 'idx_created_at' => 'created_at'] as $name => $col) {
            if ($table->hasIndex([$col])) {
                $table->removeIndex([$col], ['name' => $name]);
            }
        }

        foreach (['producer_id', 'machine_cate', 'machine_name', 'machine_code', 'uuid', 'player_phone', 'player_name', 'point', 'remark'] as $col) {
            if ($table->hasColumn($col)) {
                $table->removeColumn($col);
            }
        }

        $table->update();
    }

    /**
     * 把 MachineEditLogController\index 的角色权限复制到给定节点
     *
     * @param string[] $nodes
     */
    private function seedIndexPermissions(array $nodes): void
    {
        $source = 'addons\webman\controller\MachineEditLogController\index';

        $roleIds = $this->query(
            "SELECT DISTINCT `role_id` FROM `admin_role_permissions` WHERE `node_id` = ?",
            [$source]
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($roleIds as $row) {
            $roleId = (int)$row['role_id'];

            foreach ($nodes as $node) {
                $exists = $this->query(
                    "SELECT 1 FROM `admin_role_permissions` WHERE `role_id` = ? AND `node_id` = ? LIMIT 1",
                    [$roleId, $node]
                )->fetch(\PDO::FETCH_ASSOC);

                if ($exists) {
                    continue;
                }

                $this->execute(
                    "INSERT INTO `admin_role_permissions` (`role_id`, `node_id`) VALUES (?, ?)",
                    [$roleId, $node]
                );
            }
        }
    }
}
