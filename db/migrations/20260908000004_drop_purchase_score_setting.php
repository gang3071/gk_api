<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 删除储值机购分配置表及相关菜单
 */
final class DropPurchaseScoreSetting extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        // 1. 删除 admin_role_menus 中关联的记录
        $this->execute("
            DELETE FROM admin_role_menus
            WHERE menu_id IN (
                SELECT id FROM admin_menus WHERE name = 'store_purchase_score_setting'
            )
        ");
        $this->output->writeln('   ✅ 已删除 admin_role_menus 关联记录');

        // 2. 删除 admin_menus 中的菜单记录
        $this->execute("
            DELETE FROM admin_menus
            WHERE name = 'store_purchase_score_setting'
        ");
        $this->output->writeln('   ✅ 已删除 admin_menus 菜单记录');

        // 3. 删除 purchase_score_setting 表（如果存在）
        if ($this->hasTable('purchase_score_setting')) {
            $this->table('purchase_score_setting')->drop()->save();
            $this->output->writeln('   ✅ 已删除 purchase_score_setting 表');
        } else {
            $this->output->writeln('   ⏭  purchase_score_setting 表不存在，跳过');
        }
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        $this->output->writeln('   ⚠️  此迁移不可回滚');
    }
}
