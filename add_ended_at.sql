-- 添加 ended_at 字段到摸奖券活动表
-- 用于精确记录活动变为STATUS_ENDED状态的时间
-- 执行时间：2026-06-24

ALTER TABLE `lottery_ticket_activity`
ADD COLUMN `ended_at` TIMESTAMP NULL COMMENT '活动实际结束时间（变为已结束状态的时间）' AFTER `end_time`,
ADD INDEX `idx_ended_at` (`ended_at`);

-- 验证字段添加成功
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_COMMENT
FROM
    INFORMATION_SCHEMA.COLUMNS
WHERE
    TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'lottery_ticket_activity'
    AND COLUMN_NAME = 'ended_at';
