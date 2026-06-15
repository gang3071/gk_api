<?php

use Phinx\Migration\AbstractMigration;

/**
 * 摸奖券系统性能优化 - 添加索引
 *
 * 优化内容：
 * 1. lottery_ticket表索引优化（玩家查询、活动查询、防重复）
 * 2. lottery_ticket_bet_progress表索引优化（打码进度查询）
 * 3. lottery_ticket_record表索引优化（中奖记录查询）
 * 4. lottery_ticket_activity表索引优化（智能活动查询）
 */
class AddLotteryTicketIndexes extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change()
    {
        // 1. lottery_ticket表索引优化
        $lotteryTicketTable = $this->table('lottery_ticket');

        // 检查索引是否已存在，避免重复创建
        if (!$lotteryTicketTable->hasIndex(['player_id', 'status', 'expired_at'])) {
            $lotteryTicketTable->addIndex(
                ['player_id', 'status', 'expired_at'],
                ['name' => 'idx_player_status']
            );
        }

        if (!$lotteryTicketTable->hasIndex(['activity_id', 'status'])) {
            $lotteryTicketTable->addIndex(
                ['activity_id', 'status'],
                ['name' => 'idx_activity_status']
            );
        }

        if (!$lotteryTicketTable->hasIndex(['activity_id', 'ticket_no'])) {
            $lotteryTicketTable->addIndex(
                ['activity_id', 'ticket_no'],
                ['unique' => true, 'name' => 'uk_activity_ticket_no']
            );
        }

        $lotteryTicketTable->save();

        // 2. lottery_ticket_bet_progress表索引优化
        $betProgressTable = $this->table('lottery_ticket_bet_progress');

        if (!$betProgressTable->hasIndex(['activity_id', 'player_id', 'vip_level_id'])) {
            $betProgressTable->addIndex(
                ['activity_id', 'player_id', 'vip_level_id'],
                ['unique' => true, 'name' => 'uk_activity_player_vip']
            );
        }

        $betProgressTable->save();

        // 3. lottery_ticket_record表索引优化
        $recordTable = $this->table('lottery_ticket_record');

        if (!$recordTable->hasIndex(['player_id', 'activity_id', 'status'])) {
            $recordTable->addIndex(
                ['player_id', 'activity_id', 'status'],
                ['name' => 'idx_player_activity']
            );
        }

        $recordTable->save();

        // 4. lottery_ticket_activity表索引优化
        $activityTable = $this->table('lottery_ticket_activity');

        if (!$activityTable->hasIndex(['department_id', 'status', 'start_time'])) {
            $activityTable->addIndex(
                ['department_id', 'status', 'start_time'],
                ['name' => 'idx_department_status']
            );
        }

        $activityTable->save();
    }

    /**
     * Migrate Up.
     */
    public function up()
    {
        // 使用change方法，此方法可留空
    }

    /**
     * Migrate Down.
     */
    public function down()
    {
        // 回滚：删除所有索引

        // lottery_ticket表
        $lotteryTicketTable = $this->table('lottery_ticket');
        if ($lotteryTicketTable->hasIndex(['player_id', 'status', 'expired_at'])) {
            $lotteryTicketTable->removeIndex(['player_id', 'status', 'expired_at']);
        }
        if ($lotteryTicketTable->hasIndex(['activity_id', 'status'])) {
            $lotteryTicketTable->removeIndex(['activity_id', 'status']);
        }
        if ($lotteryTicketTable->hasIndex(['activity_id', 'ticket_no'])) {
            $lotteryTicketTable->removeIndex(['activity_id', 'ticket_no']);
        }
        $lotteryTicketTable->save();

        // lottery_ticket_bet_progress表
        $betProgressTable = $this->table('lottery_ticket_bet_progress');
        if ($betProgressTable->hasIndex(['activity_id', 'player_id', 'vip_level_id'])) {
            $betProgressTable->removeIndex(['activity_id', 'player_id', 'vip_level_id']);
        }
        $betProgressTable->save();

        // lottery_ticket_record表
        $recordTable = $this->table('lottery_ticket_record');
        if ($recordTable->hasIndex(['player_id', 'activity_id', 'status'])) {
            $recordTable->removeIndex(['player_id', 'activity_id', 'status']);
        }
        $recordTable->save();

        // lottery_ticket_activity表
        $activityTable = $this->table('lottery_ticket_activity');
        if ($activityTable->hasIndex(['department_id', 'status', 'start_time'])) {
            $activityTable->removeIndex(['department_id', 'status', 'start_time']);
        }
        $activityTable->save();
    }
}
