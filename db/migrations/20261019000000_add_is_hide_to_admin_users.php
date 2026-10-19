<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * admin_users 新增「撤店隱藏」旗標
 *
 * is_hide = 1 表示店家已撤店，店家分潤報表需排除該店家。
 */
final class AddIsHideToAdminUsers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('admin_users')
            ->addColumn('is_hide', 'boolean', [
                'null' => false,
                'default' => false,
                'after' => 'status',
                'comment' => '是否撤店隱藏：0=否，1=是',
            ])
            ->update();
    }
}
