<?php

use Phinx\Migration\AbstractMigration;

/**
 * 餐點訂單添加欄位
 */
class AddColumnDishOrder extends AbstractMigration
{
    public function up()
    {
        $table = $this->table('dish_order');

        $table->addColumn('device_id', 'integer', [
            'null' => false,
            'default' => 0,
            'after' => 'admin_user_id',
            'comment' => '設備ID'
        ]);

        $table->update();
    }

    public function down()
    {
        $table = $this->table('dish_order');

        if ($table->hasColumn('device_id')) {
            $table->removeColumn('device_id');
        }

        $table->update();
    }
}
