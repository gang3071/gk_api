<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加 player_id 字段到 store_setting 表
 *
 * 用于支持店家配置绑定到具体玩家账号
 */
class AddPlayerIdToStoreSetting extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function change()
    {
        echo "================================================================================\n";
        echo "添加 player_id 字段到 store_setting 表\n";
        echo "================================================================================\n";

        $table = $this->table('store_setting');

        // 检查字段是否已存在
        if (!$table->hasColumn('player_id')) {
            $table->addColumn('player_id', 'biginteger', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'comment' => '绑定的玩家ID（店家账号，NULL表示渠道配置）',
                'after' => 'department_id'
            ])
            ->addIndex(['department_id', 'player_id', 'feature'], [
                'name' => 'idx_dept_player_feature',
                'unique' => false
            ])
            ->addIndex(['player_id'], [
                'name' => 'idx_player_id'
            ])
            ->update();

            echo "✓ 字段 player_id 已成功添加到 store_setting 表\n";
            echo "✓ 已添加复合索引: idx_dept_player_feature (department_id, player_id, feature)\n";
            echo "✓ 已添加单列索引: idx_player_id (player_id)\n";
        } else {
            echo "✓ 字段 player_id 已存在，跳过\n";
        }

        echo "================================================================================\n";
        echo "迁移完成！\n";
        echo "================================================================================\n";
    }
}
