<?php

use Phinx\Migration\AbstractMigration;

/**
 * 更新摸奖券中奖等级配置表
 * 1. 移除不需要的字段: prize_type, prize_item_name, prize_item_image, prize_count, description
 * 2. 只保留现金奖励相关字段
 *
 * @date 2026-06-08
 */
class UpdateLotteryTicketPrizeLevelTable extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up()
    {
        $table = $this->table('lottery_ticket_prize_level');

        // 删除不需要的字段
        if ($table->hasColumn('prize_type')) {
            $table->removeColumn('prize_type');
        }

        if ($table->hasColumn('prize_item_name')) {
            $table->removeColumn('prize_item_name');
        }

        if ($table->hasColumn('prize_item_image')) {
            $table->removeColumn('prize_item_image');
        }

        if ($table->hasColumn('prize_count')) {
            $table->removeColumn('prize_count');
        }

        if ($table->hasColumn('description')) {
            $table->removeColumn('description');
        }

        if ($table->hasColumn('win_probability')) {
            $table->removeColumn('win_probability');
        }

        $table->save();

        // 更新 prize_amount 字段注释
        $this->execute("ALTER TABLE `lottery_ticket_prize_level` MODIFY COLUMN `prize_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT '奖品金额(现金)'");

        // 更新表注释
        $this->execute("ALTER TABLE `lottery_ticket_prize_level` COMMENT = '摸奖券中奖等级配置表(仅现金奖励)'");
    }

    /**
     * Migrate Down.
     */
    public function down()
    {
        $table = $this->table('lottery_ticket_prize_level');

        // 恢复删除的字段
        $table
            ->addColumn('prize_type', 'string', [
                'limit' => 50,
                'null' => false,
                'default' => 'cash',
                'comment' => '奖品类型(cash:现金,bonus:红利,item:实物,points:积分)',
                'after' => 'level_name',
            ])
            ->addColumn('prize_item_name', 'string', [
                'limit' => 100,
                'null' => true,
                'comment' => '实物奖品名称',
                'after' => 'prize_amount',
            ])
            ->addColumn('prize_item_image', 'string', [
                'limit' => 500,
                'null' => true,
                'comment' => '实物奖品图片URL',
                'after' => 'prize_item_name',
            ])
            ->addColumn('prize_count', 'integer', [
                'null' => false,
                'signed' => false,
                'default' => 1,
                'comment' => '该等级奖品数量',
                'after' => 'prize_item_image',
            ])
            ->addColumn('description', 'text', [
                'null' => true,
                'comment' => '奖品描述',
                'after' => 'status',
            ])
            ->addColumn('win_probability', 'decimal', [
                'precision' => 5,
                'scale' => 2,
                'null' => true,
                'default' => 0.00,
                'comment' => '中奖概率(%,如5.50表示5.5%)',
                'after' => 'prize_count',
            ])
            ->save();
    }
}
