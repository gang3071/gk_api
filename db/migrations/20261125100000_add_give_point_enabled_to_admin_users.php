<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 为 admin_users 表新增开分赠点开关字段
 *
 * give_point_enabled = 0（关闭，默认）/ 1（开启）
 * 渠道在门店管理页通过开关控制，gk_api givePoints() 校验此字段。
 */
final class AddGivePointEnabledToAdminUsers extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('admin_users');

        if (!$table->hasColumn('give_point_enabled')) {
            $table->addColumn('give_point_enabled', 'boolean', [
                'null'    => false,
                'default' => false,
                'comment' => '开分赠点开关（0=关闭，1=开启）',
                'after'   => 'welfare_voucher_enabled',
            ]);
            $table->update();
        }
    }
}
