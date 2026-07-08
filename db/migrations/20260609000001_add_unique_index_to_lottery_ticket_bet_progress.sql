-- 添加唯一索引到摸奖券打码进度表
-- 防止同一玩家同一活动创建多条进度记录

-- 检查并删除重复记录（如果存在）
DELETE t1 FROM lottery_ticket_bet_progress t1
INNER JOIN lottery_ticket_bet_progress t2
WHERE t1.id > t2.id
  AND t1.activity_id = t2.activity_id
  AND t1.player_id = t2.player_id;

-- 添加唯一索引
ALTER TABLE lottery_ticket_bet_progress
ADD UNIQUE INDEX `idx_activity_player` (`activity_id`, `player_id`);
