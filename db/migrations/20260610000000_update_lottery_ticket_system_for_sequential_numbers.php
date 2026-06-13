<?php

use Phinx\Migration\AbstractMigration;

class UpdateLotteryTicketSystemForSequentialNumbers extends AbstractMigration
{
    /**
     * 摸奖券系统重大变更：
     * 1. 券号改为活动内自增序列（000000~999999，共100万张）
     * 2. 开奖改为摇球方式
     * 3. 所有奖品必须发出去（确定性中奖）
     *
     * 券号规则：
     * - 第1张券：000000
     * - 第2张券：000001
     * - 第15张券：000014
     * - 最后1张券：999999
     */
    public function change()
    {
        $table = $this->table('lottery_ticket_activity');

        // 添加当前已发券数字段
        if (!$table->hasColumn('current_ticket_no')) {
            $table->addColumn('current_ticket_no', 'integer', [
                'default' => 0,
                'null' => false,
                'comment' => '当前已发券数（0表示未发券，1表示已发1张券号000000，15表示已发15张券号000000~000014）',
                'after' => 'used_tickets'
            ])->update();
        }

        // 添加最大可发券数字段
        if (!$table->hasColumn('max_ticket_no')) {
            $table->addColumn('max_ticket_no', 'integer', [
                'default' => 1000000,
                'null' => false,
                'comment' => '最大可发券数（默认100万张，券号000000~999999）',
                'after' => 'current_ticket_no'
            ])->update();
        }

        // 添加开奖方式字段
        if (!$table->hasColumn('draw_method')) {
            $table->addColumn('draw_method', 'string', [
                'limit' => 20,
                'default' => 'ball',
                'null' => false,
                'comment' => '开奖方式：ball=摇球，manual=手动录入',
                'after' => 'draw_time'
            ])->update();
        }

        // 添加摇球结果字段（JSON格式存储6个球的结果）
        if (!$table->hasColumn('ball_result')) {
            $table->addColumn('ball_result', 'text', [
                'null' => true,
                'comment' => '摇球结果（JSON格式）：{"ball1":1,"ball2":4,"ball3":0,...}',
                'after' => 'draw_method'
            ])->update();
        }

        // 修改 ticket_no 字段类型为 CHAR(6)（保证前导零）
        $this->execute("ALTER TABLE `lottery_ticket` MODIFY COLUMN `ticket_no` CHAR(6) NOT NULL COMMENT '券号（6位数字，000000~999999）'");

        // 为 ticket_no 添加索引（提升查询性能）
        $ticketTable = $this->table('lottery_ticket');
        if (!$this->hasIndex('lottery_ticket', ['activity_id', 'ticket_no'])) {
            $ticketTable->addIndex(['activity_id', 'ticket_no'], [
                'name' => 'idx_activity_ticket_no',
                'unique' => true
            ])->update();
        }
    }

    /**
     * 检查索引是否存在
     */
    protected function hasIndex($table, $columns)
    {
        $indexes = $this->fetchAll("SHOW INDEX FROM `{$table}`");
        $columnNames = implode(',', $columns);

        foreach ($indexes as $index) {
            if ($index['Column_name'] === $columns[0]) {
                return true;
            }
        }

        return false;
    }
}
