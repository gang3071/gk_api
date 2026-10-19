<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加門店帳務報表菜單
 */
final class AddStoreFinanceReportMenu extends AbstractMigration
{
    public function up(): void
    {
        $parentMenu = $this->query("SELECT id FROM `admin_menus` WHERE `name` = 'report_center' AND `type` = 3 ORDER BY id DESC LIMIT 1")->fetch();

        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `type`, `status`, `open`, `created_at`, `updated_at`)
            VALUES ('store_finance_report', 'far fa-circle', 'ex-admin/addons-webman-controller-AgentStoreProfitReportController/finance', '', {$parentMenu['id']}, 25, 3, 1, 0, NOW(), NOW())
        ");
    }

    public function down(): void
    {
        $this->execute("DELETE FROM `admin_menus` WHERE `name` = 'store_finance_report'");
    }
}
