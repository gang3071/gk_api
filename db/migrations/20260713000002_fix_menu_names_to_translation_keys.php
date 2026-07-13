<?php

use Phinx\Migration\AbstractMigration;

class FixMenuNamesToTranslationKeys extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        // 定义需要修复的菜单项：name(中文) => name(翻译key)
        $fixes = [
            '出票記錄' => 'ticket_record_list',
            '核銷記錄' => 'ticket_redeem_list',
            '上下分报表' => 'up_and_down_report',
            '报表中心' => 'report_center',
            '充值满赠管理' => 'deposit_bonus_manage',
            '彩金管理' => 'lottery_management',
            '設備列表' => 'device_list_tw',
        ];

        foreach ($fixes as $chineseName => $translationKey) {
            $this->execute(sprintf(
                "UPDATE `admin_menus` SET `name` = '%s' WHERE `name` = '%s'",
                addslashes($translationKey),
                addslashes($chineseName)
            ));
        }
    }
}
